<?php

namespace App\Services;

use App\Contracts\MarketplaceBadgeInterface;
use App\DTOs\BaseDTO;
use App\DTOs\Queries\PaginationQuery;
use App\Entities\MarketplaceBadge;
use App\Entities\Link;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\Interfaces\MarketplaceBadgeRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Validators\MarketplaceBadgeBusinessValidator;
use Closure;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use InvalidArgumentException;

/**
 * Marketplace Badge Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation for marketplace badge business operations.
 * Manages marketplace badge lifecycle with transaction boundaries, caching, and business validation.
 *
 * @package App\Services
 */
final class MarketplaceBadgeService extends BaseService implements MarketplaceBadgeInterface
{
    /**
     * Marketplace badge repository for data access operations
     *
     * @var MarketplaceBadgeRepositoryInterface
     */
    private MarketplaceBadgeRepositoryInterface $marketplaceBadgeRepository;

    /**
     * Link repository for badge assignment operations
     *
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;

    /**
     * Marketplace badge business validator
     *
     * @var MarketplaceBadgeBusinessValidator
     */
    private MarketplaceBadgeBusinessValidator $badgeValidator;

    /**
     * Service name for logging and auditing
     *
     * @var string
     */
    private const SERVICE_NAME = 'MarketplaceBadgeService';

    /**
     * Icon validation patterns
     */
    private const ICON_PATTERNS = [
        'fontawesome' => '/^(fas|far|fal|fad|fab) fa-[a-z0-9-]+$/i',
        'bootstrap' => '/^bi-[a-z0-9-]+$/i',
        'material' => '/^mi-[a-z0-9-]+$/i',
    ];

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db Database connection
     * @param CacheInterface $cache Cache service
     * @param AuditService $auditService Audit service
     * @param MarketplaceBadgeRepositoryInterface $marketplaceBadgeRepository Marketplace badge repository
     * @param LinkRepositoryInterface $linkRepository Link repository
     * @param MarketplaceBadgeBusinessValidator $badgeValidator Marketplace badge business validator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        MarketplaceBadgeRepositoryInterface $marketplaceBadgeRepository,
        LinkRepositoryInterface $linkRepository,
        MarketplaceBadgeBusinessValidator $badgeValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        $this->marketplaceBadgeRepository = $marketplaceBadgeRepository;
        $this->linkRepository = $linkRepository;
        $this->badgeValidator = $badgeValidator;
        
