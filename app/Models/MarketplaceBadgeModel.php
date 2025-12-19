<?php

namespace App\Models;

use App\Entities\MarketplaceBadge;

/**
 * MarketplaceBadge Model
 *
 * Handles marketplace badges (Official Store, Top Seller, etc.).
 * Simple CRUD with assignment tracking for MVP.
 *
 * @package App\Models
 */
class MarketplaceBadgeModel extends BaseModel
{
    /**
     * Table name
     *
     * @var string
     */
    protected $table = 'marketplace_badges';

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
    protected $returnType = MarketplaceBadge::class;

    /**
     * Allowed fields for mass assignment
     *
     * @var array
     */
    protected $allowedFields = [
        'label',
        'icon',
        'color',
    ];

    /**
     * Validation rules for insert
     *
     * @var array
     */
    protected $validationRules = [
        'label' => 'required|min_length[2]|max_length[100]',
        'icon'  => 'permit_empty|max_length[100]',
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
     * Find common marketplace badges (system defaults)
     * Returns predefined badges that are commonly used
     * Cached for 60 minutes as badges rarely change
     *
     * @param bool $activeOnly Only return non-deleted badges
     * @return MarketplaceBadge[]
     */
    public function findCommon(bool $activeOnly = true): array
    {
        $cacheKey = $this->cacheKey('common_' . ($activeOnly ? 'active' : 'all'));

        return $this->cached($cacheKey, function () use ($activeOnly) {
            // Common marketplace badge labels (matches Entity's createCommon method)
            $commonLabels = [
                'Official Store',
                'Top Seller',
                'Verified Seller',
                'Fast Delivery',
                'Recommended',
                'Trusted',
                'Choice',
                'Premium Seller',
            ];

            $builder = $this->builder();
            $builder->whereIn('label', $commonLabels);

            if ($activeOnly) {
                $builder->where('deleted_at');
            }

            return $builder->orderBy('label', 'ASC')
                          ->get()
                          ->getResult($this->returnType);
        }, 3600); // 60 minutes cache
    }

    /**
     * Find active marketplace badges (not deleted)
     * Simple active badge retrieval for UI selection in links

     * @param int $limit Maximum badges to return
     * @return MarketplaceBadge[]
     */
    public function findActive(int $limit = 50): array
    {
        $cacheKey = $this->cacheKey("active_{$limit}");

        return $this->cached($cacheKey, function () use ($limit) {
            return $this->where('deleted_at')
                       ->orderBy('label', 'ASC')
                       ->limit($limit)
                       ->findAll();
        }, 3600); // 60 minutes cache
    }

    /**
     * Find marketplace badges with link count (usage statistics)
     * Shows how many links have each badge assigned
     * Used for admin dashboard and badge management
     *
     * @return MarketplaceBadge[] With attached link_count property
     */
    public function withLinkCount(int $limit = 50): array
    {
        $cacheKey = $this->cacheKey("with_link_count_{$limit}");

        return $this->cached($cacheKey, function () use ($limit) {
            // Get all non-deleted badges
            $badges = $this->where('deleted_at')
                          ->orderBy('label', 'ASC')
                          ->limit($limit)
                          ->findAll();

            if (empty($badges)) {
                return [];
            }

            // Get link counts for each badge
            $this->attachLinkCounts($badges);

            return $badges;
        }, 1800); // 30 minutes cache
    }

    /**
     * Find marketplace badge by label (case-insensitive search)
     * Useful for finding or creating badges by label
     *
     * @param bool $activeOnly Only return non-deleted badges
     */
    public function findByLabel(string $label, bool $activeOnly = true): ?MarketplaceBadge
    {
        $cacheKey = $this->cacheKey("label_" . md5(strtolower($label)) . '_' . ($activeOnly ? 'active' : 'all'));

        return $this->cached($cacheKey, function () use ($label, $activeOnly) {
            $builder = $this->builder();

            // Case-insensitive search
            $builder->where('LOWER(label)', strtolower($label));

            if ($activeOnly) {
                $builder->where('deleted_at');
            }

            $result = $builder->get()->getFirstRow($this->returnType);

            return $result instanceof MarketplaceBadge ? $result : null;
        }, 3600); // 60 minutes cache
    }

    // ==================== HELPER METHODS ====================
    /**
     * Attach link counts to marketplace badges
     *
     * @param MarketplaceBadge[] $badges
     */
    private function attachLinkCounts(array &$badges): void
    {
        if ($badges === []) {
            return;
        }

        $badgeIds = array_map(fn ($badge) => $badge->getId(), $badges);

        // Get link counts from links table
        $linkModel = model(LinkModel::class);
        $builder = $linkModel->builder();

        $result = $builder->select('marketplace_badge_id, COUNT(*) as link_count')
                         ->whereIn('marketplace_badge_id', $badgeIds)
                         ->where('active', 1)
                         ->where('deleted_at', null)
                         ->groupBy('marketplace_badge_id')
                         ->get()
                         ->getResultArray();

        // Create lookup array
        $counts = [];
        foreach ($result as $row) {
            $counts[$row['marketplace_badge_id']] = (int) $row['link_count'];
        }

        // Attach counts to badges
        foreach ($badges as $badge) {
            $badgeId = $badge->getId();
            $badge->link_count = $counts[$badgeId] ?? 0;
            $badge->is_assigned = ($counts[$badgeId] ?? 0) > 0;
        }
    }

    /**
     * Check if marketplace badge can be deleted
     * Business rule: badge assigned to active links cannot be deleted
     *
     * @return array [bool $canDelete, string $reason]
     */
    public function canDelete(int $badgeId): array
    {
        $badge = $this->findActiveById($badgeId);
        if (!$badge) {
            return [false, 'Marketplace badge not found'];
        }

        // Check if badge is assigned to any active links
        $linkModel = model(LinkModel::class);
        $assignmentCount = $linkModel->where('marketplace_badge_id', $badgeId)
                                    ->where('active', 1)
                                    ->where('deleted_at', null)
                                    ->countAllResults();

        if ($assignmentCount > 0) {
            return [false, "Badge is assigned to {$assignmentCount} active link(s). Remove assignments first."];
        }

        return [true, ''];
    }

    /**
     * Get marketplace badge statistics for admin dashboard
     */
    public function getStats(): array
    {
        $cacheKey = $this->cacheKey('stats');

        return $this->cached($cacheKey, function () {
            $total = $this->countActive();

            // Get badge usage statistics
            $linkModel = model(LinkModel::class);
            $builder = $linkModel->builder();

            $usageStats = $builder->select('marketplace_badge_id, COUNT(*) as usage_count')
                                 ->where('marketplace_badge_id IS NOT NULL')
                                 ->where('active', 1)
                                 ->where('deleted_at', null)
                                 ->groupBy('marketplace_badge_id')
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
                $badge = $this->find($mostUsed['marketplace_badge_id']);
                if ($badge) {
                    $mostUsedBadge = [
                        'badge' => $badge,
                        'usage_count' => (int) $mostUsed['usage_count']
                    ];
                }
            }

            // Get badges without icon
            $noIconCount = $this->where('icon IS NULL')
                               ->where('deleted_at')
                               ->countAllResults();

            $withIconCount = $this->where('icon IS NOT NULL')
                                 ->where('deleted_at')
                                 ->countAllResults();

            // Get badges without color (using default styling)
            $noColorCount = $this->where('color IS NULL')
                                ->where('deleted_at')
                                ->countAllResults();

            $withColorCount = $this->where('color IS NOT NULL')
                                  ->where('deleted_at')
                                  ->countAllResults();

            return [
                'total_badges' => $total,
                'total_assignments' => $totalAssignments,
                'avg_assignments_per_badge' => $total > 0 ? round($totalAssignments / $total, 2) : 0,
                'most_used_badge' => $mostUsedBadge,
                'badges_without_icon' => $noIconCount,
                'badges_with_icon' => $withIconCount,
                'badges_without_color' => $noColorCount,
                'badges_with_color' => $withColorCount,
                'icon_coverage' => $total > 0 ? round(($withIconCount / $total) * 100, 2) : 0,
                'color_coverage' => $total > 0 ? round(($withColorCount / $total) * 100, 2) : 0,
            ];
        }, 300); // 5 minutes cache for stats
    }

