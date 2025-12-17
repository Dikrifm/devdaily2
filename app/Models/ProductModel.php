<?php

namespace App\Models;

use App\Entities\Product;
use App\Enums\ProductStatus;

/**
 * Product Model
 * 
 * Core business model for product management with MVP approach.
 * Limited to 8 main methods for 300 premium products.
 * 
 * @package App\Models
 */
class ProductModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'products';

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
    protected $returnType = Product::class;

    /**
     * Allowed fields for mass assignment
     * 
     * @var array
     */
    protected $allowedFields = [
        'category_id',
        'slug',
        'image',
        'name',
        'description',
        'market_price',
        'view_count',
        'image_path',
        'image_source_type',
        'status',
        'published_at',
        'verified_at',
        'verified_by',
        'last_price_check',
        'last_link_check',
    ];

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'name'     => 'required|min_length[3]|max_length[255]',
        'slug'     => 'required|alpha_dash|max_length[255]|is_unique[products.slug,id,{id}]',
        'category_id' => 'permit_empty|integer',
        'market_price' => 'required|decimal',
        'status'   => 'required|in_list[draft,pending_verification,verified,published,archived]',
    ];

    /**
     * Validation rules for update
     * 
     * @var array
     */
    protected $validationRulesUpdate = [
        'name'     => 'permit_empty|min_length[3]|max_length[255]',
        'slug'     => 'permit_empty|alpha_dash|max_length[255]|is_unique[products.slug,id,{id}]',
        'market_price' => 'permit_empty|decimal',
        'status'   => 'permit_empty|in_list[draft,pending_verification,verified,published,archived]',
    ];

    /**
     * Default ordering for queries
     * 
     * @var array
     */
    protected $orderBy = [
        'created_at' => 'DESC'
    ];

    // ==================== CORE BUSINESS METHODS (8 METHODS) ====================

    /**
     * Find published products for public display
     * Cached for 60 minutes for performance
     * 
     * @param int $limit
     * @param int $offset
     * @return Product[]
     */
    public function findPublished(int $limit = 20, int $offset = 0): array
    {
        $cacheKey = $this->cacheKey("published_{$limit}_{$offset}");
        
        return $this->cached($cacheKey, function() use ($limit, $offset) {
            return $this->where('status', ProductStatus::PUBLISHED->value)
                       ->where('deleted_at', null)
                       ->orderBy('published_at', 'DESC')
                       ->orderBy('created_at', 'DESC')
                       ->limit($limit, $offset)
                       ->findAll();
        }, 3600); // 60 minutes cache for public content
    }

    /**
     * Find product by ID or slug (flexible lookup)
     * For public display, only returns published products
     * For admin, can return any status
     * 
     * @param int|string $identifier ID or slug
     * @param bool $adminMode If true, returns any status (for admin)
     * @return Product|null
     */
    public function findByIdOrSlug($identifier, bool $adminMode = false): ?Product
    {
        $cacheKey = $this->cacheKey("lookup_{$identifier}_" . ($adminMode ? 'admin' : 'public'));
        
        return $this->cached($cacheKey, function() use ($identifier, $adminMode) {
            $builder = $this->builder();
            
            // Determine if identifier is ID or slug
            if (is_numeric($identifier)) {
                $builder->where('id', (int) $identifier);
            } else {
                $builder->where('slug', $identifier);
            }
            
            // For public mode, only show published and non-deleted
            if (!$adminMode) {
                $builder->where('status', ProductStatus::PUBLISHED->value)
                        ->where('deleted_at', null);
            } else {
                $builder->where('deleted_at', null); // Admin can see non-deleted only
            }
            
            $result = $builder->get()->getFirstRow($this->returnType);
            
            return $result instanceof Product ? $result : null;
        }, $adminMode ? 60 : 3600); // Shorter cache for admin
    }

    /**
     * Find product with its marketplace links (eager loading)
     * Manual join for MVP - no complex ORM
     * 
     * @param int $productId
     * @param bool $activeOnly Only active links
     * @return Product|null
     */
    public function findWithLinks(int $productId, bool $activeOnly = true): ?Product
    {
        $cacheKey = $this->cacheKey("with_links_{$productId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($productId, $activeOnly) {
            // First get the product
            $product = $this->findActiveById($productId);
            
            if (!$product) {
                return null;
            }
            
            // Manually load links using LinkModel
            $linkModel = model(LinkModel::class);
            $links = $linkModel->findByProduct($productId, $activeOnly);
            
            // Attach links to product entity
            // Note: We'll use a simple property assignment since entities are mutable in setters
            // In a more complex system, we'd use a proper relation system
            $product->links = $links;
            
            return $product;
        }, 1800); // 30 minutes cache
    }

    /**
     * Increment product view count
     * Uses direct update to avoid updating timestamps
     * 
     * @param int $productId
     * @return bool
     */
    public function incrementViewCount(int $productId): bool
    {
        // Clear cache for this product
        $this->clearCache("lookup_{$productId}_public");
        $this->clearCache("lookup_{$productId}_admin");
        $this->clearCache("with_links_{$productId}_active");
        $this->clearCache("with_links_{$productId}_all");
        
        // Direct SQL to avoid updating updated_at
        $sql = "UPDATE {$this->table} SET view_count = view_count + 1 WHERE id = ?";
        return $this->db->query($sql, [$productId]);
    }

    /**
     * Update product status with validation
     * Ensures valid status transitions based on business rules
     * 
     * @param int $productId
     * @param ProductStatus $newStatus
     * @param int|null $verifiedBy Admin ID for verification
     * @return bool
     */
    public function updateStatus(int $productId, ProductStatus $newStatus, ?int $verifiedBy = null): bool
    {
        // Get current product to validate transition
        $product = $this->findActiveById($productId);
        if (!$product) {
            return false;
        }
        
        // Validate status transition
        $currentStatus = ProductStatus::from($product->status);
        if (!$currentStatus->canTransitionTo($newStatus)) {
            log_message('error', "Invalid status transition from {$currentStatus->value} to {$newStatus->value} for product {$productId}");
            return false;
        }
        
        // Prepare update data
        $updateData = ['status' => $newStatus->value];
        
        // Set timestamps based on status
        if ($newStatus === ProductStatus::PUBLISHED && $product->published_at === null) {
            $updateData['published_at'] = date('Y-m-d H:i:s');
        }
        
        if ($newStatus === ProductStatus::VERIFIED) {
            $updateData['verified_at'] = date('Y-m-d H:i:s');
            $updateData['verified_by'] = $verifiedBy;
        }
        
        // Clear relevant caches
        $this->clearProductCaches($productId);
        
        return $this->update($productId, $updateData);
    }

    /**
     * Find products that need maintenance updates
     * Business rules: price update every 7 days, link validation every 14 days
     * 
     * @param string $type 'price' or 'link' or 'both'
     * @param int $limit
     * @return Product[]
     */
    public function findNeedsUpdate(string $type = 'both', int $limit = 50): array
    {
        $builder = $this->builder();
        $builder->where('status', ProductStatus::PUBLISHED->value)
                ->where('deleted_at', null);
        
        $now = date('Y-m-d H:i:s');
        
        if ($type === 'price' || $type === 'both') {
            // Price check needed if last_price_check is NULL or older than 7 days
            $builder->groupStart()
                    ->where('last_price_check IS NULL')
                    ->orWhere("last_price_check <= DATE_SUB('{$now}', INTERVAL 7 DAY)")
                    ->groupEnd();
        }
        
        if ($type === 'link' || $type === 'both') {
            // Link check needed if last_link_check is NULL or older than 14 days
            $builder->groupStart()
                    ->where('last_link_check IS NULL')
                    ->orWhere("last_link_check <= DATE_SUB('{$now}', INTERVAL 14 DAY)")
                    ->groupEnd();
        }
        
        return $builder->limit($limit)
                       ->orderBy('last_price_check', 'ASC')
                       ->orderBy('last_link_check', 'ASC')
                       ->get()
                       ->getResult($this->returnType);
    }

    /**
     * Search products by keyword (public search)
     * Searches in name and description
     * 
     * @param string $keyword
     * @param int $limit
     * @return Product[]
     */
    public function searchByKeyword(string $keyword, int $limit = 20): array
    {
        if (empty($keyword)) {
            return [];
        }
        
        $cacheKey = $this->cacheKey("search_" . md5($keyword) . "_$limit");
        
        return $this->cached($cacheKey, function() use ($keyword, $limit) {
            $builder = $this->builder();
            $builder->where('status', ProductStatus::PUBLISHED->value)
                    ->where('deleted_at', null)
                    ->groupStart()
                    ->like('name', $keyword)
                    ->orLike('description', $keyword)
                    ->groupEnd()
                    ->orderBy('published_at', 'DESC')
                    ->limit($limit);
            
            return $builder->get()->getResult($this->returnType);
        }, 1800); // 30 minutes cache
    }

    /**
     * Get popular products based on view count
     * 
     * @param int $limit
     * @param string $period 'all', 'week', 'month'
     * @return Product[]
     */
    public function getPopular(int $limit = 10, string $period = 'all'): array
    {
        $cacheKey = $this->cacheKey("popular_{$period}_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit, $period) {
            $builder = $this->builder();
            $builder->where('status', ProductStatus::PUBLISHED->value)
                    ->where('deleted_at', null)
                    ->orderBy('view_count', 'DESC')
                    ->orderBy('published_at', 'DESC')
                    ->limit($limit);
            
            // Apply time period filter if needed
            if ($period === 'week') {
                $builder->where('published_at >=', date('Y-m-d H:i:s', strtotime('-1 week')));
            } elseif ($period === 'month') {
                $builder->where('published_at >=', date('Y-m-d H:i:s', strtotime('-1 month')));
            }
            
            return $builder->get()->getResult($this->returnType);
        }, 1800); // 30 minutes cache
    }

    // ==================== HELPER METHODS ====================

    /**
     * Clear all caches for a product
     * 
     * @param int $productId
     * @return void
     */
    private function clearProductCaches(int $productId): void
    {
        $cacheKeys = [
            "lookup_{$productId}_public",
            "lookup_{$productId}_admin",
            "with_links_{$productId}_active",
            "with_links_{$productId}_all",
        ];
        
        foreach ($cacheKeys as $key) {
            $this->clearCache($key);
        }
        
        // Also clear listing caches
        $this->clearCache($this->cacheKey('published_20_0'));
        $this->clearCache($this->cacheKey('popular_all_10'));
    }

    /**
     * Mark product price as checked
     * Updates last_price_check timestamp
     * 
     * @param int $productId
     * @return bool
     */
    public function markPriceChecked(int $productId): bool
    {
        $this->clearProductCaches($productId);
        return $this->update($productId, [
            'last_price_check' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Mark product links as checked
     * Updates last_link_check timestamp
     * 
     * @param int $productId
     * @return bool
     */
    public function markLinksChecked(int $productId): bool
    {
        $this->clearProductCaches($productId);
        return $this->update($productId, [
            'last_link_check' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Find products by category
     * 
     * @param int $categoryId
     * @param int $limit
     * @param int $offset
     * @return Product[]
     */
    public function findByCategory(int $categoryId, int $limit = 20, int $offset = 0): array
    {
        $cacheKey = $this->cacheKey("category_{$categoryId}_{$limit}_{$offset}");
        
        return $this->cached($cacheKey, function() use ($categoryId, $limit, $offset) {
            return $this->where('category_id', $categoryId)
                       ->where('status', ProductStatus::PUBLISHED->value)
                       ->where('deleted_at', null)
                       ->orderBy('published_at', 'DESC')
                       ->limit($limit, $offset)
                       ->findAll();
        }, 3600); // 60 minutes cache
    }

    /**
     * Count published products
     * 
     * @return int
     */
    public function countPublished(): int
    {
        $cacheKey = $this->cacheKey('count_published');
        
        return $this->cached($cacheKey, function() {
            return $this->where('status', ProductStatus::PUBLISHED->value)
                       ->where('deleted_at', null)
                       ->countAllResults();
        }, 3600); // 60 minutes cache
    }
}