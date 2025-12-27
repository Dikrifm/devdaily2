<?php

namespace App\Services;

use App\Contracts\MarketplaceInterface;
use App\DTOs\BaseDTO;
use App\DTOs\Queries\PaginationQuery;
use App\Entities\Marketplace;
use App\Entities\Link;
use App\Entities\Product;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Validators\MarketplaceBusinessValidator;
use Closure;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use InvalidArgumentException;

/**
 * Marketplace Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation for marketplace business operations.
 * Manages marketplace lifecycle with transaction boundaries, caching, and business validation.
 *
 * @package App\Services
 */
final class MarketplaceService extends BaseService implements MarketplaceInterface
{
    /**
     * Marketplace repository for data access operations
     *
     * @var MarketplaceRepositoryInterface
     */
    private MarketplaceRepositoryInterface $marketplaceRepository;

    /**
     * Link repository for link-related operations
     *
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;

    /**
     * Product repository for product-related operations
     *
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * Marketplace business validator
     *
     * @var MarketplaceBusinessValidator
     */
    private MarketplaceBusinessValidator $marketplaceValidator;

    /**
     * Service name for logging and auditing
     *
     * @var string
     */
    private const SERVICE_NAME = 'MarketplaceService';

    /**
     * Cache TTL configuration
     */
    private const CACHE_TTL_SHORT = 300;   // 5 minutes
    private const CACHE_TTL_MEDIUM = 1800; // 30 minutes
    private const CACHE_TTL_LONG = 3600;   // 1 hour

    /**
     * Popularity thresholds
     */
    private const POPULARITY_THRESHOLD_DEFAULT = 100;
    private const POPULARITY_THRESHOLD_HIGH = 500;

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db Database connection
     * @param CacheInterface $cache Cache service
     * @param AuditService $auditService Audit service
     * @param MarketplaceRepositoryInterface $marketplaceRepository Marketplace repository
     * @param LinkRepositoryInterface $linkRepository Link repository
     * @param ProductRepositoryInterface $productRepository Product repository
     * @param MarketplaceBusinessValidator $marketplaceValidator Marketplace business validator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        MarketplaceRepositoryInterface $marketplaceRepository,
        LinkRepositoryInterface $linkRepository,
        ProductRepositoryInterface $productRepository,
        MarketplaceBusinessValidator $marketplaceValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        $this->marketplaceRepository = $marketplaceRepository;
        $this->linkRepository = $linkRepository;
        $this->productRepository = $productRepository;
        $this->marketplaceValidator = $marketplaceValidator;
        
