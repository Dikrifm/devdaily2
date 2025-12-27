<?php

namespace App\Models;

use App\Entities\Category;
use CodeIgniter\Database\BaseBuilder;

/**
 * Category Model - SQL Encapsulator for Category Entity
 * 
 * Layer 2: Pure Data Gateway (0% Business Logic)
 * Implements hierarchical category structure with parent-child relationships
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
     * Return type for hydration
     * MUST be set to Category Entity (Type Safety)
     * 
     * @var string
     */
    protected $returnType = Category::class;

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
        'name',
        'slug',
        'icon',
        'sort_order',
        'active',
        'parent_id',
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
        'name' => 'required|max_length[255]',
        'slug' => 'required|max_length[255]|regex_match[/^[a-z0-9\-]+$/]',
        'icon' => 'permit_empty|max_length[100]',
        'sort_order' => 'permit_empty|integer',
        'active' => 'permit_empty|in_list[0,1]',
        'parent_id' => 'permit_empty|integer',
    ];

    /**
     * Validation messages
     * 
     * @var array
     */
    protected $validationMessages = [
        'name' => [
            'required' => 'Category name is required',
            'max_length' => 'Category name cannot exceed 255 characters',
        ],
        'slug' => [
            'required' => 'Category slug is required',
            'max_length' => 'Category slug cannot exceed 255 characters',
            'regex_match' => 'Category slug can only contain lowercase letters, numbers, and hyphens',
        ],
        'icon' => [
            'max_length' => 'Icon class cannot exceed 100 characters',
        ],
        'sort_order' => [
            'integer' => 'Sort order must be an integer',
        ],
        'active' => [
            'in_list' => 'Active must be either 0 or 1',
        ],
        'parent_id' => [
            'integer' => 'Parent ID must be an integer',
        ],
    ];

    // ============================================
    // QUERY SCOPES (Pure SQL Building - 0% Business Logic)
    // ============================================

    /**
     * Scope: Active categories only
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
     * Scope: Root categories (parent_id = 0)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeRoot(BaseBuilder $builder): BaseBuilder
    {
        return $builder->where('parent_id', 0)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Categories by parent ID
     * 
     * @param BaseBuilder $builder
     * @param int $parentId
     * @return BaseBuilder
     */
    public function scopeByParent(BaseBuilder $builder, int $parentId): BaseBuilder
    {
        return $builder->where('parent_id', $parentId)
                      ->where($this->deletedField, null);
    }

    /**
     * Scope: Categories sorted by sort_order then name
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeSorted(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('sort_order', 'ASC')
                      ->orderBy('name', 'ASC');
    }

    /**
     * Scope: Categories with sub-categories (has children)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeHasChildren(BaseBuilder $builder): BaseBuilder
    {
        return $builder->whereIn('id', function (BaseBuilder $subquery) {
            return $subquery->select('parent_id')
                           ->from($this->table)
                           ->where('parent_id >', 0)
                           ->where($this->deletedField, null)
                           ->groupBy('parent_id');
        })->where($this->deletedField, null);
    }

    /**
     * Scope: Leaf categories (no children)
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    public function scopeIsLeaf(BaseBuilder $builder): BaseBuilder
    {
        return $builder->whereNotIn('id', function (BaseBuilder $subquery) {
            return $subquery->select('parent_id')
                           ->from($this->table)
                           ->where('parent_id >', 0)
                           ->where($this->deletedField, null)
                           ->groupBy('parent_id');
        })->where($this->deletedField, null);
    }

    /**
     * Scope: Categories with maximum depth constraint (for 15 categories limit)
     * Note: Business logic validation is in Service layer, this is just query helper
     * 
     * @param BaseBuilder $builder
     * @param int $maxDepth
     * @return BaseBuilder
     */
    public function scopeMaxDepth(BaseBuilder $builder, int $maxDepth = 2): BaseBuilder
    {
        // Simple implementation - assumes max 2 levels (root and one level of children)
        if ($maxDepth === 1) {
            return $builder->where('parent_id', 0);
        }
        
        return $builder; // No constraint for depth > 1 in this simple implementation
    }

    // ============================================
    // FINDER METHODS (Return Fully Hydrated Entities)
    // ============================================

    /**
     * Find active category by slug
     * 
     * @param string $slug
     * @return Category|null
     */
    public function findBySlug(string $slug): ?Category
    {
        $result = $this->where('slug', $slug)
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Category ? $result : null;
    }

    /**
     * Find category by ID with parent information
     * 
     * @param int $id
     * @return Category|null
     */
    public function findWithParent(int $id): ?Category
    {
        $result = $this->select('c.*, p.name as parent_name')
                      ->from($this->table . ' c')
                      ->join($this->table . ' p', 'p.id = c.parent_id', 'left')
                      ->where('c.id', $id)
                      ->where('c.' . $this->deletedField, null)
                      ->first();

        return $result instanceof Category ? $result : null;
    }

    /**
     * Find all root categories
     * 
     * @return array<Category>
     */
    public function findRootCategories(): array
    {
        $result = $this->scopeRoot($this->builder())
                      ->scopeSorted($this->builder())
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Category);
    }

    /**
     * Find all sub-categories for a parent
     * 
     * @param int $parentId
     * @return array<Category>
     */
    public function findSubCategories(int $parentId): array
    {
        $result = $this->scopeByParent($this->builder(), $parentId)
                      ->scopeSorted($this->builder())
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Category);
    }

    /**
     * Find category tree (all categories with hierarchy)
     * 
     * @return array Hierarchical array
     */
    public function findCategoryTree(): array
    {
        $allCategories = $this->scopeSorted($this->builder())
                             ->where($this->deletedField, null)
                             ->findAll();

        $tree = [];
        $children = [];

        // First pass: organize parent-child relationships
        foreach ($allCategories as $category) {
            if (!$category instanceof Category) {
                continue;
            }
            
            $parentId = $category->getParentId();
            if ($parentId === 0) {
                $tree[$category->getId()] = [
                    'category' => $category,
                    'children' => []
                ];
            } else {
                if (!isset($children[$parentId])) {
                    $children[$parentId] = [];
                }
                $children[$parentId][] = $category;
            }
        }

        // Second pass: attach children to parents
        foreach ($children as $parentId => $childCategories) {
            if (isset($tree[$parentId])) {
                $tree[$parentId]['children'] = $childCategories;
            } else {
                // Parent might be a child itself (multi-level hierarchy not fully supported)
                // For simplicity, we'll add as root for now
                foreach ($childCategories as $child) {
                    $tree[$child->getId()] = [
                        'category' => $child,
                        'children' => []
                    ];
                }
            }
        }

        return $tree;
    }

    /**
     * Find categories with product counts
     * 
     * @return array<Category>
     */
    public function findWithProductCounts(): array
    {
        $result = $this->select('c.*, COUNT(p.id) as product_count')
                      ->from($this->table . ' c')
                      ->join('products p', 'p.category_id = c.id AND p.deleted_at IS NULL', 'left')
                      ->where('c.' . $this->deletedField, null)
                      ->groupBy('c.id')
                      ->orderBy('c.sort_order', 'ASC')
                      ->orderBy('c.name', 'ASC')
                      ->findAll();

        // Hydrate product_count into entities
        $categories = [];
        foreach ($result as $row) {
            if ($row instanceof Category) {
                $row->setProductCount((int) $row->product_count ?? 0);
                $categories[] = $row;
            }
        }

        return $categories;
    }

    /**
     * Find categories with children counts
     * 
     * @return array<Category>
     */
    public function findWithChildrenCounts(): array
    {
        $result = $this->select('c.*, COUNT(child.id) as children_count')
                      ->from($this->table . ' c')
                      ->join($this->table . ' child', 'child.parent_id = c.id AND child.deleted_at IS NULL', 'left')
                      ->where('c.' . $this->deletedField, null)
                      ->groupBy('c.id')
                      ->orderBy('c.sort_order', 'ASC')
                      ->orderBy('c.name', 'ASC')
                      ->findAll();

        // Hydrate children_count into entities
        $categories = [];
        foreach ($result as $row) {
            if ($row instanceof Category) {
                $row->setChildrenCount((int) $row->children_count ?? 0);
                $categories[] = $row;
            }
        }

        return $categories;
    }

    /**
     * Find category by ID with all counts (products and children)
     * 
     * @param int $id
     * @return Category|null
     */
    public function findWithAllCounts(int $id): ?Category
    {
        $result = $this->select('c.*, 
                                COUNT(DISTINCT p.id) as product_count,
                                COUNT(DISTINCT child.id) as children_count')
                      ->from($this->table . ' c')
                      ->join('products p', 'p.category_id = c.id AND p.deleted_at IS NULL', 'left')
                      ->join($this->table . ' child', 'child.parent_id = c.id AND child.deleted_at IS NULL', 'left')
                      ->where('c.id', $id)
                      ->where('c.' . $this->deletedField, null)
                      ->groupBy('c.id')
                      ->first();

        if (!$result instanceof Category) {
            return null;
        }

        $result->setProductCount((int) ($result->product_count ?? 0));
        $result->setChildrenCount((int) ($result->children_count ?? 0));

        return $result;
    }

    // ============================================
    // HIERARCHY METHODS (Tree Operations)
    // ============================================

    /**
     * Get all descendants of a category (recursive)
     * 
     * @param int $categoryId
     * @return array<Category>
     */
    public function findDescendants(int $categoryId): array
    {
        // Simple implementation for 2-level hierarchy
        $descendants = [];
        
        // Direct children
        $children = $this->findSubCategories($categoryId);
        $descendants = array_merge($descendants, $children);
        
        // For each child, get its children (if supporting deeper hierarchy)
        foreach ($children as $child) {
            $grandChildren = $this->findSubCategories($child->getId());
            $descendants = array_merge($descendants, $grandChildren);
        }
        
        return $descendants;
    }

    /**
     * Get category path (breadcrumb trail)
     * 
     * @param int $categoryId
     * @return array<Category> Path from root to category
     */
    public function findCategoryPath(int $categoryId): array
    {
        $path = [];
        $currentId = $categoryId;
        $maxDepth = 10; // Prevent infinite loops
        
        while ($currentId > 0 && $maxDepth-- > 0) {
            $category = $this->find($currentId);
            if (!$category instanceof Category) {
                break;
            }
            
            array_unshift($path, $category);
            $currentId = $category->getParentId();
        }
        
        return $path;
    }

    /**
     * Check if category is ancestor of another category
     * 
     * @param int $ancestorId
     * @param int $descendantId
     * @return bool
     */
    public function isAncestorOf(int $ancestorId, int $descendantId): bool
    {
        $path = $this->findCategoryPath($descendantId);
        
        foreach ($path as $category) {
            if ($category->getId() === $ancestorId) {
                return true;
            }
        }
        
        return false;
    }

    // ============================================
    // BATCH OPERATIONS (Pure SQL - No Business Logic)
    // ============================================

    /**
     * Bulk update parent ID (for re-parenting)
     * 
     * @param array<int> $categoryIds
     * @param int $newParentId
     * @return int Affected rows
     */
    public function bulkReparent(array $categoryIds, int $newParentId): int
    {
        if (empty($categoryIds)) {
            return 0;
        }

        $data = [
            'parent_id' => $newParentId,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($categoryIds, $data);
    }

    /**
     * Bulk update sort order
     * 
     * @param array<int, int> $sortOrders Array of [categoryId => sortOrder]
     * @return int Affected rows
     */
    public function bulkUpdateSortOrder(array $sortOrders): int
    {
        if (empty($sortOrders)) {
            return 0;
        }

        $affected = 0;
        foreach ($sortOrders as $categoryId => $sortOrder) {
            $data = [
                'sort_order' => (int) $sortOrder,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($this->update($categoryId, $data)) {
                $affected++;
            }
        }

        return $affected;
    }

    /**
     * Bulk activate/deactivate categories
     * 
     * @param array<int> $categoryIds
     * @param bool $active
     * @return int Affected rows
     */
    public function bulkSetActive(array $categoryIds, bool $active): int
    {
        if (empty($categoryIds)) {
            return 0;
        }

        $data = [
            'active' => $active ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($categoryIds, $data);
    }

    // ============================================
    // VALIDATION & INTEGRITY METHODS
    // ============================================

    /**
     * Check if slug already exists (excluding current category)
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
     * Check if parent exists and is active
     * 
     * @param int $parentId
     * @return bool
     */
    public function isValidParent(int $parentId): bool
    {
        if ($parentId === 0) {
            return true; // Root category
        }

        $parent = $this->findActiveById($parentId);
        return $parent instanceof Category;
    }

    /**
     * Check if category can be deleted (no products or children)
     * This is a data check, business logic is in Service layer
     * 
     * @param int $categoryId
     * @return array{has_products: bool, has_children: bool, can_delete: bool}
     */
    public function checkDeletionPreconditions(int $categoryId): array
    {
        $result = [
            'has_products' => false,
            'has_children' => false,
            'can_delete' => false
        ];

        // Check for products
        $productCount = $this->db->table('products')
                                ->where('category_id', $categoryId)
                                ->where('deleted_at', null)
                                ->countAllResults();
        $result['has_products'] = $productCount > 0;

        // Check for children
        $childCount = $this->where('parent_id', $categoryId)
                          ->where($this->deletedField, null)
                          ->countAllResults();
        $result['has_children'] = $childCount > 0;

        // Can delete if no products and no children
        $result['can_delete'] = !$result['has_products'] && !$result['has_children'];

        return $result;
    }

    /**
     * Get total category count (excluding deleted)
     * For enforcing 15 category limit in Service layer
     * 
     * @return int
     */
    public function getTotalActiveCount(): int
    {
        return $this->where($this->deletedField, null)->countAllResults();
    }

    /**
     * Get maximum depth in category tree
     * 
     * @return int
     */
    public function getMaxDepth(): int
    {
        // Simple implementation - assumes max 2 levels
        $hasGrandChildren = $this->db->table($this->table . ' c1')
                                    ->join($this->table . ' c2', 'c2.parent_id = c1.id')
                                    ->join($this->table . ' c3', 'c3.parent_id = c2.id')
                                    ->where('c1.deleted_at', null)
                                    ->where('c2.deleted_at', null)
                                    ->where('c3.deleted_at', null)
                                    ->countAllResults();

        return $hasGrandChildren > 0 ? 3 : 2;
    }

    // ============================================
    // UTILITY METHODS
    // ============================================

    /**
     * Generate unique slug from name
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
     * Get all category IDs in a subtree (including parent)
     * 
     * @param int $parentId
     * @return array<int>
     */
    public function getSubtreeIds(int $parentId): array
    {
        $ids = [$parentId];
        $children = $this->findSubCategories($parentId);
        
        foreach ($children as $child) {
            $ids[] = $child->getId();
            // Recursively get grandchildren if needed
            $grandChildren = $this->findSubCategories($child->getId());
            foreach ($grandChildren as $grandChild) {
                $ids[] = $grandChild->getId();
            }
        }
        
        return array_unique($ids);
    }
}