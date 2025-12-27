<?php

namespace App\Models;

use App\Entities\Link;
use CodeIgniter\Database\BaseBuilder;

/**
 * Link Model - SQL Encapsulator for Link Entity
 * 
 * Layer 2: Pure Data Gateway (0% Business Logic)
 * Implements "Transient Input, Persistent Revenue" database operations
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
     * Return type for hydration
     * MUST be set to Link Entity (Type Safety)
     * 
     * @var string
     */
    protected $returnType = Link::class;

    /**
     * Use soft deletes
     * 
     * @var bool
     */
    protected $useSoftDeletes = true;

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
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'product_id' => 'required|integer',
        'marketplace_id' => 'required|integer',
        'store_name' => 'required|max_length[255]',
        'price' => 'required|decimal',
        'url' => 'permit_empty|valid_url|max_length[500]',
        'rating' => 'permit_empty|decimal',
        'active' => 'permit_empty|in_list[0,1]',
        'sold_count' => 'permit_empty|integer',
        'clicks' => 'permit_empty|integer',
        'affiliate_revenue' => 'permit_empty|decimal',
        'marketplace_badge_id' => 'permit_empty|integer',
    ];

    /**
     * Validation messages
     * 
     * @var array
     */
    protected $validationMessages = [
        'product_id' => [
            'required' => 'Product ID is required',
            'integer' => 'Product ID must be an integer',
        ],
        'marketplace_id' => [
            'required' => 'Marketplace ID is required',
            'integer' => 'Marketplace ID must be an integer',
        ],
        'store_name' => [
            'required' => 'Store name is required',
            'max_length' => 'Store name cannot exceed 255 characters',
        ],
        'price' => [
            'required' => 'Price is required',
            'decimal' => 'Price must be a valid decimal',
        ],
        'url' => [
            'valid_url' => 'URL must be a valid URL',
            'max_length' => 'URL cannot exceed 500 characters',
        ],
        'rating' => [
            'decimal' => 'Rating must be a valid decimal',
        ],
        'active' => [
            'in_list' => 'Active must be either 0 or 1',
        ],
        'sold_count' => [
            'integer' => 'Sold count must be an integer',
        ],
        'clicks' => [
            'integer' => 'Clicks must be an integer',
        ],
        'affiliate_revenue' => [
            'decimal' => 'Affiliate revenue must be a valid decimal',
        ],
        'marketplace_badge_id' => [
            'integer' => 'Marketplace badge ID must be an integer',
        ],
    ];

    // ============================================
    // QUERY SCOPES (Pure SQL Building - 0% Business Logic)
    // ============================================

    /**
     * Scope: Active links only
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeActive(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where('active', 1)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links by product ID
     * 
     * @param BaseBuilder $builder
     * @param int $productId
     * @return BaseBuilder
     */
    public function scopeByProduct(BaseBuilder $builder, int $productId): BaseBuilder
    {
        return $builder->where('product_id', $productId)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links by marketplace ID
     * 
     * @param BaseBuilder $builder
     * @param int $marketplaceId
     * @return BaseBuilder
     */
    public function scopeByMarketplace(BaseBuilder $builder, int $marketplaceId): BaseBuilder
    {
        return $builder->where('marketplace_id', $marketplaceId)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links that need price update (older than 24 hours)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeNeedsPriceUpdate(BaseBuilder $builder): BaseBuilder
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-24 hours'));
        return $builder->groupStart()
                      ->where('last_price_update <', $threshold)
                      ->orWhere('last_price_update IS NULL')
                      ->groupEnd()
                      ->where('active', 1)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links that need validation (older than 48 hours)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeNeedsValidation(BaseBuilder $builder): BaseBuilder
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-48 hours'));
        return $builder->groupStart()
                      ->where('last_validation <', $threshold)
                      ->orWhere('last_validation IS NULL')
                      ->groupEnd()
                      ->where('active', 1)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links with affiliate revenue
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeWithRevenue(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where('affiliate_revenue >', 0)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links by price range
     * 
     * @param BaseBuilder $builder
     * @param float $min
     * @param float $max
     * @return BaseBuilder
     */
    public function scopePriceBetween(BaseBuilder $builder, float $min, float $max): BaseBuilder
    {
        return $builder->where('price >=', $min)
                      ->where('price <=', $max)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links sorted by best selling
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeBestSelling(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('sold_count', 'DESC')
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links sorted by highest revenue
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeHighestRevenue(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('affiliate_revenue', 'DESC')
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Links with marketplace badge
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeWithBadge(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where('marketplace_badge_id IS NOT NULL')
                      ->where($this->deletedField, null);
    }

    // ============================================
    // FINDER METHODS (Return Fully Hydrated Entities)
    // ============================================

    /**
     * Find active link by ID with product and marketplace relations
     * 
     * @param int $id
     * @return Link|null
     */
    public function findActiveWithRelations(int $id): ?Link
    {
        $result = $this->select('links.*, products.name as product_name, marketplaces.name as marketplace_name')
                      ->join('products', 'products.id = links.product_id')
                      ->join('marketplaces', 'marketplaces.id = links.marketplace_id')
                      ->where('links.id', $id)
                      ->where('links.' . $this->deletedField, null)
                      ->first();

        return $result instanceof Link ? $result : null;
    }

    /**
     * Find all active links by product ID
     * 
     * @param int $productId
     * @return array<Link>
     */
    public function findByProductId(int $productId): array
    {
        $result = $this->scopeByProduct($this->builder(), $productId)
                      ->orderBy('price', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Link);
    }

    /**
     * Find cheapest active link for a product
     * 
     * @param int $productId
     * @return Link|null
     */
    public function findCheapestForProduct(int $productId): ?Link
    {
        $result = $this->scopeByProduct($this->builder(), $productId)
                      ->where('active', 1)
                      ->orderBy('price', 'ASC')
                      ->first();

        return $result instanceof Link ? $result : null;
    }

    /**
     * Find links that need price updates (batch processing)
     * 
     * @param int $limit
     * @return array<Link>
     */
    public function findNeedingPriceUpdate(int $limit = 100): array
    {
        $result = $this->scopeNeedsPriceUpdate($this->builder())
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Link);
    }

    /**
     * Find links that need validation (batch processing)
     * 
     * @param int $limit
     * @return array<Link>
     */
    public function findNeedingValidation(int $limit = 100): array
    {
        $result = $this->scopeNeedsValidation($this->builder())
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Link);
    }

    /**
     * Find top performing links by revenue
     * 
     * @param int $limit
     * @return array<Link>
     */
    public function findTopPerforming(int $limit = 10): array
    {
        $result = $this->scopeHighestRevenue($this->builder())
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Link);
    }

    // ============================================
    // BATCH OPERATIONS (Pure SQL - No Business Logic)
    // ============================================

    /**
     * Bulk update price and timestamp
     * 
     * @param array<int> $linkIds
     * @param string $newPrice
     * @return int Affected rows
     */
    public function bulkUpdatePrice(array $linkIds, string $newPrice): int
    {
        if (empty($linkIds)) {
            return 0;
        }

        $data = [
            'price' => $newPrice,
            'last_price_update' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($linkIds, $data);
    }

    /**
     * Bulk update validation timestamp
     * 
     * @param array<int> $linkIds
     * @return int Affected rows
     */
    public function bulkMarkValidated(array $linkIds): int
    {
        if (empty($linkIds)) {
            return 0;
        }

        $data = [
            'last_validation' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($linkIds, $data);
    }

    /**
     * Bulk increment clicks
     * 
     * @param array<int> $linkIds
     * @param int $increment
     * @return int Affected rows
     */
    public function bulkIncrementClicks(array $linkIds, int $increment = 1): int
    {
        if (empty($linkIds)) {
            return 0;
        }

        $builder = $this->builder();
        $builder->whereIn($this->primaryKey, $linkIds)
                ->set('clicks', 'clicks + ' . $increment, false)
                ->set('updated_at', date('Y-m-d H:i:s'));

        return $builder->update() ? count($linkIds) : 0;
    }

    /**
     * Bulk increment sold count
     * 
     * @param array<int> $linkIds
     * @param int $increment
     * @return int Affected rows
     */
    public function bulkIncrementSoldCount(array $linkIds, int $increment = 1): int
    {
        if (empty($linkIds)) {
            return 0;
        }

        $builder = $this->builder();
        $builder->whereIn($this->primaryKey, $linkIds)
                ->set('sold_count', 'sold_count + ' . $increment, false)
                ->set('updated_at', date('Y-m-d H:i:s'));

        return $builder->update() ? count($linkIds) : 0;
    }

    /**
     * Update affiliate revenue for a link
     * Pure SQL operation - business logic handled in Service layer
     * 
     * @param int $linkId
     * @param string $revenue Rupiah amount
     * @return bool
     */
    public function updateAffiliateRevenue(int $linkId, string $revenue): bool
    {
        $data = [
            'affiliate_revenue' => $revenue,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($linkId, $data);
    }

    // ============================================
    // AGGREGATE QUERIES (Pure SQL Calculations)
    // ============================================

    /**
     * Get total revenue for a product across all links
     * 
     * @param int $productId
     * @return string Total revenue
     */
    public function getTotalRevenueForProduct(int $productId): string
    {
        $result = $this->selectSum('affiliate_revenue', 'total_revenue')
                      ->where('product_id', $productId)
                      ->where($this->deletedField, null)
                      ->first();

        return $result->total_revenue ?? '0.00';
    }

    /**
     * Get total clicks for a product across all links
     * 
     * @param int $productId
     * @return int Total clicks
     */
    public function getTotalClicksForProduct(int $productId): int
    {
        $result = $this->selectSum('clicks', 'total_clicks')
                      ->where('product_id', $productId)
                      ->where($this->deletedField, null)
                      ->first();

        return (int) ($result->total_clicks ?? 0);
    }

    /**
     * Get average price for a product across all active links
     * 
     * @param int $productId
     * @return string Average price
     */
    public function getAveragePriceForProduct(int $productId): string
    {
        $result = $this->selectAvg('price', 'avg_price')
                      ->where('product_id', $productId)
                      ->where('active', 1)
                      ->where($this->deletedField, null)
                      ->first();

        return number_format((float) ($result->avg_price ?? 0), 2, '.', '');
    }

    /**
     * Get marketplace distribution for a product
     * 
     * @param int $productId
     * @return array Marketplace counts
     */
    public function getMarketplaceDistribution(int $productId): array
    {
        return $this->select('marketplace_id, COUNT(*) as count')
                   ->where('product_id', $productId)
                   ->where($this->deletedField, null)
                   ->groupBy('marketplace_id')
                   ->findAll();
    }

    // ============================================
    // UTILITY METHODS
    // ============================================

    /**
     * Check if link exists and is active
     * 
     * @param int $linkId
     * @return bool
     */
    public function existsAndActive(int $linkId): bool
    {
        $count = $this->where('id', $linkId)
                     ->where('active', 1)
                     ->where($this->deletedField, null)
                     ->countAllResults();

        return $count > 0;
    }

    /**
     * Get count of active links for a product
     * 
     * @param int $productId
     * @return int
     */
    public function countActiveForProduct(int $productId): int
    {
        return $this->scopeByProduct($this->builder(), $productId)
                   ->where('active', 1)
                   ->countAllResults();
    }

    /**
     * Validate price format (helper for input validation)
     * 
     * @param string $price
     * @return bool
     */
    public function isValidPriceFormat(string $price): bool
    {
        return (bool) preg_match('/^\d+(\.\d{2})?$/', $price);
    }

    /**
     * Custom validation for URL format
     * 
     * @param string|null $url
     * @return bool
     */
    public function isValidUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return true; // Empty is allowed
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}