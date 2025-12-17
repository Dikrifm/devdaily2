<?php

namespace App\Repositories\Concrete;

use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Exceptions\ProductNotFoundException;
use App\Models\ProductModel;
use App\Services\CacheService;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * Product Repository Implementation
 * 
 * Concrete implementation of ProductRepositoryInterface using CodeIgniter 4 Model.
 * Handles data access for Product entities with caching and transaction support.
 * 
 * @package App\Repositories\Concrete
 */
class ProductRepository implements ProductRepositoryInterface
{
    /**
     * Product model instance
     * 
     * @var ProductModel
     */
    private ProductModel $model;

    /**
     * Cache service instance
     * 
     * @var CacheService
     */
    private CacheService $cache;

    /**
     * Database connection
     * 
     * @var ConnectionInterface
     */
    private ConnectionInterface $db;

    /**
     * Cache TTL for product data (60 minutes)
     * 
     * @var int
     */
    private int $cacheTtl = 3600;

    /**
     * ProductRepository constructor
     * 
     * @param ProductModel $model
     * @param CacheService $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        ProductModel $model,
        CacheService $cache,
        ConnectionInterface $db
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->db = $db;
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * Find product by ID
     * 
     * @param int $id Product ID
     * @param bool $withTrashed Include soft-deleted products
     * @return Product|null
     */
    public function find(int $id, bool $withTrashed = false): ?Product
    {
        $cacheKey = $this->getCacheKey("product_{$id}_" . ($withTrashed ? 'with_trashed' : 'active'));
        
        return $this->cache->remember($cacheKey, function() use ($id, $withTrashed) {
            if ($withTrashed) {
                return $this->model->withDeleted()->find($id);
            }
            
            return $this->model->findActiveById($id);
        }, $this->cacheTtl);
    }

    /**
     * Find product by slug
     * 
     * @param string $slug Product slug
     * @param bool $withTrashed Include soft-deleted products
     * @return Product|null
     */
    public function findBySlug(string $slug, bool $withTrashed = false): ?Product
    {
        $cacheKey = $this->getCacheKey("product_slug_{$slug}_" . ($withTrashed ? 'with_trashed' : 'active'));
        
        return $this->cache->remember($cacheKey, function() use ($slug, $withTrashed) {
            $builder = $this->model->builder();
            $builder->where('slug', $slug);
            
            if (!$withTrashed) {
                $builder->where('deleted_at', null);
            }
            
            $result = $builder->get()->getFirstRow($this->model->returnType);
            
            return $result instanceof Product ? $result : null;
        }, $this->cacheTtl);
    }

    /**
     * Find product by ID or slug (flexible lookup)
     * 
     * @param int|string $identifier ID or slug
     * @param bool $adminMode If true, returns any status (for admin)
     * @param bool $withTrashed Include soft-deleted products
     * @return Product|null
     */
    public function findByIdOrSlug($identifier, bool $adminMode = false, bool $withTrashed = false): ?Product
    {
        if (is_numeric($identifier)) {
            return $this->find((int) $identifier, $withTrashed);
        }
        
        return $this->findBySlug((string) $identifier, $withTrashed);
    }

    /**
     * Get all products
     * 
     * @param array $filters Filter criteria
     * @param array $sort Sorting criteria
     * @param int $limit Maximum results
     * @param int $offset Results offset
     * @param bool $withTrashed Include soft-deleted products
     * @return Product[]
     */
    public function findAll(
        array $filters = [],
        array $sort = [],
        int $limit = 0,
        int $offset = 0,
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKeyForFindAll($filters, $sort, $limit, $offset, $withTrashed);
        
        return $this->cache->remember($cacheKey, function() use ($filters, $sort, $limit, $offset, $withTrashed) {
            $builder = $this->model->builder();
            
            // Apply filters
            foreach ($filters as $field => $value) {
                if (is_array($value)) {
                    $builder->whereIn($field, $value);
                } else {
                    $builder->where($field, $value);
                }
            }
            
            // Apply soft delete filter
            if (!$withTrashed) {
                $builder->where('deleted_at', null);
            }
            
            // Apply sorting
            foreach ($sort as $field => $direction) {
                $builder->orderBy($field, $direction);
            }
            
            // Apply limit/offset
            if ($limit > 0) {
                $builder->limit($limit, $offset);
            }
            
            $results = $builder->get()->getResult($this->model->returnType);
            
            return $results ?? [];
        }, $this->cacheTtl);
    }

