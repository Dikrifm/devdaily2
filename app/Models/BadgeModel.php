<?php

namespace App\Models;

use App\Entities\Badge;

/**
 * Badge Model
 * 
 * Handles product badges (Best Seller, New Arrival, etc.).
 * Simple CRUD with usage tracking for MVP.
 * 
 * @package App\Models
 */
class BadgeModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'badges';

    /**
     * Primary key
     * 
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Entity class for result objects
     * 
     * @var string
     */
    protected $returnType = Badge::class;

    /**
     * Allowed fields for mass assignment
     * 
     * @var array
     */
    protected $allowedFields = [
        'label',
        'color',
    ];

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'label' => 'required|min_length[2]|max_length[100]',
        'color' => 'permit_empty|regex_match[/^#[0-9A-F]{6}$/i]',
    ];

    /**
     * Default ordering for queries
     * 
     * @var array
     */
    protected $orderBy = [
        'label' => 'ASC'
    ];

    // ==================== CORE BUSINESS METHODS (4 METHODS) ====================

    /**
     * Find common badges (system defaults)
     * Returns predefined badges that are commonly used
     * Cached for 60 minutes as badges rarely change
     * 
     * @param bool $activeOnly Only return non-deleted badges
     * @return Badge[]
     */
    public function findCommon(bool $activeOnly = true): array
    {
        $cacheKey = $this->cacheKey('common_' . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($activeOnly) {
            // Common badge labels (matches Entity's createCommon method)
            $commonLabels = [
                'Best Seller',
                'New Arrival',
                'Limited Edition',
                'Exclusive',
                'Trending',
                'Verified',
                'Discount',
                'Premium',
            ];
            
            $builder = $this->builder();
            $builder->whereIn('label', $commonLabels);
            
            if ($activeOnly) {
                $builder->where('deleted_at', null);
            }
            
            return $builder->orderBy('label', 'ASC')
                          ->get()
                          ->getResult($this->returnType);
        }, 3600); // 60 minutes cache
    }

    /**
     * Find active badges (not deleted)
     * Simple active badge retrieval for UI selection
     * 
     * @param int $limit Maximum badges to return
     * @return Badge[]
     */
    public function findActive(int $limit = 50): array
    {
        $cacheKey = $this->cacheKey("active_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit) {
            return $this->where('deleted_at', null)
                       ->orderBy('label', 'ASC')
                       ->limit($limit)
                       ->findAll();
        }, 3600); // 60 minutes cache
    }

    /**
     * Find badges with product count (usage statistics)
     * Shows how many products have each badge assigned
     * Used for admin dashboard and badge management
     * 
     * @param int $limit
     * @return Badge[] With attached product_count property
     */
    public function withProductCount(int $limit = 50): array
    {
        $cacheKey = $this->cacheKey("with_product_count_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit) {
            // Get all non-deleted badges
            $badges = $this->where('deleted_at', null)
                          ->orderBy('label', 'ASC')
                          ->limit($limit)
                          ->findAll();
            
            if (empty($badges)) {
                return [];
            }
            
            // Get product counts for each badge
            $this->attachProductCounts($badges);
            
            return $badges;
        }, 1800); // 30 minutes cache
    }

    /**
     * Find badge by label (case-insensitive search)
     * Useful for finding or creating badges by label
     * 
     * @param string $label
     * @param bool $activeOnly Only return non-deleted badges
     * @return Badge|null
     */
    public function findByLabel(string $label, bool $activeOnly = true): ?Badge
    {
        $cacheKey = $this->cacheKey("label_" . md5(strtolower($label)) . '_' . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($label, $activeOnly) {
            $builder = $this->builder();
            
            // Case-insensitive search
            $builder->where('LOWER(label)', strtolower($label));
            
            if ($activeOnly) {
                $builder->where('deleted_at', null);
            }
            
            $result = $builder->get()->getFirstRow($this->returnType);
            
            return $result instanceof Badge ? $result : null;
        }, 3600); // 60 minutes cache
    }

    // ==================== HELPER METHODS ====================

    /**
     * Attach product counts to badges
     * 
     * @param Badge[] $badges
     * @return void
     */
    private function attachProductCounts(array &$badges): void
    {
        if (empty($badges)) {
            return;
        }
        
        $badgeIds = array_map(fn($badge) => $badge->getId(), $badges);
        
        // Get product counts from junction table
        $productBadgeModel = model(ProductBadgeModel::class);
        $builder = $productBadgeModel->builder();
        
        $result = $builder->select('badge_id, COUNT(*) as product_count')
                         ->whereIn('badge_id', $badgeIds)
                         ->groupBy('badge_id')
                         ->get()
                         ->getResultArray();
        
        // Create lookup array
        $counts = [];
        foreach ($result as $row) {
            $counts[$row['badge_id']] = (int) $row['product_count'];
        }
        
        // Attach counts to badges
        foreach ($badges as $badge) {
            $badgeId = $badge->getId();
            $badge->product_count = $counts[$badgeId] ?? 0;
            $badge->is_in_use = ($counts[$badgeId] ?? 0) > 0;
        }
    }

    /**
     * Check if badge can be deleted
     * Business rule: badge assigned to products cannot be deleted
     * 
     * @param int $badgeId
     * @return array [bool $canDelete, string $reason]
     */
    public function canDelete(int $badgeId): array
    {
        $badge = $this->findActiveById($badgeId);
        if (!$badge) {
            return [false, 'Badge not found'];
        }
        
        // Check if badge is assigned to any products
        $productBadgeModel = model(ProductBadgeModel::class);
        $assignmentCount = $productBadgeModel->countByBadge($badgeId);
        
        if ($assignmentCount > 0) {
            return [false, "Badge is assigned to {$assignmentCount} product(s). Remove assignments first."];
        }
        
        return [true, ''];
    }

    /**
     * Get badge statistics for admin dashboard
     * 
     * @return array
     */
    public function getStats(): array
    {
        $cacheKey = $this->cacheKey('stats');
        
        return $this->cached($cacheKey, function() {
            $total = $this->countActive();
            
            // Get badge usage statistics
            $productBadgeModel = model(ProductBadgeModel::class);
            $builder = $productBadgeModel->builder();
            
            $usageStats = $builder->select('badge_id, COUNT(*) as usage_count')
                                 ->groupBy('badge_id')
                                 ->orderBy('usage_count', 'DESC')
                                 ->get()
                                 ->getResultArray();
            
            $totalAssignments = 0;
            $mostUsedBadge = null;
            
            if (!empty($usageStats)) {
                foreach ($usageStats as $stat) {
                    $totalAssignments += $stat['usage_count'];
                }
                
                // Get most used badge details
                $mostUsed = $usageStats[0];
                $badge = $this->find($mostUsed['badge_id']);
                if ($badge) {
                    $mostUsedBadge = [
                        'badge' => $badge,
                        'usage_count' => (int) $mostUsed['usage_count']
                    ];
                }
            }
            
            // Get badges without color (default styling)
            $noColorCount = $this->where('color IS NULL')
                                ->where('deleted_at', null)
                                ->countAllResults();
            
            $withColorCount = $this->where('color IS NOT NULL')
                                  ->where('deleted_at', null)
                                  ->countAllResults();
            
            return [
                'total_badges' => $total,
                'total_assignments' => $totalAssignments,
                'avg_assignments_per_badge' => $total > 0 ? round($totalAssignments / $total, 2) : 0,
                'most_used_badge' => $mostUsedBadge,
                'badges_without_color' => $noColorCount,
                'badges_with_color' => $withColorCount,
                'color_coverage' => $total > 0 ? round(($withColorCount / $total) * 100, 2) : 0,
            ];
        }, 300); // 5 minutes cache for stats
    }

    /**
     * Find or create badge by label
     * Useful for bulk operations where badges might not exist
     * 
     * @param string $label
     * @param string|null $color Optional hex color
     * @return Badge The found or created badge
     */
    public function findOrCreate(string $label, ?string $color = null): Badge
    {
        // Try to find existing badge
        $badge = $this->findByLabel($label, false); // Include deleted for restoration
        
        if ($badge) {
            // If badge was deleted, restore it
            if ($badge->isDeleted()) {
                $this->restore($badge->getId());
                $badge = $this->find($badge->getId());
            }
            
            // Update color if provided and different
            if ($color !== null && $badge->getColor() !== $color) {
                $this->update($badge->getId(), ['color' => $color]);
                $badge = $this->find($badge->getId());
            }
            
            return $badge;
        }
        
        // Create new badge
        $data = [
            'label' => $label,
            'color' => $color,
        ];
        
        $id = $this->insert($data);
        
        if (!$id) {
            throw new \RuntimeException("Failed to create badge: {$label}");
        }
        
        // Clear caches
        $this->clearBadgeCaches();
        
        return $this->find($id);
    }

    /**
     * Create default badges for system initialization
     * 
     * @return array IDs of created badges
     */
    public function createDefaultBadges(): array
    {
        $defaultBadges = [
            ['label' => 'Best Seller', 'color' => '#EF4444'],
            ['label' => 'New Arrival', 'color' => '#10B981'],
            ['label' => 'Limited Edition', 'color' => '#8B5CF6'],
            ['label' => 'Exclusive', 'color' => '#F59E0B'],
            ['label' => 'Trending', 'color' => '#3B82F6'],
            ['label' => 'Verified', 'color' => '#059669'],
            ['label' => 'Discount', 'color' => '#EC4899'],
            ['label' => 'Premium', 'color' => '#D97706'],
        ];
        
        $createdIds = [];
        
        foreach ($defaultBadges as $badgeData) {
            // Check if badge already exists by label (case-insensitive)
            $existing = $this->findByLabel($badgeData['label'], false);
            
            if (!$existing) {
                if ($id = $this->insert($badgeData)) {
                    $createdIds[] = $id;
                }
            }
        }
        
        // Clear caches after creating defaults
        $this->clearBadgeCaches();
        
        return $createdIds;
    }

    /**
     * Clear all badge caches
     * 
     * @return void
     */
    private function clearBadgeCaches(): void
    {
        $keys = [
            'common_active',
            'common_all',
            'active_50',
            'with_product_count_50',
            'stats',
        ];
        
        foreach ($keys as $key) {
            $this->clearCache($this->cacheKey($key));
        }
    }

    /**
     * Find badges by IDs
     * 
     * @param array $badgeIds
     * @param bool $activeOnly Only return non-deleted badges
     * @return Badge[]
     */
    public function findByIds(array $badgeIds, bool $activeOnly = true): array
    {
        if (empty($badgeIds)) {
            return [];
        }
        
        $cacheKey = $this->cacheKey('ids_' . md5(implode(',', $badgeIds)) . '_' . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($badgeIds, $activeOnly) {
            $builder = $this->builder();
            $builder->whereIn('id', $badgeIds);
            
            if ($activeOnly) {
                $builder->where('deleted_at', null);
            }
            
            $builder->orderBy('label', 'ASC');
            
            return $builder->get()->getResult($this->returnType);
        }, 3600);
    }

    /**
     * Search badges by keyword
     * 
     * @param string $keyword
     * @param int $limit
     * @return Badge[]
     */
    public function search(string $keyword, int $limit = 20): array
    {
        if (empty($keyword)) {
            return [];
        }
        
        $cacheKey = $this->cacheKey("search_" . md5($keyword) . "_{$limit}");
        
        return $this->cached($cacheKey, function() use ($keyword, $limit) {
            return $this->like('label', $keyword)
                       ->where('deleted_at', null)
                       ->orderBy('label', 'ASC')
                       ->limit($limit)
                       ->findAll();
        }, 1800); // 30 minutes cache
    }
}