    /**
     * Find or create marketplace badge by label
     * Useful for bulk operations where badges might not exist
     *
     * @param string|null $icon Optional FontAwesome icon
     * @param string|null $color Optional hex color
     * @return MarketplaceBadge The found or created badge
     */
    public function findOrCreate(string $label, ?string $icon = null, ?string $color = null): MarketplaceBadge
    {
        // Try to find existing badge
        $badge = $this->findByLabel($label, false); // Include deleted for restoration

        if ($badge instanceof \App\Entities\MarketplaceBadge) {
            // If badge was deleted, restore it
            if ($badge->isDeleted()) {
                $this->restore($badge->getId());
                $badge = $this->find($badge->getId());
            }

            // Update icon/color if provided and different
            $updateData = [];
            if ($icon !== null && $badge->getIcon() !== $icon) {
                $updateData['icon'] = $icon;
            }
            if ($color !== null && $badge->getColor() !== $color) {
                $updateData['color'] = $color;
            }

            if ($updateData !== []) {
                $this->update($badge->getId(), $updateData);
                $badge = $this->find($badge->getId());
            }

            return $badge;
        }

        // Create new badge
        $data = [
            'label' => $label,
            'icon'  => $icon,
            'color' => $color,
        ];

        $id = $this->insert($data);

        if (!$id) {
            throw new \RuntimeException("Failed to create marketplace badge: {$label}");
        }

        // Clear caches
        $this->clearMarketplaceBadgeCaches();

        return $this->find($id);
    }

