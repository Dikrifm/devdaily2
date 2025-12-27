<?php

namespace App\Services;

use App\DTOs\Requests\Marketplace\CreateMarketplaceRequest;
use App\DTOs\Requests\Marketplace\UpdateMarketplaceRequest;
use App\DTOs\Requests\Marketplace\UpdateMarketplaceConfigurationRequest;
use App\DTOs\Responses\MarketplaceResponse;
use App\Entities\Marketplace;
use App\Exceptions\MarketplaceNotFoundException;
use App\Exceptions\DomainException;
use App\Exceptions\ValidationException;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Services\AuditService;
use App\Services\CacheService;
use App\Services\LinkService;
use App\Services\ValidationService;
use CodeIgniter\Database\ConnectionInterface;
use Exception;
use RuntimeException;

class MarketplaceService
{
    private MarketplaceRepositoryInterface $marketplaceRepository;
    private ValidationService $validationService;
    private AuditService $auditService;
    private CacheService $cacheService;
    private LinkService $linkService;
    private ConnectionInterface $db;
    private array $config;

    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'marketplace_service_';
    private const CONFIGURATION_CACHE_TTL = 1800; // 30 minutes
    private const STATS_CACHE_TTL = 300; // 5 minutes
    private const MAX_MARKETPLACES = 50; // Maximum marketplaces allowed

