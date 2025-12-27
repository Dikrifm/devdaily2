<?php

namespace App\Services;

use App\Contracts\BadgeInterface;
use App\DTOs\BaseDTO;
use App\DTOs\Queries\PaginationQuery;
use App\Entities\Badge;
use App\Enums\BulkActionStatusType;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\Interfaces\BadgeRepositoryInterface;
use App\Validators\BadgeBusinessValidator;
use Closure;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use InvalidArgumentException;

/**
 * Badge Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation for badge business operations.
 * Manages badge lifecycle with transaction boundaries, caching, and business validation.
 *
 * @package App\Services
 */
final class BadgeService extends BaseService implements BadgeInterface
{
    /**
     * Badge repository for data access operations
     *
     * @var BadgeRepositoryInterface
     */
    private BadgeRepositoryInterface $badgeRepository;

    /**
     * Badge business validator
     *
     * @var BadgeBusinessValidator
     */
    private BadgeBusinessValidator $badgeValidator;

    /**
     * Service name for logging and auditing
     *
     * @var string
     */
    private const SERVICE_NAME = 'BadgeService';

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db Database connection
     * @param CacheInterface $cache Cache service
     * @param AuditService $auditService Audit service
     * @param BadgeRepositoryInterface $badgeRepository Badge repository
     * @param BadgeBusinessValidator $badgeValidator Badge business validator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        BadgeRepositoryInterface $badgeRepository,
        BadgeBusinessValidator $badgeValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        $this->badgeRepository = $badgeRepository;
        $this->badgeValidator = $badgeValidator;
        