    /**
     * Save product (create or update)
     * 
     * @param Product $product Product entity
     * @return Product Saved product
     * @throws RuntimeException If save fails
     */
    public function save(Product $product): Product
    {
        $this->db->transStart();
        
        try {
            $isUpdate = !$product->isNew();
            
            // Prepare product for save (this may update timestamps)
            $product->prepareForSave($isUpdate);
            
            // Get array data from entity
            $data = $product->toArray();
            
            // Remove non-database fields
            unset(
                $data['id'],
                $data['created_at'],
                $data['updated_at'],
                $data['deleted_at'],
                $data['is_deleted'],
                $data['links'] // Remove relations
            );
            
            if ($isUpdate) {
                // Update existing product
                $success = $this->model->update($product->getId(), $data);
                
                if (!$success) {
                    throw new RuntimeException('Failed to update product');
                }
                
                // Get updated product
                $savedProduct = $this->model->find($product->getId());
            } else {
                // Insert new product
                $id = $this->model->insert($data);
                
                if (!$id) {
                    throw new RuntimeException('Failed to create product');
                }
                
                // Get newly created product
                $savedProduct = $this->model->find($id);
            }
            
            if (!$savedProduct instanceof Product) {
                throw new RuntimeException('Failed to retrieve saved product');
            }
            
            $this->db->transComplete();
            
            // Clear relevant caches
            $this->clearProductCaches($savedProduct);
            
            return $savedProduct;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            throw new RuntimeException('Product save failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete product (soft delete if supported)
     * 
     * @param int $id Product ID
     * @param bool $force Force permanent deletion
     * @return bool Success status
     */
    public function delete(int $id, bool $force = false): bool
    {
        if ($force) {
            // Permanent delete
            $success = $this->model->delete($id, true);
        } else {
            // Soft delete (archive)
            $success = $this->model->delete($id);
        }
        
        if ($success) {
            $this->clearProductCachesById($id);
        }
        
        return $success;
    }

    /**
     * Restore soft-deleted product
     * 
     * @param int $id Product ID
     * @return bool Success status
     */
    public function restore(int $id): bool
    {
        $success = $this->model->restore($id);
        
        if ($success) {
            $this->clearProductCachesById($id);
        }
        
        return $success;
    }

    /**
     * Check if product exists
     * 
     * @param int $id Product ID
     * @param bool $withTrashed Include soft-deleted products
     * @return bool
     */
    public function exists(int $id, bool $withTrashed = false): bool
    {
        $cacheKey = $this->getCacheKey("exists_{$id}_" . ($withTrashed ? 'with_trashed' : 'active'));
        
        return $this->cache->remember($cacheKey, function() use ($id, $withTrashed) {
            $builder = $this->model->builder();
            $builder->select('1')->where('id', $id);
            
            if (!$withTrashed) {
                $builder->where('deleted_at', null);
            }
            
            $result = $builder->get()->getRow();
            
            return $result !== null;
        }, $this->cacheTtl);
    }

    // ==================== BUSINESS OPERATIONS ====================

    /**
     * Find published products for public display
     * 
     * @param int $limit Maximum results
     * @param int $offset Results offset
     * @return Product[]
     */
    public function findPublished(int $limit = 20, int $offset = 0): array
    {
        $cacheKey = $this->getCacheKey("published_{$limit}_{$offset}");
        
        return $this->cache->remember($cacheKey, function() use ($limit, $offset) {
            return $this->model->findPublished($limit, $offset);
        }, $this->cacheTtl);
    }

    /**
     * Find product with its marketplace links (eager loading)
     * 
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @return Product|null
     */
    public function findWithLinks(int $productId, bool $activeOnly = true): ?Product
    {
        $cacheKey = $this->getCacheKey("with_links_{$productId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cache->remember($cacheKey, function() use ($productId, $activeOnly) {
            return $this->model->findWithLinks($productId, $activeOnly);
        }, 1800); // 30 minutes for product with links
    }

    /**
     * Increment product view count
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function incrementViewCount(int $productId): bool
    {
        $success = $this->model->incrementViewCount($productId);
        
        if ($success) {
            // Clear caches that include this product
            $this->clearProductCachesById($productId);
            
            // Also clear popular products cache
            $this->cache->deleteMultiple([
                $this->getCacheKey('popular_all_10'),
                $this->getCacheKey('popular_week_10'),
                $this->getCacheKey('popular_month_10'),
            ]);
        }
        
        return $success;
    }

    /**
     * Update product status with validation
     * 
     * @param int $productId Product ID
     * @param ProductStatus $newStatus New status
     * @param int|null $verifiedBy Admin ID for verification
     * @return bool Success status
     */
    public function updateStatus(int $productId, ProductStatus $newStatus, ?int $verifiedBy = null): bool
    {
        $success = $this->model->updateStatus($productId, $newStatus, $verifiedBy);
        
        if ($success) {
            $this->clearProductCachesById($productId);
        }
        
        return $success;
    }

    /**
     * Find products that need maintenance updates
     * 
     * @param string $type 'price' or 'link' or 'both'
     * @param int $limit Maximum results
     * @return Product[]
     */
    public function findNeedsUpdate(string $type = 'both', int $limit = 50): array
    {
        // Not cached because this is maintenance data that changes frequently
        return $this->model->findNeedsUpdate($type, $limit);
    }

    /**
     * Search products by keyword (public search)
     * 
     * @param string $keyword Search keyword
     * @param int $limit Maximum results
     * @return Product[]
     */
    public function searchByKeyword(string $keyword, int $limit = 20): array
    {
        $cacheKey = $this->getCacheKey("search_" . md5($keyword) . "_$limit");
        
        return $this->cache->remember($cacheKey, function() use ($keyword, $limit) {
            return $this->model->searchByKeyword($keyword, $limit);
        }, 1800); // 30 minutes for search results
    }

    /**
     * Get popular products based on view count
     * 
     * @param int $limit Maximum results
     * @param string $period 'all', 'week', 'month'
     * @return Product[]
     */
    public function getPopular(int $limit = 10, string $period = 'all'): array
    {
        $cacheKey = $this->getCacheKey("popular_{$period}_{$limit}");
        
        return $this->cache->remember($cacheKey, function() use ($limit, $period) {
            return $this->model->getPopular($limit, $period);
        }, 1800); // 30 minutes for popular products
    }

    /**
     * Find products by category
     * 
     * @param int $categoryId Category ID
     * @param int $limit Maximum results
     * @param int $offset Results offset
     * @return Product[]
     */
    public function findByCategory(int $categoryId, int $limit = 20, int $offset = 0): array
    {
        $cacheKey = $this->getCacheKey("category_{$categoryId}_{$limit}_{$offset}");
        
        return $this->cache->remember($cacheKey, function() use ($categoryId, $limit, $offset) {
            return $this->model->findByCategory($categoryId, $limit, $offset);
        }, $this->cacheTtl);
    }

    /**
     * Mark product price as checked
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function markPriceChecked(int $productId): bool
    {
        $success = $this->model->markPriceChecked($productId);
        
        if ($success) {
            $this->clearProductCachesById($productId);
        }
        
        return $success;
    }

    /**
     * Mark product links as checked
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function markLinksChecked(int $productId): bool
    {
        $success = $this->model->markLinksChecked($productId);
        
        if ($success) {
            $this->clearProductCachesById($productId);
        }
        
        return $success;
    }

    // ==================== STATISTICS & AGGREGATION ====================

    /**
     * Count products by status
     * 
     * @param bool $withTrashed Include soft-deleted products
     * @return array [status => count]
     */
    public function countByStatus(bool $withTrashed = false): array
    {
        $cacheKey = $this->getCacheKey('count_by_status_' . ($withTrashed ? 'with_trashed' : 'active'));
        
        return $this->cache->remember($cacheKey, function() use ($withTrashed) {
            $builder = $this->model->builder();
            $builder->select('status, COUNT(*) as count');
            
            if (!$withTrashed) {
                $builder->where('deleted_at', null);
            }
            
            $builder->groupBy('status');
            
            $results = $builder->get()->getResultArray();
            
            $counts = [];
            foreach (ProductStatus::cases() as $status) {
                $counts[$status->value] = 0;
            }
            
            foreach ($results as $row) {
                $counts[$row['status']] = (int) $row['count'];
            }
            
            return $counts;
        }, 300); // 5 minutes for statistics
    }

    /**
     * Count published products
     * 
     * @return int
     */
    public function countPublished(): int
    {
        $cacheKey = $this->getCacheKey('count_published');
        
        return $this->cache->remember($cacheKey, function() {
            return $this->model->countPublished();
        }, 300); // 5 minutes for statistics
    }

    /**
     * Count total products
     * 
     * @param bool $withTrashed Include soft-deleted products
     * @return int
     */
    public function countAll(bool $withTrashed = false): int
    {
        $cacheKey = $this->getCacheKey('count_all_' . ($withTrashed ? 'with_trashed' : 'active'));
        
        return $this->cache->remember($cacheKey, function() use ($withTrashed) {
            if ($withTrashed) {
                return $this->model->countAll();
            }
            
            return $this->model->countActive();
        }, 300); // 5 minutes for statistics
    }

    /**
     * Get product statistics for dashboard
     * 
     * @return array
     */
    public function getStats(): array
    {
        $cacheKey = $this->getCacheKey('dashboard_stats');
        
        return $this->cache->remember($cacheKey, function() {
            $total = $this->countAll(false);
            $published = $this->countPublished();
            $draft = $this->countByStatus(false)[ProductStatus::DRAFT->value] ?? 0;
            $pending = $this->countByStatus(false)[ProductStatus::PENDING_VERIFICATION->value] ?? 0;
            $verified = $this->countByStatus(false)[ProductStatus::VERIFIED->value] ?? 0;
            $archived = $this->countAll(true) - $total;
            
            // Get recent products (last 7 days)
            $builder = $this->model->builder();
            $recent = $builder->where('created_at >=', date('Y-m-d H:i:s', strtotime('-7 days')))
                             ->where('deleted_at', null)
                             ->countAllResults();
            
            // Get products needing updates
            $needsPriceUpdate = count($this->findNeedsUpdate('price', 1000));
            $needsLinkCheck = count($this->findNeedsUpdate('link', 1000));
            
            return [
                'total' => $total,
                'published' => $published,
                'draft' => $draft,
                'pending_verification' => $pending,
                'verified' => $verified,
                'archived' => $archived,
                'recent_7_days' => $recent,
                'needs_price_update' => $needsPriceUpdate,
                'needs_link_check' => $needsLinkCheck,
                'publish_rate' => $total > 0 ? round(($published / $total) * 100, 2) : 0,
                'business_limit' => [
                    'current' => $total,
                    'max' => 300,
                    'remaining' => max(0, 300 - $total),
                    'percentage' => round(($total / 300) * 100, 2),
                ]
            ];
        }, 300); // 5 minutes for dashboard stats
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * Update multiple products in batch
     * 
     * @param array $ids Product IDs
     * @param array $data Update data
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $ids, array $data): int
    {
        if (empty($ids) || empty($data)) {
            return 0;
        }
        
        $affected = $this->model->bulkUpdate($ids, $data);
        
        if ($affected > 0) {
            // Clear caches for all affected products
            foreach ($ids as $id) {
                $this->clearProductCachesById($id);
            }
            
            // Clear aggregate caches
            $this->clearAggregateCaches();
        }
        
        return $affected;
    }

    /**
     * Archive multiple products in batch
     * 
     * @param array $ids Product IDs
     * @return int Number of archived products
     */
    public function bulkArchive(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        
        $data = ['status' => ProductStatus::ARCHIVED->value];
        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Publish multiple products in batch
     * 
     * @param array $ids Product IDs
     * @return int Number of published products
     */
    public function bulkPublish(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        
        $data = [
            'status' => ProductStatus::PUBLISHED->value,
            'published_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->bulkUpdate($ids, $data);
    }

    // ==================== VALIDATION OPERATIONS ====================

    /**
     * Check if slug is unique
     * 
     * @param string $slug Slug to check
     * @param int|null $excludeId Product ID to exclude from check
     * @return bool True if unique
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool
    {
        $cacheKey = $this->getCacheKey("slug_unique_{$slug}_" . ($excludeId ?? 'no_exclude'));
        
        return $this->cache->remember($cacheKey, function() use ($slug, $excludeId) {
            $builder = $this->model->builder();
            $builder->select('1')->where('slug', $slug);
            
            if ($excludeId !== null) {
                $builder->where('id !=', $excludeId);
            }
            
            $builder->where('deleted_at', null);
            
            $result = $builder->get()->getRow();
            
            return $result === null;
        }, $this->cacheTtl);
    }

    /**
     * Validate product before save
     * 
     * @param Product $product Product entity
     * @return array Validation result [valid: bool, errors: string[]]
     */
    public function validate(Product $product): array
    {
        // Use entity's own validation
        return $product->validate();
    }

    /**
     * Check business rule: maximum 300 products
     * 
     * @return array [can_create: bool, current_count: int, max_allowed: int]
     */
    public function checkProductLimit(): array
    {
        $current = $this->countAll(false);
        $max = 300;
        $canCreate = $current < $max;
        
        return [
            'can_create' => $canCreate,
            'current_count' => $current,
            'max_allowed' => $max,
            'remaining' => max(0, $max - $current),
            'message' => $canCreate 
                ? sprintf('You can create %d more products', $max - $current)
                : 'Maximum product limit (300) reached'
        ];
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Get cache key with prefix
     * 
     * @param string $key
     * @return string
     */
    private function getCacheKey(string $key): string
    {
        return "product_repo_{$key}";
    }

    /**
     * Generate cache key for findAll operation
     * 
     * @param array $filters
     * @param array $sort
     * @param int $limit
     * @param int $offset
     * @param bool $withTrashed
     * @return string
     */
    private function getCacheKeyForFindAll(
        array $filters,
        array $sort,
        int $limit,
        int $offset,
        bool $withTrashed
    ): string {
        $key = 'findall_' . md5(serialize($filters)) . '_' . md5(serialize($sort)) . 
               "_{$limit}_{$offset}_" . ($withTrashed ? 'withtrashed' : 'active');
        
        return $this->getCacheKey($key);
    }

    /**
     * Clear all caches for a specific product
     * 
     * @param Product $product
     * @return void
     */
    private function clearProductCaches(Product $product): void
    {
        $id = $product->getId();
        $slug = $product->getSlug();
        
        $this->clearProductCachesById($id);
        
        // Also clear slug-based caches
        if ($slug) {
            $this->cache->deleteMultiple([
                $this->getCacheKey("product_slug_{$slug}_active"),
                $this->getCacheKey("product_slug_{$slug}_with_trashed"),
            ]);
        }
    }

    /**
     * Clear all caches for a product by ID
     * 
     * @param int $productId
     * @return void
     */
    private function clearProductCachesById(int $productId): void
    {
        $keys = [
            $this->getCacheKey("product_{$productId}_active"),
            $this->getCacheKey("product_{$productId}_with_trashed"),
            $this->getCacheKey("with_links_{$productId}_active"),
            $this->getCacheKey("with_links_{$productId}_all"),
            $this->getCacheKey("exists_{$productId}_active"),
            $this->getCacheKey("exists_{$productId}_with_trashed"),
        ];
        
        $this->cache->deleteMultiple($keys);
        
        // Also clear model's internal caches if they exist
        if (method_exists($this->model, 'clearCache')) {
            $this->model->clearCache($this->model->cacheKey("lookup_{$productId}_public"));
            $this->model->clearCache($this->model->cacheKey("lookup_{$productId}_admin"));
        }
    }

    /**
     * Clear aggregate caches (lists, statistics, etc.)
     * 
     * @return void
     */
    private function clearAggregateCaches(): void
    {
        // Clear all cache keys with product_repo prefix
        // Note: This is a simplified approach. In production, you'd use cache tags.
        $this->cache->flushTag(['product_repo']);
    }

    /**
     * Set cache TTL
     * 
     * @param int $ttl
     * @return self
     */
    public function setCacheTtl(int $ttl): self
    {
        $this->cacheTtl = $ttl;
        return $this;
    }

    /**
     * Get cache TTL
     * 
     * @return int
     */
    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    /**
     * Factory method to create instance
     * 
     * @return static
     */
    public static function create(): self
    {
        $model = model(ProductModel::class);
        $cache = service('cache'); // Assuming we have a cache service
        $db = \Config\Database::connect();
        
        return new self($model, $cache, $db);
    }
}