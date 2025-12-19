<?php

namespace App\Repositories\Concrete;

use App\Entities\Category;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\ValidationException;
use App\Models\CategoryModel;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Services\AuditService;
use App\Services\CacheService;
use CodeIgniter\Database\ConnectionInterface;
use InvalidArgumentException;
use RuntimeException;

class CategoryRepository implements CategoryRepositoryInterface
{
    private CategoryModel $categoryModel;
    private CacheService $cacheService;
    private AuditService $auditService;
    private ConnectionInterface $db;

    private int $cacheTtl = 3600;
    private string $cachePrefix = 'category_repo_';

    // Cache keys constants
    private const CACHE_KEY_TREE = 'tree';
    private const CACHE_KEY_FLATTENED = 'flattened';
    private const CACHE_KEY_NAVIGATION = 'navigation';
    private const CACHE_KEY_STATS = 'stats';
    private const CACHE_KEY_ALL_ACTIVE = 'all_active';
    private const CACHE_KEY_BY_PARENT = 'by_parent_';
    private const CACHE_KEY_BREADCRUMBS = 'breadcrumbs_';

    public function __construct(
        CategoryModel $categoryModel,
        CacheService $cacheService,
        AuditService $auditService,
        ConnectionInterface $db
    ) {
        $this->categoryModel = $categoryModel;
        $this->cacheService = $cacheService;
        $this->auditService = $auditService;
        $this->db = $db;
    }

    // ==================== BASIC CRUD OPERATIONS ====================

    public function find(int $id, bool $withTrashed = false): ?Category
    {
        $cacheKey = $this->getCacheKey("find_{$id}_" . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($id, $withTrashed) {
            $category = $withTrashed
                ? $this->categoryModel->withDeleted()->find($id)
                : $this->categoryModel->find($id);

            if (!$category instanceof Category) {
                return null;
            }

            return $category;
        }, $this->cacheTtl);
    }

    public function findBySlug(string $slug, bool $withTrashed = false): ?Category
    {
        $cacheKey = $this->getCacheKey("find_by_slug_{$slug}_" . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($slug, $withTrashed) {
            $method = $withTrashed ? 'withDeleted' : 'where';
            $this->categoryModel->$method(['slug' => $slug]);

            return $this->categoryModel->first();
        }, $this->cacheTtl);
    }

    public function findByIdOrSlug($identifier, bool $withTrashed = false): ?Category
    {
        if (is_numeric($identifier)) {
            return $this->find((int) $identifier, $withTrashed);
        }

        return $this->findBySlug((string) $identifier, $withTrashed);
    }

