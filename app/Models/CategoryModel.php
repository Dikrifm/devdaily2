<?php

namespace App\Models;

use App\Entities\Category;

/**
 * Category Model
 * 
 * Handles product categories with business rule: maximum 15 categories.
 * Simple navigation model for MVP with 5 core methods.
 * 
 * @package App\Models
 */
class CategoryModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'categories';

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
    protected $returnType = Category::class;

    /**
     * Allowed fields for mass assignment
     * 
     * @var array
     */
    protected $allowedFields = [
        'name',
        'slug',
        'icon',
        'sort_order',
        'active',
    ];

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'name'       => 'required|min_length[2]|max_length[100]',
        'slug'       => 'required|alpha_dash|max_length[100]|is_unique[categories.slug,id,{id}]',
        'icon'       => 'permit_empty|max_length[255]',
        'sort_order' => 'permit_empty|integer',
        'active'     => 'permit_empty|in_list[0,1]',
    ];

    /**
     * Default ordering for queries
     * 
     * @var array
     */
    protected $orderBy = [
        'sort_order' => 'ASC',
        'name'       => 'ASC'
    ];

    // ==================== CORE BUSINESS METHODS (5 METHODS) ====================

    /**
     * Find active categories for public display
     * Ordered by sort_order then name
     * Cached for 60 minutes as categories rarely change
     * 
     * @param int $limit Maximum categories to return (business rule: max 15)
     * @return Category[]
     */
    public function findActive(int $limit = 15): array
    {
        $cacheKey = $this->cacheKey("active_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit) {
            return $this->where('active', 1)
                       ->where('deleted_at', null)
                       ->orderBy('sort_order', 'ASC')
                       ->orderBy('name', 'ASC')
                       ->limit($limit)
                       ->findAll();
        }, 3600); // 60 minutes cache - categories rarely change
    }

    /**
     * Find categories with product count
     * Used for navigation with product counts
     * Only includes published products in count
     * 
     * @param int $limit Maximum categories to return
     * @return array Categories with product_count property
     */
    public function withProductCount(int $limit = 15): array
    {
        $cacheKey = $this->cacheKey("with_product_count_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit) {
            // Get active categories
            $categories = $this->findActive($limit);
            
            if (empty($categories)) {
                return [];
            }
            
            // Get product counts for each category
            $productModel = model(ProductModel::class);
            $categoryIds = array_map(fn($cat) => $cat->getId(), $categories);
            
            // Build a single query to get counts for all categories
            $counts = [];
            if (!empty($categoryIds)) {
                $builder = $productModel->builder();
                $result = $builder->select('category_id, COUNT(*) as product_count')
                                  ->whereIn('category_id', $categoryIds)
                                  ->where('status', 'published')
                                  ->where('deleted_at', null)
                                  ->groupBy('category_id')
                                  ->get()
                                  ->getResultArray();
                
                foreach ($result as $row) {
                    $counts[$row['category_id']] = (int) $row['product_count'];
                }
            }
            
            // Attach product counts to categories
            foreach ($categories as $category) {
                $categoryId = $category->getId();
                $category->product_count = $counts[$categoryId] ?? 0;
            }
            
            // Filter out categories with 0 products (optional)
            // return array_filter($categories, fn($cat) => $cat->product_count > 0);
            
            return $categories;
        }, 1800); // 30 minutes cache
    }

    /**
     * Update category sort order
     * Used for manual ordering in admin interface
     * Business rule: sort_order is manually managed
     * 
     * @param array $orderData Array of [category_id => sort_order]
     * @return bool
     */
    public function updateSortOrder(array $orderData): bool
    {
        if (empty($orderData)) {
            return false;
        }
        
        $this->db->transStart();
        
        try {
            foreach ($orderData as $categoryId => $sortOrder) {
                if (!is_numeric($categoryId) || !is_numeric($sortOrder)) {
                    continue;
                }
                
                $this->update($categoryId, [
                    'sort_order' => (int) $sortOrder
                ]);
            }
            
            $this->db->transComplete();
            
            // Clear all category caches since order changed
            $this->clearAllCategoryCaches();
            
            return $this->db->transStatus();
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'Failed to update category sort order: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Find category by slug
     * Used for routing and URL resolution
     * 
     * @param string $slug
     * @param bool $activeOnly Only return active categories
     * @return Category|null
     */
    public function findBySlug(string $slug, bool $activeOnly = true): ?Category
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
            
            return $result instanceof Category ? $result : null;
        }, 3600); // 60 minutes cache
    }

    /**
     * Get navigation categories
     * Returns active categories that have at least one published product
     * Used for main navigation menu
     * 
     * @param int $limit
     * @return Category[]
     */
    public function getNavigation(int $limit = 10): array
    {
        $cacheKey = $this->cacheKey("navigation_{$limit}");
        
        return $this->cached($cacheKey, function() use ($limit) {
            // Get categories with product counts
            $categories = $this->withProductCount($limit);
            
            // Filter out categories with 0 products
            return array_filter($categories, function($category) {
                return $category->product_count > 0;
            });
        }, 1800); // 30 minutes cache
    }

    // ==================== HELPER METHODS ====================

    /**
     * Clear all category caches
     * Used when categories are updated
     * 
     * @return void
     */
    private function clearAllCategoryCaches(): void
    {
        $cache = $this->getCache();
        $prefix = $this->cacheKey('');
        
        // Get all cache items with category prefix
        // Note: This is a simple implementation for MVP
        // In production, you might want a more sophisticated cache invalidation
        $keysToDelete = [
            $this->cacheKey('active_15'),
            $this->cacheKey('with_product_count_15'),
            $this->cacheKey('navigation_10'),
        ];
        
        foreach ($keysToDelete as $key) {
            $this->clearCache($key);
        }
        
        // Also clear any slug-based caches (we don't know all slugs, so we can't clear them all)
        // In MVP, we rely on TTL for slug caches
    }

    /**
     * Check if category can be deleted
     * Business rule: category with products cannot be deleted
     * 
     * @param int $categoryId
     * @return array [bool $canDelete, string $reason]
     */
    public function canDelete(int $categoryId): array
    {
        $category = $this->findActiveById($categoryId);
        if (!$category) {
            return [false, 'Category not found'];
        }
        
        // Check if category has any products
        $productModel = model(ProductModel::class);
        $productCount = $productModel->where('category_id', $categoryId)
                                    ->where('deleted_at', null)
                                    ->countAllResults();
        
        if ($productCount > 0) {
            return [false, "Category has {$productCount} product(s). Remove products first."];
        }
        
        return [true, ''];
    }

    /**
     * Get category statistics for admin dashboard
     * 
     * @return array
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
            
            // Get category with most products
            $productModel = model(ProductModel::class);
            $builder = $productModel->builder();
            $mostProducts = $builder->select('category_id, COUNT(*) as product_count')
                                   ->where('category_id IS NOT NULL')
                                   ->where('status', 'published')
                                   ->where('deleted_at', null)
                                   ->groupBy('category_id')
                                   ->orderBy('product_count', 'DESC')
                                   ->limit(1)
                                   ->get()
                                   ->getRowArray();
            
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'archived' => $archived,
                'most_popular_category' => $mostProducts ? [
                    'category_id' => $mostProducts['category_id'],
                    'product_count' => (int) $mostProducts['product_count']
                ] : null,
                'limit_remaining' => max(0, 15 - $total), // Business rule: max 15 categories
            ];
        }, 300); // 5 minutes cache for stats
    }

    /**
     * Deactivate category (soft deactivation, not deletion)
     * 
     * @param int $categoryId
     * @return bool
     */
    public function deactivate(int $categoryId): bool
    {
        $result = $this->update($categoryId, ['active' => 0]);
        
        if ($result) {
            $this->clearAllCategoryCaches();
        }
        
        return $result;
    }

    /**
     * Activate category
     * 
     * @param int $categoryId
     * @return bool
     */
    public function activate(int $categoryId): bool
    {
        // Check business rule: maximum 15 active categories
        $activeCount = $this->where('active', 1)
                           ->where('deleted_at', null)
                           ->countAllResults();
        
        if ($activeCount >= 15) {
            log_message('error', 'Cannot activate category: maximum 15 active categories reached');
            return false;
        }
        
        $result = $this->update($categoryId, ['active' => 1]);
        
        if ($result) {
            $this->clearAllCategoryCaches();
        }
        
        return $result;
    }

    /**
     * Find categories by IDs
     * 
     * @param array $categoryIds
     * @param bool $activeOnly
     * @return Category[]
     */
    public function findByIds(array $categoryIds, bool $activeOnly = true): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        
        $cacheKey = $this->cacheKey('ids_' . md5(implode(',', $categoryIds)) . '_' . ($activeOnly ? 'active' : 'all'));
        
        return $this->cached($cacheKey, function() use ($categoryIds, $activeOnly) {
            $builder = $this->builder();
            $builder->whereIn('id', $categoryIds)
                    ->where('deleted_at', null);
            
            if ($activeOnly) {
                $builder->where('active', 1);
            }
            
            $builder->orderBy('sort_order', 'ASC')
                    ->orderBy('name', 'ASC');
            
            return $builder->get()->getResult($this->returnType);
        }, 3600);
    }

    /**
     * Create default categories for system initialization
     * Business rule: Maximum 15 categories total
     * 
     * @return array IDs of created categories
     */
    public function createDefaultCategories(): array
    {
        $defaultCategories = [
            ['name' => 'Electronics', 'slug' => 'electronics', 'icon' => 'fas fa-laptop', 'sort_order' => 1],
            ['name' => 'Fashion', 'slug' => 'fashion', 'icon' => 'fas fa-tshirt', 'sort_order' => 2],
            ['name' => 'Home & Living', 'slug' => 'home-living', 'icon' => 'fas fa-home', 'sort_order' => 3],
            ['name' => 'Beauty', 'slug' => 'beauty', 'icon' => 'fas fa-spa', 'sort_order' => 4],
            ['name' => 'Sports', 'slug' => 'sports', 'icon' => 'fas fa-futbol', 'sort_order' => 5],
            ['name' => 'Books', 'slug' => 'books', 'icon' => 'fas fa-book', 'sort_order' => 6],
            ['name' => 'Toys & Games', 'slug' => 'toys-games', 'icon' => 'fas fa-gamepad', 'sort_order' => 7],
        ];
        
        $createdIds = [];
        
        foreach ($defaultCategories as $categoryData) {
            // Check if category already exists by slug
            $existing = $this->where('slug', $categoryData['slug'])
                            ->where('deleted_at', null)
                            ->first();
            
            if (!$existing) {
                $categoryData['active'] = 1;
                if ($id = $this->insert($categoryData)) {
                    $createdIds[] = $id;
                }
            }
        }
        
        // Clear caches after creating defaults
        $this->clearAllCategoryCaches();
        
        return $createdIds;
    }
}