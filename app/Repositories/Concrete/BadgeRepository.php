<?php

namespace App\Repositories\Concrete;

use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\BadgeRepositoryInterface;
use App\Entities\Badge;
use App\Models\BadgeModel;
use App\DTOs\Queries\PaginationQuery;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ConnectionInterface;
use App\Contracts\CacheInterface;
use InvalidArgumentException;

/**
 * Badge Repository Concrete Implementation
 *
 * Data Orchestrator for Badge entities with full cache management
 * and business logic enforcement.
 *
 * @extends BaseRepository<Badge>
 * @implements BadgeRepositoryInterface
 * @package App\Repositories\Concrete
 */
class BadgeRepository extends BaseRepository implements BadgeRepositoryInterface
{
    /**
     * Badge Model instance
     *
     * @var BadgeModel
     */
    protected BadgeModel $badgeModel;

    /**
     * Constructor with dependency injection
     *
     * @param BadgeModel $model
     * @param CacheInterface $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        BadgeModel $model,
        CacheInterface $cache,
        ConnectionInterface $db
    ) {
        parent::__construct($model, $cache, $db, Badge::class, 'badges');
        $this->badgeModel = $model;
    }

    /**
     * {@inheritDoc}
     */
    public function findByLabel(string $label): ?Badge
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByLabel', 'label' => $label]);
        