        log_message('debug', sprintf('[%s] MarketplaceService initialized', self::SERVICE_NAME));
    }

    // ==================== CORE CRUD OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function createMarketplace(BaseDTO $requestDTO): Marketplace
    {
        $this->authorize('marketplace.create');
        
        return $this->transaction(function () use ($requestDTO) {
            // Validate DTO
            $this->validateDTOOrFail($requestDTO, ['context' => 'create']);
            
            // Extract marketplace data
            $marketplaceData = $requestDTO->toArray();
            
            // Apply business rules validation
            $validationResult = $this->marketplaceValidator->validateCreate($marketplaceData);
            if (!$validationResult['is_valid']) {
                throw ValidationException::forBusinessRule(
                    self::SERVICE_NAME,
                    'Marketplace creation validation failed',
                    ['errors' => $validationResult['errors']]
                );
            }
            
            // Generate slug if not provided
            if (empty($marketplaceData['slug']) && !empty($marketplaceData['name'])) {
                $marketplaceData['slug'] = $this->generateSlug($marketplaceData['name']);
            }
            
            // Create marketplace entity
            $marketplace = Marketplace::fromArray($marketplaceData);
            $marketplace->prepareForSave(false);
            
            // Persist marketplace
            $savedMarketplace = $this->marketplaceRepository->save($marketplace);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($savedMarketplace->getId()));
            $this->queueCacheOperation('marketplace:list:*');
            $this->queueCacheOperation('marketplace:stats:*');
            $this->queueCacheOperation('marketplace:suggestions:*');
            
            // Record audit log
            $this->audit(
                'CREATE',
                'Marketplace',
                $savedMarketplace->getId(),
                null,
                $savedMarketplace->toArray(),
                ['via_service' => self::SERVICE_NAME]
            );
            
            log_message('info', sprintf(
                '[%s] Marketplace created: ID=%d, Name="%s", Slug="%s"',
                self::SERVICE_NAME,
                $savedMarketplace->getId(),
                $savedMarketplace->getName(),
                $savedMarketplace->getSlug()
            ));
            
            return $savedMarketplace;
        }, 'marketplace_create');
    }

    /**
     * {@inheritDoc}
     */
    public function updateMarketplace(int $marketplaceId, BaseDTO $requestDTO): Marketplace
    {
        $this->authorize('marketplace.update');
        
        return $this->transaction(function () use ($marketplaceId, $requestDTO) {
            // Get existing marketplace
            $existingMarketplace = $this->marketplaceRepository->findByIdOrFail($marketplaceId);
            
            // Validate DTO
            $this->validateDTOOrFail($requestDTO, [
                'context' => 'update',
                'existing_marketplace' => $existingMarketplace
            ]);
            
            // Extract update data
            $updateData = $requestDTO->toArray();
            $oldValues = $existingMarketplace->toArray();
            
            // Apply business rules validation
            $validationResult = $this->marketplaceValidator->validateUpdate($marketplaceId, $updateData);
            if (!$validationResult['is_valid']) {
                throw ValidationException::forBusinessRule(
                    self::SERVICE_NAME,
                    'Marketplace update validation failed',
                    ['errors' => $validationResult['errors']]
                );
            }
            
            // Update marketplace entity
            $updatedMarketplace = $this->marketplaceRepository->update($marketplaceId, $updateData);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
            $this->queueCacheOperation('marketplace:list:*');
            $this->queueCacheOperation('marketplace:stats:*');
            $this->queueCacheOperation('marketplace:suggestions:*');
            
            // If slug changed, invalidate slug-based caches
            if (isset($updateData['slug']) && $updateData['slug'] !== $existingMarketplace->getSlug()) {
                $this->queueCacheOperation('marketplace:by_slug:*');
            }
            
            // Record audit log
            $this->audit(
                'UPDATE',
                'Marketplace',
                $marketplaceId,
                $oldValues,
                $updatedMarketplace->toArray(),
                ['via_service' => self::SERVICE_NAME]
            );
            
            log_message('info', sprintf(
                '[%s] Marketplace updated: ID=%d, Name="%s"',
                self::SERVICE_NAME,
                $marketplaceId,
                $updatedMarketplace->getName()
            ));
            
            return $updatedMarketplace;
        }, 'marketplace_update_' . $marketplaceId);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplace(int $marketplaceId, bool $withTrashed = false): Marketplace
    {
        $this->authorize('marketplace.view');
        
        $cacheKey = sprintf('marketplace:entity:%d:%s', $marketplaceId, $withTrashed ? 'with_trashed' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $withTrashed) {
            $marketplace = $this->marketplaceRepository->find($marketplaceId, $withTrashed);
            
            if ($marketplace === null) {
                throw NotFoundException::forEntity('Marketplace', $marketplaceId);
            }
            
            return $marketplace;
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBySlug(string $slug, bool $withTrashed = false): ?Marketplace
    {
        $cacheKey = sprintf('marketplace:by_slug:%s:%s', md5($slug), $withTrashed ? 'with_trashed' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($slug, $withTrashed) {
            return $this->marketplaceRepository->findBySlug($slug, $withTrashed);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceByName(string $name, bool $withTrashed = false): ?Marketplace
    {
        $cacheKey = sprintf('marketplace:by_name:%s:%s', md5($name), $withTrashed ? 'with_trashed' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($name, $withTrashed) {
            return $this->marketplaceRepository->findByName($name, $withTrashed);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMarketplace(int $marketplaceId, bool $force = false, ?string $reason = null): bool
    {
        $this->authorize('marketplace.delete');
        
        return $this->transaction(function () use ($marketplaceId, $force, $reason) {
            // Check if marketplace exists and can be deleted
            $marketplace = $this->marketplaceRepository->findByIdOrFail($marketplaceId);
            
            $deletionCheck = $this->canDeleteMarketplace($marketplaceId);
            if (!$deletionCheck['can_delete']) {
                throw new DomainException(
                    sprintf('Marketplace cannot be deleted: %s', implode(', ', $deletionCheck['reasons'])),
                    'MARKETPLACE_DELETION_CONSTRAINT'
                );
            }
            
            $oldValues = $marketplace->toArray();
            
            // Perform deletion
            if ($force) {
                $result = $this->marketplaceRepository->forceDelete($marketplaceId);
                $actionType = 'FORCE_DELETE';
            } else {
                $result = $this->marketplaceRepository->delete($marketplaceId);
                $actionType = 'DELETE';
            }
            
            if ($result) {
                // Deactivate all links if soft deleting
                if (!$force) {
                    $deactivatedLinks = $this->deactivateAllLinks($marketplaceId, $reason ?? 'Marketplace deleted');
                    log_message('info', sprintf(
                        '[%s] Deactivated %d links for marketplace ID=%d',
                        self::SERVICE_NAME,
                        $deactivatedLinks,
                        $marketplaceId
                    ));
                }
                
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:suggestions:*');
                $this->queueCacheOperation('marketplace:by_slug:*');
                $this->queueCacheOperation('marketplace:by_name:*');
                $this->queueCacheOperation('link:*');
                $this->queueCacheOperation('product:*');
                
                // Record audit log
                $this->audit(
                    $actionType,
                    'Marketplace',
                    $marketplaceId,
                    $oldValues,
                    null,
                    [
                        'force' => $force,
                        'reason' => $reason,
                        'via_service' => self::SERVICE_NAME
                    ]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace %s: ID=%d, Name="%s"',
                    self::SERVICE_NAME,
                    $force ? 'force deleted' : 'deleted',
                    $marketplaceId,
                    $marketplace->getName()
                ));
            }
            
            return $result;
        }, 'marketplace_delete_' . $marketplaceId);
    }

    // ==================== STATUS MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function activateMarketplace(int $marketplaceId): bool
    {
        $this->authorize('marketplace.activate');
        
        return $this->transaction(function () use ($marketplaceId) {
            $marketplace = $this->marketplaceRepository->findByIdOrFail($marketplaceId);
            
            if ($marketplace->isActive()) {
                return true; // Already active
            }
            
            $oldValues = $marketplace->toArray();
            
            // Activate marketplace
            $result = $this->marketplaceRepository->activate($marketplaceId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:active:*');
                
                // Record audit log
                $this->audit(
                    'ACTIVATE',
                    'Marketplace',
                    $marketplaceId,
                    $oldValues,
                    ['active' => true],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace activated: ID=%d, Name="%s"',
                    self::SERVICE_NAME,
                    $marketplaceId,
                    $marketplace->getName()
                ));
            }
            
            return $result;
        }, 'marketplace_activate_' . $marketplaceId);
    }

    /**
     * {@inheritDoc}
     */
    public function deactivateMarketplace(int $marketplaceId, ?string $reason = null): bool
    {
        $this->authorize('marketplace.deactivate');
        
        return $this->transaction(function () use ($marketplaceId, $reason) {
            $marketplace = $this->marketplaceRepository->findByIdOrFail($marketplaceId);
            
            if (!$marketplace->isActive()) {
                return true; // Already inactive
            }
            
            $deactivationCheck = $this->canDeactivateMarketplace($marketplaceId);
            if (!$deactivationCheck['can_deactivate']) {
                throw new DomainException(
                    sprintf('Marketplace cannot be deactivated: %s', implode(', ', $deactivationCheck['reasons'])),
                    'MARKETPLACE_DEACTIVATION_CONSTRAINT'
                );
            }
            
            $oldValues = $marketplace->toArray();
            
            // Deactivate marketplace
            $result = $this->marketplaceRepository->deactivate($marketplaceId, $reason);
            
            if ($result) {
                // Deactivate all links
                $deactivatedLinks = $this->deactivateAllLinks($marketplaceId, $reason ?? 'Marketplace deactivated');
                
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:active:*');
                $this->queueCacheOperation('link:*');
                
                // Record audit log
                $this->audit(
                    'DEACTIVATE',
                    'Marketplace',
                    $marketplaceId,
                    $oldValues,
                    [
                        'active' => false,
                        'deactivated_links' => $deactivatedLinks,
                        'reason' => $reason
                    ],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace deactivated: ID=%d, Name="%s", Deactivated links=%d',
                    self::SERVICE_NAME,
                    $marketplaceId,
                    $marketplace->getName(),
                    $deactivatedLinks
                ));
            }
            
            return $result;
        }, 'marketplace_deactivate_' . $marketplaceId);
    }

    /**
     * {@inheritDoc}
     */
    public function archiveMarketplace(int $marketplaceId, ?string $notes = null): bool
    {
        $this->authorize('marketplace.archive');
        
        return $this->transaction(function () use ($marketplaceId, $notes) {
            $marketplace = $this->marketplaceRepository->findByIdOrFail($marketplaceId);
            
            $oldValues = $marketplace->toArray();
            
            // Archive marketplace
            $result = $this->marketplaceRepository->archive($marketplaceId, $notes);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:active:*');
                $this->queueCacheOperation('marketplace:by_slug:*');
                $this->queueCacheOperation('marketplace:by_name:*');
                
                // Record audit log
                $this->audit(
                    'ARCHIVE',
                    'Marketplace',
                    $marketplaceId,
                    $oldValues,
                    [
                        'deleted_at' => Time::now()->toDateTimeString(),
                        'archive_notes' => $notes
                    ],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace archived: ID=%d, Name="%s"',
                    self::SERVICE_NAME,
                    $marketplaceId,
                    $marketplace->getName()
                ));
            }
            
            return $result;
        }, 'marketplace_archive_' . $marketplaceId);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreMarketplace(int $marketplaceId): bool
    {
        $this->authorize('marketplace.restore');
        
        return $this->transaction(function () use ($marketplaceId) {
            $marketplace = $this->marketplaceRepository->findByIdOrFail($marketplaceId);
            
            $oldValues = $marketplace->toArray();
            
            // Restore marketplace
            $result = $this->marketplaceRepository->restore($marketplaceId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:active:*');
                $this->queueCacheOperation('marketplace:by_slug:*');
                $this->queueCacheOperation('marketplace:by_name:*');
                
                // Record audit log
                $this->audit(
                    'RESTORE',
                    'Marketplace',
                    $marketplaceId,
                    $oldValues,
                    ['deleted_at' => null],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace restored: ID=%d, Name="%s"',
                    self::SERVICE_NAME,
                    $marketplaceId,
                    $marketplace->getName()
                ));
            }
            
            return $result;
        }, 'marketplace_restore_' . $marketplaceId);
    }

    /**
     * {@inheritDoc}
     */
    public function isMarketplaceActive(int $marketplaceId): bool
    {
        try {
            $marketplace = $this->getMarketplace($marketplaceId);
            return $marketplace->isActive();
        } catch (NotFoundException $e) {
            return false;
        }
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $marketplaceIds, string $status, ?string $reason = null): int
    {
        $this->authorize('marketplace.bulk_update');
        
        // Validate status
        $validStatuses = ['active', 'inactive'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid status: %s. Valid statuses: %s',
                $status,
                implode(', ', $validStatuses)
            ));
        }
        
        if (empty($marketplaceIds)) {
            return 0;
        }
        
        return $this->transaction(function () use ($marketplaceIds, $status, $reason) {
            $result = $this->marketplaceRepository->bulkUpdateStatus($marketplaceIds, $status, $reason);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected marketplaces
                foreach ($marketplaceIds as $marketplaceId) {
                    $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                }
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                
                // Deactivate links if status is inactive
                if ($status === 'inactive') {
                    foreach ($marketplaceIds as $marketplaceId) {
                        $this->deactivateAllLinks($marketplaceId, $reason ?? 'Bulk deactivation');
                    }
                }
                
                // Record audit log
                $this->audit(
                    'BULK_STATUS_UPDATE',
                    'Marketplace',
                    0,
                    null,
                    [
                        'updated_ids' => $marketplaceIds,
                        'status' => $status,
                        'count' => $result,
                        'reason' => $reason
                    ],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d marketplaces bulk updated to status "%s"',
                    self::SERVICE_NAME,
                    $result,
                    $status
                ));
            }
            
            return $result;
        }, 'marketplace_bulk_status_update');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkActivate(array $marketplaceIds, ?string $reason = null): int
    {
        $this->authorize('marketplace.bulk_activate');
        
        if (empty($marketplaceIds)) {
            return 0;
        }
        
        $successCount = 0;
        $errors = [];
        
        foreach ($marketplaceIds as $marketplaceId) {
            try {
                if ($this->activateMarketplace($marketplaceId)) {
                    $successCount++;
                }
            } catch (\Throwable $e) {
                $errors[$marketplaceId] = $e->getMessage();
            }
        }
        
        if (!empty($errors)) {
            log_message('warning', sprintf(
                '[%s] Bulk activation completed with %d successes and %d errors',
                self::SERVICE_NAME,
                $successCount,
                count($errors)
            ));
        }
        
        return $successCount;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDeactivate(array $marketplaceIds, ?string $reason = null): int
    {
        $this->authorize('marketplace.bulk_deactivate');
        
        if (empty($marketplaceIds)) {
            return 0;
        }
        
        $successCount = 0;
        $errors = [];
        
        foreach ($marketplaceIds as $marketplaceId) {
            try {
                if ($this->deactivateMarketplace($marketplaceId, $reason)) {
                    $successCount++;
                }
            } catch (\Throwable $e) {
                $errors[$marketplaceId] = $e->getMessage();
            }
        }
        
        if (!empty($errors)) {
            log_message('warning', sprintf(
                '[%s] Bulk deactivation completed with %d successes and %d errors',
                self::SERVICE_NAME,
                $successCount,
                count($errors)
            ));
        }
        
        return $successCount;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $marketplaceIds, bool $force = false): int
    {
        $this->authorize('marketplace.bulk_delete');
        
        return $this->transaction(function () use ($marketplaceIds, $force) {
            $result = $this->marketplaceRepository->bulkDelete($marketplaceIds, $force);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected marketplaces
                foreach ($marketplaceIds as $marketplaceId) {
                    $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                }
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:suggestions:*');
                $this->queueCacheOperation('marketplace:by_slug:*');
                $this->queueCacheOperation('marketplace:by_name:*');
                
                // Record bulk audit log
                $this->audit(
                    'BULK_DELETE',
                    'Marketplace',
                    0,
                    null,
                    [
                        'deleted_ids' => $marketplaceIds,
                        'force' => $force,
                        'count' => $result
                    ],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d marketplaces bulk %s',
                    self::SERVICE_NAME,
                    $result,
                    $force ? 'force deleted' : 'deleted'
                ));
            }
            
            return $result;
        }, 'marketplace_bulk_delete');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $marketplaceIds): int
    {
        $this->authorize('marketplace.bulk_restore');
        
        return $this->transaction(function () use ($marketplaceIds) {
            $result = $this->marketplaceRepository->bulkRestore($marketplaceIds);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected marketplaces
                foreach ($marketplaceIds as $marketplaceId) {
                    $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                }
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                $this->queueCacheOperation('marketplace:suggestions:*');
                
                // Record bulk audit log
                $this->audit(
                    'BULK_RESTORE',
                    'Marketplace',
                    0,
                    null,
                    ['restored_ids' => $marketplaceIds, 'count' => $result],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d marketplaces bulk restored',
                    self::SERVICE_NAME,
                    $result
                ));
            }
            
            return $result;
        }, 'marketplace_bulk_restore');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdate(array $marketplaceIds, array $updateData): int
    {
        $this->authorize('marketplace.bulk_update');
        
        // Validate update data
        $validationResult = $this->marketplaceValidator->validateBulkUpdate($updateData);
        if (!$validationResult['is_valid']) {
            throw ValidationException::forBusinessRule(
                self::SERVICE_NAME,
                'Bulk update validation failed',
                ['errors' => $validationResult['errors']]
            );
        }
        
        if (empty($marketplaceIds)) {
            return 0;
        }
        
        return $this->transaction(function () use ($marketplaceIds, $updateData) {
            $result = $this->marketplaceRepository->bulkUpdate($marketplaceIds, $updateData);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected marketplaces
                foreach ($marketplaceIds as $marketplaceId) {
                    $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                }
                $this->queueCacheOperation('marketplace:list:*');
                $this->queueCacheOperation('marketplace:stats:*');
                
                // If slug is being updated, invalidate slug-based caches
                if (isset($updateData['slug'])) {
                    $this->queueCacheOperation('marketplace:by_slug:*');
                }
                
                // Record audit log
                $this->audit(
                    'BULK_UPDATE',
                    'Marketplace',
                    0,
                    null,
                    [
                        'updated_ids' => $marketplaceIds,
                        'update_data' => $updateData,
                        'count' => $result
                    ],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d marketplaces bulk updated',
                    self::SERVICE_NAME,
                    $result
                ));
            }
            
            return $result;
        }, 'marketplace_bulk_update');
    }

    // ==================== QUERY & SEARCH OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function getAllMarketplaces(
        array $filters = [],
        int $perPage = 25,
        int $page = 1,
        bool $withTrashed = false
    ): array {
        $this->authorize('marketplace.view');
        
        $cacheKey = sprintf(
            'marketplace:list:%s:%d:%d:%s',
            md5(serialize($filters)),
            $perPage,
            $page,
            $withTrashed ? 'with_trashed' : 'active'
        );
        
        return $this->withCaching($cacheKey, function () use ($filters, $perPage, $page, $withTrashed) {
            $paginationQuery = new PaginationQuery($perPage, $page);
            return $this->marketplaceRepository->findAll($paginationQuery, $filters, $withTrashed);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveMarketplaces(array $filters = []): array
    {
        $cacheKey = sprintf('marketplace:active:%s', md5(serialize($filters)));
        
        return $this->withCaching($cacheKey, function () use ($filters) {
            return $this->marketplaceRepository->findAll($filters, false);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getInactiveMarketplaces(array $filters = []): array
    {
        $cacheKey = sprintf('marketplace:inactive:%s', md5(serialize($filters)));
        
        return $this->withCaching($cacheKey, function () use ($filters) {
            $filters['active'] = false;
            return $this->marketplaceRepository->findAll($filters, false);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getArchivedMarketplaces(array $filters = []): array
    {
        $cacheKey = sprintf('marketplace:archived:%s', md5(serialize($filters)));
        
        return $this->withCaching($cacheKey, function () use ($filters) {
            return $this->marketplaceRepository->findAll($filters, true);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function searchMarketplaces(
        string $searchTerm,
        int $limit = 20,
        int $offset = 0,
        bool $activeOnly = true
    ): array {
        $cacheKey = sprintf(
            'marketplace:search:%s:%d:%d:%s',
            md5($searchTerm),
            $limit,
            $offset,
            $activeOnly ? 'active' : 'all'
        );
        
        return $this->withCaching($cacheKey, function () use ($searchTerm, $limit, $offset, $activeOnly) {
            return $this->marketplaceRepository->search($searchTerm, $limit, $offset, $activeOnly);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplacesByIds(array $marketplaceIds, bool $withTrashed = false): array
    {
        if (empty($marketplaceIds)) {
            return [];
        }
        
        sort($marketplaceIds);
        $cacheKey = sprintf(
            'marketplace:by_ids:%s:%s',
            md5(implode(',', $marketplaceIds)),
            $withTrashed ? 'with_trashed' : 'active'
        );
        
        return $this->withCaching($cacheKey, function () use ($marketplaceIds, $withTrashed) {
            return $this->marketplaceRepository->findByIds($marketplaceIds, $withTrashed);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplacesWithActiveLinks(
        int $minLinks = 1,
        bool $activeOnly = true,
        int $limit = 50
    ): array {
        $cacheKey = sprintf(
            'marketplace:with_active_links:%d:%s:%d',
            $minLinks,
            $activeOnly ? 'active' : 'all',
            $limit
        );
        
        return $this->withCaching($cacheKey, function () use ($minLinks, $activeOnly, $limit) {
            return $this->marketplaceRepository->findWithActiveLinks($minLinks, $activeOnly, $limit);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplacesWithoutActiveLinks(bool $activeOnly = true, int $limit = 50): array
    {
        $cacheKey = sprintf(
            'marketplace:without_active_links:%s:%d',
            $activeOnly ? 'active' : 'all',
            $limit
        );
        
        return $this->withCaching($cacheKey, function () use ($activeOnly, $limit) {
            return $this->marketplaceRepository->findWithoutActiveLinks($activeOnly, $limit);
        }, self::CACHE_TTL_MEDIUM);
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultMarketplaces(bool $activeOnly = true): array
    {
        $cacheKey = sprintf('marketplace:defaults:%s', $activeOnly ? 'active' : 'all');
        
        return $this->withCaching($cacheKey, function () use ($activeOnly) {
            return $this->marketplaceRepository->getDefaults($activeOnly);
        }, self::CACHE_TTL_LONG);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceSuggestions(?string $query = null, bool $activeOnly = true, int $limit = 20): array
    {
        $cacheKey = sprintf(
            'marketplace:suggestions:%s:%s:%d',
            $query ? md5($query) : 'all',
            $activeOnly ? 'active' : 'all',
            $limit
        );
        
        return $this->withCaching($cacheKey, function () use ($query, $activeOnly, $limit) {
            return $this->marketplaceRepository->getSuggestions($query, $activeOnly, $limit);
        }, self::CACHE_TTL_SHORT);
    }

    // ==================== LINK MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function getLinksForMarketplace(
        int $marketplaceId,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = sprintf(
            'marketplace:links:%d:%s:%d:%d',
            $marketplaceId,
            $activeOnly ? 'active' : 'all',
            $limit,
            $offset
        );
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $activeOnly, $limit, $offset) {
            return $this->marketplaceRepository->getLinks($marketplaceId, $activeOnly, $limit, $offset);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function countLinksForMarketplace(int $marketplaceId, bool $activeOnly = true): int
    {
        $cacheKey = sprintf('marketplace:link_count:%d:%s', $marketplaceId, $activeOnly ? 'active' : 'all');
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $activeOnly) {
            return $this->marketplaceRepository->countLinks($marketplaceId, $activeOnly);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function countActiveLinksForMarketplace(int $marketplaceId): int
    {
        $cacheKey = sprintf('marketplace:active_link_count:%d', $marketplaceId);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            return $this->marketplaceRepository->countActiveLinks($marketplaceId);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsForMarketplace(
        int $marketplaceId,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = sprintf(
            'marketplace:products:%d:%s:%d:%d',
            $marketplaceId,
            $activeOnly ? 'active' : 'all',
            $limit,
            $offset
        );
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $activeOnly, $limit, $offset) {
            return $this->marketplaceRepository->getProducts($marketplaceId, $activeOnly, $limit, $offset);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function countProductsForMarketplace(int $marketplaceId, bool $activeOnly = true): int
    {
        $cacheKey = sprintf('marketplace:product_count:%d:%s', $marketplaceId, $activeOnly ? 'active' : 'all');
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $activeOnly) {
            return $this->marketplaceRepository->countProducts($marketplaceId, $activeOnly);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getCategoriesForMarketplace(int $marketplaceId, bool $activeOnly = true): array
    {
        $cacheKey = sprintf('marketplace:categories:%d:%s', $marketplaceId, $activeOnly ? 'active' : 'all');
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $activeOnly) {
            return $this->marketplaceRepository->getCategories($marketplaceId, $activeOnly);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getTopSellingProductsForMarketplace(
        int $marketplaceId,
        int $limit = 10,
        string $period = 'month'
    ): array {
        $cacheKey = sprintf('marketplace:top_products:%d:%d:%s', $marketplaceId, $limit, $period);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $limit, $period) {
            return $this->marketplaceRepository->getTopSellingProducts($marketplaceId, $limit, $period);
        }, self::CACHE_TTL_SHORT);
    }

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceStatistics(?int $marketplaceId = null): array
    {
        $cacheKey = sprintf('marketplace:stats:%s', $marketplaceId ?: 'global');
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            if ($marketplaceId) {
                return $this->marketplaceRepository->getStatistics($marketplaceId);
            }
            
            return $this->marketplaceRepository->getStatistics();
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getLinkStatisticsForMarketplace(int $marketplaceId, string $period = 'month'): array
    {
        $cacheKey = sprintf('marketplace:link_stats:%d:%s', $marketplaceId, $period);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $period) {
            return $this->marketplaceRepository->getLinkStatistics($marketplaceId, $period);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getGrowthStatistics(string $period = 'month'): array
    {
        $cacheKey = sprintf('marketplace:growth_stats:%s', $period);
        
        return $this->withCaching($cacheKey, function () use ($period) {
            return $this->marketplaceRepository->getGrowthStatistics($period);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getRevenueRanking(string $period = 'month', int $limit = 10): array
    {
        $cacheKey = sprintf('marketplace:revenue_ranking:%s:%d', $period, $limit);
        
        return $this->withCaching($cacheKey, function () use ($period, $limit) {
            return $this->marketplaceRepository->getRevenueRanking($period, $limit);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getClickRanking(string $period = 'month', int $limit = 10): array
    {
        $cacheKey = sprintf('marketplace:click_ranking:%s:%d', $period, $limit);
        
        return $this->withCaching($cacheKey, function () use ($period, $limit) {
            return $this->marketplaceRepository->getClickRanking($period, $limit);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getConversionRanking(string $period = 'month', int $limit = 10): array
    {
        $cacheKey = sprintf('marketplace:conversion_ranking:%s:%d', $period, $limit);
        
        return $this->withCaching($cacheKey, function () use ($period, $limit) {
            return $this->marketplaceRepository->getConversionRanking($period, $limit);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceComparison(array $marketplaceIds, string $period = 'month'): array
    {
        sort($marketplaceIds);
        $cacheKey = sprintf(
            'marketplace:performance_comparison:%s:%s',
            md5(implode(',', $marketplaceIds)),
            $period
        );
        
        return $this->withCaching($cacheKey, function () use ($marketplaceIds, $period) {
            return $this->marketplaceRepository->getPerformanceComparison($marketplaceIds, $period);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceHealthStatus(int $marketplaceId): array
    {
        $cacheKey = sprintf('marketplace:health:%d', $marketplaceId);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            return $this->marketplaceRepository->getHealthStatus($marketplaceId);
        }, self::CACHE_TTL_SHORT);
    }

    // ==================== VALIDATION & CHECKS ====================

    /**
     * {@inheritDoc}
     */
    public function canDeleteMarketplace(int $marketplaceId): array
    {
        $marketplace = $this->marketplaceRepository->find($marketplaceId);
        
        if ($marketplace === null) {
            return [
                'can_delete' => false,
                'reasons' => ['Marketplace not found'],
                'dependent_records' => []
            ];
        }
        
        $reasons = [];
        $dependentRecords = [];
        
        // Check active links
        $activeLinksCount = $this->countActiveLinksForMarketplace($marketplaceId);
        if ($activeLinksCount > 0) {
            $reasons[] = sprintf('Marketplace has %d active links', $activeLinksCount);
            $dependentRecords['active_links'] = $activeLinksCount;
        }
        
        // Check if it's a default marketplace
        $defaultMarketplaces = $this->getDefaultMarketplaces(false);
        foreach ($defaultMarketplaces as $default) {
            if ($default->getId() === $marketplaceId) {
                $reasons[] = 'Cannot delete default marketplace';
                $dependentRecords['is_default'] = true;
                break;
            }
        }
        
        return [
            'can_delete' => empty($reasons),
            'reasons' => $reasons,
            'dependent_records' => $dependentRecords
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function canDeactivateMarketplace(int $marketplaceId): array
    {
        $marketplace = $this->marketplaceRepository->find($marketplaceId);
        
        if ($marketplace === null) {
            return [
                'can_deactivate' => false,
                'reasons' => ['Marketplace not found']
            ];
        }
        
        if (!$marketplace->isActive()) {
            return [
                'can_deactivate' => true,
                'reasons' => ['Marketplace is already inactive']
            ];
        }
        
        $reasons = [];
        
        // Check for ongoing promotions or campaigns
        // This would require integration with promotion/campaign module
        // For now, we'll assume no constraints
        
        return [
            'can_deactivate' => empty($reasons),
            'reasons' => $reasons
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool
    {
        return $this->marketplaceRepository->isSlugUnique($slug, $excludeId);
    }

    /**
     * {@inheritDoc}
     */
    public function isNameUnique(string $name, ?int $excludeId = null): bool
    {
        return $this->marketplaceRepository->isNameUnique($name, $excludeId);
    }

    /**
     * {@inheritDoc}
     */
    public function validateMarketplaceData(array $data, string $context = 'create'): array
    {
        switch ($context) {
            case 'create':
                return $this->marketplaceValidator->validateCreate($data);
            case 'update':
                $marketplaceId = $data['id'] ?? null;
                if ($marketplaceId) {
                    return $this->marketplaceValidator->validateUpdate($marketplaceId, $data);
                }
                break;
        }
        
        return [
            'is_valid' => false,
            'errors' => ['context' => 'Invalid validation context']
        ];
    }

    // ==================== SYSTEM & CONFIGURATION ====================

    /**
     * {@inheritDoc}
     */
    public function createDefaultMarketplaces(): array
    {
        $this->authorize('marketplace.system_manage');
        
        return $this->transaction(function () {
            $result = $this->marketplaceRepository->createDefaultMarketplaces();
            
            if ($result['created'] > 0 || $result['skipped'] > 0) {
                // Clear all marketplace caches
                $this->clearMarketplaceCache();
                
                // Record audit log
                $this->audit(
                    'CREATE_DEFAULTS',
                    'Marketplace',
                    0,
                    null,
                    $result,
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Default marketplaces created: %d created, %d skipped',
                    self::SERVICE_NAME,
                    $result['created'],
                    $result['skipped']
                ));
            }
            
            return $result;
        }, 'marketplace_create_defaults');
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceConfiguration(int $marketplaceId): array
    {
        $cacheKey = sprintf('marketplace:config:%d', $marketplaceId);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            return $this->marketplaceRepository->getConfiguration($marketplaceId);
        }, self::CACHE_TTL_LONG);
    }

    /**
     * {@inheritDoc}
     */
    public function updateMarketplaceConfiguration(int $marketplaceId, array $config): bool
    {
        $this->authorize('marketplace.configure');
        
        return $this->transaction(function () use ($marketplaceId, $config) {
            $result = $this->marketplaceRepository->updateConfiguration($marketplaceId, $config);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceRepository->getEntityCacheKey($marketplaceId));
                $this->queueCacheOperation(sprintf('marketplace:config:%d', $marketplaceId));
                
                // Record audit log
                $this->audit(
                    'UPDATE_CONFIG',
                    'Marketplace',
                    $marketplaceId,
                    null,
                    ['config_updated' => true],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace configuration updated: ID=%d',
                    self::SERVICE_NAME,
                    $marketplaceId
                ));
            }
            
            return $result;
        }, 'marketplace_update_config_' . $marketplaceId);
    }

    /**
     * {@inheritDoc}
     */
    public function getAllowedDomains(int $marketplaceId): array
    {
        $cacheKey = sprintf('marketplace:allowed_domains:%d', $marketplaceId);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            return $this->marketplaceRepository->getAllowedDomains($marketplaceId);
        }, self::CACHE_TTL_LONG);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceIconUrl(int $marketplaceId, string $size = 'medium'): ?string
    {
        $cacheKey = sprintf('marketplace:icon_url:%d:%s', $marketplaceId, $size);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $size) {
            return $this->marketplaceRepository->getIconUrl($marketplaceId, $size);
        }, self::CACHE_TTL_LONG);
    }

    /**
     * {@inheritDoc}
     */
    public function hasAffiliateProgram(int $marketplaceId): bool
    {
        $cacheKey = sprintf('marketplace:affiliate_program:%d', $marketplaceId);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            return $this->marketplaceRepository->hasAffiliateProgram($marketplaceId);
        }, self::CACHE_TTL_LONG);
    }

    /**
     * {@inheritDoc}
     */
    public function supportsFeatures(int $marketplaceId, array $features): array
    {
        sort($features);
        $cacheKey = sprintf(
            'marketplace:features:%d:%s',
            $marketplaceId,
            md5(implode(',', $features))
        );
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $features) {
            return $this->marketplaceRepository->supportsFeatures($marketplaceId, $features);
        }, self::CACHE_TTL_LONG);
    }

    /**
     * {@inheritDoc}
     */
    public function isPopularMarketplace(int $marketplaceId, int $threshold = self::POPULARITY_THRESHOLD_DEFAULT): bool
    {
        $cacheKey = sprintf('marketplace:popular:%d:%d', $marketplaceId, $threshold);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $threshold) {
            return $this->marketplaceRepository->isPopular($marketplaceId, $threshold);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getSimilarMarketplaces(int $marketplaceId, int $limit = 5): array
    {
        $cacheKey = sprintf('marketplace:similar:%d:%d', $marketplaceId, $limit);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $limit) {
            return $this->marketplaceRepository->findSimilar($marketplaceId, $limit);
        }, self::CACHE_TTL_SHORT);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceSummary(int $marketplaceId): array
    {
        $cacheKey = sprintf('marketplace:summary:%d', $marketplaceId);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId) {
            return $this->marketplaceRepository->getSummary($marketplaceId);
        }, self::CACHE_TTL_SHORT);
    }

    // ==================== REPORT GENERATION ====================

    /**
     * {@inheritDoc}
     */
    public function generateMarketplaceReport(int $marketplaceId, string $period = 'month', string $format = 'array')
    {
        $this->authorize('marketplace.report');
        
        $cacheKey = sprintf('marketplace:report:%d:%s:%s', $marketplaceId, $period, $format);
        
        return $this->withCaching($cacheKey, function () use ($marketplaceId, $period, $format) {
            return $this->marketplaceRepository->generateReport($marketplaceId, $period, $format);
        }, self::CACHE_TTL_SHORT);
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function clearMarketplaceCache(?int $marketplaceId = null): bool
    {
        $this->authorize('marketplace.cache_manage');
        
        if ($marketplaceId !== null) {
            $success = $this->cache->deleteMatching(sprintf('marketplace:*:%d:*', $marketplaceId));
            $success = $success && $this->cache->deleteMatching(sprintf('marketplace:*%d*', $marketplaceId));
        } else {
            $success = $this->cache->deleteMatching('marketplace:*');
        }
        
        if ($success) {
            log_message('debug', sprintf(
                '[%s] Marketplace cache cleared%s',
                self::SERVICE_NAME,
                $marketplaceId ? sprintf(' for ID=%d', $marketplaceId) : ''
            ));
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceCacheStats(): array
    {
        $cacheStats = $this->cache->getStats();
        $marketplaceCacheKeys = $this->cache->getKeys('marketplace:*');
        
        $keysByType = [];
        foreach ($marketplaceCacheKeys as $key) {
            $parts = explode(':', $key);
            $type = $parts[1] ?? 'unknown';
            $keysByType[$type] = ($keysByType[$type] ?? 0) + 1;
        }
        
        return [
            'total_keys' => count($marketplaceCacheKeys),
            'memory_usage' => $cacheStats['memory_usage'] ?? 'N/A',
            'hit_rate' => $cacheStats['hit_rate'] ?? 0.0,
            'keys_by_type' => $keysByType
        ];
    }

    // ==================== BATCH PROCESSING ====================

    /**
     * {@inheritDoc}
     */
    public function processBatchMarketplaceOperations(
        array $marketplaceIds,
        Closure $operation,
        int $batchSize = 50,
        ?callable $progressCallback = null
    ): array {
        $this->authorize('marketplace.batch_operations');
        
        if (empty($marketplaceIds)) {
            return [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        $results = $this->batchOperation($marketplaceIds, function ($marketplaceId, $index) use ($operation, $progressCallback) {
            try {
                $result = $operation($marketplaceId, $index);
                
                if ($progressCallback !== null) {
                    $progressCallback($marketplaceId, $index, count($marketplaceIds));
                }
                
                return [
                    'id' => $marketplaceId,
                    'success' => true,
                    'result' => $result,
                    'error' => null
                ];
            } catch (\Throwable $e) {
                return [
                    'id' => $marketplaceId,
                    'success' => false,
                    'result' => null,
                    'error' => $e->getMessage()
                ];
            }
        }, $batchSize);
        
        // Analyze results
        $successful = array_filter($results, fn($r) => $r['success']);
        $failed = array_filter($results, fn($r) => !$r['success']);
        
        $errorMessages = [];
        foreach ($failed as $failure) {
            $errorMessages[$failure['id']] = $failure['error'];
        }
        
        return [
            'processed' => count($results),
            'successful' => count($successful),
            'failed' => count($failed),
            'errors' => $errorMessages
        ];
    }

    // ==================== IMPORT/EXPORT ====================

    /**
     * {@inheritDoc}
     */
    public function importMarketplaces(array $importData, array $options = []): array
    {
        $this->authorize('marketplace.import');
        
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        $dryRun = $options['dry_run'] ?? false;
        $overwrite = $options['overwrite'] ?? false;
        
        return $this->transaction(function () use ($importData, $dryRun, $overwrite, $result) {
            foreach ($importData as $index => $marketplaceData) {
                try {
                    $name = $marketplaceData['name'] ?? null;
                    if (!$name) {
                        throw new ValidationException("Missing required field: name");
                    }
                    
                    // Check if marketplace already exists by name or slug
                    $slug = $marketplaceData['slug'] ?? $this->generateSlug($name);
                    $existingByName = $this->getMarketplaceByName($name, true);
                    $existingBySlug = $this->getMarketplaceBySlug($slug, true);
                    $existingMarketplace = $existingByName ?? $existingBySlug;
                    
                    if ($existingMarketplace && !$overwrite) {
                        $result['skipped']++;
                        continue;
                    }
                    
                    if ($existingMarketplace && $overwrite) {
                        // Update existing marketplace
                        $updateDTO = new BaseDTO($marketplaceData);
                        $this->updateMarketplace($existingMarketplace->getId(), $updateDTO);
                        $result['imported']++;
                    } else {
                        // Create new marketplace
                        $createDTO = new BaseDTO($marketplaceData);
                        $this->createMarketplace($createDTO);
                        $result['imported']++;
                    }
                } catch (\Throwable $e) {
                    $result['errors'][$index] = sprintf(
                        'Row %d: %s',
                        $index + 1,
                        $e->getMessage()
                    );
                }
            }
            
            if (!$dryRun && ($result['imported'] > 0 || $result['skipped'] > 0)) {
                // Clear cache
                $this->clearMarketplaceCache();
                
                // Record audit log
                $this->audit(
                    'IMPORT',
                    'Marketplace',
                    0,
                    null,
                    array_merge($result, ['dry_run' => $dryRun]),
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Import completed: %d imported, %d skipped, %d errors',
                    self::SERVICE_NAME,
                    $result['imported'],
                    $result['skipped'],
                    count($result['errors'])
                ));
            }
            
            return $result;
        }, 'marketplace_import');
    }

    /**
     * {@inheritDoc}
     */
    public function exportMarketplaces(array $marketplaceIds, array $options = []): array
    {
        $this->authorize('marketplace.export');
        
        $exportData = [];
        $includeArchived = $options['include_archived'] ?? false;
        $format = $options['format'] ?? 'array';
        
        $marketplaces = $this->marketplaceRepository->findByIds($marketplaceIds, $includeArchived);
        
        foreach ($marketplaces as $marketplace) {
            if (!$includeArchived && $marketplace->isDeleted()) {
                continue;
            }
            
            $exportData[] = $marketplace->toArray();
        }
        
        // Record audit log for export
        $this->audit(
            'EXPORT',
            'Marketplace',
            0,
            null,
            [
                'exported_count' => count($exportData),
                'requested_ids' => $marketplaceIds,
                'format' => $format
            ],
            ['via_service' => self::SERVICE_NAME]
        );
        
        log_message('info', sprintf(
            '[%s] %d marketplaces exported in %s format',
            self::SERVICE_NAME,
            count($exportData),
            $format
        ));
        
        return $exportData;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Generate slug from name
     *
     * @param string $name Marketplace name
     * @return string Generated slug
     */
    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Ensure uniqueness
        $originalSlug = $slug;
        $counter = 1;
        
        while (!$this->isSlugUnique($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }
        
        return $slug;
    }

    /**
     * Deactivate all links for a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $reason Reason for deactivation
     * @return int Number of deactivated links
     */
    private function deactivateAllLinks(int $marketplaceId, string $reason): int
    {
        try {
            $links = $this->linkRepository->findActiveForProduct($marketplaceId);
            $deactivatedCount = 0;
            
            foreach ($links as $link) {
                try {
                    $this->linkRepository->bulkUpdateStatus([$link->getId()], false);
                    $deactivatedCount++;
                    
                    // Record audit log for each link deactivation
                    $this->audit(
                        'DEACTIVATE',
                        'Link',
                        $link->getId(),
                        ['active' => true],
                        ['active' => false],
                        [
                            'reason' => $reason,
                            'marketplace_id' => $marketplaceId,
                            'via_service' => self::SERVICE_NAME
                        ]
                    );
                } catch (\Throwable $e) {
                    log_message('error', sprintf(
                        '[%s] Failed to deactivate link ID=%d: %s',
                        self::SERVICE_NAME,
                        $link->getId(),
                        $e->getMessage()
                    ));
                }
            }
            
            return $deactivatedCount;
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                '[%s] Failed to deactivate links for marketplace ID=%d: %s',
                self::SERVICE_NAME,
                $marketplaceId,
                $e->getMessage()
            ));
            return 0;
        }
    }

    // ==================== BASE SERVICE ABSTRACT METHODS ====================

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        $errors = [];
        $operation = $context['operation'] ?? 'general';
        
        // Extract data from DTO
        $data = $dto->toArray();
        
        // Perform business-specific validation
        switch ($operation) {
            case 'create':
                $validationResult = $this->marketplaceValidator->validateCreate($data);
                $errors = $validationResult['errors'] ?? [];
                break;
                
            case 'update':
                $marketplaceId = $context['marketplace_id'] ?? null;
                if ($marketplaceId) {
                    $validationResult = $this->marketplaceValidator->validateUpdate($marketplaceId, $data);
                    $errors = $validationResult['errors'] ?? [];
                }
                break;
                
            case 'delete':
                $marketplaceId = $context['marketplace_id'] ?? null;
                if ($marketplaceId) {
                    $deletionCheck = $this->canDeleteMarketplace($marketplaceId);
                    if (!$deletionCheck['can_delete']) {
                        $errors['general'] = $deletionCheck['reasons'];
                    }
                }
                break;
                
            case 'deactivate':
                $marketplaceId = $context['marketplace_id'] ?? null;
                if ($marketplaceId) {
                    $deactivationCheck = $this->canDeactivateMarketplace($marketplaceId);
                    if (!$deactivationCheck['can_deactivate']) {
                        $errors['general'] = $deactivationCheck['reasons'];
                    }
                }
                break;
        }
        
        // Validate slug uniqueness if present
        if (isset($data['slug'])) {
            $excludeId = $operation === 'update' ? ($context['marketplace_id'] ?? null) : null;
            if (!$this->isSlugUnique($data['slug'], $excludeId)) {
                $errors['slug'][] = 'Slug already exists';
            }
        }
        
        // Validate name uniqueness if present
        if (isset($data['name'])) {
            $excludeId = $operation === 'update' ? ($context['marketplace_id'] ?? null) : null;
            if (!$this->isNameUnique($data['name'], $excludeId)) {
                $errors['name'][] = 'Name already exists';
            }
        }
        
        // Validate color format if present
        if (isset($data['color']) && $data['color'] !== null) {
            if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $data['color'])) {
                $errors['color'][] = 'Invalid color format. Must be hex color (e.g., #FF0000 or #F00)';
            }
        }
        
        return $errors;
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return self::SERVICE_NAME;
    }
}