    public function __construct(
        MarketplaceRepositoryInterface $marketplaceRepository,
        ValidationService $validationService,
        AuditService $auditService,
        CacheService $cacheService,
        LinkService $linkService,
        ConnectionInterface $db,
        array $config = []
    ) {
        $this->marketplaceRepository = $marketplaceRepository;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->cacheService = $cacheService;
        $this->linkService = $linkService;
        $this->db = $db;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function create(CreateMarketplaceRequest $request, int $creatorId): Marketplace
    {
        // Validate business rules
        $validationResult = $this->validationService->validateAdminPermission(
            $creatorId, 
            'marketplace.create'
        );
        
        if (!$validationResult['valid']) {
            throw new DomainException(
                'You do not have permission to create marketplace', 
                403, 
                $validationResult['errors']
            );
        }

        // Check marketplace limit
        $currentCount = $this->marketplaceRepository->countActive();
        if ($currentCount >= self::MAX_MARKETPLACES) {
            throw new DomainException(
                'Maximum number of marketplaces reached. Limit: ' . self::MAX_MARKETPLACES,
                400
            );
        }

        // Check uniqueness
        if (!$this->marketplaceRepository->isSlugUnique($request->getSlug())) {
            throw new ValidationException(
                [], 
                'Marketplace slug already exists', 
                ['field' => 'slug']
            );
        }

        if (!$this->marketplaceRepository->isNameUnique($request->getName())) {
            throw new ValidationException(
                [], 
                'Marketplace name already exists', 
                ['field' => 'name']
            );
        }

        // Validate domain if provided
        if ($request->getDomain() && !$this->isValidDomain($request->getDomain())) {
            throw new ValidationException(
                [], 
                'Invalid domain format', 
                ['field' => 'domain']
            );
        }

        // Create Marketplace entity
        $marketplace = new Marketplace($request->getName(), $request->getSlug());
        
        // Set properties
        if ($request->getDescription() !== null) {
            // No setter for description in Marketplace entity - need to add or use array
            // For now, we'll assume it's handled in toArray/save
        }
        
        if ($request->getIcon() !== null) {
            $marketplace->setIcon($request->getIcon());
        }
        
        $marketplace->setColor($request->getColor());
        $marketplace->setActive($request->isActive());
        
        // Domain is not in entity - we'll need to handle via repository
        // This indicates a mismatch between entity and request
        
        // Save through repository
        try {
            $this->db->transStart();
            
            // Convert to array for repository (temporary solution)
            $marketplaceData = [
                'name' => $marketplace->getName(),
                'slug' => $marketplace->getSlug(),
                'icon' => $marketplace->getIcon(),
                'color' => $marketplace->getColor(),
                'active' => $marketplace->isActive(),
                'description' => $request->getDescription(),
                'domain' => $request->getDomain(),
                'affiliate_program_url' => $request->getAffiliateProgramUrl(),
                'default_commission_rate' => $request->getDefaultCommissionRate(),
                'api_key' => $request->getApiKey(),
                'api_secret' => $request->getApiSecret(),
                'webhook_url' => $request->getWebhookUrl(),
            ];
            
            // We need a method to save from array or update entity
            // For now, create a new entity with full data
            $fullMarketplace = Marketplace::fromArray($marketplaceData);
            $marketplace = $this->marketplaceRepository->save($fullMarketplace);
            
            $this->auditService->logCreate(
                $creatorId, 
                $marketplace, 
                Marketplace::class, 
                ['creator_id' => $creatorId]
            );
            
            $this->db->transComplete();
        } catch (Exception $e) {
            $this->db->transRollback();
            throw new RuntimeException(
                'Failed to create marketplace: ' . $e->getMessage(), 
                500, 
                $e
            );
        }

        // Clear relevant caches
        $this->clearMarketplaceCaches($marketplace->getId());

        return $marketplace;
    }

    public function update(UpdateMarketplaceRequest $request, int $updaterId): Marketplace
    {
        // Get marketplace to update
        $marketplace = $this->marketplaceRepository->find($request->getMarketplaceId());
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($request->getMarketplaceId());
        }

        // Validate permission
        $validationResult = $this->validationService->validateAdminPermission(
            $updaterId, 
            'marketplace.update', 
            $marketplace
        );
        
        if (!$validationResult['valid']) {
            throw new DomainException(
                'You do not have permission to update this marketplace', 
                403, 
                $validationResult['errors']
            );
        }

        // Check for changes that need validation
        $changes = [];
        
        if ($request->getName() !== null && $request->getName() !== $marketplace->getName()) {
            // Check name uniqueness
            if (!$this->marketplaceRepository->isNameUnique($request->getName(), $marketplace->getId())) {
                throw new ValidationException([], 'Marketplace name already exists', ['field' => 'name']);
            }
            $changes['name'] = $request->getName();
        }
        
        if ($request->getSlug() !== null && $request->getSlug() !== $marketplace->getSlug()) {
            // Check slug uniqueness
            if (!$this->marketplaceRepository->isSlugUnique($request->getSlug(), $marketplace->getId())) {
                throw new ValidationException([], 'Marketplace slug already exists', ['field' => 'slug']);
            }
            $changes['slug'] = $request->getSlug();
        }
        
        if ($request->getIcon() !== null) {
            $changes['icon'] = $request->getIcon();
        }
        
        if ($request->getColor() !== null) {
            $changes['color'] = $request->getColor();
        }
        
        if ($request->isActive() !== null) {
            $changes['active'] = $request->isActive();
            
            // If deactivating, need to check if marketplace has active links
            if ($request->isActive() === false) {
                $activeLinksCount = $this->marketplaceRepository->countActiveLinks($marketplace->getId());
                if ($activeLinksCount > 0) {
                    throw new DomainException(
                        "Cannot deactivate marketplace with {$activeLinksCount} active links. " .
                        "Deactivate or remove links first.",
                        409
                    );
                }
            }
        }

        // Apply changes
        foreach ($changes as $field => $value) {
            switch ($field) {
                case 'name':
                    $marketplace->setName($value);
                    break;
                case 'slug':
                    $marketplace->setSlug($value);
                    break;
                case 'icon':
                    $marketplace->setIcon($value);
                    break;
                case 'color':
                    $marketplace->setColor($value);
                    break;
                case 'active':
                    $marketplace->setActive($value);
                    break;
            }
        }

        // Save changes
        try {
            $this->db->transStart();
            $marketplace = $this->marketplaceRepository->save($marketplace);
            $this->auditService->logUpdate(
                $updaterId, 
                $marketplace, 
                Marketplace::class, 
                [
                    'updater_id' => $updaterId,
                    'changes' => $changes
                ]
            );
            $this->db->transComplete();
        } catch (Exception $e) {
            $this->db->transRollback();
            throw new RuntimeException(
                'Failed to update marketplace: ' . $e->getMessage(), 
                500, 
                $e
            );
        }

        $this->clearMarketplaceCaches($marketplace->getId());

        return $marketplace;
    }

    public function delete(int $marketplaceId, int $deleterId, bool $force = false): bool
    {
        $marketplace = $this->marketplaceRepository->find($marketplaceId);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($marketplaceId);
        }

        // Validate permission
        $validationResult = $this->validationService->validateAdminPermission(
            $deleterId, 
            'marketplace.delete', 
            $marketplace
        );
        
        if (!$validationResult['valid']) {
            throw new DomainException(
                'You do not have permission to delete this marketplace', 
                403, 
                $validationResult['errors']
            );
        }