        log_message('debug', sprintf('[%s] BadgeService initialized', self::SERVICE_NAME));
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function createBadge(BaseDTO $requestDTO): Badge
    {
        $this->authorize('badge.create');
        
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
                    'Badge creation validation failed',
                    ['errors' => $validationResult['errors']]
                );
            }
            
            // Create badge entity
            $badge = Badge::fromArray($badgeData);
            $badge->prepareForSave(false);
            
            // Persist badge
            $savedBadge = $this->badgeRepository->save($badge);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($savedBadge->getId()));
            $this->queueCacheOperation('badge:list:*');
            $this->queueCacheOperation('badge:stats:*');
            
            // Record audit log
            $this->audit(
                'CREATE',
                'Badge',
                $savedBadge->getId(),
                null,
                $savedBadge->toArray(),
                ['via_service' => self::SERVICE_NAME]
            );
            
            log_message('info', sprintf(
                '[%s] Badge created: ID=%d, Label="%s"',
                self::SERVICE_NAME,
                $savedBadge->getId(),
                $savedBadge->getLabel()
            ));
            
            return $savedBadge;
        }, 'badge_create');
    }

    /**
     * {@inheritDoc}
     */
    public function updateBadge(int $badgeId, BaseDTO $requestDTO): Badge
    {
        $this->authorize('badge.update');
        
        return $this->transaction(function () use ($badgeId, $requestDTO) {
            // Get existing badge
            $existingBadge = $this->badgeRepository->findByIdOrFail($badgeId);
            
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
                    'Badge update validation failed',
                    ['errors' => $validationResult['errors']]
                );
            }
            
            // Update badge entity
            $updatedBadge = $this->badgeRepository->update($badgeId, $updateData);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($badgeId));
            $this->queueCacheOperation('badge:list:*');
            
            // Record audit log
            $this->audit(
                'UPDATE',
                'Badge',
                $badgeId,
                $oldValues,
                $updatedBadge->toArray(),
                ['via_service' => self::SERVICE_NAME]
            );
            
            log_message('info', sprintf(
                '[%s] Badge updated: ID=%d, Label="%s"',
                self::SERVICE_NAME,
                $badgeId,
                $updatedBadge->getLabel()
            ));
            
            return $updatedBadge;
        }, 'badge_update_' . $badgeId);
    }

    /**
     * {@inheritDoc}
     */
    public function getBadge(int $badgeId, bool $withArchived = false): Badge
    {
        $this->authorize('badge.view');
        
        $cacheKey = sprintf('badge:entity:%d:%s', $badgeId, $withArchived ? 'archived' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($badgeId, $withArchived) {
            $criteria = $withArchived ? [] : ['active' => true];
            $badge = $this->badgeRepository->findById($badgeId);
            
            if ($badge === null || (!$withArchived && $badge->isDeleted())) {
                throw NotFoundException::forEntity('Badge', $badgeId);
            }
            
            return $badge;
        }, 300); // 5 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getBadgeByLabel(string $label, bool $withArchived = false): ?Badge
    {
        $cacheKey = sprintf('badge:by_label:%s:%s', md5(strtolower($label)), $withArchived ? 'archived' : 'active');
        
        return $this->withCaching($cacheKey, function () use ($label, $withArchived) {
            return $this->badgeRepository->findByLabel($label);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteBadge(int $badgeId, bool $force = false): bool
    {
        $this->authorize('badge.delete');
        
        return $this->transaction(function () use ($badgeId, $force) {
            // Check if badge exists and can be deleted
            $badge = $this->badgeRepository->findByIdOrFail($badgeId);
            
            $deletionCheck = $this->canDeleteBadge($badgeId);
            if (!$deletionCheck['can_delete']) {
                throw new DomainException(
                    sprintf('Badge cannot be deleted: %s', implode(', ', $deletionCheck['reasons'])),
                    'BADGE_DELETION_CONSTRAINT'
                );
            }
            
            $oldValues = $badge->toArray();
            
            // Perform deletion
            if ($force) {
                $result = $this->badgeRepository->forceDelete($badgeId);
                $actionType = 'FORCE_DELETE';
            } else {
                $result = $this->badgeRepository->delete($badgeId);
                $actionType = 'DELETE';
            }
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation('badge:list:*');
                $this->queueCacheOperation('badge:stats:*');
                $this->queueCacheOperation('badge:usage:*');
                
                // Record audit log
                $this->audit(
                    $actionType,
                    'Badge',
                    $badgeId,
                    $oldValues,
                    null,
                    [
                        'force' => $force,
                        'via_service' => self::SERVICE_NAME
                    ]
                );
                
                log_message('info', sprintf(
                    '[%s] Badge %s: ID=%d, Label="%s"',
                    self::SERVICE_NAME,
                    $force ? 'force deleted' : 'deleted',
                    $badgeId,
                    $badge->getLabel()
                ));
            }
            
            return $result;
        }, 'badge_delete_' . $badgeId);
    }

    // ==================== BULK OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function archiveBadge(int $badgeId): bool
    {
        $this->authorize('badge.archive');
        
        return $this->transaction(function () use ($badgeId) {
            $badge = $this->badgeRepository->findByIdOrFail($badgeId);
            
            $archiveCheck = $this->canArchiveBadge($badgeId);
            if (!$archiveCheck['can_archive']) {
                throw new DomainException(
                    sprintf('Badge cannot be archived: %s', implode(', ', $archiveCheck['reasons'])),
                    'BADGE_ARCHIVE_CONSTRAINT'
                );
            }
            
            $oldValues = $badge->toArray();
            
            // Archive the badge
            $result = $this->badgeRepository->archiveBadge($badgeId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation('badge:list:*');
                $this->queueCacheOperation('badge:stats:*');
                
                // Record audit log
                $this->audit(
                    'ARCHIVE',
                    'Badge',
                    $badgeId,
                    $oldValues,
                    ['deleted_at' => Time::now()->toDateTimeString()],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Badge archived: ID=%d, Label="%s"',
                    self::SERVICE_NAME,
                    $badgeId,
                    $badge->getLabel()
                ));
            }
            
            return $result;
        }, 'badge_archive_' . $badgeId);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreBadge(int $badgeId): bool
    {
        $this->authorize('badge.restore');
        
        return $this->transaction(function () use ($badgeId) {
            $badge = $this->badgeRepository->findByIdOrFail($badgeId);
            
            $result = $this->badgeRepository->restoreBadge($badgeId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($badgeId));
                $this->queueCacheOperation('badge:list:*');
                $this->queueCacheOperation('badge:stats:*');
                
                // Record audit log
                $this->audit(
                    'RESTORE',
                    'Badge',
                    $badgeId,
                    ['deleted_at' => $badge->getDeletedAt()?->format('Y-m-d H:i:s')],
                    ['deleted_at' => null],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Badge restored: ID=%d, Label="%s"',
                    self::SERVICE_NAME,
                    $badgeId,
                    $badge->getLabel()
                ));
            }
            
            return $result;
        }, 'badge_restore_' . $badgeId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchiveBadges(array $badgeIds): int
    {
        $this->authorize('badge.bulk_archive');
        
        if (empty($badgeIds)) {
            return 0;
        }
        
        return $this->batchOperation($badgeIds, function ($badgeId) {
            try {
                return [
                    'id' => $badgeId,
                    'success' => $this->archiveBadge($badgeId),
                    'error' => null
                ];
            } catch (\Throwable $e) {
                return [
                    'id' => $badgeId,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }, 50);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestoreBadges(array $badgeIds): int
    {
        $this->authorize('badge.bulk_restore');
        
        if (empty($badgeIds)) {
            return 0;
        }
        
        return $this->transaction(function () use ($badgeIds) {
            $result = $this->badgeRepository->bulkRestore($badgeIds);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected badges
                foreach ($badgeIds as $badgeId) {
                    $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($badgeId));
                }
                $this->queueCacheOperation('badge:list:*');
                $this->queueCacheOperation('badge:stats:*');
                
                // Record bulk audit log
                $this->audit(
                    'BULK_RESTORE',
                    'Badge',
                    0,
                    null,
                    ['restored_ids' => $badgeIds, 'count' => $result],
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] %d badges bulk restored',
                    self::SERVICE_NAME,
                    $result
                ));
            }
            
            return $result;
        }, 'badge_bulk_restore');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $badgeIds, string $status, array $context = []): int
    {
        $this->authorize('badge.bulk_update');
        
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
            $result = $this->badgeRepository->bulkUpdate($badgeIds, $updateData);
            
            if ($result > 0) {
                // Queue cache invalidation for all affected badges
                foreach ($badgeIds as $badgeId) {
                    $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($badgeId));
                }
                $this->queueCacheOperation('badge:list:*');
                
                // Record audit log
                $this->audit(
                    'BULK_STATUS_UPDATE',
                    'Badge',
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
                    '[%s] %d badges updated to status "%s"',
                    self::SERVICE_NAME,
                    $result,
                    $status
                ));
            }
            
            return $result;
        }, 'badge_bulk_status_update');
    }

    // ==================== QUERY OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function getAllBadges(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $this->authorize('badge.view');
        
        $cacheKey = sprintf(
            'badge:list:%s:%d:%d',
            md5(serialize($filters)),
            $perPage,
            $page
        );
        
        return $this->withCaching($cacheKey, function () use ($filters, $perPage, $page) {
            $paginationQuery = new PaginationQuery($perPage, $page);
            return $this->badgeRepository->paginateWithFilters($paginationQuery, $filters);
        }, 600); // 10 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveBadges(string $orderDirection = 'ASC'): array
    {
        $cacheKey = sprintf('badge:active:%s', $orderDirection);
        
        return $this->withCaching($cacheKey, function () use ($orderDirection) {
            return $this->badgeRepository->findAllActive($orderDirection);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getArchivedBadges(string $orderDirection = 'ASC'): array
    {
        $cacheKey = sprintf('badge:archived:%s', $orderDirection);
        
        return $this->withCaching($cacheKey, function () use ($orderDirection) {
            return $this->badgeRepository->findArchived($orderDirection);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function searchBadges(string $searchTerm, int $limit = 10, int $offset = 0): array
    {
        $cacheKey = sprintf('badge:search:%s:%d:%d', md5($searchTerm), $limit, $offset);
        
        return $this->withCaching($cacheKey, function () use ($searchTerm, $limit, $offset) {
            return $this->badgeRepository->searchByLabel($searchTerm, $limit, $offset);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getBadgesByColor(string $color): array
    {
        $cacheKey = sprintf('badge:by_color:%s', md5($color));
        
        return $this->withCaching($cacheKey, function () use ($color) {
            return $this->badgeRepository->findByColor($color);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getBadgesWithoutColor(): array
    {
        $cacheKey = 'badge:without_color';
        
        return $this->withCaching($cacheKey, function () {
            return $this->badgeRepository->findWithoutColor();
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getBadgesWithColor(): array
    {
        $cacheKey = 'badge:with_color';
        
        return $this->withCaching($cacheKey, function () {
            return $this->badgeRepository->findWithColor();
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getCommonBadges(): array
    {
        $cacheKey = 'badge:common';
        
        return $this->withCaching($cacheKey, function () {
            return $this->badgeRepository->findCommonBadges();
        }, 3600); // 1 hour cache - common badges rarely change
    }

    /**
     * {@inheritDoc}
     */
    public function getUnassignedBadges(): array
    {
        $cacheKey = 'badge:unassigned';
        
        return $this->withCaching($cacheKey, function () {
            return $this->badgeRepository->findUnassignedBadges();
        }, 300);
    }

    // ==================== BUSINESS VALIDATION ====================

    /**
     * {@inheritDoc}
     */
    public function canArchiveBadge(int $badgeId): array
    {
        $badge = $this->badgeRepository->findById($badgeId);
        
        if ($badge === null) {
            return [
                'can_archive' => false,
                'reasons' => ['Badge not found']
            ];
        }
        
        if ($badge->isDeleted()) {
            return [
                'can_archive' => false,
                'reasons' => ['Badge is already archived']
            ];
        }
        
        $reasons = [];
        
        // Check if badge is in use
        if ($badge->isInUse()) {
            $reasons[] = 'Badge is currently in use by products';
        }
        
        return [
            'can_archive' => empty($reasons),
            'reasons' => $reasons
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function canDeleteBadge(int $badgeId): array
    {
        $badge = $this->badgeRepository->findById($badgeId);
        
        if ($badge === null) {
            return [
                'can_delete' => false,
                'reasons' => ['Badge not found']
            ];
        }
        
        $reasons = [];
        
        // Check if badge is in use
        if ($badge->isInUse()) {
            $reasons[] = 'Badge is currently in use by products';
        }
        
        // Check if it's a system badge (common badge)
        $commonBadges = $this->badgeRepository->findCommonBadges();
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
    public function isLabelUnique(string $label, ?int $excludeId = null): bool
    {
        return $this->badgeRepository->labelExists($label, $excludeId) === false;
    }

    /**
     * {@inheritDoc}
     */
    public function validateBadgeColor(?string $color): array
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
    public function getBadgeStatistics(): array
    {
        $cacheKey = 'badge:stats:global';
        
        return $this->withCaching($cacheKey, function () {
            $stats = $this->badgeRepository->getStatistics();
            
            // Calculate usage statistics
            $usageStats = $this->badgeRepository->findMostUsed(10);
            $stats['most_used'] = array_map(function ($item) {
                return [
                    'id' => $item['badge']->getId(),
                    'label' => $item['badge']->getLabel(),
                    'usage_count' => $item['usage_count'] ?? 0
                ];
            }, $usageStats);
            
            return $stats;
        }, 600); // 10 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getBadgeUsageCounts(?int $limit = null, int $offset = 0): array
    {
        $cacheKey = sprintf('badge:usage:counts:%d:%d', $limit ?? 0, $offset);
        
        return $this->withCaching($cacheKey, function () use ($limit, $offset) {
            return $this->badgeRepository->findWithUsageCount($limit, $offset);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getColorDistribution(): array
    {
        $cacheKey = 'badge:color:distribution';
        
        return $this->withCaching($cacheKey, function () {
            $colorStats = $this->badgeRepository->countByColorStatus();
            $total = array_sum(array_column($colorStats, 'count'));
            
            return array_map(function ($item) use ($total) {
                $percentage = $total > 0 ? round(($item['count'] / $total) * 100, 2) : 0;
                return [
                    'color' => $item['color'] ?? 'No Color',
                    'count' => $item['count'],
                    'percentage' => $percentage
                ];
            }, $colorStats);
        }, 600);
    }

    // ==================== SYSTEM OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function initializeCommonBadges(): array
    {
        $this->authorize('badge.system_manage');
        
        return $this->transaction(function () {
            $result = $this->badgeRepository->initializeCommonBadges();
            
            if ($result['created'] > 0 || $result['skipped'] > 0) {
                // Clear all badge caches
                $this->clearBadgeCache();
                
                // Record audit log
                $this->audit(
                    'INITIALIZE_COMMON',
                    'Badge',
                    0,
                    null,
                    $result,
                    ['via_service' => self::SERVICE_NAME]
                );
                
                log_message('info', sprintf(
                    '[%s] Common badges initialized: %d created, %d skipped',
                    self::SERVICE_NAME,
                    $result['created'],
                    $result['skipped']
                ));
            }
            
            return $result;
        }, 'badge_initialize_common');
    }

    /**
     * {@inheritDoc}
     */
    public function createSampleBadge(array $overrides = []): Badge
    {
        $this->authorize('badge.create_sample');
        
        return $this->transaction(function () use ($overrides) {
            $sampleBadge = $this->badgeRepository->createSample($overrides);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->badgeRepository->getEntityCacheKey($sampleBadge->getId()));
            $this->queueCacheOperation('badge:list:*');
            
            // Record audit log
            $this->audit(
                'CREATE_SAMPLE',
                'Badge',
                $sampleBadge->getId(),
                null,
                $sampleBadge->toArray(),
                [
                    'via_service' => self::SERVICE_NAME,
                    'is_sample' => true
                ]
            );
            
            log_message('info', sprintf(
                '[%s] Sample badge created: ID=%d, Label="%s"',
                self::SERVICE_NAME,
                $sampleBadge->getId(),
                $sampleBadge->getLabel()
            ));
            
            return $sampleBadge;
        }, 'badge_create_sample');
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupUnusedBadges(int $daysUnused = 90, bool $archiveFirst = true): array
    {
        $this->authorize('badge.cleanup');
        
        return $this->transaction(function () use ($daysUnused, $archiveFirst) {
            $result = [
                'archived' => 0,
                'deleted' => 0,
                'errors' => []
            ];
            
            // Find unused badges
            $unusedBadges = $this->getUnassignedBadges();
            
            foreach ($unusedBadges as $badge) {
                try {
                    // Check if badge is old enough
                    $createdAt = $badge->getCreatedAt();
                    $daysSinceCreation = $createdAt ? (time() - $createdAt->getTimestamp()) / (60 * 60 * 24) : 0;
                    
                    if ($daysSinceCreation >= $daysUnused) {
                        if ($archiveFirst && !$badge->isDeleted()) {
                            if ($this->archiveBadge($badge->getId())) {
                                $result['archived']++;
                            }
                        } else {
                            if ($this->deleteBadge($badge->getId(), true)) {
                                $result['deleted']++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $result['errors'][] = sprintf(
                        'Badge ID %d: %s',
                        $badge->getId(),
                        $e->getMessage()
                    );
                }
            }
            
            if ($result['archived'] > 0 || $result['deleted'] > 0) {
                // Clear caches
                $this->clearBadgeCache();
                
                // Record audit log
                $this->audit(
                    'CLEANUP_UNUSED',
                    'Badge',
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
        }, 'badge_cleanup_unused');
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function clearBadgeCache(): bool
    {
        $this->authorize('badge.cache_manage');
        
        $success = $this->cache->deleteMatching('badge:*');
        
        if ($success) {
            log_message('debug', sprintf('[%s] Badge cache cleared', self::SERVICE_NAME));
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function preloadBadgeCache(array $badgeIds): int
    {
        return $this->badgeRepository->preloadCache($badgeIds);
    }

    /**
     * {@inheritDoc}
     */
    public function getBadgeCacheStats(): array
    {
        $cacheStats = $this->cache->getStats();
        $badgeCacheKeys = $this->cache->getKeys('badge:*');
        
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
    public function processBatchBadgeUpdates(
        array $badgeIds,
        Closure $updateOperation,
        int $batchSize = 50,
        ?callable $progressCallback = null
    ): array {
        $this->authorize('badge.batch_update');
        
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
    public function importBadges(array $importData, array $options = []): array
    {
        $this->authorize('badge.import');
        
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
                    $existingBadge = $this->getBadgeByLabel($label, true);
                    
                    if ($existingBadge && !$overwrite) {
                        $result['skipped']++;
                        continue;
                    }
                    
                    if ($existingBadge && $overwrite) {
                        // Update existing badge
                        $updateDTO = new BaseDTO($badgeData);
                        $this->updateBadge($existingBadge->getId(), $updateDTO);
                        $result['imported']++;
                    } else {
                        // Create new badge
                        $createDTO = new BaseDTO($badgeData);
                        $this->createBadge($createDTO);
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
                $this->clearBadgeCache();
                
                // Record audit log
                $this->audit(
                    'IMPORT',
                    'Badge',
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
        }, 'badge_import');
    }

    /**
     * {@inheritDoc}
     */
    public function exportBadges(array $badgeIds, array $options = []): array
    {
        $this->authorize('badge.export');
        
        $exportData = [];
        $includeArchived = $options['include_archived'] ?? false;
        $format = $options['format'] ?? 'array';
        
        $badges = $this->badgeRepository->findByIds($badgeIds);
        
        foreach ($badges as $badge) {
            if (!$includeArchived && $badge->isDeleted()) {
                continue;
            }
            
            $exportData[] = $badge->toArray();
        }
        
        // Record audit log for export
        $this->audit(
            'EXPORT',
            'Badge',
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
            '[%s] %d badges exported in %s format',
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
                    $deletionCheck = $this->canDeleteBadge($badgeId);
                    if (!$deletionCheck['can_delete']) {
                        $errors['general'] = $deletionCheck['reasons'];
                    }
                }
                break;
                
            case 'archive':
                $badgeId = $context['badge_id'] ?? null;
                if ($badgeId) {
                    $archiveCheck = $this->canArchiveBadge($badgeId);
                    if (!$archiveCheck['can_archive']) {
                        $errors['general'] = $archiveCheck['reasons'];
                    }
                }
                break;
        }
        
        // Validate color format if present
        if (isset($data['color']) && $data['color'] !== null) {
            $colorValidation = $this->validateBadgeColor($data['color']);
            if (!$colorValidation['is_valid']) {
                $errors['color'] = $colorValidation['errors'];
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