    public function findAll(
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortDirection = 'ASC',
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKey(
            "find_all_" .
            md5(serialize($filters)) . "_" .
            "{$sortBy}_{$sortDirection}_" .
            ($withTrashed ? 'with' : 'without')
        );

        return $this->cacheService->remember($cacheKey, function () use ($filters, $sortBy, $sortDirection, $withTrashed) {
            $builder = $withTrashed
                ? $this->categoryModel->withDeleted()
                : $this->categoryModel;

            // Apply filters
            if (!empty($filters)) {
                $this->applyFilters($builder, $filters);
            }

            // Apply sorting
            $builder->orderBy($sortBy, $sortDirection);

            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }

    public function save(Category $category): Category
    {
        $isUpdate = $category->getId() !== null;
        $oldData = $isUpdate ? $this->find($category->getId(), true)?->toArray() : null;

        try {
            $this->db->transBegin();

            // Validate before save
            $validationResult = $this->validate($category);
            if (!$validationResult['is_valid']) {
                throw new ValidationException(
                    'Category validation failed',
                    $validationResult['errors']
                );
            }

            // Prepare for save
            $category->prepareForSave($isUpdate);

            // Save to database
            $saved = $isUpdate
                ? $this->categoryModel->update($category->getId(), $category)
                : $this->categoryModel->insert($category);

            if (!$saved) {
                throw new RuntimeException(
                    'Failed to save category: ' .
                    implode(', ', $this->categoryModel->errors())
                );
            }

            // If new category, get the ID
            if (!$isUpdate) {
                $category->setId($this->categoryModel->getInsertID());
            }

            // Clear relevant caches
            $this->clearCache($category->getId());

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $isUpdate ? 'UPDATE' : 'CREATE';
                $adminId = service('auth')->user()?->getId() ?? 0;

                $this->auditService->logCrudOperation(
                    'CATEGORY',
                    $category->getId(),
                    $action,
                    $adminId,
                    $oldData,
                    $category->toArray()
                );
            }

            $this->db->transCommit();

            return $category;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository save failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to save category: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(int $id, bool $force = false): bool
    {
        $category = $this->find($id, true);
        if (!$category) {
            throw CategoryNotFoundException::forId($id);
        }

        // Check if can be deleted
        $canDeleteResult = $this->canDelete($id);
        if (!$canDeleteResult['can_delete'] && !$force) {
            throw new ValidationException(
                'Cannot delete category',
                $canDeleteResult['reasons']
            );
        }

        try {
            $this->db->transBegin();

            $oldData = $category->toArray();
            $adminId = service('auth')->user()?->getId() ?? 0;

            if ($force) {
                // Permanent deletion
                $deleted = $this->categoryModel->delete($id, true);
            } else {
                // Soft delete
                $category->softDelete();
                $deleted = $this->categoryModel->save($category);
            }

            if (!$deleted) {
                throw new RuntimeException('Failed to delete category');
            }

            // Handle orphaned categories if soft deleting
            if (!$force && $category->isDeleted()) {
                // In a real implementation, you might reparent children
                // $this->reparentOrphanedCategories($id, 0);
            }

            // Clear caches
            $this->clearCache($id);

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $force ? 'DELETE' : 'SOFT_DELETE';
                $this->auditService->logCrudOperation(
                    'CATEGORY',
                    $id,
                    $action,
                    $adminId,
                    $oldData,
                    null
                );
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository delete failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to delete category: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restore(int $id): bool
    {
        $category = $this->find($id, true);
        if (!$category || !$category->isDeleted()) {
            return false;
        }

        try {
            $this->db->transBegin();

            $category->restore();
            $restored = $this->categoryModel->save($category);

            if (!$restored) {
                throw new RuntimeException('Failed to restore category');
            }

            // Clear caches
            $this->clearCache($id);

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logCrudOperation(
                    'CATEGORY',
                    $id,
                    'RESTORE',
                    $adminId,
                    null,
                    $category->toArray()
                );
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository restore failed: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(int $id, bool $withTrashed = false): bool
    {
        $cacheKey = $this->getCacheKey("exists_{$id}_" . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($id, $withTrashed) {
            $builder = $withTrashed
                ? $this->categoryModel->withDeleted()
                : $this->categoryModel;

            return $builder->find($id) !== null;
        }, 300); // Short cache for existence checks
    }

    // ==================== TREE & HIERARCHY OPERATIONS ====================

    public function getTree(
        ?int $parentId = null,
        bool $includeInactive = false,
        ?int $maxDepth = null,
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKey(
            "tree_{$parentId}_" .
            ($includeInactive ? 'inactive_' : 'active_') .
            ($maxDepth ?? 'all') . '_' .
            ($withTrashed ? 'with' : 'without')
        );

        return $this->cacheService->remember($cacheKey, function () use ($parentId, $includeInactive, $maxDepth, $withTrashed) {
            // Get all categories first
            $allCategories = $this->findAll([], 'sort_order', 'ASC', $withTrashed);

            // Filter by active status if needed
            if (!$includeInactive) {
                $allCategories = array_filter($allCategories, function ($category) {
                    return $category->isActive();
                });
            }

            // Build tree recursively
            return $this->buildTree($allCategories, $parentId, 0, $maxDepth);
        }, $this->cacheTtl);
    }

    public function getFlattenedTree(
        bool $includeInactive = false,
        string $indicator = '--'
    ): array {
        $cacheKey = $this->getCacheKey("flattened_" . ($includeInactive ? 'inactive' : 'active'));

        return $this->cacheService->remember($cacheKey, function () use ($includeInactive, $indicator) {
            $tree = $this->getTree(null, $includeInactive);
            return $this->flattenTree($tree, 0, $indicator);
        }, $this->cacheTtl);
    }

    public function getChildren(
        int $parentId,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKey(
            "children_{$parentId}_" .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with' : 'without')
        );

        return $this->cacheService->remember($cacheKey, function () use ($parentId, $activeOnly, $withTrashed) {
            $filters = ['parent_id' => $parentId];
            if ($activeOnly) {
                $filters['active'] = true;
            }

            return $this->findAll($filters, 'sort_order', 'ASC', $withTrashed);
        }, $this->cacheTtl);
    }

    public function getParentPath(int $categoryId, bool $includeSelf = false): array
    {
        $cacheKey = $this->getCacheKey("parent_path_{$categoryId}_" . ($includeSelf ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($categoryId, $includeSelf) {
            $path = [];
            $currentId = $categoryId;

            // Note: This assumes parent_id field exists in Category entity
            // In real implementation, you'd need to fetch each parent
            // For now, returning empty as placeholder

            if ($includeSelf) {
                $category = $this->find($categoryId);
                if ($category) {
                    array_unshift($path, $category);
                }
            }

            return $path;
        }, $this->cacheTtl);
    }

    public function isDescendantOf(int $categoryId, int $parentId): bool
    {
        if ($categoryId === $parentId) {
            return false;
        }

        $parentPath = $this->getParentPath($categoryId, false);

        foreach ($parentPath as $parent) {
            if ($parent->getId() === $parentId) {
                return true;
            }
        }

        return false;
    }

    public function wouldCreateCircularReference(int $categoryId, int $newParentId): bool
    {
        if ($categoryId === $newParentId) {
            return true;
        }

        return $this->isDescendantOf($newParentId, $categoryId);
    }

    public function moveToParent(int $categoryId, int $newParentId): bool
    {
        $category = $this->find($categoryId);
        if (!$category) {
            throw CategoryNotFoundException::forId($categoryId);
        }

        if ($this->wouldCreateCircularReference($categoryId, $newParentId)) {
            throw new ValidationException(
                'Cannot move category: would create circular reference'
            );
        }

        try {
            $this->db->transBegin();

            // Update parent_id - assuming Category entity has setParentId method
            // $category->setParentId($newParentId);
            // $this->save($category);

            // Clear caches
            $this->clearCache($categoryId);
            if ($category->getParentId() !== $newParentId) {
                $this->clearCache($category->getParentId());
                $this->clearCache($newParentId);
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository moveToParent failed: ' . $e->getMessage());
            return false;
        }
    }

    // ==================== STATUS & ACTIVATION OPERATIONS ====================

    public function activate(int $categoryId): bool
    {
        $category = $this->find($categoryId);
        if (!$category) {
            throw CategoryNotFoundException::forId($categoryId);
        }

        if ($category->isActive()) {
            return true; // Already active
        }

        try {
            $this->db->transBegin();

            $category->activate();
            $saved = $this->categoryModel->save($category);

            if (!$saved) {
                throw new RuntimeException('Failed to activate category');
            }

            // Clear caches
            $this->clearCache($categoryId);

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logStateTransition(
                    'CATEGORY',
                    $categoryId,
                    'inactive',
                    'active',
                    $adminId,
                    'Category activated'
                );
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository activate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deactivate(int $categoryId): bool
    {
        $category = $this->find($categoryId);
        if (!$category) {
            throw CategoryNotFoundException::forId($categoryId);
        }

        if (!$category->isActive()) {
            return true; // Already inactive
        }

        try {
            $this->db->transBegin();

            $category->deactivate();
            $saved = $this->categoryModel->save($category);

            if (!$saved) {
                throw new RuntimeException('Failed to deactivate category');
            }

            // Clear caches
            $this->clearCache($categoryId);

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logStateTransition(
                    'CATEGORY',
                    $categoryId,
                    'active',
                    'inactive',
                    $adminId,
                    'Category deactivated'
                );
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository deactivate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function archive(int $categoryId): bool
    {
        return $this->delete($categoryId, false);
    }

    public function bulkUpdateStatus(array $categoryIds, string $status): int
    {
        if (empty($categoryIds)) {
            return 0;
        }

        $validStatuses = ['active', 'inactive', 'archived'];
        if (!in_array($status, $validStatuses)) {
            throw new InvalidArgumentException('Invalid status: ' . $status);
        }

        try {
            $this->db->transBegin();

            $updated = 0;
            $adminId = service('auth')->user()?->getId() ?? 0;

            foreach ($categoryIds as $categoryId) {
                try {
                    switch ($status) {
                        case 'active':
                            if ($this->activate($categoryId)) {
                                $updated++;
                            }
                            break;
                        case 'inactive':
                            if ($this->deactivate($categoryId)) {
                                $updated++;
                            }
                            break;
                        case 'archived':
                            if ($this->archive($categoryId)) {
                                $updated++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to update category {$categoryId}: " . $e->getMessage());
                    // Continue with other categories
                }
            }

            // Clear all category caches since multiple categories were affected
            $this->clearCache();

            $this->db->transCommit();

            return $updated;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository bulkUpdateStatus failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ==================== SORTING & ORDERING ====================

    public function updateSortOrder(int $categoryId, int $newSortOrder): bool
    {
        $category = $this->find($categoryId);
        if (!$category) {
            throw CategoryNotFoundException::forId($categoryId);
        }

        try {
            $this->db->transBegin();

            $category->setSortOrder($newSortOrder);
            $updated = $this->categoryModel->save($category);

            if (!$updated) {
                throw new RuntimeException('Failed to update sort order');
            }

            // Clear caches
            $this->clearCache($categoryId);
            $this->clearCache($category->getParentId());

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository updateSortOrder failed: ' . $e->getMessage());
            return false;
        }
    }

    public function reorderSiblings(int $parentId, array $orderData): bool
    {
        if (empty($orderData)) {
            return true;
        }

        try {
            $this->db->transBegin();

            foreach ($orderData as $categoryId => $newOrder) {
                $this->categoryModel->update($categoryId, [
                    'sort_order' => $newOrder,
                    'parent_id' => $parentId
                ]);
            }

            // Clear caches
            $this->clearCache();

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository reorderSiblings failed: ' . $e->getMessage());
            return false;
        }
    }

    // ==================== SEARCH & FILTER ====================

    public function search(
        string $keyword,
        bool $searchDescription = false,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(
            "search_" . md5($keyword) . "_" .
            ($searchDescription ? 'desc_' : 'name_') .
            ($activeOnly ? 'active_' : 'all_') .
            "{$limit}_{$offset}"
        );

        return $this->cacheService->remember($cacheKey, function () use ($keyword, $searchDescription, $activeOnly, $limit, $offset) {
            $builder = $this->categoryModel;

            if ($activeOnly) {
                $builder->where('active', true);
            }

            $builder->groupStart();
            $builder->like('name', $keyword);
            $builder->orLike('slug', $keyword);

            if ($searchDescription) {
                $builder->orLike('description', $keyword);
            }

            $builder->groupEnd();

            $builder->orderBy('name', 'ASC');
            $builder->limit($limit, $offset);

            $result = $builder->findAll();
            return $result ?: [];
        }, 300); // Shorter cache for search results
    }

    public function findByIds(
        array $categoryIds,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array {
        if (empty($categoryIds)) {
            return [];
        }

        $cacheKey = $this->getCacheKey(
            "by_ids_" . md5(implode(',', $categoryIds)) . "_" .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with' : 'without')
        );

        return $this->cacheService->remember($cacheKey, function () use ($categoryIds, $activeOnly, $withTrashed) {
            $builder = $withTrashed
                ? $this->categoryModel->withDeleted()
                : $this->categoryModel;

            if ($activeOnly) {
                $builder->where('active', true);
            }

            $builder->whereIn('id', $categoryIds);
            $builder->orderBy('sort_order', 'ASC');

            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }

    public function withProductCount(
        bool $activeOnly = true,
        bool $includeEmpty = false,
        int $limit = 50
    ): array {
        $cacheKey = $this->getCacheKey(
            "with_product_count_" .
            ($activeOnly ? 'active_' : 'all_') .
            ($includeEmpty ? 'empty_' : 'nonempty_') .
            $limit
        );

        return $this->cacheService->remember($cacheKey, function () use ($activeOnly, $includeEmpty, $limit) {
            // In real implementation, you'd join with products table
            // This is a simplified version

            $categories = $this->findAll([], 'name', 'ASC', !$activeOnly);

            $result = [];
            foreach ($categories as $category) {
                // Simulate product count - replace with actual query
                $productCount = 0; // $this->productModel->where('category_id', $category->getId())->countAllResults();

                if ($includeEmpty || $productCount > 0) {
                    $categoryData = $category->toArray();
                    $categoryData['product_count'] = $productCount;
                    $result[] = $categoryData;

                    if (count($result) >= $limit) {
                        break;
                    }
                }
            }

            return $result;
        }, $this->cacheTtl);
    }

    // ==================== STATISTICS & ANALYTICS ====================

    public function getStatistics(?int $categoryId = null): array
    {
        $cacheKey = $this->getCacheKey("stats_" . ($categoryId ?? 'all'));

        return $this->cacheService->remember($cacheKey, function () use ($categoryId) {
            $stats = [];

            if ($categoryId) {
                // Single category statistics
                $category = $this->find($categoryId);
                if (!$category) {
                    return [];
                }

                $stats = [
                    'id' => $category->getId(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                    'product_count' => 0, // Get from database
                    'active_product_count' => 0,
                    'child_category_count' => count($this->getChildren($categoryId)),
                    'depth' => $this->calculateCategoryDepth($categoryId),
                    'created_at' => $category->getCreatedAt()?->format('Y-m-d H:i:s'),
                    'updated_at' => $category->getUpdatedAt()?->format('Y-m-d H:i:s'),
                ];
            } else {
                // System-wide statistics
                $stats = [
                    'total_categories' => $this->countAll(),
                    'active_categories' => $this->countActive(),
                    'inactive_categories' => $this->countAll() - $this->countActive(),
                    'categories_with_products' => 0, // Get from database
                    'average_products_per_category' => 0,
                    'max_depth' => 0, // Calculate from tree
                    'recently_added' => [], // Last 5 categories
                    'most_products' => [], // Top 5 categories by product count
                ];
            }

            return $stats;
        }, 1800); // 30 minutes cache for statistics
    }

    public function countByStatus(bool $withTrashed = false): array
    {
        $cacheKey = $this->getCacheKey("count_by_status_" . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($withTrashed) {
            $builder = $withTrashed
                ? $this->categoryModel->withDeleted()
                : $this->categoryModel;

            // Note: This assumes 'active' and 'deleted_at' fields exist
            // Adjust based on your actual schema

            $total = $builder->countAllResults();

            $builder->where('active', true);
            $active = $builder->countAllResults();

            $builder->where('active', false);
            $inactive = $builder->countAllResults();

            $archived = $withTrashed
                ? $this->categoryModel->onlyDeleted()->countAllResults()
                : 0;

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'archived' => $archived,
            ];
        }, 300); // 5 minutes cache for counts
    }

    public function countAll(bool $withTrashed = false): int
    {
        $cacheKey = $this->getCacheKey("count_all_" . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($withTrashed) {
            $builder = $withTrashed
                ? $this->categoryModel->withDeleted()
                : $this->categoryModel;

            return $builder->countAllResults();
        }, 300);
    }

    public function countActive(): int
    {
        $cacheKey = $this->getCacheKey('count_active');

        return $this->cacheService->remember($cacheKey, function () {
            return $this->categoryModel->where('active', true)->countAllResults();
        }, 300);
    }

    public function getDepthStatistics(): array
    {
        $cacheKey = $this->getCacheKey('depth_stats');

        return $this->cacheService->remember($cacheKey, function () {
            // This would require recursive query or tree analysis
            // For now, return placeholder
            return [
                'depth_0' => 0, // Root categories
                'depth_1' => 0,
                'depth_2' => 0,
                'depth_3' => 0,
                'depth_4' => 0,
                'depth_5+' => 0,
                'max_depth' => 0,
                'average_depth' => 0.0,
            ];
        }, 1800);
    }

    // ==================== VALIDATION & BUSINESS RULES ====================

    public function canDelete(int $categoryId): array
    {
        $category = $this->find($categoryId, true);
        if (!$category) {
            return [
                'can_delete' => false,
                'reasons' => ['Category not found'],
                'affected_products' => 0,
            ];
        }

        $reasons = [];
        $canDelete = true;

        // Check if category has products
        $productCount = 0; // $this->productModel->where('category_id', $categoryId)->countAllResults();
        if ($productCount > 0) {
            $canDelete = false;
            $reasons[] = "Category has {$productCount} associated product(s)";
        }

        // Check if category has children
        $children = $this->getChildren($categoryId, false, true);
        $childCount = count($children);
        if ($childCount > 0) {
            $canDelete = false;
            $reasons[] = "Category has {$childCount} sub-category(s)";
        }

        return [
            'can_delete' => $canDelete,
            'reasons' => $reasons,
            'affected_products' => $productCount,
            'child_categories' => $childCount,
        ];
    }

    public function canArchive(int $categoryId): array
    {
        $category = $this->find($categoryId);
        if (!$category) {
            return [
                'can_archive' => false,
                'reasons' => ['Category not found'],
            ];
        }

        $reasons = [];
        $canArchive = true;

        // Check if already archived
        if ($category->isDeleted()) {
            $canArchive = false;
            $reasons[] = 'Category is already archived';
        }

        // Additional business rules can be added here

        return [
            'can_archive' => $canArchive,
            'reasons' => $reasons,
        ];
    }

    public function isSlugUnique(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->categoryModel->where('slug', $slug);

        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() === 0;
    }

    public function validate(Category $category): array
    {
        $errors = [];
        $isValid = true;

        // Required fields
        if (empty($category->getName())) {
            $errors[] = 'Category name is required';
            $isValid = false;
        }

        if (empty($category->getSlug())) {
            $errors[] = 'Category slug is required';
            $isValid = false;
        }

        // Slug uniqueness
        if (!$this->isSlugUnique($category->getSlug(), $category->getId())) {
            $errors[] = 'Category slug must be unique';
            $isValid = false;
        }

        // Slug format
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category->getSlug())) {
            $errors[] = 'Category slug can only contain lowercase letters, numbers, and hyphens';
            $isValid = false;
        }

        // Name length
        if (strlen($category->getName()) > 100) {
            $errors[] = 'Category name cannot exceed 100 characters';
            $isValid = false;
        }

        // Prevent circular reference
        if ($category->getId() && $category->getParentId()) {
            if ($this->wouldCreateCircularReference($category->getId(), $category->getParentId())) {
                $errors[] = 'Cannot set parent: would create circular reference';
                $isValid = false;
            }
        }

        return [
            'is_valid' => $isValid,
            'errors' => $errors,
        ];
    }

    // ==================== BATCH & BULK OPERATIONS ====================

    public function bulkUpdate(array $categoryIds, array $updateData): int
    {
        if (empty($categoryIds) || empty($updateData)) {
            return 0;
        }

        try {
            $this->db->transBegin();

            $updated = 0;

            foreach ($categoryIds as $categoryId) {
                try {
                    $category = $this->find($categoryId);
                    if (!$category) {
                        continue;
                    }

                    // Apply updates
                    foreach ($updateData as $field => $value) {
                        $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                        if (method_exists($category, $setter)) {
                            $category->$setter($value);
                        }
                    }

                    // Save updated category
                    if ($this->save($category)) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to update category {$categoryId}: " . $e->getMessage());
                    // Continue with other categories
                }
            }

            // Clear all caches
            $this->clearCache();

            $this->db->transCommit();

            return $updated;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository bulkUpdate failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function bulkDelete(array $categoryIds, bool $force = false): int
    {
        if (empty($categoryIds)) {
            return 0;
        }

        try {
            $this->db->transBegin();

            $deleted = 0;

            foreach ($categoryIds as $categoryId) {
                try {
                    if ($this->delete($categoryId, $force)) {
                        $deleted++;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to delete category {$categoryId}: " . $e->getMessage());
                    // Continue with other categories
                }
            }

            // Clear all caches
            $this->clearCache();

            $this->db->transCommit();

            return $deleted;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository bulkDelete failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function bulkRestore(array $categoryIds): int
    {
        if (empty($categoryIds)) {
            return 0;
        }

        try {
            $this->db->transBegin();

            $restored = 0;

            foreach ($categoryIds as $categoryId) {
                try {
                    if ($this->restore($categoryId)) {
                        $restored++;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to restore category {$categoryId}: " . $e->getMessage());
                    // Continue with other categories
                }
            }

            // Clear all caches
            $this->clearCache();

            $this->db->transCommit();

            return $restored;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'CategoryRepository bulkRestore failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ==================== CACHE MANAGEMENT ====================

    public function clearCache(?int $categoryId = null): void
    {
        if ($categoryId) {
            // Clear specific category caches
            $patterns = [
                $this->getCacheKey("find_{$categoryId}_*"),
                $this->getCacheKey("children_{$categoryId}_*"),
                $this->getCacheKey("parent_path_{$categoryId}_*"),
                $this->getCacheKey("stats_{$categoryId}"),
            ];

            foreach ($patterns as $pattern) {
                $this->cacheService->deleteMultiple(
                    $this->cacheService->getKeysByPattern($pattern)
                );
            }
        } else {
            // Clear all category caches
            $this->cacheService->deleteMultiple(
                $this->cacheService->getKeysByPattern($this->getCacheKey('*'))
            );
        }

        // Also clear tree and navigation caches
        $this->cacheService->delete($this->getCacheKey(self::CACHE_KEY_TREE . '*'));
        $this->cacheService->delete($this->getCacheKey(self::CACHE_KEY_FLATTENED . '*'));
        $this->cacheService->delete($this->getCacheKey(self::CACHE_KEY_NAVIGATION . '*'));
        $this->cacheService->delete($this->getCacheKey(self::CACHE_KEY_STATS . '*'));
        $this->cacheService->delete($this->getCacheKey(self::CACHE_KEY_ALL_ACTIVE));
    }

    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    public function setCacheTtl(int $ttl): self
    {
        $this->cacheTtl = $ttl;
        return $this;
    }

    // ==================== NAVIGATION & UI ====================

    public function getNavigation(int $maxDepth = 2, int $limitPerLevel = 15): array
    {
        $cacheKey = $this->getCacheKey("navigation_{$maxDepth}_{$limitPerLevel}");

        return $this->cacheService->remember($cacheKey, function () use ($maxDepth, $limitPerLevel) {
            $tree = $this->getTree(null, false, $maxDepth);
            return $this->limitTreeItems($tree, $limitPerLevel);
        }, 1800); // 30 minutes for navigation
    }

    public function getBreadcrumbs(int $categoryId, bool $includeCurrent = true): array
    {
        return $this->getParentPath($categoryId, $includeCurrent);
    }

    public function getSuggestions(?string $query = null, int $limit = 20): array
    {
        $categories = $this->search($query ?? '', false, true, $limit);

        $suggestions = [];
        foreach ($categories as $category) {
            $suggestions[$category->getId()] = $category->getName();
        }

        return $suggestions;
    }

    // ==================== PRIVATE HELPER METHODS ====================

    private function getCacheKey(string $suffix): string
    {
        return $this->cachePrefix . $suffix;
    }

    private function applyFilters(&$builder, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                if (is_array($value)) {
                    $builder->whereIn($field, $value);
                } else {
                    $builder->where($field, $value);
                }
            }
        }
    }

    private function buildTree(array $categories, ?int $parentId, int $currentDepth, ?int $maxDepth): array
    {
        if ($maxDepth !== null && $currentDepth >= $maxDepth) {
            return [];
        }

        $branch = [];

        foreach ($categories as $category) {
            // This assumes Category has getParentId() method
            // Adjust based on your actual entity
            $categoryParentId = 0; // $category->getParentId() ?? 0;

            if ($categoryParentId == $parentId) {
                $children = $this->buildTree($categories, $category->getId(), $currentDepth + 1, $maxDepth);

                $categoryData = $category->toArray();
                if (!empty($children)) {
                    $categoryData['children'] = $children;
                    $categoryData['has_children'] = true;
                    $categoryData['children_count'] = count($children);
                } else {
                    $categoryData['has_children'] = false;
                    $categoryData['children_count'] = 0;
                }

                $categoryData['depth'] = $currentDepth;

                $branch[] = $categoryData;
            }
        }

        return $branch;
    }

    private function flattenTree(array $tree, int $depth, string $indicator): array
    {
        $result = [];

        foreach ($tree as $item) {
            $name = str_repeat($indicator, $depth) . ' ' . $item['name'];
            $result[$item['id']] = $name;

            if (!empty($item['children'])) {
                $childItems = $this->flattenTree($item['children'], $depth + 1, $indicator);
                $result = $result + $childItems;
            }
        }

        return $result;
    }

    private function limitTreeItems(array $tree, int $limitPerLevel): array
    {
        if (count($tree) <= $limitPerLevel) {
            return $tree;
        }

        $limited = array_slice($tree, 0, $limitPerLevel);

        // Add "more" indicator if there are more items
        if (count($tree) > $limitPerLevel) {
            $limited[] = [
                'id' => null,
                'name' => 'More...',
                'slug' => '#',
                'has_more' => true,
                'total_count' => count($tree),
                'hidden_count' => count($tree) - $limitPerLevel,
            ];
        }

        return $limited;
    }

    private function calculateCategoryDepth(int $categoryId, int $currentDepth = 0): int
    {
        $category = $this->find($categoryId);
        if (!$category) {
            return $currentDepth;
        }

        // $parentId = $category->getParentId();
        $parentId = 0; // Replace with actual

        if ($parentId && $parentId != $categoryId) {
            return $this->calculateCategoryDepth($parentId, $currentDepth + 1);
        }

        return $currentDepth;
    }

    // ==================== FACTORY METHOD ====================

    public static function create(): self
    {
        $categoryModel = model(CategoryModel::class);
        $cacheService = service('cache');
        $auditService = service('audit');
        $db = db_connect();

        return new self($categoryModel, $cacheService, $auditService, $db);
    }
}
