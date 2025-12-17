<?php

namespace App\Models;

use App\Entities\Marketplace;

/**
 * Marketplace Model
 * * Handles e-commerce marketplaces (Tokopedia, Shopee, etc.).
 * Simple CRUD for MVP.
 * * @package App\Models
 */
class MarketplaceModel extends BaseModel
{
    /**
     * Table name
     * * @var string
     */
    protected $table = 'marketplaces';

    /**
     * Primary key
     * * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Entity class for result objects
     * * @var string
     */
    protected $returnType = Marketplace::class;

    /**
     * Allowed fields for mass assignment
     * * @var array
     */
    protected $allowedFields = [
        'name',
        'slug',
        'icon',
        'color',
        'active',
    ];

    /**
     * Validation rules for insert
     * * @var array
     */
    protected $validationRules = [
        'name'            => 'required|min_length[2]|max_length[100]',
        'slug'            => 'required|alpha_dash|max_length[100]|is_unique[marketplaces.slug,id,{id}]',
        'icon'            => 'permit_empty|max_length[255]',
        'color'           => 'permit_empty|regex_match[/^#[0-9A-F]{6}$/i]',
        'active'          => 'permit_empty|in_list[0,1]',
    ];

    /**
     * Default ordering for queries
     * * @var array
     */
    protected $orderBy = [
        'name' => 'ASC'
    ];

    // ==================== CORE BUSINESS METHODS (5 METHODS) ====================

    /**
     * Find active marketplaces for public display
     * Cached for 60 minutes as marketplaces rarely change
     * * @param bool $withStats Include link count statistics
     * @return Marketplace[]
     */
    public function findActive(bool $withStats = false): array
    {
        $cacheKey = $this->cacheKey('active_' . ($withStats ? 'with_stats' : 'basic'));
        
        return $this->cached($cacheKey, function() use ($withStats) {
            $marketplaces = $this->where('active', 1)
                                ->where('deleted_at', null)
                                ->orderBy('name', 'ASC')
                                ->findAll();
            
            if ($withStats && !empty($marketplaces)) {
                $this->attachLinkCounts($marketplaces);
            }
            
            return $marketplaces;
        }, 3600); // 60 minutes cache
    }