        log_message('debug', sprintf('[%s] MarketplaceBadgeService initialized', self::SERVICE_NAME));
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function createMarketplaceBadge(BaseDTO $requestDTO): MarketplaceBadge
    {
        $this->authorize('marketplace_badge.create');
        
        return $this->transaction(function () use ($requestDTO) {
            // Validate DTO
            $this->validateDTOOrFail($requestDTO, ['context' => 'create']);
            
            // Extract badge data
            $badgeData = $requestDTO->toArray();
            
            // Apply business rules validation
            $validationResult = $this->badgeValidator->validateCreate($badgeData);
            if (!$validationResult['is_valid']) {
                throw ValidationException::forBusinessRule(
                    self::SERVICE_NAME,
                    'Marketplace badge creation validation failed',
                    ['errors' => $validationResult['errors']]
                );
            }
            
            // Create badge entity
            $badge = MarketplaceBadge::fromArray($badgeData);
            $badge->prepareForSave(false);
            
            // Persist badge
            $savedBadge = $this->marketplaceBadgeRepository->save($badge);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($savedBadge->getId()));
            $this->queueCacheOperation('marketplace_badge:list:*');
            $this->queueCacheOperation('marketplace_badge:stats:*');
            
            // Record audit log
            $this->audit(
                'CREATE',
                'MarketplaceBadge',
                $savedBadge->getId(),
                null,
                $savedBadge->toArray(),
                ['via_service' => self::SERVICE_NAME]
            );
            
            log_message('info', sprintf(
                '[%s] Marketplace badge created: ID=%d, Label="%s"',
                self::SERVICE_NAME,
                $savedBadge->getId(),
                $savedBadge->getLabel()
            ));
            
            return $savedBadge;
        }, 'marketplace_badge_create');
    }

    /**
     * {@inheritDoc}
     */
    public function updateMarketplaceBadge(int $badgeId, BaseDTO $requestDTO): MarketplaceBadge
    {
        $this->authorize('marketplace_badge.update');
        
        return $this->transaction(function () use ($badgeId, $requestDTO) {
            // Get existing badge
            $existingBadge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            
            // Validate DTO
            $this->validateDTOOrFail($requestDTO, [
                'context' => 'update',
                'existing_badge' => $existingBadge
            ]);
            
            // Extract update data
            $updateData = $requestDTO->toArray();
            $oldValues = $existingBadge->toArray();
            
            // Apply business rules validation
            $validationResult = $this->badgeValidator->validateUpdate($badgeId, $updateData);
            if (!$validationResult['is_valid']) {
                throw ValidationException::forBusinessRule(
                    self::SERVICE_NAME,
                    'Marketplace badge update validation failed',
                    ['errors' => $validationResult['errors']]
                );
            }
            
            // Update badge entity
            $updatedBadge = $this->marketplaceBadgeRepository->update($badgeId, $updateData);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
            $this->queueCacheOperation('marketplace_badge:list:*');
            $this->queueCacheOperation('marketplace_badge:usage:*');
            
            // Record audit log
            $this->audit(
                'UPDATE',
                'MarketplaceBadge',
                $badgeId,
                $oldValues,
                $updatedBadge->toArray(),
                ['via_service' => self::SERVICE_NAME]
            );
            
            log_message('info', sprintf(
                '[%s] Marketplace badge updated: ID=%d, Label="%s"',
                self::SERVICE_NAME,
                $badgeId,
                $updatedBadge->getLabel()
            ));
            
            return $updatedBadge;
        }, 'marketplace_badge_update_' . $badgeId);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadge(int $badgeId, bool $withArchived = false): MarketplaceBadge
    {
        $this->authorize('marketplace_badge.view');
        
        $cacheKey = sprintf('marketplace_badge:entity:%d:%s', $badgeId, $withArchived ? 'archived' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($badgeId, $withArchived) {
            $badge = $this->marketplaceBadgeRepository->findById($badgeId);
            
            if ($badge === null || (!$withArchived && $badge->isDeleted())) {
                throw NotFoundException::forEntity('MarketplaceBadge', $badgeId);
            }
            
            return $badge;
        }, 300); // 5 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgeByLabel(string $label, bool $withArchived = false): ?MarketplaceBadge
    {
        $cacheKey = sprintf('marketplace_badge:by_label:%s:%s', md5(strtolower($label)), $withArchived ? 'archived' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($label, $withArchived) {
            $badge = $this->marketplaceBadgeRepository->findByLabel($label);
            
            if ($badge && !$withArchived && $badge->isDeleted()) {
                return null;
            }
            
            return $badge;
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesByIcon(string $icon): array
    {
        $cacheKey = sprintf('marketplace_badge:by_icon:%s', md5($icon));
        
        return $this->withCaching($cacheKey, function () use ($icon) {
            return $this->marketplaceBadgeRepository->findByIcon($icon);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteMarketplaceBadge(int $badgeId, bool $force = false): bool
    {
        $this->authorize('marketplace_badge.delete');
        
        return $this->transaction(function () use ($badgeId, $force) {
            // Check if badge exists and can be deleted
            $badge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            
            $deletionCheck = $this->canDeleteMarketplaceBadge($badgeId);
            if (!$deletionCheck['can_delete']) {
                throw new DomainException(
                    sprintf('Marketplace badge cannot be deleted: %s', implode(', ', $deletionCheck['reasons'])),
                    'MARKETPLACE_BADGE_DELETION_CONSTRAINT'
                );
            }
            
            $oldValues = $badge->toArray();
            
            // Perform deletion
            if ($force) {
                $result = $this->marketplaceBadgeRepository->forceDelete($badgeId);
                $actionType = 'FORCE_DELETE';
            } else {
                $result = $this->marketplaceBadgeRepository->delete($badgeId);
                $actionType = 'DELETE';
            }
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation('marketplace_badge:list:*');
                $this->queueCacheOperation('marketplace_badge:stats:*');
                $this->queueCacheOperation('marketplace_badge:usage:*');
                $this->queueCacheOperation('link:*'); // Invalidate link caches since badges affect link display
                
                // Record audit log
                $this->audit(
                    $actionType,
                    'MarketplaceBadge',
                    $badgeId,
                    $oldValues,
                    null,
                    [
                        'force' => $force,
                        'via_service' => self::SERVICE_NAME
                    ]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace badge %s: ID=%d, Label="%s"',
                    self::SERVICE_NAME,
                    $force ? 'force deleted' : 'deleted',
                    $badgeId,
                    $badge->getLabel()
                ));
            }
            
            return $result;
        }, 'marketplace_badge_delete_' . $badgeId);
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function archiveMarketplaceBadge(int $badgeId): bool
    {
        $this->authorize('marketplace_badge.archive');
        
        return $this->transaction(function () use ($badgeId) {
            $badge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            
            $archiveCheck = $this->canArchiveMarketplaceBadge($badgeId);
            if (!$archiveCheck['can_archive']) {
                throw new DomainException(
                    sprintf('Marketplace badge cannot be archived: %s', implode(', ', $archiveCheck['reasons'])),
                    'MARKETPLACE_BADGE_ARCHIVE_CONSTRAINT'
                );
            }
            
            $oldValues = $badge->toArray();
            
            // Archive the badge
            $result = $this->marketplaceBadgeRepository->archiveBadge($badgeId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation('marketplace_badge:list:*');
                $this->queueCacheOperation('marketplace_badge:stats:*');
                $this->queueCacheOperation('marketplace_badge:usage:*');
                $this->queueCacheOperation('link:*');
                
                // Record audit log
                $this->audit(
                    'ARCHIVE',
                    'MarketplaceBadge',
                    $badgeId,
                    $oldValues,
                    ['deleted_at' => Time::now()->toDateTimeString()],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace badge archived: ID=%d, Label="%s"',
                    self::SERVICE_NAME,
                    $badgeId,
                    $badge->getLabel()
                ));
            }
            
            return $result;
        }, 'marketplace_badge_archive_' . $badgeId);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreMarketplaceBadge(int $badgeId): bool
    {
        $this->authorize('marketplace_badge.restore');
        
        return $this->transaction(function () use ($badgeId) {
            $badge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            
            $result = $this->marketplaceBadgeRepository->restoreBadge($badgeId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation('marketplace_badge:list:*');
                $this->queueCacheOperation('marketplace_badge:stats:*');
                $this->queueCacheOperation('marketplace_badge:usage:*');
                $this->queueCacheOperation('link:*');
                
                // Record audit log
                $this->audit(
                    'RESTORE',
                    'MarketplaceBadge',
                    $badgeId,
                    ['deleted_at' => $badge->getDeletedAt()?->format('Y-m-d H:i:s')],
                    ['deleted_at' => null],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Marketplace badge restored: ID=%d, Label="%s"',
                    self::SERVICE_NAME,
                    $badgeId,
                    $badge->getLabel()
                ));
            }
            
            return $result;
        }, 'marketplace_badge_restore_' . $badgeId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchiveMarketplaceBadges(array $badgeIds): int
    {
        $this->authorize('marketplace_badge.bulk_archive');
        
        if (empty($badgeIds)) {
            return 0;
        }
        
        $successCount = 0;
        $errors = [];
        
        foreach ($badgeIds as $badgeId) {
            try {
                if ($this->archiveMarketplaceBadge($badgeId)) {
                    $successCount++;
                }
            } catch (\Throwable $e) {
                $errors[$badgeId] = $e->getMessage();
            }
        }
        
        if (!empty($errors)) {
            log_message('warning', sprintf(
                '[%s] Bulk archive completed with %d successes and %d errors',
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
    public function bulkRestoreMarketplaceBadges(array $badgeIds): int
    {
        $this->authorize('marketplace_badge.bulk_restore');
        
        if (empty($badgeIds)) {
            return 0;
        }
        
        return $this->transaction(function () use ($badgeIds) {
            $result = $this->marketplaceBadgeRepository->bulkRestore($badgeIds);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected badges
                foreach ($badgeIds as $badgeId) {
                    $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                }
                $this->queueCacheOperation('marketplace_badge:list:*');
                $this->queueCacheOperation('marketplace_badge:stats:*');
                $this->queueCacheOperation('marketplace_badge:usage:*');
                $this->queueCacheOperation('link:*');
                
                // Record bulk audit log
                $this->audit(
                    'BULK_RESTORE',
                    'MarketplaceBadge',
                    0,
                    null,
                    ['restored_ids' => $badgeIds, 'count' => $result],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d marketplace badges bulk restored',
                    self::SERVICE_NAME,
                    $result
                ));
            }
            
            return $result;
        }, 'marketplace_badge_bulk_restore');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $badgeIds, string $status, array $context = []): int
    {
        $this->authorize('marketplace_badge.bulk_update');
        
        // Validate status
        $validStatuses = ['active', 'inactive'];
        if (!in_array($status, $validStatuses, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid status: %s. Valid statuses: %s',
                $status,
                implode(', ', $validStatuses)
            ));
        }
        
        if (empty($badgeIds)) {
            return 0;
        }
        
        return $this->transaction(function () use ($badgeIds, $status, $context) {
            $updateData = ['active' => $status === 'active'];
            $result = $this->marketplaceBadgeRepository->bulkUpdate($badgeIds, $updateData);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected badges
                foreach ($badgeIds as $badgeId) {
                    $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                }
                $this->queueCacheOperation('marketplace_badge:list:*');
                $this->queueCacheOperation('marketplace_badge:stats:*');
                
                // Record audit log
                $this->audit(
                    'BULK_STATUS_UPDATE',
                    'MarketplaceBadge',
                    0,
                    null,
                    [
                        'updated_ids' => $badgeIds,
                        'status' => $status,
                        'count' => $result,
                        'context' => $context
                    ],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d marketplace badges updated to status "%s"',
                    self::SERVICE_NAME,
                    $result,
                    $status
                ));
            }
            
            return $result;
        }, 'marketplace_badge_bulk_status_update');
    }

    // ==================== QUERY OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function getAllMarketplaceBadges(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $this->authorize('marketplace_badge.view');
        
        $cacheKey = sprintf(
            'marketplace_badge:list:%s:%d:%d',
            md5(serialize($filters)),
            $perPage,
            $page
        );
        
        return $this->withCaching($cacheKey, function () use ($filters, $perPage, $page) {
            $paginationQuery = new PaginationQuery($perPage, $page);
            return $this->marketplaceBadgeRepository->paginateWithFilters($paginationQuery, $filters);
        }, 600); // 10 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveMarketplaceBadges(string $orderDirection = 'ASC'): array
    {
        $cacheKey = sprintf('marketplace_badge:active:%s', $orderDirection);
        
        return $this->withCaching($cacheKey, function () use ($orderDirection) {
            return $this->marketplaceBadgeRepository->findAllActive($orderDirection);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getArchivedMarketplaceBadges(string $orderDirection = 'ASC'): array
    {
        $cacheKey = sprintf('marketplace_badge:archived:%s', $orderDirection);
        
        return $this->withCaching($cacheKey, function () use ($orderDirection) {
            return $this->marketplaceBadgeRepository->findArchived($orderDirection);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function searchMarketplaceBadges(string $searchTerm, int $limit = 10, int $offset = 0): array
    {
        $cacheKey = sprintf('marketplace_badge:search:%s:%d:%d', md5($searchTerm), $limit, $offset);
        
        return $this->withCaching($cacheKey, function () use ($searchTerm, $limit, $offset) {
            return $this->marketplaceBadgeRepository->searchByLabel($searchTerm, $limit, $offset);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesByIconPrefix(string $iconPrefix): array
    {
        $cacheKey = sprintf('marketplace_badge:by_icon_prefix:%s', md5($iconPrefix));
        
        return $this->withCaching($cacheKey, function () use ($iconPrefix) {
            return $this->marketplaceBadgeRepository->findByIconPrefix($iconPrefix);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesWithIcons(): array
    {
        $cacheKey = 'marketplace_badge:with_icons';
        
        return $this->withCaching($cacheKey, function () {
            return $this->marketplaceBadgeRepository->findWithIcons();
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesWithoutIcons(): array
    {
        $cacheKey = 'marketplace_badge:without_icons';
        
        return $this->withCaching($cacheKey, function () {
            return $this->marketplaceBadgeRepository->findWithoutIcons();
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesByColor(string $color): array
    {
        $cacheKey = sprintf('marketplace_badge:by_color:%s', md5($color));
        
        return $this->withCaching($cacheKey, function () use ($color) {
            return $this->marketplaceBadgeRepository->findByColor($color);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getCommonMarketplaceBadges(): array
    {
        $cacheKey = 'marketplace_badge:common';
        
        return $this->withCaching($cacheKey, function () {
            return $this->marketplaceBadgeRepository->findCommonBadges();
        }, 3600); // 1 hour cache - common badges rarely change
    }

    /**
     * {@inheritDoc}
     */
    public function getUnassignedMarketplaceBadges(): array
    {
        $cacheKey = 'marketplace_badge:unassigned';
        
        return $this->withCaching($cacheKey, function () {
            return $this->marketplaceBadgeRepository->findUnassignedBadges();
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesWithFontAwesomeIcons(): array
    {
        $cacheKey = 'marketplace_badge:fontawesome';
        
        return $this->withCaching($cacheKey, function () {
            return $this->marketplaceBadgeRepository->findWithFontAwesomeIcons();
        }, 300);
    }

    // ==================== BUSINESS VALIDATION ====================

    /**
     * {@inheritDoc}
     */
    public function canArchiveMarketplaceBadge(int $badgeId): array
    {
        $badge = $this->marketplaceBadgeRepository->findById($badgeId);
        
        if ($badge === null) {
            return [
                'can_archive' => false,
                'reasons' => ['Marketplace badge not found']
            ];
        }
        
        if ($badge->isDeleted()) {
            return [
                'can_archive' => false,
                'reasons' => ['Marketplace badge is already archived']
            ];
        }
        
        $reasons = [];
        
        // Check if badge is in use
        if ($badge->isAssigned()) {
            $reasons[] = 'Marketplace badge is currently assigned to links';
        }
        
        return [
            'can_archive' => empty($reasons),
            'reasons' => $reasons
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function canDeleteMarketplaceBadge(int $badgeId): array
    {
        $badge = $this->marketplaceBadgeRepository->findById($badgeId);
        
        if ($badge === null) {
            return [
                'can_delete' => false,
                'reasons' => ['Marketplace badge not found']
            ];
        }
        
        $reasons = [];
        
        // Check if badge is in use
        if ($badge->isAssigned()) {
            $reasons[] = 'Marketplace badge is currently assigned to links';
        }
        
        // Check if it's a system badge (common badge)
        $commonBadges = $this->marketplaceBadgeRepository->findCommonBadges();
        foreach ($commonBadges as $commonBadge) {
            if ($commonBadge->getId() === $badgeId) {
                $reasons[] = 'Cannot delete system badge';
                break;
            }
        }
        
        return [
            'can_delete' => empty($reasons),
            'reasons' => $reasons
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function isMarketplaceBadgeLabelUnique(string $label, ?int $excludeId = null): bool
    {
        return $this->marketplaceBadgeRepository->labelExists($label, $excludeId) === false;
    }

    /**
     * {@inheritDoc}
     */
    public function validateMarketplaceBadgeIcon(?string $icon): array
    {
        $errors = [];
        
        if ($icon === null || $icon === '') {
            return [
                'is_valid' => true,
                'errors' => $errors
            ];
        }
        
        // Check icon length
        if (strlen($icon) > 50) {
            $errors[] = 'Icon name must not exceed 50 characters';
        }
        
        // Validate icon format against known patterns
        $validFormat = false;
        foreach (self::ICON_PATTERNS as $pattern) {
            if (preg_match($pattern, $icon)) {
                $validFormat = true;
                break;
            }
        }
        
        if (!$validFormat) {
            $errors[] = sprintf(
                'Invalid icon format. Supported formats: %s',
                implode(', ', array_keys(self::ICON_PATTERNS))
            );
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function validateMarketplaceBadgeColor(?string $color): array
    {
        $errors = [];
        
        if ($color === null || $color === '') {
            return [
                'is_valid' => true,
                'errors' => $errors
            ];
        }
        
        // Validate hex color format
        if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
            $errors[] = 'Invalid color format. Must be hex color (e.g., #FF0000 or #F00)';
        }
        
        // Validate color length
        if (strlen($color) !== 7 && strlen($color) !== 4) {
            $errors[] = 'Color must be 4 or 7 characters including #';
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors
        ];
    }

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgeUsageStatistics(int $limit = 20): array
    {
        $cacheKey = sprintf('marketplace_badge:usage_stats:%d', $limit);
        
        return $this->withCaching($cacheKey, function () use ($limit) {
            return $this->marketplaceBadgeRepository->findUsageStatistics($limit);
        }, 600); // 10 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgeStatistics(): array
    {
        $cacheKey = 'marketplace_badge:stats:global';
        
        return $this->withCaching($cacheKey, function () {
            return $this->marketplaceBadgeRepository->getStatistics();
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgeIconDistribution(): array
    {
        $cacheKey = 'marketplace_badge:icon_distribution';
        
        return $this->withCaching($cacheKey, function () {
            $badges = $this->marketplaceBadgeRepository->findAll();
            $distribution = [];
            $total = count($badges);
            
            foreach ($badges as $badge) {
                $icon = $badge->getIcon();
                if ($icon) {
                    // Extract icon prefix (e.g., 'fas fa-')
                    $prefix = preg_replace('/ fa-[a-z0-9-]+$/i', ' fa-', $icon);
                    $prefix = preg_replace('/^bi-[a-z0-9-]+$/i', 'bi-', $prefix);
                    $prefix = $prefix ?: 'unknown';
                    
                    $distribution[$prefix] = ($distribution[$prefix] ?? 0) + 1;
                }
            }
            
            // Convert to array with percentages
            $result = [];
            foreach ($distribution as $prefix => $count) {
                $percentage = $total > 0 ? round(($count / $total) * 100, 2) : 0;
                $result[] = [
                    'icon_prefix' => $prefix,
                    'count' => $count,
                    'percentage' => $percentage
                ];
            }
            
            // Sort by count descending
            usort($result, fn($a, $b) => $b['count'] <=> $a['count']);
            
            return $result;
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgeColorDistribution(): array
    {
        $cacheKey = 'marketplace_badge:color_distribution';
        
        return $this->withCaching($cacheKey, function () {
            $badges = $this->marketplaceBadgeRepository->findAll();
            $distribution = [];
            $total = count($badges);
            
            foreach ($badges as $badge) {
                $color = $badge->getColor() ?? 'No Color';
                $distribution[$color] = ($distribution[$color] ?? 0) + 1;
            }
            
            // Convert to array with percentages
            $result = [];
            foreach ($distribution as $color => $count) {
                $percentage = $total > 0 ? round(($count / $total) * 100, 2) : 0;
                $result[] = [
                    'color' => $color,
                    'count' => $count,
                    'percentage' => $percentage
                ];
            }
            
            // Sort by count descending
            usort($result, fn($a, $b) => $b['count'] <=> $a['count']);
            
            return $result;
        }, 600);
    }

    // ==================== SYSTEM OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function initializeCommonMarketplaceBadges(): array
    {
        $this->authorize('marketplace_badge.system_manage');
        
        return $this->transaction(function () {
            $result = $this->marketplaceBadgeRepository->initializeCommonBadges();
            
            if ($result['created'] > 0 || $result['skipped'] > 0) {
                // Clear all marketplace badge caches
                $this->clearMarketplaceBadgeCache();
                
                // Record audit log
                $this->audit(
                    'INITIALIZE_COMMON',
                    'MarketplaceBadge',
                    0,
                    null,
                    $result,
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Common marketplace badges initialized: %d created, %d skipped',
                    self::SERVICE_NAME,
                    $result['created'],
                    $result['skipped']
                ));
            }
            
            return $result;
        }, 'marketplace_badge_initialize_common');
    }

    /**
     * {@inheritDoc}
     */
    public function createSampleMarketplaceBadge(array $overrides = []): MarketplaceBadge
    {
        $this->authorize('marketplace_badge.create_sample');
        
        return $this->transaction(function () use ($overrides) {
            $sampleBadge = $this->marketplaceBadgeRepository->createSample($overrides);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($sampleBadge->getId()));
            $this->queueCacheOperation('marketplace_badge:list:*');
            
            // Record audit log
            $this->audit(
                'CREATE_SAMPLE',
                'MarketplaceBadge',
                $sampleBadge->getId(),
                null,
                $sampleBadge->toArray(),
                [
                    'via_service' => self::SERVICE_NAME,
                    'is_sample' => true
                ]
            );
            
            log_message('info', sprintf(
                '[%s] Sample marketplace badge created: ID=%d, Label="%s"',
                self::SERVICE_NAME,
                $sampleBadge->getId(),
                $sampleBadge->getLabel()
            ));
            
            return $sampleBadge;
        }, 'marketplace_badge_create_sample');
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupUnusedMarketplaceBadges(int $daysUnused = 90, bool $archiveFirst = true): array
    {
        $this->authorize('marketplace_badge.cleanup');
        
        return $this->transaction(function () use ($daysUnused, $archiveFirst) {
            $result = [
                'archived' => 0,
                'deleted' => 0,
                'errors' => []
            ];
            
            // Find unused badges
            $unusedBadges = $this->getUnassignedMarketplaceBadges();
            
            foreach ($unusedBadges as $badge) {
                try {
                    // Check if badge is old enough
                    $createdAt = $badge->getCreatedAt();
                    $daysSinceCreation = $createdAt ? (time() - $createdAt->getTimestamp()) / (60 * 60 * 24) : 0;
                    
                    if ($daysSinceCreation >= $daysUnused) {
                        if ($archiveFirst && !$badge->isDeleted()) {
                            if ($this->archiveMarketplaceBadge($badge->getId())) {
                                $result['archived']++;
                            }
                        } else {
                            if ($this->deleteMarketplaceBadge($badge->getId(), true)) {
                                $result['deleted']++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $result['errors'][] = sprintf(
                        'Marketplace badge ID %d: %s',
                        $badge->getId(),
                        $e->getMessage()
                    );
                }
            }
            
            if ($result['archived'] > 0 || $result['deleted'] > 0) {
                // Clear caches
                $this->clearMarketplaceBadgeCache();
                
                // Record audit log
                $this->audit(
                    'CLEANUP_UNUSED',
                    'MarketplaceBadge',
                    0,
                    null,
                    array_merge($result, ['days_unused' => $daysUnused]),
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Cleanup completed: %d archived, %d deleted, %d errors',
                    self::SERVICE_NAME,
                    $result['archived'],
                    $result['deleted'],
                    count($result['errors'])
                ));
            }
            
            return $result;
        }, 'marketplace_badge_cleanup_unused');
    }

    // ==================== ASSIGNMENT OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function assignBadgeToLink(int $badgeId, int $linkId): bool
    {
        $this->authorize('marketplace_badge.assign');
        
        return $this->transaction(function () use ($badgeId, $linkId) {
            // Verify badge exists and is active
            $badge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            if ($badge->isDeleted()) {
                throw new DomainException(
                    'Cannot assign archived badge to link',
                    'BADGE_ARCHIVED'
                );
            }
            
            // Verify link exists
            $link = $this->linkRepository->findOrFail($linkId);
            
            // Update link with badge ID
            $updateResult = $this->linkRepository->update($linkId, [
                'marketplace_badge_id' => $badgeId
            ]);
            
            if ($updateResult) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation($this->linkRepository->getEntityCacheKey($linkId));
                $this->queueCacheOperation('marketplace_badge:usage:*');
                $this->queueCacheOperation('link:*');
                
                // Record audit log
                $this->audit(
                    'ASSIGN_BADGE',
                    'Link',
                    $linkId,
                    ['marketplace_badge_id' => $link->getMarketplaceBadgeId()],
                    ['marketplace_badge_id' => $badgeId],
                    [
                        'badge_id' => $badgeId,
                        'badge_label' => $badge->getLabel(),
                        'via_service' => self::SERVICE_NAME
                    ]
                );
                
                log_message('info', sprintf(
                    '[%s] Badge assigned: Badge ID=%d to Link ID=%d',
                    self::SERVICE_NAME,
                    $badgeId,
                    $linkId
                ));
            }
            
            return $updateResult;
        }, 'assign_badge_to_link_' . $badgeId . '_' . $linkId);
    }

    /**
     * {@inheritDoc}
     */
    public function removeBadgeFromLink(int $badgeId, int $linkId): bool
    {
        $this->authorize('marketplace_badge.unassign');
        
        return $this->transaction(function () use ($badgeId, $linkId) {
            // Verify badge exists
            $badge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            
            // Verify link exists and has this badge assigned
            $link = $this->linkRepository->findOrFail($linkId);
            
            if ($link->getMarketplaceBadgeId() !== $badgeId) {
                throw new DomainException(
                    'Link does not have this badge assigned',
                    'BADGE_NOT_ASSIGNED'
                );
            }
            
            // Remove badge assignment
            $updateResult = $this->linkRepository->update($linkId, [
                'marketplace_badge_id' => null
            ]);
            
            if ($updateResult) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->marketplaceBadgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation($this->linkRepository->getEntityCacheKey($linkId));
                $this->queueCacheOperation('marketplace_badge:usage:*');
                $this->queueCacheOperation('link:*');
                
                // Record audit log
                $this->audit(
                    'REMOVE_BADGE',
                    'Link',
                    $linkId,
                    ['marketplace_badge_id' => $badgeId],
                    ['marketplace_badge_id' => null],
                    [
                        'badge_id' => $badgeId,
                        'badge_label' => $badge->getLabel(),
                        'via_service' => self::SERVICE_NAME
                    ]
                );
                
                log_message('info', sprintf(
                    '[%s] Badge removed: Badge ID=%d from Link ID=%d',
                    self::SERVICE_NAME,
                    $badgeId,
                    $linkId
                ));
            }
            
            return $updateResult;
        }, 'remove_badge_from_link_' . $badgeId . '_' . $linkId);
    }

    /**
     * {@inheritDoc}
     */
    public function getLinksForMarketplaceBadge(int $badgeId, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = sprintf('marketplace_badge:links:%d:%d:%d', $badgeId, $limit, $offset);
        
        return $this->withCaching($cacheKey, function () use ($badgeId, $limit, $offset) {
            // This would require a custom repository method or query
            // For now, we'll implement a simplified version
            $badge = $this->marketplaceBadgeRepository->findByIdOrFail($badgeId);
            
            // Get links with this badge ID
            $links = $this->linkRepository->findBy(['marketplace_badge_id' => $badgeId], $limit, $offset);
            
            return array_map(function (Link $link) {
                return [
                    'link_id' => $link->getId(),
                    'product_id' => $link->getProductId(),
                    'store_name' => $link->getStoreName(),
                    'marketplace_id' => $link->getMarketplaceId(),
                    'url' => $link->getUrl(),
                    'active' => $link->isActive()
                ];
            }, $links);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgesForLink(int $linkId): array
    {
        $cacheKey = sprintf('link:marketplace_badges:%d', $linkId);
        
        return $this->withCaching($cacheKey, function () use ($linkId) {
            $link = $this->linkRepository->findOrFail($linkId);
            $badgeId = $link->getMarketplaceBadgeId();
            
            if ($badgeId === null) {
                return [];
            }
            
            $badge = $this->marketplaceBadgeRepository->findById($badgeId);
            return $badge ? [$badge] : [];
        }, 300);
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function clearMarketplaceBadgeCache(): bool
    {
        $this->authorize('marketplace_badge.cache_manage');
        
        $success = $this->cache->deleteMatching('marketplace_badge:*');
        
        if ($success) {
            log_message('debug', sprintf('[%s] Marketplace badge cache cleared', self::SERVICE_NAME));
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function preloadMarketplaceBadgeCache(array $badgeIds): int
    {
        return $this->marketplaceBadgeRepository->preloadCache($badgeIds);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceBadgeCacheStats(): array
    {
        $cacheStats = $this->cache->getStats();
        $badgeCacheKeys = $this->cache->getKeys('marketplace_badge:*');
        
        $keysByType = [];
        foreach ($badgeCacheKeys as $key) {
            $parts = explode(':', $key);
            $type = $parts[1] ?? 'unknown';
            $keysByType[$type] = ($keysByType[$type] ?? 0) + 1;
        }
        
        return [
            'total_keys' => count($badgeCacheKeys),
            'memory_usage' => $cacheStats['memory_usage'] ?? 'N/A',
            'hit_rate' => $cacheStats['hit_rate'] ?? 0.0,
            'keys_by_type' => $keysByType
        ];
    }

    // ==================== BATCH PROCESSING ====================

    /**
     * {@inheritDoc}
     */
    public function processBatchMarketplaceBadgeUpdates(
        array $badgeIds,
        Closure $updateOperation,
        int $batchSize = 50,
        ?callable $progressCallback = null
    ): array {
        $this->authorize('marketplace_badge.batch_update');
        
        if (empty($badgeIds)) {
            return [
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'errors' => []
            ];
        }
        
        $results = $this->batchOperation($badgeIds, function ($badgeId, $index) use ($updateOperation, $progressCallback) {
            try {
                $result = $updateOperation($badgeId, $index);
                
                if ($progressCallback !== null) {
                    $progressCallback($badgeId, $index, count($badgeIds));
                }
                
                return [
                    'id' => $badgeId,
                    'success' => true,
                    'result' => $result,
                    'error' => null
                ];
            } catch (\Throwable $e) {
                return [
                    'id' => $badgeId,
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
    public function importMarketplaceBadges(array $importData, array $options = []): array
    {
        $this->authorize('marketplace_badge.import');
        
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        $dryRun = $options['dry_run'] ?? false;
        $overwrite = $options['overwrite'] ?? false;
        
        return $this->transaction(function () use ($importData, $dryRun, $overwrite, $result) {
            foreach ($importData as $index => $badgeData) {
                try {
                    $label = $badgeData['label'] ?? null;
                    if (!$label) {
                        throw new ValidationException("Missing required field: label");
                    }
                    
                    // Check if badge already exists
                    $existingBadge = $this->getMarketplaceBadgeByLabel($label, true);
                    
                    if ($existingBadge && !$overwrite) {
                        $result['skipped']++;
                        continue;
                    }
                    
                    if ($existingBadge && $overwrite) {
                        // Update existing badge
                        $updateDTO = new BaseDTO($badgeData);
                        $this->updateMarketplaceBadge($existingBadge->getId(), $updateDTO);
                        $result['imported']++;
                    } else {
                        // Create new badge
                        $createDTO = new BaseDTO($badgeData);
                        $this->createMarketplaceBadge($createDTO);
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
                $this->clearMarketplaceBadgeCache();
                
                // Record audit log
                $this->audit(
                    'IMPORT',
                    'MarketplaceBadge',
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
        }, 'marketplace_badge_import');
    }

    /**
     * {@inheritDoc}
     */
    public function exportMarketplaceBadges(array $badgeIds, array $options = []): array
    {
        $this->authorize('marketplace_badge.export');
        
        $exportData = [];
        $includeArchived = $options['include_archived'] ?? false;
        $format = $options['format'] ?? 'array';
        
        $badges = $this->marketplaceBadgeRepository->findByIds($badgeIds);
        
        foreach ($badges as $badge) {
            if (!$includeArchived && $badge->isDeleted()) {
                continue;
            }
            
            $exportData[] = $badge->toArray();
        }
        
        // Record audit log for export
        $this->audit(
            'EXPORT',
            'MarketplaceBadge',
            0,
            null,
            [
                'exported_count' => count($exportData),
                'requested_ids' => $badgeIds,
                'format' => $format
            ],
            ['via_service' => self::SERVICE_NAME]
        );
        
        log_message('info', sprintf(
            '[%s] %d marketplace badges exported in %s format',
            self::SERVICE_NAME,
            count($exportData),
            $format
        ));
        
        return $exportData;
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
                $validationResult = $this->badgeValidator->validateCreate($data);
                $errors = $validationResult['errors'] ?? [];
                break;
                
            case 'update':
                $badgeId = $context['badge_id'] ?? null;
                if ($badgeId) {
                    $validationResult = $this->badgeValidator->validateUpdate($badgeId, $data);
                    $errors = $validationResult['errors'] ?? [];
                }
                break;
                
            case 'delete':
                $badgeId = $context['badge_id'] ?? null;
                if ($badgeId) {
                    $deletionCheck = $this->canDeleteMarketplaceBadge($badgeId);
                    if (!$deletionCheck['can_delete']) {
                        $errors['general'] = $deletionCheck['reasons'];
                    }
                }
                break;
                
            case 'archive':
                $badgeId = $context['badge_id'] ?? null;
                if ($badgeId) {
                    $archiveCheck = $this->canArchiveMarketplaceBadge($badgeId);
                    if (!$archiveCheck['can_archive']) {
                        $errors['general'] = $archiveCheck['reasons'];
                    }
                }
                break;
                
            case 'assign':
                $badgeId = $context['badge_id'] ?? null;
                $linkId = $context['link_id'] ?? null;
                if ($badgeId && $linkId) {
                    // Check if badge is archived
                    $badge = $this->marketplaceBadgeRepository->findById($badgeId);
                    if ($badge && $badge->isDeleted()) {
                        $errors['badge'] = ['Cannot assign archived badge'];
                    }
                }
                break;
        }
        
        // Validate icon format if present
        if (isset($data['icon']) && $data['icon'] !== null) {
            $iconValidation = $this->validateMarketplaceBadgeIcon($data['icon']);
            if (!$iconValidation['is_valid']) {
                $errors['icon'] = $iconValidation['errors'];
            }
        }
        
        // Validate color format if present
        if (isset($data['color']) && $data['color'] !== null) {
            $colorValidation = $this->validateMarketplaceBadgeColor($data['color']);
            if (!$colorValidation['is_valid']) {
                $errors['color'] = $colorValidation['errors'];
            }
        }
        
        // Validate label uniqueness
        if (isset($data['label'])) {
            $excludeId = $operation === 'update' ? ($context['badge_id'] ?? null) : null;
            if (!$this->isMarketplaceBadgeLabelUnique($data['label'], $excludeId)) {
                $errors['label'][] = 'Label already exists';
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