<?php

namespace App\Models;

use App\Entities\Product;
use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;
use CodeIgniter\Database\BaseBuilder;

/**
 * Product Model - SQL Encapsulator for Product Entity
 * 
 * Layer 2: Pure Data Gateway (0% Business Logic)
 * Implements premium product curation with 300 product limit
 * Supports state machine transitions and manual curation workflow
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
     * Return type for hydration
     * MUST be set to Product Entity (Type Safety)
     * 
     * @var string
     */
    protected $returnType = Product::class;

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
        'category_id' => 'permit_empty|integer',
        'slug' => 'required|max_length[255]|regex_match[/^[a-z0-9\-]+$/]',
        'image' => 'permit_empty|max_length[500]|valid_url_strict',
        'name' => 'required|max_length[255]',
        'description' => 'permit_empty|max_length[2000]',
        'market_price' => 'required|decimal',
        'view_count' => 'permit_empty|integer',
        'image_path' => 'permit_empty|max_length[255]',
        'image_source_type' => 'required|in_list[' . ImageSourceType::valuesString() . ']',
        'status' => 'required|in_list[' . ProductStatus::valuesString() . ']',
        'published_at' => 'permit_empty|valid_date',
        'verified_at' => 'permit_empty|valid_date',
        'verified_by' => 'permit_empty|integer',
        'last_price_check' => 'permit_empty|valid_date',
        'last_link_check' => 'permit_empty|valid_date',
    ];

    /**
     * Validation messages
     * 
     * @var array
     */
    protected $validationMessages = [
        'category_id' => [
            'integer' => 'Category ID must be an integer',
        ],
        'slug' => [
            'required' => 'Product slug is required',
            'max_length' => 'Product slug cannot exceed 255 characters',
            'regex_match' => 'Product slug can only contain lowercase letters, numbers, and hyphens',
        ],
        'image' => [
            'max_length' => 'Image URL cannot exceed 500 characters',
            'valid_url_strict' => 'Image must be a valid URL',
        ],
        'name' => [
            'required' => 'Product name is required',
            'max_length' => 'Product name cannot exceed 255 characters',
        ],
        'description' => [
            'max_length' => 'Description cannot exceed 2000 characters',
        ],
        'market_price' => [
            'required' => 'Market price is required',
            'decimal' => 'Market price must be a valid decimal',
        ],
        'view_count' => [
            'integer' => 'View count must be an integer',
        ],
        'image_path' => [
            'max_length' => 'Image path cannot exceed 255 characters',
        ],
        'image_source_type' => [
            'required' => 'Image source type is required',
            'in_list' => 'Image source type must be one of: ' . ImageSourceType::valuesString(),
        ],
        'status' => [
            'required' => 'Product status is required',
            'in_list' => 'Product status must be one of: ' . ProductStatus::valuesString(),
        ],
        'published_at' => [
            'valid_date' => 'Published at must be a valid date',
        ],
        'verified_at' => [
            'valid_date' => 'Verified at must be a valid date',
        ],
        'verified_by' => [
            'integer' => 'Verified by must be an integer',
        ],
        'last_price_check' => [
            'valid_date' => 'Last price check must be a valid date',
        ],
        'last_link_check' => [
            'valid_date' => 'Last link check must be a valid date',
        ],
    ];

    /**
     * Custom validation rule for strict URL validation
     * 
     * @param string $str
     * @return bool
     */
    public function valid_url_strict(string $str): bool
    {
        if (empty($str)) {
            return true;
        }
        
        return filter_var($str, FILTER_VALIDATE_URL) !== false;
    }

    // ============================================
    // QUERY SCOPES (Pure SQL Building - 0% Business Logic)
    // ============================================

    /**
     * Scope: Active products (not deleted)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeActive(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where($this->deletedField, null);
    }

    /**
     * Scope: Published products only
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopePublished(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where('status', ProductStatus::PUBLISHED->value)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products by status
     * 
     * @param BaseBuilder $builder
     * @param ProductStatus $status
     * @return BaseBuilder
     */
    public function scopeByStatus(BaseBuilder $builder, ProductStatus $status): BaseBuilder
    {
        return $builder->where('status', $status->value)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products by category ID
     * 
     * @param BaseBuilder $builder
     * @param int $categoryId
     * @return BaseBuilder
     */
    public function scopeByCategory(BaseBuilder $builder, int $categoryId): BaseBuilder
    {
        return $builder->where('category_id', $categoryId)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products that need price updates (last check > 7 days or never)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeNeedsPriceUpdate(BaseBuilder $builder): BaseBuilder
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-7 days'));
        return $builder->groupStart()
                      ->where('last_price_check <', $threshold)
                      ->orWhere('last_price_check IS NULL')
                      ->groupEnd()
                      ->whereIn('status', [ProductStatus::PUBLISHED->value, ProductStatus::VERIFIED->value])
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products that need link validation (last check > 14 days or never)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeNeedsLinkValidation(BaseBuilder $builder): BaseBuilder
    {
        $threshold = date('Y-m-d H:i:s', strtotime('-14 days'));
        return $builder->groupStart()
                      ->where('last_link_check <', $threshold)
                      ->orWhere('last_link_check IS NULL')
                      ->groupEnd()
                      ->whereIn('status', [ProductStatus::PUBLISHED->value, ProductStatus::VERIFIED->value])
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products pending verification
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopePendingVerification(BaseBuilder $builder): BaseBuilder
    {
        return $this->scopeByStatus($builder, ProductStatus::PENDING_VERIFICATION);
    }

    /**
     * Scope: Verified products (not yet published)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeVerified(BaseBuilder $builder): BaseBuilder
    {
        return $this->scopeByStatus($builder, ProductStatus::VERIFIED);
    }

    /**
     * Scope: Draft products
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeDraft(BaseBuilder $builder): BaseBuilder
    {
        return $this->scopeByStatus($builder, ProductStatus::DRAFT);
    }

    /**
     * Scope: Archived products
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeArchived(BaseBuilder $builder): BaseBuilder
    {
        return $this->scopeByStatus($builder, ProductStatus::ARCHIVED);
    }

    /**
     * Scope: Products with uploaded images
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeWithUploadedImages(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where('image_source_type', ImageSourceType::UPLOAD->value)
                      ->where('image_path IS NOT NULL')
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products sorted by most viewed
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeMostViewed(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('view_count', 'DESC')
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products sorted by newest
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeNewest(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('created_at', 'DESC')
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products sorted by price (low to high)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopePriceLowToHigh(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('market_price', 'ASC')
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Products sorted by price (high to low)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopePriceHighToLow(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('market_price', 'DESC')
                      ->where($this->deletedField, null);
    }

    // ============================================
    // FINDER METHODS (Return Fully Hydrated Entities)
    // ============================================

    /**
     * Find published product by slug with category info
     * 
     * @param string $slug
     * @return Product|null
     */
    public function findPublishedBySlug(string $slug): ?Product
    {
        $result = $this->select('products.*, categories.name as category_name, categories.slug as category_slug')
                      ->join('categories', 'categories.id = products.category_id', 'left')
                      ->where('products.slug', $slug)
                      ->where('products.status', ProductStatus::PUBLISHED->value)
                      ->where('products.' . $this->deletedField, null)
                      ->first();

        return $result instanceof Product ? $result : null;
    }

    /**
     * Find product by ID with all relationships
     * 
     * @param int $id
     * @return Product|null
     */
    public function findWithRelations(int $id): ?Product
    {
        $result = $this->select('products.*, categories.name as category_name, categories.slug as category_slug')
                      ->join('categories', 'categories.id = products.category_id', 'left')
                      ->where('products.id', $id)
                      ->where('products.' . $this->deletedField, null)
                      ->first();

        return $result instanceof Product ? $result : null;
    }

    /**
     * Find product by ID with links
     * 
     * @param int $id
     * @return Product|null
     */
    public function findWithLinks(int $id): ?Product
    {
        // First get the product
        $product = $this->findWithRelations($id);
        if (!$product instanceof Product) {
            return null;
        }

        // Get links separately (could be optimized with join, but keeping separation of concerns)
        // Links will be hydrated by LinkRepository in Service layer
        return $product;
    }

    /**
     * Find all published products with pagination
     * 
     * @param int $perPage
     * @param int $page
     * @return array{products: array<Product>, pager: object}
     */
    public function findPublishedPaginated(int $perPage = 20, int $page = 1): array
    {
        $result = $this->scopePublished($this->builder())
                      ->orderBy('published_at', 'DESC')
                      ->paginate($perPage, 'default', $page);

        $products = array_filter($result, fn($item) => $item instanceof Product);
        $pager = $this->pager;

        return [
            'products' => $products,
            'pager' => $pager
        ];
    }

    /**
     * Find products by category with pagination
     * 
     * @param int $categoryId
     * @param int $perPage
     * @param int $page
     * @return array{products: array<Product>, pager: object}
     */
    public function findByCategoryPaginated(int $categoryId, int $perPage = 20, int $page = 1): array
    {
        $result = $this->scopeByCategory($this->builder(), $categoryId)
                      ->scopePublished($this->builder())
                      ->orderBy('published_at', 'DESC')
                      ->paginate($perPage, 'default', $page);

        $products = array_filter($result, fn($item) => $item instanceof Product);
        $pager = $this->pager;

        return [
            'products' => $products,
            'pager' => $pager
        ];
    }

    /**
     * Find products that need price updates (batch processing)
     * 
     * @param int $limit
     * @return array<Product>
     */
    public function findNeedingPriceUpdate(int $limit = 50): array
    {
        $result = $this->scopeNeedsPriceUpdate($this->builder())
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    /**
     * Find products that need link validation (batch processing)
     * 
     * @param int $limit
     * @return array<Product>
     */
    public function findNeedingLinkValidation(int $limit = 50): array
    {
        $result = $this->scopeNeedsLinkValidation($this->builder())
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    /**
     * Find products pending verification (for admin dashboard)
     * 
     * @param int $limit
     * @return array<Product>
     */
    public function findPendingVerification(int $limit = 100): array
    {
        $result = $this->scopePendingVerification($this->builder())
                      ->orderBy('created_at', 'ASC')
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    /**
     * Find verified products ready for publication
     * 
     * @param int $limit
     * @return array<Product>
     */
    public function findVerifiedForPublication(int $limit = 100): array
    {
        $result = $this->scopeVerified($this->builder())
                      ->orderBy('verified_at', 'ASC')
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    /**
     * Search products by name or description
     * 
     * @param string $query
     * @param int $limit
     * @return array<Product>
     */
    public function search(string $query, int $limit = 20): array
    {
        $result = $this->groupStart()
                      ->like('name', $query)
                      ->orLike('description', $query)
                      ->groupEnd()
                      ->scopePublished($this->builder())
                      ->orderBy('view_count', 'DESC')
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    /**
     * Find related products (same category)
     * 
     * @param int $productId
     * @param int $limit
     * @return array<Product>
     */
    public function findRelated(int $productId, int $limit = 5): array
    {
        // First get the product to know its category
        $product = $this->find($productId);
        if (!$product instanceof Product || !$product->getCategoryId()) {
            return [];
        }

        $result = $this->scopeByCategory($this->builder(), $product->getCategoryId())
                      ->where('products.id !=', $productId)
                      ->scopePublished($this->builder())
                      ->orderBy('view_count', 'DESC')
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    /**
     * Find featured products (most viewed published products)
     * 
     * @param int $limit
     * @return array<Product>
     */
    public function findFeatured(int $limit = 10): array
    {
        $result = $this->scopePublished($this->builder())
                      ->scopeMostViewed($this->builder())
                      ->limit($limit)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Product);
    }

    // ============================================
    // STATUS TRANSITION METHODS (Pure SQL)
    // ============================================

    /**
     * Update product status with timestamp management
     * 
     * @param int $productId
     * @param ProductStatus $status
     * @return bool
     */
    public function updateStatus(int $productId, ProductStatus $status): bool
    {
        $data = [
            'status' => $status->value,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Set appropriate timestamps based on status
        if ($status === ProductStatus::PUBLISHED) {
            $data['published_at'] = date('Y-m-d H:i:s');
        } elseif ($status === ProductStatus::VERIFIED) {
            $data['verified_at'] = date('Y-m-d H:i:s');
        }

        return $this->update($productId, $data);
    }

    /**
     * Verify product (set verified status, timestamp, and admin ID)
     * 
     * @param int $productId
     * @param int $adminId
     * @return bool
     */
    public function verify(int $productId, int $adminId): bool
    {
        $data = [
            'status' => ProductStatus::VERIFIED->value,
            'verified_at' => date('Y-m-d H:i:s'),
            'verified_by' => $adminId,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($productId, $data);
    }

    /**
     * Publish product (set published status and timestamp)
     * 
     * @param int $productId
     * @return bool
     */
    public function publish(int $productId): bool
    {
        $data = [
            'status' => ProductStatus::PUBLISHED->value,
            'published_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($productId, $data);
    }

    /**
     * Archive product (soft delete via status change)
     * 
     * @param int $productId
     * @return bool
     */
    public function archive(int $productId): bool
    {
        $data = [
            'status' => ProductStatus::ARCHIVED->value,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($productId, $data);
    }

    /**
     * Request verification (move from DRAFT to PENDING_VERIFICATION)
     * 
     * @param int $productId
     * @return bool
     */
    public function requestVerification(int $productId): bool
    {
        $data = [
            'status' => ProductStatus::PENDING_VERIFICATION->value,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($productId, $data);
    }

    // ============================================
    // BATCH OPERATIONS (Pure SQL - No Business Logic)
    // ============================================

    /**
     * Bulk update product statuses
     * 
     * @param array<int> $productIds
     * @param ProductStatus $status
     * @return int Affected rows
     */
    public function bulkUpdateStatus(array $productIds, ProductStatus $status): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $data = [
            'status' => $status->value,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // Set appropriate timestamps based on status
        if ($status === ProductStatus::PUBLISHED) {
            $data['published_at'] = date('Y-m-d H:i:s');
        } elseif ($status === ProductStatus::VERIFIED) {
            $data['verified_at'] = date('Y-m-d H:i:s');
        }

        return $this->bulkUpdate($productIds, $data);
    }

    /**
     * Bulk update price check timestamp
     * 
     * @param array<int> $productIds
     * @return int Affected rows
     */
    public function bulkMarkPriceChecked(array $productIds): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $data = [
            'last_price_check' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($productIds, $data);
    }

    /**
     * Bulk update link check timestamp
     * 
     * @param array<int> $productIds
     * @return int Affected rows
     */
    public function bulkMarkLinksChecked(array $productIds): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $data = [
            'last_link_check' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($productIds, $data);
    }

    /**
     * Bulk increment view counts
     * 
     * @param array<int> $productIds
     * @param int $increment
     * @return int Affected rows
     */
    public function bulkIncrementViews(array $productIds, int $increment = 1): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $builder = $this->builder();
        $builder->whereIn($this->primaryKey, $productIds)
                ->set('view_count', 'view_count + ' . $increment, false)
                ->set('updated_at', date('Y-m-d H:i:s'));

        return $builder->update() ? count($productIds) : 0;
    }

    /**
     * Bulk update categories
     * 
     * @param array<int> $productIds
     * @param int $categoryId
     * @return int Affected rows
     */
    public function bulkUpdateCategory(array $productIds, int $categoryId): int
    {
        if (empty($productIds)) {
            return 0;
        }

        $data = [
            'category_id' => $categoryId,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($productIds, $data);
    }

    // ============================================
    // AGGREGATE QUERIES (Pure SQL Calculations)
    // ============================================

    /**
     * Get total product count by status
     * 
     * @return array<string, int>
     */
    public function getCountByStatus(): array
    {
        $result = $this->select('status, COUNT(*) as count')
                      ->where($this->deletedField, null)
                      ->groupBy('status')
                      ->findAll();

        $counts = [];
        foreach (ProductStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }

        foreach ($result as $row) {
            $counts[$row->status] = (int) $row->count;
        }

        return $counts;
    }

    /**
     * Get total published product count
     * 
     * @return int
     */
    public function getPublishedCount(): int
    {
        return $this->scopePublished($this->builder())->countAllResults();
    }

    /**
     * Get total active product count (not deleted)
     * 
     * @return int
     */
    public function getTotalActiveCount(): int
    {
        return $this->where($this->deletedField, null)->countAllResults();
    }

    /**
     * Get average price of published products
     * 
     * @return string Average price
     */
    public function getAveragePublishedPrice(): string
    {
        $result = $this->selectAvg('market_price', 'avg_price')
                      ->scopePublished($this->builder())
                      ->first();

        return number_format((float) ($result->avg_price ?? 0), 2, '.', '');
    }

    /**
     * Get total views across all published products
     * 
     * @return int
     */
    public function getTotalPublishedViews(): int
    {
        $result = $this->selectSum('view_count', 'total_views')
                      ->scopePublished($this->builder())
                      ->first();

        return (int) ($result->total_views ?? 0);
    }

    /**
     * Get product count by category
     * 
     * @return array<int, int> [category_id => count]
     */
    public function getCountByCategory(): array
    {
        $result = $this->select('category_id, COUNT(*) as count')
                      ->where($this->deletedField, null)
                      ->where('category_id IS NOT NULL')
                      ->groupBy('category_id')
                      ->findAll();

        $counts = [];
        foreach ($result as $row) {
            $counts[(int) $row->category_id] = (int) $row->count;
        }

        return $counts;
    }

    // ============================================
    // VALIDATION & INTEGRITY METHODS
    // ============================================

    /**
     * Check if slug already exists (excluding current product)
     * 
     * @param string $slug
     * @param int|null $excludeId
     * @return bool
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->where('slug', $slug)
                       ->where($this->deletedField, null);

        if ($excludeId !== null) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Check if category exists and is active
     * 
     * @param int $categoryId
     * @return bool
     */
    public function isValidCategory(int $categoryId): bool
    {
        if ($categoryId === 0) {
            return true; // No category
        }

        $categoryModel = model(CategoryModel::class);
        $category = $categoryModel->findActiveById($categoryId);
        return $category !== null;
    }

    /**
     * Check if product can transition to new status
     * (Business logic validation is in Service layer, this is data check)
     * 
     * @param int $productId
     * @param ProductStatus $newStatus
     * @return bool
     */
    public function canTransitionTo(int $productId, ProductStatus $newStatus): bool
    {
        $product = $this->find($productId);
        if (!$product instanceof Product) {
            return false;
        }

        // Check if current status exists in ProductStatus enum
        $currentStatus = $product->getStatus();
        
        // Basic check: status exists and is not the same
        if ($currentStatus === $newStatus) {
            return false;
        }

        // More complex transition logic is in Entity/Service layer
        return true;
    }

    /**
     * Check if product has active links
     * 
     * @param int $productId
     * @return bool
     */
    public function hasActiveLinks(int $productId): bool
    {
        $linkModel = model(LinkModel::class);
        $count = $linkModel->where('product_id', $productId)
                          ->where('active', 1)
                          ->where($linkModel->deletedField, null)
                          ->countAllResults();

        return $count > 0;
    }

    /**
     * Get product limit status (for 300 product limit)
     * 
     * @return array{current: int, limit: int, remaining: int, reached: bool}
     */
    public function getLimitStatus(): array
    {
        $current = $this->getTotalActiveCount();
        $limit = 300;
        
        return [
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'reached' => $current >= $limit
        ];
    }

    // ============================================
    // UTILITY METHODS
    // ============================================

    /**
     * Generate unique slug from product name
     * 
     * @param string $name
     * @param int|null $excludeId
     * @return string
     */
    public function generateSlug(string $name, ?int $excludeId = null): string
    {
        $slug = url_title($name, '-', true);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Validate slug format
     * 
     * @param string $slug
     * @return bool
     */
    public function isValidSlugFormat(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9\-]+$/', $slug);
    }

    /**
     * Validate price format
     * 
     * @param string|float|int $price
     * @return bool
     */
    public function isValidPriceFormat($price): bool
    {
        $normalized = (string) $price;
        return (bool) preg_match('/^\d+(\.\d{2})?$/', $normalized);
    }

    /**
     * Increment view count for a product
     * 
     * @param int $productId
     * @param int $increment
     * @return bool
     */
    public function incrementViewCount(int $productId, int $increment = 1): bool
    {
        $builder = $this->builder();
        $builder->where('id', $productId)
                ->set('view_count', 'view_count + ' . $increment, false);

        return $builder->update() !== false;
    }

    /**
     * Update price check timestamp
     * 
     * @param int $productId
     * @return bool
     */
    public function updatePriceCheckTimestamp(int $productId): bool
    {
        $data = [
            'last_price_check' => date('Y-m-d H:i:s')
        ];

        return $this->updateIfChanged($productId, $data);
    }

    /**
     * Update link check timestamp
     * 
     * @param int $productId
     * @return bool
     */
    public function updateLinkCheckTimestamp(int $productId): bool
    {
        $data = [
            'last_link_check' => date('Y-m-d H:i:s')
        ];

        return $this->updateIfChanged($productId, $data);
    }
}