        // Check if marketplace can be deleted
        $canDelete = $this->marketplaceRepository->canDelete($marketplaceId);
        if (!$canDelete['can_delete'] && !$force) {
            throw new DomainException(
                $canDelete['message'] . ' Use force delete to override.',
                409
            );
        }

        try {
            $this->db->transStart();
            
            if ($force) {
                // Hard delete
                $success = $this->marketplaceRepository->delete($marketplaceId, true);
            } else {
                // Soft delete (archive)
                $marketplace->archive();
                $success = $this->marketplaceRepository->save($marketplace);
            }
            
            if ($success) {
                $this->auditService->logDelete(
                    $deleterId, 
                    $marketplace, 
                    Marketplace::class, 
                    ['deleter_id' => $deleterId, 'force' => $force]
                );
            }
            
            $this->db->transComplete();
            
            if (!$success) {
                throw new RuntimeException('Failed to delete marketplace');
            }
        } catch (Exception $e) {
            $this->db->transRollback();
            throw new RuntimeException(
                'Failed to delete marketplace: ' . $e->getMessage(), 
                500, 
                $e
            );
        }

        $this->clearMarketplaceCaches($marketplaceId);

        return true;
    }

    public function activate(int $marketplaceId, int $activatorId): bool
    {
        return $this->setActiveStatus($marketplaceId, $activatorId, true);
    }

    public function deactivate(int $marketplaceId, int $deactivatorId, ?string $reason = null): bool
    {
        return $this->setActiveStatus($marketplaceId, $deactivatorId, false, $reason);
    }

    private function setActiveStatus(
        int $marketplaceId, 
        int $adminId, 
        bool $active, 
        ?string $reason = null
    ): bool {
        $marketplace = $this->marketplaceRepository->find($marketplaceId);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($marketplaceId);
        }

        $action = $active ? 'activate' : 'deactivate';
        $validationResult = $this->validationService->validateAdminPermission(
            $adminId, 
            "marketplace.{$action}", 
            $marketplace
        );
        
        if (!$validationResult['valid']) {
            throw new DomainException(
                "You do not have permission to {$action} this marketplace", 
                403, 
                $validationResult['errors']
            );
        }

        // If deactivating, check for active links
        if (!$active) {
            $activeLinksCount = $this->marketplaceRepository->countActiveLinks($marketplaceId);
            if ($activeLinksCount > 0) {
                throw new DomainException(
                    "Cannot deactivate marketplace with {$activeLinksCount} active links",
                    409
                );
            }
        }

        $oldStatus = $marketplace->isActive() ? 'active' : 'inactive';
        $newStatus = $active ? 'active' : 'inactive';
        
        $marketplace->setActive($active);

        try {
            $this->db->transStart();
            $this->marketplaceRepository->save($marketplace);
            $this->auditService->logStateTransition(
                $adminId, 
                $marketplace, 
                Marketplace::class, 
                $oldStatus, 
                $newStatus, 
                ['reason' => $reason]
            );
            $this->db->transComplete();
        } catch (Exception $e) {
            $this->db->transRollback();
            throw new RuntimeException(
                "Failed to {$action} marketplace: " . $e->getMessage(), 
                500, 
                $e
            );
        }

        $this->clearMarketplaceCaches($marketplaceId);

        return true;
    }

    public function getMarketplace(int $marketplaceId, bool $includeStats = false): MarketplaceResponse
    {
        $marketplace = $this->marketplaceRepository->find($marketplaceId);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($marketplaceId);
        }

        $additionalData = [];
        if ($includeStats) {
            $additionalData = [
                'statistics' => $this->marketplaceRepository->getStatistics($marketplaceId),
                'links_count' => $this->marketplaceRepository->countActiveLinks($marketplaceId),
                'configuration' => $this->marketplaceRepository->getConfiguration($marketplaceId),
            ];
        }

        return MarketplaceResponse::fromEntity($marketplace, $additionalData);
    }

    public function getMarketplaces(array $filters = [], bool $includeStats = false, int $limit = 50): array
    {
        $marketplaces = $this->marketplaceRepository->findAll($filters, $limit);
        
        if ($includeStats) {
            foreach ($marketplaces as &$marketplace) {
                $marketplaceId = $marketplace->getId();
                $marketplace = $this->getMarketplace($marketplaceId, true);
            }
        }

        return $marketplaces;
    }

    public function updateConfiguration(
        UpdateMarketplaceConfigurationRequest $request, 
        int $updaterId
    ): array {
        $marketplace = $this->marketplaceRepository->find($request->getMarketplaceId());
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($request->getMarketplaceId());
        }

        // Validate permission
        $validationResult = $this->validationService->validateAdminPermission(
            $updaterId, 
            'marketplace.configure', 
            $marketplace
        );
        
        if (!$validationResult['valid']) {
            throw new DomainException(
                'You do not have permission to configure this marketplace', 
                403, 
                $validationResult['errors']
            );
        }

        $configuration = [];
        if ($request->getApiKey() !== null) {
            $configuration['api_key'] = $request->getApiKey();
        }
        if ($request->getApiSecret() !== null) {
            $configuration['api_secret'] = $request->getApiSecret();
        }
        if ($request->getWebhookUrl() !== null) {
            $configuration['webhook_url'] = $request->getWebhookUrl();
        }
        if ($request->getCommissionRate() !== null) {
            $configuration['default_commission_rate'] = $request->getCommissionRate();
        }

        try {
            $success = $this->marketplaceRepository->updateConfiguration(
                $request->getMarketplaceId(), 
                $configuration
            );
            
            if ($success) {
                $this->auditService->logUpdate(
                    $updaterId, 
                    $marketplace, 
                    Marketplace::class, 
                    [
                        'updater_id' => $updaterId,
                        'configuration_updated' => array_keys($configuration)
                    ]
                );
            }
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to update configuration: ' . $e->getMessage(), 
                500, 
                $e
            );
        }

        $this->clearMarketplaceCaches($request->getMarketplaceId());

        return [
            'success' => true,
            'marketplace_id' => $request->getMarketplaceId(),
            'configuration_updated' => array_keys($configuration)
        ];
    }

    public function getStatistics(?int $marketplaceId = null): array
    {
        if ($marketplaceId) {
            $cacheKey = $this->getCacheKey("stats_{$marketplaceId}");
            return $this->cacheService->remember(
                $cacheKey, 
                function() use ($marketplaceId) {
                    return $this->marketplaceRepository->getStatistics($marketplaceId);
                }, 
                self::STATS_CACHE_TTL
            );
        }

        $cacheKey = $this->getCacheKey('system_stats');
        return $this->cacheService->remember(
            $cacheKey, 
            function() {
                return $this->marketplaceRepository->getStatistics();
            }, 
            self::STATS_CACHE_TTL
        );
    }

    public function testConnection(int $marketplaceId, int $adminId): array
    {
        $marketplace = $this->marketplaceRepository->find($marketplaceId);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($marketplaceId);
        }

        $configuration = $this->marketplaceRepository->getConfiguration($marketplaceId);
        
        // Implement actual API connection test
        $result = [
            'success' => false,
            'message' => 'Connection test not implemented',
            'marketplace_id' => $marketplaceId,
            'marketplace_name' => $marketplace->getName(),
        ];

        // Log test attempt
        $this->auditService->logCrudOperation(
            $adminId,
            $marketplace,
            Marketplace::class,
            'TEST_CONNECTION',
            $result
        );

        return $result;
    }

    private function isValidDomain(string $domain): bool
    {
        // Simple domain validation
        return preg_match('/^(https?:\/\/)?([a-z0-9-]+\.)+[a-z]{2,}(:\d+)?(\/.*)?$/i', $domain) === 1;
    }

    private function clearMarketplaceCaches(?int $marketplaceId = null): void
    {
        if ($marketplaceId) {
            $this->cacheService->delete($this->getCacheKey("find_{$marketplaceId}"));
            $this->cacheService->delete($this->getCacheKey("stats_{$marketplaceId}"));
            $this->cacheService->delete($this->getCacheKey("configuration_{$marketplaceId}"));
        }
        
        $this->cacheService->delete($this->getCacheKey('system_stats'));
        $this->cacheService->delete($this->getCacheKey('all_active'));
        
        // Clear cache with pattern
        $this->cacheService->flushTag(['marketplace_service']);
    }

    private function getCacheKey(string $suffix): string
    {
        return self::CACHE_PREFIX . $suffix;
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_marketplaces' => self::MAX_MARKETPLACES,
            'default_commission_rate' => 0.02, // 2%
            'connection_timeout' => 30,
            'test_mode' => false,
        ];
    }
}