    /**
     * Get marketplaces with link count statistics
     * Used for admin dashboard and analytics
     * * @param int $limit
     * @return Marketplace[] With attached link_count property
     */
    public function withLinkCount(int $limit = 50): array
    {
        $cacheKey = $this->cacheKey("with_link_count_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit) {
            // Get all active marketplaces
            $marketplaces = $this->where('deleted_at', null)
                                ->orderBy('name', 'ASC')
                                ->limit($limit)
                                ->findAll();
            
            if (empty($marketplaces)) {
                return [];
            }
            
            // Attach link counts
            $this->attachLinkCounts($marketplaces);
            
            return $marketplaces;
        }, 1800); // 30 minutes cache
    }

    /**
     * Get commission statistics per marketplace
     * Returns aggregated revenue and performance data
     * * @param string $period 'day', 'week', 'month', or 'all'
     * @return array
     */
    public function getCommissionStats(string $period = 'month'): array
    {
        $cacheKey = $this->cacheKey("commission_stats_{$period}");
        
        return $this->cached($cacheKey, function() use ($period) {
            // Get all active marketplaces
            $marketplaces = $this->where('active', 1)
                                ->where('deleted_at', null)
                                ->orderBy('name', 'ASC')
                                ->findAll();
            
            if (empty($marketplaces)) {
                return [];
            }
            
            $linkModel = model(LinkModel::class);
            $stats = [];
            
            foreach ($marketplaces as $marketplace) {
                $marketplaceId = $marketplace->getId();
                
                // Get link stats for this marketplace
                $linkStats = $linkModel->getClickStats($period, null, $marketplaceId);
                
                // Use actual revenue from links (manually input by admin)
                $totalRevenue = (float) $linkStats['total_revenue'];
                
                $stats[] = [
                    'marketplace' => $marketplace,
                    'link_stats' => $linkStats,
                    'total_revenue' => number_format($totalRevenue, 2, '.', ''), // Renamed from estimated_commission
                    'total_links' => $linkStats['total_links'],
                    'total_clicks' => $linkStats['total_clicks'],
                ];
            }
            
            // Sort by total revenue (descending)
            usort($stats, function($a, $b) {
                return (float) $b['total_revenue'] <=> (float) $a['total_revenue'];
            });
            
            return $stats;
        }, 300); // 5 minutes cache for stats
    }

    /**
     * Find marketplace by slug
     * Used for routing and URL resolution
     * * @param string $slug
     * @param bool $activeOnly Only return active marketplaces
     * @return Marketplace|null
     */
    public function findBySlug(string $slug, bool $activeOnly = true): ?Marketplace
    {
        $cacheKey = $this->cacheKey("slug_{$slug}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($slug, $activeOnly) {
            $builder = $this->builder();
            $builder->where('slug', $slug)
                    ->where('deleted_at', null);
            
            if ($activeOnly) {
                $builder->where('active', 1);
            }
            
            $result = $builder->get()->getFirstRow($this->returnType);
            
            return $result instanceof Marketplace ? $result : null;
        }, 3600); // 60 minutes cache
    }

    // ==================== HELPER METHODS ====================

    /**
     * Attach link counts to marketplaces
     * * @param Marketplace[] $marketplaces
     * @return void
     */
    private function attachLinkCounts(array &$marketplaces): void
    {
        if (empty($marketplaces)) {
            return;
        }
        
        $marketplaceIds = array_map(fn($mp) => $mp->getId(), $marketplaces);
        
        // Get link counts in a single query
        $linkModel = model(LinkModel::class);
        $builder = $linkModel->builder();
        
        $result = $builder->select('marketplace_id, COUNT(*) as link_count, SUM(clicks) as total_clicks')
                         ->whereIn('marketplace_id', $marketplaceIds)
                         ->where('active', 1)
                         ->where('deleted_at', null)
                         ->groupBy('marketplace_id')
                         ->get()
                         ->getResultArray();
        
        // Create lookup array
        $counts = [];
        foreach ($result as $row) {
            $counts[$row['marketplace_id']] = [
                'link_count' => (int) $row['link_count'],
                'total_clicks' => (int) $row['total_clicks']
            ];
        }
        
        // Attach counts to marketplaces
        foreach ($marketplaces as $marketplace) {
            $marketplaceId = $marketplace->getId();
            $marketplace->link_count = $counts[$marketplaceId]['link_count'] ?? 0;
            $marketplace->total_clicks = $counts[$marketplaceId]['total_clicks'] ?? 0;
            $marketplace->is_in_use = ($counts[$marketplaceId]['link_count'] ?? 0) > 0;
        }
    }

    /**
     * Clear all caches related to a marketplace
     * * @param int $marketplaceId
     * @return void
     */
    private function clearMarketplaceCaches(int $marketplaceId): void
    {
        $cacheKeys = [
            'active_basic',
            'active_with_stats',
            'with_link_count_50',
            "slug_{$marketplaceId}_active",
            "slug_{$marketplaceId}_all",
        ];
        
        // Also clear commission stats caches
        $periods = ['day', 'week', 'month', 'all'];
        foreach ($periods as $period) {
            $cacheKeys[] = "commission_stats_{$period}";
        }
        
        foreach ($cacheKeys as $key) {
            $this->clearCache($this->cacheKey($key));
        }
    }

    /**
     * Check if marketplace can be deleted
     * Business rule: marketplace with active links cannot be deleted
     * * @param int $marketplaceId
     * @return array [bool $canDelete, string $reason]
     */
    public function canDelete(int $marketplaceId): array
    {
        $marketplace = $this->findActiveById($marketplaceId);
        if (!$marketplace) {
            return [false, 'Marketplace not found'];
        }
        
        // Check if marketplace has any active links
        $linkModel = model(LinkModel::class);
        $linkCount = $linkModel->where('marketplace_id', $marketplaceId)
                              ->where('active', 1)
                              ->where('deleted_at', null)
                              ->countAllResults();
        
        if ($linkCount > 0) {
            return [false, "Marketplace has {$linkCount} active link(s). Remove links first."];
        }
        
        return [true, ''];
    }

    /**
     * Get marketplace statistics for admin dashboard
     * * @return array
     */
    public function getStats(): array
    {
        $cacheKey = $this->cacheKey('stats');
        
        return $this->cached($cacheKey, function() {
            $total = $this->countActive();
            $active = $this->where('active', 1)
                          ->where('deleted_at', null)
                          ->countAllResults();
            
            $inactive = $this->where('active', 0)
                            ->where('deleted_at', null)
                            ->countAllResults();
            
            $archived = $this->where('deleted_at IS NOT NULL')
                            ->countAllResults();
            
            // Get marketplace with most links
            $linkModel = model(LinkModel::class);
            $builder = $linkModel->builder();
            $mostLinks = $builder->select('marketplace_id, COUNT(*) as link_count')
                                ->where('marketplace_id IS NOT NULL')
                                ->where('active', 1)
                                ->where('deleted_at', null)
                                ->groupBy('marketplace_id')
                                ->orderBy('link_count', 'DESC')
                                ->limit(1)
                                ->get()
                                ->getRowArray();
            
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'archived' => $archived,
                'most_used_marketplace' => $mostLinks ? [
                    'marketplace_id' => $mostLinks['marketplace_id'],
                    'link_count' => (int) $mostLinks['link_count']
                ] : null,
            ];
        }, 300); // 5 minutes cache for stats
    }

    /**
     * Find marketplaces by IDs
     * * @param array $marketplaceIds
     * @param bool $activeOnly
     * @return Marketplace[]
     */
    public function findByIds(array $marketplaceIds, bool $activeOnly = true): array
    {
        if (empty($marketplaceIds)) {
            return [];
        }
        
        $cacheKey = $this->cacheKey('ids_' . md5(implode(',', $marketplaceIds)) . '_' . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($marketplaceIds, $activeOnly) {
            $builder = $this->builder();
            $builder->whereIn('id', $marketplaceIds)
                    ->where('deleted_at', null);
            
            if ($activeOnly) {
                $builder->where('active', 1);
            }
            
            $builder->orderBy('name', 'ASC');
            
            return $builder->get()->getResult($this->returnType);
        }, 3600);
    }

    /**
     * Create default marketplaces for system initialization
     * * @return array IDs of created marketplaces
     */
    public function createDefaultMarketplaces(): array
    {
        $defaultMarketplaces = [
            [
                'name' => 'Tokopedia',
                'slug' => 'tokopedia',
                'icon' => 'fas fa-store',
                'color' => '#42B549',
                'active' => 1,
            ],
            [
                'name' => 'Shopee',
                'slug' => 'shopee',
                'icon' => 'fas fa-shopping-cart',
                'color' => '#FF5316',
                'active' => 1,
            ],
            [
                'name' => 'Lazada',
                'slug' => 'lazada',
                'icon' => 'fas fa-bolt',
                'color' => '#0F146C',
                'active' => 1,
            ],
            [
                'name' => 'Blibli',
                'slug' => 'blibli',
                'icon' => 'fas fa-box',
                'color' => '#E60012',
                'active' => 1,
            ],
            [
                'name' => 'Bukalapak',
                'slug' => 'bukalapak',
                'icon' => 'fas fa-shopping-bag',
                'color' => '#E31B23',
                'active' => 1,
            ],
        ];
        
        $createdIds = [];
        
        foreach ($defaultMarketplaces as $marketplaceData) {
            // Check if marketplace already exists by slug
            $existing = $this->where('slug', $marketplaceData['slug'])
                            ->where('deleted_at', null)
                            ->first();
            
            if (!$existing) {
                if ($id = $this->insert($marketplaceData)) {
                    $createdIds[] = $id;
                }
            }
        }
        
        // Clear caches after creating defaults
        $this->clearCache($this->cacheKey('active_basic'));
        $this->clearCache($this->cacheKey('active_with_stats'));
        
        return $createdIds;
    }

    /**
     * Deactivate marketplace
     * * @param int $marketplaceId
     * @return bool
     */
    public function deactivate(int $marketplaceId): bool
    {
        $result = $this->update($marketplaceId, ['active' => 0]);
        
        if ($result) {
            $this->clearMarketplaceCaches($marketplaceId);
        }
        
        return $result;
    }

    /**
     * Activate marketplace
     * * @param int $marketplaceId
     * @return bool
     */
    public function activate(int $marketplaceId): bool
    {
        $result = $this->update($marketplaceId, ['active' => 1]);
        
        if ($result) {
            $this->clearMarketplaceCaches($marketplaceId);
        }
        
        return $result;
    }
}