    /**
     * Create default marketplace badges for system initialization
     *
     * @return array IDs of created badges
     */
    public function createDefaultBadges(): array
    {
        $defaultBadges = [
            [
                'label' => 'Official Store',
                'icon' => 'fas fa-check-circle',
                'color' => '#059669'
            ],
            [
                'label' => 'Top Seller',
                'icon' => 'fas fa-crown',
                'color' => '#D97706'
            ],
            [
                'label' => 'Verified Seller',
                'icon' => 'fas fa-shield-check',
                'color' => '#2563EB'
            ],
            [
                'label' => 'Fast Delivery',
                'icon' => 'fas fa-shipping-fast',
                'color' => '#7C3AED'
            ],
            [
                'label' => 'Recommended',
                'icon' => 'fas fa-thumbs-up',
                'color' => '#DC2626'
            ],
            [
                'label' => 'Trusted',
                'icon' => 'fas fa-award',
                'color' => '#059669'
            ],
            [
                'label' => 'Choice',
                'icon' => 'fas fa-star',
                'color' => '#4F46E5'
            ],
            [
                'label' => 'Premium Seller',
                'icon' => 'fas fa-gem',
                'color' => '#F59E0B'
            ],
        ];

        $createdIds = [];

        foreach ($defaultBadges as $badgeData) {
            // Check if badge already exists by label (case-insensitive)
            $existing = $this->findByLabel($badgeData['label'], false);

            if (!$existing instanceof \App\Entities\MarketplaceBadge && $id = $this->insert($badgeData)) {
                $createdIds[] = $id;
            }
        }

        // Clear caches after creating defaults
        $this->clearMarketplaceBadgeCaches();

        return $createdIds;
    }

    /**
     * Clear all marketplace badge caches
     */
    private function clearMarketplaceBadgeCaches(): void
    {
        $keys = [
            'common_active',
            'common_all',
            'active_50',
            'with_link_count_50',
            'stats',
        ];

        foreach ($keys as $key) {
            $this->clearCache($this->cacheKey($key));
        }
    }

    /**
     * Find marketplace badges by IDs
     *
     * @param bool $activeOnly Only return non-deleted badges
     * @return MarketplaceBadge[]
     */
    public function findByIds(array $badgeIds, bool $activeOnly = true): array
    {
        if ($badgeIds === []) {
            return [];
        }

        $cacheKey = $this->cacheKey('ids_' . md5(implode(',', $badgeIds)) . '_' . ($activeOnly ? 'active' : 'all'));

        return $this->cached($cacheKey, function () use ($badgeIds, $activeOnly) {
            $builder = $this->builder();
            $builder->whereIn('id', $badgeIds);

            if ($activeOnly) {
                $builder->where('deleted_at');
            }

            $builder->orderBy('label', 'ASC');

            return $builder->get()->getResult($this->returnType);
        }, 3600);
    }

    /**
     * Search marketplace badges by keyword
     *
     * @return MarketplaceBadge[]
     */
    public function search(string $keyword, array $fields = [], int $limit = 10)
    {
        if ($keyword === '' || $keyword === '0') {
            return [];
        }

        $cacheKey = $this->cacheKey("search_" . md5($keyword) . "_{$limit}");

        return $this->cached($cacheKey, function () use ($keyword, $limit) {
            return $this->like('label', $keyword)
                       ->where('deleted_at')
                       ->orderBy('label', 'ASC')
                       ->limit($limit)
                       ->findAll();
        }, 1800); // 30 minutes cache
    }
}
