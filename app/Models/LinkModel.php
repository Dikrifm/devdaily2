<?php

namespace App\Models;

use App\Entities\Link;
use CodeIgniter\Database\BaseBuilder;

/**
 * Link Model
 * 
 * Handles affiliate links, click tracking, and revenue management.
 * Core of the affiliate monetization system with MVP approach.
 * 
 * @package App\Models
 */
class LinkModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'links';

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
    protected $returnType = Link::class;

    /**
     * Allowed fields for mass assignment
     * 
     * @var array
     */
    protected $allowedFields = [
        'product_id',
        'marketplace_id',
        'store_name',
        'price',
        'url',
        'rating',
        'active',
        'sold_count',
        'clicks',
        'last_price_update',
        'last_validation',
        'affiliate_revenue',
        'marketplace_badge_id',
    ];

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'product_id'        => 'required|integer',
        'marketplace_id'    => 'required|integer',
        'store_name'        => 'required|min_length[2]|max_length[255]',
        'price'             => 'required|decimal',
        'url'               => 'permit_empty|valid_url|max_length[2000]',
        'rating'            => 'permit_empty|decimal|greater_than_equal_to[0]|less_than_equal_to[5]',
        'active'            => 'permit_empty|in_list[0,1]',
        'sold_count'        => 'permit_empty|integer|greater_than_equal_to[0]',
        'marketplace_badge_id' => 'permit_empty|integer',
    ];

    /**
     * Default ordering for queries
     * 
     * @var array
     */
    protected $orderBy = [
        'price' => 'ASC',
        'rating' => 'DESC'
    ];

    // ==================== CORE BUSINESS METHODS (6 METHODS) ====================

    /**
     * Find links by product ID
     * Optionally filter by active status
     * 
     * @param int $productId
     * @param bool|null $active null = all, true = active only, false = inactive only
     * @param int $limit
     * @return Link[]
     */
    public function findByProduct(int $productId, ?bool $active = true, int $limit = 10): array
    {
        $cacheKey = $this->cacheKey("product_{$productId}_" . ($active === null ? 'all' : ($active ? 'active' : 'inactive')));
        
        return $this->cached($cacheKey, function() use ($productId, $active, $limit) {
            $builder = $this->builder();
            $builder->where('product_id', $productId)
                    ->where('deleted_at', null);
            
            if ($active !== null) {
                $builder->where('active', $active ? 1 : 0);
            }
            
            return $builder->orderBy('price', 'ASC')
                           ->orderBy('rating', 'DESC')
                           ->limit($limit)
                           ->get()
                           ->getResult($this->returnType);
        }, 1800); // 30 minutes cache
    }

    /**
     * Find active links for a product with marketplace details
     * Used for product comparison table
     * 
     * @param int $productId
     * @return Link[]
     */
    public function findActiveByProduct(int $productId): array
    {
        // This method intentionally not cached because it's called from cached findWithLinks
        $builder = $this->builder();
        
        return $builder->select('links.*')
                       ->join('marketplaces', 'marketplaces.id = links.marketplace_id', 'left')
                       ->where('links.product_id', $productId)
                       ->where('links.active', 1)
                       ->where('links.deleted_at', null)
                       ->where('marketplaces.deleted_at', null)
                       ->orderBy('links.price', 'ASC')
                       ->orderBy('links.rating', 'DESC')
                       ->get()
                       ->getResult($this->returnType);
    }

    /**
     * Increment click count for a link
     * Uses direct SQL to avoid updating timestamps
     * Clears relevant caches
     * 
     * @param int $linkId
     * @return bool
     */
    public function incrementClicks(int $linkId): bool
    {
        // Get product_id for cache clearing
        $link = $this->findActiveById($linkId);
        if (!$link) {
            return false;
        }
        
        // Clear caches that include this link
        $this->clearLinkCaches($link->getProductId(), $linkId);
        
        // Direct SQL to avoid updating timestamps
        $sql = "UPDATE {$this->table} SET clicks = clicks + 1 WHERE id = ?";
        $result = $this->db->query($sql, [$linkId]);
        
        // Also update last_validation timestamp (click indicates link is working)
        if ($result) {
            $this->update($linkId, [
                'last_validation' => date('Y-m-d H:i:s')
            ]);
        }
        
        return $result;
    }

    /**
     * Update link price with timestamp tracking
     * Only updates if price actually changed
     * 
     * @param int $linkId
     * @param string $newPrice Must be in format 1234.56
     * @return bool
     */
    public function updatePrice(int $linkId, string $newPrice): bool
    {
        // Validate price format
        if (!preg_match('/^\d+\.\d{2}$/', $newPrice)) {
            log_message('error', "Invalid price format for link {$linkId}: {$newPrice}");
            return false;
        }
        
        $link = $this->findActiveById($linkId);
        if (!$link) {
            return false;
        }
        
        // Check if price actually changed
        if ($link->getPrice() === $newPrice) {
            return true; // No change needed
        }
        
        // Get product_id for cache clearing
        $productId = $link->getProductId();
        
        // Clear caches
        $this->clearLinkCaches($productId, $linkId);
        
        // Update with new price and timestamp
        return $this->update($linkId, [
            'price' => $newPrice,
            'last_price_update' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Find links that need validation
     * Business rule: validate every 14 days
     * 
     * @param string $type 'validation' or 'price' or 'both'
     * @param int $limit
     * @return Link[]
     */
    public function findExpired(string $type = 'validation', int $limit = 50): array
    {
        $builder = $this->builder();
        $builder->where('active', 1)
                ->where('deleted_at', null);
        
        $now = date('Y-m-d H:i:s');
        
        if ($type === 'validation' || $type === 'both') {
            // Validation needed if last_validation is NULL or older than 14 days
            $builder->groupStart()
                    ->where('last_validation IS NULL')
                    ->orWhere("last_validation <= DATE_SUB('{$now}', INTERVAL 14 DAY)")
                    ->groupEnd();
        }
        
        if ($type === 'price' || $type === 'both') {
            // Price update needed if last_price_update is NULL or older than 7 days
            $builder->groupStart()
                    ->where('last_price_update IS NULL')
                    ->orWhere("last_price_update <= DATE_SUB('{$now}', INTERVAL 7 DAY)")
                    ->groupEnd();
        }
        
        return $builder->limit($limit)
                       ->orderBy('last_validation', 'ASC')
                       ->orderBy('last_price_update', 'ASC')
                       ->get()
                       ->getResult($this->returnType);
    }

    /**
     * Get click statistics for analytics
     * Returns aggregated data for dashboard
     * 
     * @param string $period 'day', 'week', 'month', or 'all'
     * @param int|null $productId Filter by product
     * @param int|null $marketplaceId Filter by marketplace
     * @return array
     */
    public function getClickStats(string $period = 'month', ?int $productId = null, ?int $marketplaceId = null): array
    {
        $cacheKey = $this->cacheKey("click_stats_{$period}_{$productId}_{$marketplaceId}");
        
        return $this->cached($cacheKey, function() use ($period, $productId, $marketplaceId) {
            $builder = $this->builder();
            
            // Select aggregated data
            $builder->select([
                'COUNT(*) as total_links',
                'SUM(clicks) as total_clicks',
                'SUM(affiliate_revenue) as total_revenue',
                'AVG(clicks) as avg_clicks_per_link',
                'MAX(clicks) as max_clicks',
                'MIN(clicks) as min_clicks',
            ]);
            
            // Apply filters
            $builder->where('active', 1)
                    ->where('deleted_at', null);
            
            if ($productId) {
                $builder->where('product_id', $productId);
            }
            
            if ($marketplaceId) {
                $builder->where('marketplace_id', $marketplaceId);
            }
            
            // Apply time period filter based on last_validation
            if ($period !== 'all') {
                $dateField = 'last_validation';
                $intervals = [
                    'day' => '-1 day',
                    'week' => '-1 week',
                    'month' => '-1 month',
                ];
                
                if (isset($intervals[$period])) {
                    $builder->where("{$dateField} >= DATE_SUB(NOW(), INTERVAL 1 {$period})");
                }
            }
            
            $result = $builder->get()->getRowArray();
            
            // Format the result
            if (!$result) {
                return [
                    'total_links' => 0,
                    'total_clicks' => 0,
                    'total_revenue' => '0.00',
                    'avg_clicks_per_link' => 0,
                    'max_clicks' => 0,
                    'min_clicks' => 0,
                    'revenue_per_click' => '0.00',
                ];
            }
            
            // Calculate revenue per click
            $totalClicks = (int) $result['total_clicks'];
            $totalRevenue = (float) $result['total_revenue'];
            $revenuePerClick = $totalClicks > 0 ? $totalRevenue / $totalClicks : 0;
            
            return [
                'total_links' => (int) $result['total_links'],
                'total_clicks' => $totalClicks,
                'total_revenue' => number_format($totalRevenue, 2, '.', ''),
                'avg_clicks_per_link' => round((float) $result['avg_clicks_per_link'], 2),
                'max_clicks' => (int) $result['max_clicks'],
                'min_clicks' => (int) $result['min_clicks'],
                'revenue_per_click' => number_format($revenuePerClick, 2, '.', ''),
            ];
        }, 300); // 5 minutes cache for stats
    }

    // ==================== HELPER METHODS ====================

    /**
     * Clear all caches related to a link
     * 
     * @param int $productId
     * @param int $linkId
     * @return void
     */
    private function clearLinkCaches(int $productId, int $linkId): void
    {
        // Clear link-specific caches
        $cacheKeys = [
            "product_{$productId}_all",
            "product_{$productId}_active",
            "product_{$productId}_inactive",
        ];
        
        foreach ($cacheKeys as $key) {
            $this->clearCache($key);
        }
        
        // Also clear product model caches (since product has links)
        $productModel = model(ProductModel::class);
        $productModel->clearCache($productModel->cacheKey("with_links_{$productId}_active"));
        $productModel->clearCache($productModel->cacheKey("with_links_{$productId}_all"));
    }

    /**
     * Add affiliate revenue to a link
     * Used when affiliate commission is confirmed
     * 
     * @param int $linkId
     * @param string $amount Decimal amount to add
     * @return bool
     */
    public function addAffiliateRevenue(int $linkId, string $amount): bool
    {
        // Validate amount format
        if (!preg_match('/^\d+\.\d{2}$/', $amount)) {
            log_message('error', "Invalid revenue amount format for link {$linkId}: {$amount}");
            return false;
        }
        
        $link = $this->findActiveById($linkId);
        if (!$link) {
            return false;
        }
        
        // Get product_id for cache clearing
        $productId = $link->getProductId();
        
        // Clear caches
        $this->clearLinkCaches($productId, $linkId);
        
        // Use direct SQL to avoid float precision issues
        $sql = "UPDATE {$this->table} SET affiliate_revenue = affiliate_revenue + ? WHERE id = ?";
        return $this->db->query($sql, [(float) $amount, $linkId]);
    }

    /**
     * Mark link as validated (updates last_validation timestamp)
     * 
     * @param int $linkId
     * @return bool
     */
    public function markAsValidated(int $linkId): bool
    {
        $link = $this->findActiveById($linkId);
        if (!$link) {
            return false;
        }
        
        $productId = $link->getProductId();
        $this->clearLinkCaches($productId, $linkId);
        
        return $this->update($linkId, [
            'last_validation' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Update link active status
     * 
     * @param int $linkId
     * @param bool $active
     * @return bool
     */
    public function setActiveStatus(int $linkId, bool $active): bool
    {
        $link = $this->findActiveById($linkId);
        if (!$link) {
            return false;
        }
        
        $productId = $link->getProductId();
        $this->clearLinkCaches($productId, $linkId);
        
        return $this->update($linkId, ['active' => $active ? 1 : 0]);
    }

    /**
     * Find links by marketplace
     * 
     * @param int $marketplaceId
     * @param bool $activeOnly
     * @param int $limit
     * @return Link[]
     */
    public function findByMarketplace(int $marketplaceId, bool $activeOnly = true, int $limit = 50): array
    {
        $cacheKey = $this->cacheKey("marketplace_{$marketplaceId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($marketplaceId, $activeOnly, $limit) {
            $builder = $this->builder();
            $builder->where('marketplace_id', $marketplaceId)
                    ->where('deleted_at', null);
            
            if ($activeOnly) {
                $builder->where('active', 1);
            }
            
            return $builder->orderBy('clicks', 'DESC')
                           ->limit($limit)
                           ->get()
                           ->getResult($this->returnType);
        }, 1800); // 30 minutes cache
    }

    /**
     * Get top performing links by clicks or revenue
     * 
     * @param string $by 'clicks' or 'revenue'
     * @param int $limit
     * @return Link[]
     */
    public function getTopPerformers(string $by = 'clicks', int $limit = 10): array
    {
        $cacheKey = $this->cacheKey("top_{$by}_{$limit}");
        
        return $this->cached($cacheKey, function() use ($by, $limit) {
            $builder = $this->builder();
            $builder->where('active', 1)
                    ->where('deleted_at', null);
            
            $orderBy = $by === 'revenue' ? 'affiliate_revenue' : 'clicks';
            
            return $builder->orderBy($orderBy, 'DESC')
                           ->limit($limit)
                           ->get()
                           ->getResult($this->returnType);
        }, 300); // 5 minutes cache for top performers
    }

    /**
     * Count active links for a product
     * Business rule: minimum 3 marketplace links per product
     * 
     * @param int $productId
     * @return int
     */
    public function countActiveByProduct(int $productId): int
    {
        $cacheKey = $this->cacheKey("count_active_product_{$productId}");
        
        return $this->cached($cacheKey, function() use ($productId) {
            return $this->where('product_id', $productId)
                       ->where('active', 1)
                       ->where('deleted_at', null)
                       ->countAllResults();
        }, 3600); // 60 minutes cache
    }
}