        return $this->remember($cacheKey, function () use ($label) {
            return $this->badgeModel->findByLabel($label);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findByColor(string $color): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByColor', 'color' => $color]);
        
        return $this->remember($cacheKey, function () use ($color) {
            return $this->badgeModel->findByColor($color);
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithoutColor(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findWithoutColor']);
        
        return $this->remember($cacheKey, function () {
            return $this->badgeModel->findWithoutColor();
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithColor(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findWithColor']);
        
        return $this->remember($cacheKey, function () {
            return $this->badgeModel->findWithColor();
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function searchByLabel(string $searchTerm, int $limit = 10, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'searchByLabel',
            'searchTerm' => $searchTerm,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->remember($cacheKey, function () use ($searchTerm, $limit, $offset) {
            return $this->badgeModel->searchByLabel($searchTerm, $limit, $offset);
        }, 900); // 15 minutes TTL for search results
    }

    /**
     * {@inheritDoc}
     */
    public function findAllActive(string $orderDirection = 'ASC'): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findAllActive', 'order' => $orderDirection]);
        
        return $this->remember($cacheKey, function () use ($orderDirection) {
            return $this->badgeModel->findAllActive($orderDirection);
        }, 3600); // 1 hour TTL for active badges list
    }

    /**
     * {@inheritDoc}
     */
    public function labelExists(string $label, int|string|null $excludeId = null): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'labelExists',
            'label' => $label,
            'excludeId' => $excludeId
        ]);
        
        return $this->remember($cacheKey, function () use ($label, $excludeId) {
            return $this->badgeModel->labelExists($label, $excludeId);
        }, 300); // 5 minutes TTL for existence checks
    }

    /**
     * {@inheritDoc}
     */
    public function countByColorStatus(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'countByColorStatus']);
        
        return $this->remember($cacheKey, function () {
            return $this->badgeModel->countByColorStatus();
        }, 3600); // 1 hour TTL for statistical counts
    }

    /**
     * {@inheritDoc}
     */
    public function findMostUsed(int $limit = 10): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findMostUsed', 'limit' => $limit]);
        
        return $this->remember($cacheKey, function () use ($limit) {
            return $this->badgeModel->findMostUsed($limit);
        }, 1800); // 30 minutes TTL for usage statistics
    }

    /**
     * {@inheritDoc}
     */
    public function findArchived(string $orderDirection = 'ASC'): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findArchived', 'order' => $orderDirection]);
        
        return $this->remember($cacheKey, function () use ($orderDirection) {
            return $this->badgeModel->findArchived($orderDirection);
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getStatistics']);
        
        return $this->remember($cacheKey, function () {
            $stats = $this->badgeModel->getStatistics();
            
            // Add cache info to statistics
            $cacheStats = $this->getCacheStats();
            $stats['cache_hits'] = $cacheStats['hits'] ?? 0;
            $stats['cache_misses'] = $cacheStats['misses'] ?? 0;
            $stats['cache_hit_rate'] = $stats['cache_hits'] > 0 
                ? round($stats['cache_hits'] / ($stats['cache_hits'] + $stats['cache_misses']) * 100, 2)
                : 0;
            
            return $stats;
        }, 300); // 5 minutes TTL for statistics
    }

    /**
     * {@inheritDoc}
     */
    public function findCommonBadges(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findCommonBadges']);
        
        return $this->remember($cacheKey, function () {
            return $this->badgeModel->findCommonBadges();
        }, 86400); // 24 hours TTL for common badges (rarely changes)
    }

    /**
     * {@inheritDoc}
     */
    public function initializeCommonBadges(): array
    {
        // Don't cache initialization operations
        $result = $this->badgeModel->initializeCommonBadges();
        
        // Clear cache after initialization
        $this->clearCache();
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findUnassignedBadges(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findUnassignedBadges']);
        
        return $this->remember($cacheKey, function () {
            $builder = $this->badgeModel->builder();
            $builder->select('badges.*');
            $builder->join('product_badges', 'product_badges.badge_id = badges.id', 'left');
            $builder->where('product_badges.badge_id IS NULL');
            $builder->where('badges.deleted_at', null);
            $builder->groupBy('badges.id');
            $builder->orderBy('badges.created_at', 'DESC');
            
            $result = $builder->get()->getResult(Badge::class);
            return $result ?: [];
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function canBeArchived(int|string $id): bool
    {
        $cacheKey = $this->getEntityCacheKey($id) . ':canBeArchived';
        
        return $this->remember($cacheKey, function () use ($id) {
            $badge = $this->findById($id);
            if (!$badge) {
                return false;
            }
            
            // Check if badge has method isInUse (from Entity)
            if (method_exists($badge, 'isInUse')) {
                return !$badge->isInUse();
            }
            
            // Fallback: check product_badges table
            $builder = $this->db->table('product_badges');
            $builder->select('1');
            $builder->where('badge_id', $id);
            $builder->limit(1);
            
            $result = $builder->get()->getRow();
            return $result === null;
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function archiveBadge(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            // Check if can be archived
            if (!$this->canBeArchived($id)) {
                throw new DomainException(
                    'Cannot archive badge: it is currently assigned to products'
                );
            }
            
            // Perform soft delete
            $success = $this->badgeModel->delete($id);
            if (!$success) {
                return false;
            }
            
            // Invalidate caches
            $this->queueCacheInvalidation($this->getEntityCacheKey($id));
            $this->queueCacheInvalidation($this->cachePrefix . '*');
            
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function restoreBadge(int|string $id): bool
    {
        return $this->restore($id);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return $this->transaction(function () use ($ids) {
            $successCount = 0;
            
            foreach ($ids as $id) {
                try {
                    if ($this->archiveBadge($id)) {
                        $successCount++;
                    }
                } catch (DomainException $e) {
                    // Log warning but continue with other badges
                    log_message('warning', sprintf(
                        'Skipping badge %s: %s',
                        $id,
                        $e->getMessage()
                    ));
                } catch (\Exception $e) {
                    // Log error but continue
                    log_message('error', sprintf(
                        'Failed to archive badge %s: %s',
                        $id,
                        $e->getMessage()
                    ));
                }
            }
            
            return $successCount;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $ids): int
    {
        // Use parent's bulkRestore which already has transaction handling
        return parent::bulkRestore($ids);
    }

    /**
     * {@inheritDoc}
     */
    public function findByLabels(array $labels): array
    {
        if (empty($labels)) {
            return [];
        }
        
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByLabels', 'labels' => $labels]);
        
        return $this->remember($cacheKey, function () use ($labels) {
            $builder = $this->badgeModel->builder();
            $builder->whereIn('label', $labels);
            $builder->where('deleted_at', null);
            $builder->orderBy('label', 'ASC');
            
            $result = $builder->get()->getResult(Badge::class);
            return $result ?: [];
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithUsageCount(?int $limit = null, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findWithUsageCount',
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->remember($cacheKey, function () use ($limit, $offset) {
            $builder = $this->badgeModel->builder();
            $builder->select('badges.*, COUNT(product_badges.badge_id) as usage_count');
            $builder->join('product_badges', 'product_badges.badge_id = badges.id', 'left');
            $builder->where('badges.deleted_at', null);
            $builder->groupBy('badges.id');
            $builder->orderBy('usage_count', 'DESC');
            $builder->orderBy('badges.label', 'ASC');
            
            if ($limit !== null) {
                $builder->limit($limit, $offset);
            }
            
            $result = $builder->get()->getResultArray();
            
            // Convert to structured array with Badge entities
            $badges = [];
            foreach ($result as $row) {
                $usageCount = (int) $row['usage_count'];
                unset($row['usage_count']);
                
                $badge = Badge::fromArray($row);
                $badges[] = [
                    'badge' => $badge,
                    'usage_count' => $usageCount
                ];
            }
            
            return $badges;
        }, 1800);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateWithFilters(PaginationQuery $query, array $filters = []): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'paginateWithFilters',
            'query' => $query->toArray(),
            'filters' => $filters
        ]);
        
        return $this->remember($cacheKey, function () use ($query, $filters) {
            $builder = $this->badgeModel->builder();
            
            // Apply filters
            $this->applyAdvancedFilters($builder, $filters);
            
            // Apply ordering
            if ($query->orderBy && $query->orderDirection) {
                $builder->orderBy($query->orderBy, $query->orderDirection);
            } else {
                $builder->orderBy('created_at', 'DESC');
            }
            
            // Get total count
            $totalBuilder = clone $builder;
            $total = $totalBuilder->countAllResults(false);
            
            // Apply pagination
            $offset = ($query->page - 1) * $query->perPage;
            $builder->limit($query->perPage, $offset);
            
            // Execute query
            $data = $builder->get()->getResult(Badge::class);
            $data = $data ?: [];
            
            // Calculate pagination metadata
            $lastPage = ceil($total / $query->perPage);
            $from = $total > 0 ? $offset + 1 : 0;
            $to = min($offset + $query->perPage, $total);
            
            return [
                'data' => $data,
                'pagination' => [
                    'total' => (int) $total,
                    'per_page' => $query->perPage,
                    'current_page' => $query->page,
                    'last_page' => (int) $lastPage,
                    'from' => (int) $from,
                    'to' => (int) $to
                ]
            ];
        }, 300); // 5 minutes TTL for filtered pagination
    }

    /**
     * Apply advanced filters to query builder
     *
     * @param BaseBuilder $builder
     * @param array{
     *     search?: string,
     *     has_color?: bool,
     *     is_active?: bool,
     *     min_usage?: int,
     *     max_usage?: int
     * } $filters
     * @return void
     */
    private function applyAdvancedFilters(BaseBuilder $builder, array $filters): void
    {
        // Apply base criteria (deleted_at = null for active)
        if (!isset($filters['is_active']) || $filters['is_active'] !== false) {
            $builder->where('deleted_at', null);
        }
        
        // Search by label
        if (!empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $builder->groupStart();
            $builder->like('label', $searchTerm);
            $builder->orLike('color', $searchTerm);
            $builder->groupEnd();
        }
        
        // Filter by color existence
        if (isset($filters['has_color'])) {
            if ($filters['has_color']) {
                $builder->where('color IS NOT NULL');
                $builder->where('color !=', '');
            } else {
                $builder->groupStart();
                $builder->where('color IS NULL');
                $builder->orWhere('color', '');
                $builder->groupEnd();
            }
        }
        
        // Filter by usage count (requires subquery)
        if (isset($filters['min_usage']) || isset($filters['max_usage'])) {
            $subquery = $this->db->table('product_badges')
                ->select('badge_id, COUNT(*) as usage_count')
                ->groupBy('badge_id');
            
            $builder->join(
                "({$subquery->getCompiledSelect(false)}) as usage",
                'usage.badge_id = badges.id',
                'left'
            );
            
            if (isset($filters['min_usage'])) {
                $builder->where('usage.usage_count >=', (int) $filters['min_usage']);
            }
            
            if (isset($filters['max_usage'])) {
                $builder->where('usage.usage_count <=', (int) $filters['max_usage']);
            }
        }
    }

    /**
     * Override parent's applyCriteria to add badge-specific logic
     *
     * {@inheritDoc}
     */
    protected function applyCriteria(BaseBuilder $builder, array $criteria): void
    {
        // Handle special badge criteria
        $specialHandlers = [
            'has_color' => function ($value, $builder) {
                if ($value === true) {
                    $builder->where('color IS NOT NULL');
                    $builder->where('color !=', '');
                } elseif ($value === false) {
                    $builder->groupStart();
                    $builder->where('color IS NULL');
                    $builder->orWhere('color', '');
                    $builder->groupEnd();
                }
            },
            'label_like' => function ($value, $builder) {
                $builder->like('label', $value);
            }
        ];
        
        $remainingCriteria = [];
        
        foreach ($criteria as $field => $value) {
            if (isset($specialHandlers[$field])) {
                $specialHandlers[$field]($value, $builder);
            } else {
                $remainingCriteria[$field] = $value;
            }
        }
        
        // Apply remaining criteria using parent's method
        parent::applyCriteria($builder, $remainingCriteria);
    }

    /**
     * Create a badge with color validation
     *
     * @param array{label: string, color?: string|null} $data
     * @return Badge
     * @throws ValidationException
     */
    public function create(array $data): Badge
    {
        // Validate color format if provided
        if (isset($data['color']) && $data['color'] !== null) {
            if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $data['color'])) {
                throw new \App\Exceptions\ValidationException([
                    'color' => 'Color must be a valid hex color code (e.g., #FF0000 or #F00)'
                ]);
            }
        }
        
        return parent::create($data);
    }

    /**
     * Update badge with color validation
     *
     * @param int|string $id
     * @param array<string, mixed> $data
     * @return Badge
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(int|string $id, array $data): Badge
    {
        // Validate color format if provided
        if (isset($data['color']) && $data['color'] !== null) {
            if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $data['color'])) {
                throw new \App\Exceptions\ValidationException([
                    'color' => 'Color must be a valid hex color code (e.g., #FF0000 or #F00)'
                ]);
            }
        }
        
        return parent::update($id, $data);
    }

    /**
     * Override delete to use archiveBadge for business logic
     *
     * @param int|string $id
     * @return bool
     * @throws NotFoundException
     */
    public function delete(int|string $id): bool
    {
        return $this->archiveBadge($id);
    }

    /**
     * Get model instance (type-hinted for BadgeModel)
     *
     * @return BadgeModel
     */
    protected function getModel(): BadgeModel
    {
        return $this->badgeModel;
    }
}