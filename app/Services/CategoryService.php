<?php

namespace App\Services;

use App\DTOs\Requests\Category\CreateCategoryRequest;
use App\DTOs\Requests\Category\UpdateCategoryRequest;
use App\DTOs\Responses\CategoryResponse;
use App\DTOs\Responses\CategoryTreeResponse;
use App\Entities\Category;
use App\Entities\Product;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use App\Models\CategoryModel;
use App\Models\ProductModel;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use DateTimeImmutable;
use Exception;
use RuntimeException;

/**
 * Enterprise-grade Category Service
 * 
 * Manages hierarchical category structure with nested set pattern,
 * provides efficient tree operations, caching, and bulk operations.
 */
class CategoryService
{
    private CategoryModel $categoryModel;
    private ProductModel $productModel;
    private ValidationService $validationService;
    private AuditService $auditService;
    private CacheService $cacheService;
    private ConnectionInterface $db;
    
    // Configuration
    private array $config;
    
    // Cache constants
    private const CACHE_TTL = 7200; // 2 hours
    private const CACHE_PREFIX = 'category_service_';
    private const TREE_CACHE_TTL = 3600;
    private const NAVIGATION_CACHE_TTL = 1800;
    
    // Business rules
    private const MAX_CATEGORY_DEPTH = 5;
    private const MAX_CATEGORIES_PER_LEVEL = 50;
    private const MAX_CATEGORY_NAME_LENGTH = 100;
    private const MAX_CATEGORY_SLUG_LENGTH = 50;
    
    // Nested set constants
    private const ROOT_PARENT_ID = 0;
    
    public function __construct(
        CategoryModel $categoryModel,
        ProductModel $productModel,
        ValidationService $validationService,
        AuditService $auditService,
        CacheService $cacheService,
        ConnectionInterface $db,
        array $config = []
    ) {
        $this->categoryModel = $categoryModel;
        $this->productModel = $productModel;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->cacheService = $cacheService;
        $this->db = $db;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        // Set cache TTL from config
        $this->cacheService->setDefaultTtl($this->config['cache_ttl'] ?? self::CACHE_TTL);
    }
    
    /**
     * Create a new category with hierarchical support
     */
    public function create(CreateCategoryRequest $request, int $adminId): Category
    {
        // 1. Validate business rules
        $validationErrors = $this->validationService->validateCategoryOperation(
            $request->id ?? 0,
            ValidationService::CONTEXT_CREATE,
            $request->toArray()
        );
        
        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'CATEGORY_CREATE_VALIDATION',
                'Category creation validation failed',
                ['errors' => $validationErrors]
            );
        }
        
        // 2. Validate admin permissions
        $adminValidation = $this->validationService->validateAdminPermission(
            $adminId,
            ValidationService::CONTEXT_CREATE,
            'category'
        );
        
        if (!empty($adminValidation)) {
            throw ValidationException::forBusinessRule(
                'ADMIN_PERMISSION_DENIED',
                'Admin does not have permission to create categories',
                ['errors' => $adminValidation]
            );
        }
        
        // 3. Check parent category if provided
        $parentId = $request->parentId ?? self::ROOT_PARENT_ID;
        if ($parentId !== self::ROOT_PARENT_ID) {
            $parentCategory = $this->categoryModel->find($parentId);
            if (!$parentCategory || !$parentCategory->isActive()) {
                throw new DomainException(
                    'INVALID_PARENT_CATEGORY',
                    'Parent category is invalid or inactive',
                    ['parent_id' => $parentId]
                );
            }
            
            // Check max depth
            $parentDepth = $this->calculateCategoryDepth($parentId);
            if ($parentDepth >= self::MAX_CATEGORY_DEPTH) {
                throw new DomainException(
                    'MAX_DEPTH_EXCEEDED',
                    sprintf('Maximum category depth of %s exceeded', self::MAX_CATEGORY_DEPTH),
                    [
                        'parent_id' => $parentId,
                        'current_depth' => $parentDepth,
                        'max_depth' => self::MAX_CATEGORY_DEPTH
                    ]
                );
            }
        }
        
        // 4. Check sibling count
        $siblingCount = $this->countCategoriesByParent($parentId);
        if ($siblingCount >= self::MAX_CATEGORIES_PER_LEVEL) {
            throw new DomainException(
                'MAX_CATEGORIES_PER_LEVEL_EXCEEDED',
                sprintf('Maximum %s categories per level exceeded', self::MAX_CATEGORIES_PER_LEVEL),
                [
                    'parent_id' => $parentId,
                    'current_count' => $siblingCount,
                    'max_count' => self::MAX_CATEGORIES_PER_LEVEL
                ]
            );
        }
        
        // 5. Create category entity
        $categoryData = $request->toArray();
        $category = Category::fromArray($categoryData);
        
        // Set additional fields
        $category->setActive(true);
        $category->initializeTimestamps();
        
        // Set sort order (append to end)
        $category->setSortOrder($siblingCount + 1);
        
        // 6. Save with transaction (nested set updates)
        $this->db->transStart();
        
        try {
            // For nested set, we need to handle left/right values
            // In MVP, we'll use simple parent_id approach
            $savedCategory = $this->categoryModel->save($category);
            
            // 7. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            $this->auditService->logCreate(
                AuditService::ENTITY_CATEGORY,
                $savedCategory->getId(),
                $savedCategory,
                $admin,
                sprintf('Category created under parent ID: %s', $parentId)
            );
            
            $this->db->transComplete();
            
            // 8. Clear relevant caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 9. Publish event
            $this->publishEvent('category.created', [
                'category_id' => $savedCategory->getId(),
                'admin_id' => $adminId,
                'parent_id' => $parentId,
                'category_data' => $savedCategory->toArray(),
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $savedCategory;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category creation failed', [
                'admin_id' => $adminId,
                'request_data' => $request->toArray(),
                'parent_id' => $parentId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_CREATION_FAILED',
                'Failed to create category: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Update existing category
     */
    public function update(UpdateCategoryRequest $request, int $adminId): Category
    {
        $categoryId = $request->id;
        
        // 1. Get existing category
        $existingCategory = $this->categoryModel->find($categoryId);
        
        if (!$existingCategory) {
            throw CategoryNotFoundException::forId($categoryId);
        }
        
        // 2. Validate update
        $validationErrors = $this->validationService->validateCategoryOperation(
            $categoryId,
            ValidationService::CONTEXT_UPDATE,
            $request->toArray()
        );
        
        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'CATEGORY_UPDATE_VALIDATION',
                'Category update validation failed',
                ['errors' => $validationErrors]
            );
        }
        
        // 3. Check if parent change is requested
        $newParentId = $request->parentId ?? $existingCategory->getParentId();
        if ($newParentId !== $existingCategory->getParentId()) {
            // Validate new parent
            if ($newParentId !== self::ROOT_PARENT_ID) {
                $newParent = $this->categoryModel->find($newParentId);
                if (!$newParent || !$newParent->isActive()) {
                    throw new DomainException(
                        'INVALID_NEW_PARENT',
                        'New parent category is invalid or inactive',
                        ['new_parent_id' => $newParentId]
                    );
                }
                
                // Check for circular reference
                if ($this->isCircularReference($categoryId, $newParentId)) {
                    throw new DomainException(
                        'CIRCULAR_REFERENCE',
                        'Cannot move category under its own descendant',
                        [
                            'category_id' => $categoryId,
                            'new_parent_id' => $newParentId
                        ]
                    );
                }
                
                // Check max depth
                $newParentDepth = $this->calculateCategoryDepth($newParentId);
                $currentDepth = $this->calculateCategoryDepth($categoryId);
                if ($newParentDepth + $currentDepth > self::MAX_CATEGORY_DEPTH) {
                    throw new DomainException(
                        'MAX_DEPTH_EXCEEDED',
                        'Moving category would exceed maximum depth',
                        [
                            'category_id' => $categoryId,
                            'new_parent_id' => $newParentId,
                            'current_depth' => $currentDepth,
                            'new_parent_depth' => $newParentDepth,
                            'max_depth' => self::MAX_CATEGORY_DEPTH
                        ]
                    );
                }
            }
        }
        
        // 4. Apply changes
        $updateData = $request->toArray();
        $updatedCategory = clone $existingCategory;
        
        foreach ($updateData as $field => $value) {
            if ($value !== null) {
                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($updatedCategory, $setter)) {
                    $updatedCategory->$setter($value);
                }
            }
        }
        
        $updatedCategory->markAsUpdated();
        
        // 5. Save with transaction
        $this->db->transStart();
        
        try {
            $savedCategory = $this->categoryModel->save($updatedCategory);
            
            // 6. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            $this->auditService->logUpdate(
                AuditService::ENTITY_CATEGORY,
                $savedCategory->getId(),
                $existingCategory,
                $savedCategory,
                $admin,
                'Category updated'
            );
            
            $this->db->transComplete();
            
            // 7. Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 8. Publish event
            $this->publishEvent('category.updated', [
                'category_id' => $savedCategory->getId(),
                'admin_id' => $adminId,
                'changes' => $request->getChangedFields(),
                'old_category' => $existingCategory->toArray(),
                'new_category' => $savedCategory->toArray(),
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $savedCategory;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category update failed', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_UPDATE_FAILED',
                'Failed to update category: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Delete category (soft delete with dependency check)
     */
    public function delete(int $categoryId, int $adminId, bool $force = false): bool
    {
        // 1. Validate deletion
        $validationErrors = $this->validationService->validateCategoryOperation(
            $categoryId,
            ValidationService::CONTEXT_DELETE
        );
        
        if (!empty($validationErrors) && !$force) {
            throw ValidationException::forBusinessRule(
                'CATEGORY_DELETE_VALIDATION',
                'Category deletion validation failed',
                ['errors' => $validationErrors]
            );
        }
        
        // 2. Get existing category
        $existingCategory = $this->categoryModel->find($categoryId, true); // Include trashed
        
        if (!$existingCategory) {
            throw CategoryNotFoundException::forId($categoryId);
        }
        
        // 3. Check if already deleted
        if ($existingCategory->isDeleted() && !$force) {
            throw new DomainException(
                'CATEGORY_ALREADY_DELETED',
                'Category is already deleted',
                ['category_id' => $categoryId]
            );
        }
        
        // 4. Check for child categories
        $childCategories = $this->getChildCategories($categoryId, true);
        if (!empty($childCategories) && !$force) {
            throw new DomainException(
                'CATEGORY_HAS_CHILDREN',
                'Category has child categories. Delete or move them first.',
                [
                    'category_id' => $categoryId,
                    'child_count' => count($childCategories)
                ]
            );
        }
        
        // 5. Check for products in this category
        $productsInCategory = $this->productModel->findByCategory($categoryId, 1, 0);
        if (!empty($productsInCategory) && !$force) {
            throw new DomainException(
                'CATEGORY_HAS_PRODUCTS',
                'Category contains products. Move or delete them first.',
                [
                    'category_id' => $categoryId,
                    'product_count' => count($productsInCategory)
                ]
            );
        }
        
        // 6. Perform deletion with transaction
        $this->db->transStart();
        
        try {
            $result = $force ? 
                $this->categoryModel->delete($categoryId, true) : // Hard delete
                $this->categoryModel->delete($categoryId); // Soft delete
            
            if (!$result) {
                throw new RuntimeException('Category deletion failed');
            }
            
            // 7. If soft delete, update child categories' parent
            if (!$force && !empty($childCategories)) {
                $this->reparentOrphanedCategories($categoryId, $existingCategory->getParentId());
            }
            
            // 8. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            $this->auditService->logDelete(
                AuditService::ENTITY_CATEGORY,
                $categoryId,
                $existingCategory,
                $admin,
                !$force,
                'Category ' . ($force ? 'permanently deleted' : 'soft deleted')
            );
            
            $this->db->transComplete();
            
            // 9. Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 10. Publish event
            $this->publishEvent('category.deleted', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'force_delete' => $force,
                'had_children' => !empty($childCategories),
                'had_products' => !empty($productsInCategory),
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category deletion failed', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'force' => $force,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_DELETE_FAILED',
                'Failed to delete category: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Archive category (deactivate)
     */
    public function archive(int $categoryId, int $adminId, ?string $notes = null): Category
    {
        // 1. Get existing category
        $existingCategory = $this->categoryModel->find($categoryId);
        
        if (!$existingCategory) {
            throw CategoryNotFoundException::forId($categoryId);
        }
        
        // 2. Check if already inactive
        if (!$existingCategory->isActive()) {
            throw new DomainException(
                'CATEGORY_ALREADY_INACTIVE',
                'Category is already inactive',
                ['category_id' => $categoryId]
            );
        }
        
        // 3. Check for active child categories
        $activeChildren = $this->getChildCategories($categoryId, false);
        if (!empty($activeChildren)) {
            throw new DomainException(
                'CATEGORY_HAS_ACTIVE_CHILDREN',
                'Category has active child categories. Archive or deactivate them first.',
                [
                    'category_id' => $categoryId,
                    'active_child_count' => count($activeChildren)
                ]
            );
        }
        
        // 4. Check for products in this category
        $productsInCategory = $this->productModel->findByCategory($categoryId, 1, 0);
        if (!empty($productsInCategory)) {
            throw new DomainException(
                'CATEGORY_HAS_ACTIVE_PRODUCTS',
                'Category contains active products. Move or archive them first.',
                [
                    'category_id' => $categoryId,
                    'product_count' => count($productsInCategory)
                ]
            );
        }
        
        // 5. Prepare category for archiving
        $categoryToArchive = clone $existingCategory;
        $categoryToArchive->setActive(false);
        $categoryToArchive->markAsUpdated();
        
        // 6. Save with transaction
        $this->db->transStart();
        
        try {
            $archivedCategory = $this->categoryModel->save($categoryToArchive);
            
            // 7. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            
            $this->auditService->logStateTransition(
                AuditService::ENTITY_CATEGORY,
                $categoryId,
                'ACTIVE',
                'INACTIVE',
                $admin,
                ['notes' => $notes],
                $notes ?? 'Category archived'
            );
            
            $this->db->transComplete();
            
            // 8. Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 9. Publish event
            $this->publishEvent('category.archived', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'notes' => $notes,
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $archivedCategory;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category archiving failed', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_ARCHIVE_FAILED',
                'Failed to archive category: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Activate category
     */
    public function activate(int $categoryId, int $adminId): Category
    {
        // 1. Get existing category
        $existingCategory = $this->categoryModel->find($categoryId);
        
        if (!$existingCategory) {
            throw CategoryNotFoundException::forId($categoryId);
        }
        
        // 2. Check if already active
        if ($existingCategory->isActive()) {
            throw new DomainException(
                'CATEGORY_ALREADY_ACTIVE',
                'Category is already active',
                ['category_id' => $categoryId]
            );
        }
        
        // 3. Check parent category status
        $parentId = $existingCategory->getParentId();
        if ($parentId !== self::ROOT_PARENT_ID) {
            $parentCategory = $this->categoryModel->find($parentId);
            if ($parentCategory && !$parentCategory->isActive()) {
                throw new DomainException(
                    'PARENT_CATEGORY_INACTIVE',
                    'Parent category is inactive. Activate it first.',
                    [
                        'category_id' => $categoryId,
                        'parent_id' => $parentId
                    ]
                );
            }
        }
        
        // 4. Prepare category for activation
        $categoryToActivate = clone $existingCategory;
        $categoryToActivate->setActive(true);
        $categoryToActivate->markAsUpdated();
        
        // 5. Save with transaction
        $this->db->transStart();
        
        try {
            $activatedCategory = $this->categoryModel->save($categoryToActivate);
            
            // 6. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            
            $this->auditService->logStateTransition(
                AuditService::ENTITY_CATEGORY,
                $categoryId,
                'INACTIVE',
                'ACTIVE',
                $admin,
                null,
                'Category activated'
            );
            
            $this->db->transComplete();
            
            // 7. Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 8. Publish event
            $this->publishEvent('category.activated', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $activatedCategory;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category activation failed', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_ACTIVATION_FAILED',
                'Failed to activate category: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Restore deleted category
     */
    public function restore(int $categoryId, int $adminId): Category
    {
        // 1. Get existing category (including trashed)
        $existingCategory = $this->categoryModel->find($categoryId, true);
        
        if (!$existingCategory) {
            throw CategoryNotFoundException::forId($categoryId);
        }
        
        // 2. Check if category is deleted
        if (!$existingCategory->isDeleted()) {
            throw new DomainException(
                'CATEGORY_NOT_DELETED',
                'Category is not deleted',
                ['category_id' => $categoryId]
            );
        }
        
        // 3. Check parent category status
        $parentId = $existingCategory->getParentId();
        if ($parentId !== self::ROOT_PARENT_ID) {
            $parentCategory = $this->categoryModel->find($parentId);
            if (!$parentCategory || $parentCategory->isDeleted()) {
                throw new DomainException(
                    'PARENT_CATEGORY_DELETED',
                    'Parent category is deleted. Restore it first.',
                    [
                        'category_id' => $categoryId,
                        'parent_id' => $parentId
                    ]
                );
            }
        }
        
        // 4. Restore with transaction
        $this->db->transStart();
        
        try {
            $restored = $this->categoryModel->restore($categoryId);
            
            if (!$restored) {
                throw new RuntimeException('Category restoration failed');
            }
            
            // Get the restored category
            $restoredCategory = $this->categoryModel->find($categoryId);
            
            // 5. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            
            $this->auditService->logRestore(
                AuditService::ENTITY_CATEGORY,
                $categoryId,
                $restoredCategory,
                $admin,
                'Category restored from deleted state'
            );
            
            $this->db->transComplete();
            
            // 6. Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 7. Publish event
            $this->publishEvent('category.restored', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $restoredCategory;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category restoration failed', [
                'category_id' => $categoryId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_RESTORE_FAILED',
                'Failed to restore category: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Get category by ID or slug with caching
     */
    public function find($identifier, bool $withStatistics = false): CategoryResponse
    {
        $cacheKey = $this->getCacheKey(sprintf(
            'find_%s_%s',
            is_int($identifier) ? 'id' : 'slug',
            md5((string) $identifier)
        ));
        
        return $this->cacheService->remember($cacheKey, function() use ($identifier, $withStatistics) {
            // 1. Find category
            $category = is_int($identifier) ?
                $this->categoryModel->find($identifier) :
                $this->categoryModel->findBySlug($identifier);
            
            if (!$category || !$category->isActive()) {
                throw CategoryNotFoundException::forId($identifier);
            }
            
            // 2. Load statistics if requested
            $statistics = $withStatistics ? $this->getCategoryStatistics($category->getId()) : null;
            
            // 3. Create response
            return CategoryResponse::fromEntity($category, [
                'with_statistics' => $withStatistics,
                'statistics' => $statistics
            ]);
            
        }, $this->config['cache_ttl_find'] ?? self::CACHE_TTL);
    }
    
    /**
     * Get category tree (hierarchical structure)
     */
    public function getTree(bool $includeInactive = false, int $maxDepth = null): CategoryTreeResponse
    {
        $cacheKey = $this->getCacheKey(sprintf(
            'tree_%s_%s',
            $includeInactive ? 'all' : 'active',
            $maxDepth ?? 'full'
        ));
        
        return $this->cacheService->remember($cacheKey, function() use ($includeInactive, $maxDepth) {
            // 1. Get all categories
            $categories = $includeInactive ?
                $this->categoryModel->findAll() :
                $this->categoryModel->findActive();
            
            // 2. Build tree structure
            $tree = $this->buildCategoryTree($categories, self::ROOT_PARENT_ID, 0, $maxDepth);
            
            // 3. Create tree response
            return CategoryTreeResponse::fromTree($tree, [
                'include_inactive' => $includeInactive,
                'max_depth' => $maxDepth,
                'total_categories' => count($categories)
            ]);
            
        }, $this->config['cache_ttl_tree'] ?? self::TREE_CACHE_TTL);
    }
    
    /**
     * Get flat category list with optional filtering
     */
    public function getList(
        ?int $parentId = null,
        bool $activeOnly = true,
        string $sortBy = 'sort_order',
        string $sortDirection = 'ASC',
        int $limit = 100,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(sprintf(
            'list_%s_%s_%s_%s_%s_%s',
            $parentId ?? 'root',
            $activeOnly ? 'active' : 'all',
            $sortBy,
            $sortDirection,
            $limit,
            $offset
        ));
        
        return $this->cacheService->remember($cacheKey, function() use (
            $parentId, $activeOnly, $sortBy, $sortDirection, $limit, $offset
        ) {
            // 1. Get categories based on filters
            $categories = $this->getCategoriesByParent($parentId, $activeOnly);
            
            // 2. Sort categories
            $categories = $this->sortCategories($categories, $sortBy, $sortDirection);
            
            // 3. Apply limit and offset
            $paginatedCategories = array_slice($categories, $offset, $limit);
            
            // 4. Convert to responses
            $responses = [];
            foreach ($paginatedCategories as $category) {
                $responses[] = CategoryResponse::fromEntity($category, [
                    'with_product_count' => true,
                    'with_children_count' => true
                ]);
            }
            
            return [
                'data' => $responses,
                'meta' => [
                    'total' => count($categories),
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < count($categories),
                    'parent_id' => $parentId
                ]
            ];
            
        }, $this->config['cache_ttl_list'] ?? self::CACHE_TTL);
    }
    
    /**
     * Get navigation tree (optimized for frontend)
     */
    public function getNavigation(int $maxDepth = 2, int $limit = 15): array
    {
        $cacheKey = $this->getCacheKey(sprintf('navigation_%s_%s', $maxDepth, $limit));
        
        return $this->cacheService->remember($cacheKey, function() use ($maxDepth, $limit) {
            // 1. Get active categories with product counts
            $categories = $this->categoryModel->withProductCount($limit);
            
            // 2. Build navigation tree
            $navigation = $this->buildNavigationTree($categories, self::ROOT_PARENT_ID, 0, $maxDepth);
            
            // 3. Sort by product count (most popular first)
            usort($navigation, function($a, $b) {
                return ($b['product_count'] ?? 0) <=> ($a['product_count'] ?? 0);
            });
            
            return [
                'navigation' => $navigation,
                'max_depth' => $maxDepth,
                'total_categories' => count($categories),
                'generated_at' => (new DateTimeImmutable())->format('c')
            ];
            
        }, $this->config['cache_ttl_navigation'] ?? self::NAVIGATION_CACHE_TTL);
    }
    
    /**
     * Reorder categories (drag & drop support)
     */
    public function reorder(array $orderData, int $adminId): bool
    {
        // 1. Validate order data
        if (empty($orderData) || !isset($orderData['items'])) {
            throw new DomainException(
                'INVALID_ORDER_DATA',
                'Invalid order data provided',
                ['order_data' => $orderData]
            );
        }
        
        $items = $orderData['items'];
        $parentId = $orderData['parent_id'] ?? self::ROOT_PARENT_ID;
        
        // 2. Validate all category IDs exist
        $categoryIds = array_column($items, 'id');
        $existingCategories = $this->categoryModel->findByIds($categoryIds);
        
        if (count($existingCategories) !== count($categoryIds)) {
            $existingIds = array_map(fn($cat) => $cat->getId(), $existingCategories);
            $missingIds = array_diff($categoryIds, $existingIds);
            
            throw new DomainException(
                'INVALID_CATEGORY_IDS',
                'Some category IDs are invalid',
                ['missing_ids' => $missingIds]
            );
        }
        
        // 3. Save original state for audit
        $originalCategories = [];
        foreach ($existingCategories as $category) {
            $originalCategories[$category->getId()] = clone $category;
        }
        
        // 4. Reorder with transaction
        $this->db->transStart();
        
        try {
            $success = $this->categoryModel->updateSortOrder($items);
            
            if (!$success) {
                throw new RuntimeException('Failed to update sort order');
            }
            
            // 5. Update parent if changed
            if (isset($orderData['parent_id'])) {
                foreach ($items as $item) {
                    $category = $this->categoryModel->find($item['id']);
                    if ($category && $category->getParentId() !== $parentId) {
                        $category->setParentId($parentId);
                        $category->markAsUpdated();
                        $this->categoryModel->save($category);
                    }
                }
            }
            
            // 6. Log audit trail for each category
            $admin = $this->getAdminModel()->find($adminId);
            
            foreach ($items as $index => $item) {
                $categoryId = $item['id'];
                $newPosition = $index + 1;
                
                $category = $this->categoryModel->find($categoryId);
                $originalCategory = $originalCategories[$categoryId] ?? null;
                
                if ($originalCategory && $category) {
                    $changes = [];
                    if ($originalCategory->getSortOrder() !== $newPosition) {
                        $changes[] = sprintf('position: %d → %d', $originalCategory->getSortOrder(), $newPosition);
                    }
                    if ($originalCategory->getParentId() !== $category->getParentId()) {
                        $changes[] = sprintf('parent: %d → %d', $originalCategory->getParentId(), $category->getParentId());
                    }
                    
                    if (!empty($changes)) {
                        $this->auditService->logUpdate(
                            AuditService::ENTITY_CATEGORY,
                            $categoryId,
                            $originalCategory,
                            $category,
                            $admin,
                            'Category reordered: ' . implode(', ', $changes)
                        );
                    }
                }
            }
            
            $this->db->transComplete();
            
            // 7. Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // 8. Publish event
            $this->publishEvent('category.reordered', [
                'items' => $items,
                'parent_id' => $parentId,
                'admin_id' => $adminId,
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Category reordering failed', [
                'order_data' => $orderData,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'CATEGORY_REORDER_FAILED',
                'Failed to reorder categories: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }
    
    /**
     * Bulk update categories
     */
    public function bulkUpdate(array $categoryIds, array $updateData, int $adminId): array
    {
        // 1. Validate bulk operation
        $validationErrors = $this->validationService->validateBulkOperation(
            $categoryIds,
            'category',
            ValidationService::CONTEXT_UPDATE,
            $adminId
        );
        
        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'BULK_CATEGORY_UPDATE_VALIDATION',
                'Bulk category update validation failed',
                ['errors' => $validationErrors]
            );
        }
        
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($categoryIds)
        ];
        
        $this->db->transStart();
        
        try {
            foreach ($categoryIds as $categoryId) {
                try {
                    $existingCategory = $this->categoryModel->find($categoryId);
                    
                    if (!$existingCategory) {
                        $results['failed'][] = [
                            'category_id' => $categoryId,
                            'error' => 'Category not found'
                        ];
                        continue;
                    }
                    
                    // Validate individual update
                    $categoryValidation = $this->validationService->validateCategoryOperation(
                        $categoryId,
                        ValidationService::CONTEXT_UPDATE,
                        $updateData
                    );
                    
                    if (!empty($categoryValidation)) {
                        $results['failed'][] = [
                            'category_id' => $categoryId,
                            'error' => 'Validation failed',
                            'details' => $categoryValidation
                        ];
                        continue;
                    }
                    
                    // Apply update
                    $updatedCategory = clone $existingCategory;
                    
                    foreach ($updateData as $field => $value) {
                        if ($value !== null) {
                            $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                            if (method_exists($updatedCategory, $setter)) {
                                $updatedCategory->$setter($value);
                            }
                        }
                    }
                    
                    $updatedCategory->markAsUpdated();
                    
                    // Save
                    $savedCategory = $this->categoryModel->save($updatedCategory);
                    
                    // Log audit
                    $admin = $this->getAdminModel()->find($adminId);
                    
                    $this->auditService->logUpdate(
                        AuditService::ENTITY_CATEGORY,
                        $categoryId,
                        $existingCategory,
                        $savedCategory,
                        $admin,
                        'Bulk update: ' . implode(', ', array_keys($updateData))
                    );
                    
                    $results['successful'][] = [
                        'category_id' => $categoryId,
                        'changes' => array_keys($updateData)
                    ];
                    
                } catch (Exception $e) {
                    $results['failed'][] = [
                        'category_id' => $categoryId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->db->transComplete();
            
            // Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // Publish event
            $this->publishEvent('category.bulk_updated', [
                'category_ids' => $categoryIds,
                'admin_id' => $adminId,
                'update_data' => $updateData,
                'results' => $results,
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError('Bulk category update failed', [
                'category_ids' => $categoryIds,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'BULK_CATEGORY_UPDATE_FAILED',
                'Bulk category update failed: ' . $e->getMessage(),
                ['results' => $results],
                500,
                $e
            );
        }
    }
    
    /**
     * Bulk archive categories
     */
    public function bulkArchive(array $categoryIds, int $adminId): array
    {
        return $this->executeBulkStateChange(
            $categoryIds,
            $adminId,
            'archive',
            function($categoryId, $adminId) {
                return $this->archive($categoryId, $adminId, 'Bulk archive');
            },
            'Bulk archive'
        );
    }
    
    /**
     * Bulk activate categories
     */
    public function bulkActivate(array $categoryIds, int $adminId): array
    {
        return $this->executeBulkStateChange(
            $categoryIds,
            $adminId,
            'activate',
            function($categoryId, $adminId) {
                return $this->activate($categoryId, $adminId);
            },
            'Bulk activation'
        );
    }
    
    /**
     * Get category statistics
     */
    public function getCategoryStatistics(int $categoryId): array
    {
        $cacheKey = $this->getCacheKey(sprintf('stats_%s', $categoryId));
        
        return $this->cacheService->remember($cacheKey, function() use ($categoryId) {
            // 1. Count products in this category
            $productCount = count($this->productModel->findByCategory($categoryId, 1000, 0));
            
            // 2. Count child categories
            $childCategories = $this->getChildCategories($categoryId, false);
            $childCount = count($childCategories);
            
            // 3. Calculate depth
            $depth = $this->calculateCategoryDepth($categoryId);
            
            // 4. Get sibling position
            $siblings = $this->getCategoriesByParent(
                $this->categoryModel->find($categoryId)?->getParentId() ?? self::ROOT_PARENT_ID,
                true
            );
            $siblingCount = count($siblings);
            $currentPosition = 0;
            
            foreach ($siblings as $index => $sibling) {
                if ($sibling->getId() === $categoryId) {
                    $currentPosition = $index + 1;
                    break;
                }
            }
            
            return [
                'product_count' => $productCount,
                'child_category_count' => $childCount,
                'depth' => $depth,
                'sibling_position' => $currentPosition,
                'sibling_count' => $siblingCount,
                'has_children' => $childCount > 0,
                'has_products' => $productCount > 0,
                'calculated_at' => (new DateTimeImmutable())->format('c')
            ];
            
        }, 300); // 5 minute cache for statistics
    }
    
    /**
     * Get system-wide category statistics
     */
    public function getSystemStatistics(): array
    {
        $cacheKey = $this->getCacheKey('system_stats');
        
        return $this->cacheService->remember($cacheKey, function() {
            // 1. Get all categories
            $categories = $this->categoryModel->findAll();
            
            // 2. Calculate statistics
            $activeCount = 0;
            $inactiveCount = 0;
            $deletedCount = 0;
            $maxDepth = 0;
            $categoryByDepth = [];
            
            foreach ($categories as $category) {
                if ($category->isDeleted()) {
                    $deletedCount++;
                } elseif ($category->isActive()) {
                    $activeCount++;
                } else {
                    $inactiveCount++;
                }
                
                // Calculate depth for active categories
                if (!$category->isDeleted()) {
                    $depth = $this->calculateCategoryDepth($category->getId());
                    $maxDepth = max($maxDepth, $depth);
                    
                    if (!isset($categoryByDepth[$depth])) {
                        $categoryByDepth[$depth] = 0;
                    }
                    $categoryByDepth[$depth]++;
                }
            }
            
            // 3. Get category with most products
            $categoriesWithCounts = $this->categoryModel->withProductCount(10);
            $topCategories = [];
            
            foreach ($categoriesWithCounts as $categoryData) {
                $topCategories[] = [
                    'id' => $categoryData['id'],
                    'name' => $categoryData['name'],
                    'product_count' => $categoryData['product_count'] ?? 0
                ];
            }
            
            return [
                'total_categories' => count($categories),
                'active_categories' => $activeCount,
                'inactive_categories' => $inactiveCount,
                'deleted_categories' => $deletedCount,
                'max_depth' => $maxDepth,
                'distribution_by_depth' => $categoryByDepth,
                'top_categories_by_products' => $topCategories,
                'calculated_at' => (new DateTimeImmutable())->format('c')
            ];
            
        }, 600); // 10 minute cache for system stats
    }
    
    /**
     * Search categories by keyword
     */
    public function search(string $keyword, bool $includeInactive = false, int $limit = 20): array
    {
        $cacheKey = $this->getCacheKey(sprintf(
            'search_%s_%s_%s',
            md5($keyword),
            $includeInactive ? 'all' : 'active',
            $limit
        ));
        
        return $this->cacheService->remember($cacheKey, function() use ($keyword, $includeInactive, $limit) {
            $results = $this->categoryModel->search($keyword, $limit);
            
            if (!$includeInactive) {
                $results = array_filter($results, function($category) {
                    return $category->isActive() && !$category->isDeleted();
                });
            }
            
            $responses = [];
            foreach ($results as $category) {
                $responses[] = CategoryResponse::fromEntity($category, [
                    'with_product_count' => true,
                    'highlight_query' => $keyword
                ]);
            }
            
            return [
                'data' => $responses,
                'query' => $keyword,
                'total' => count($responses),
                'limit' => $limit
            ];
            
        }, 300); // 5 minute cache for search results
    }
    
    /**
     * Build hierarchical category tree
     */
    private function buildCategoryTree(array $categories, int $parentId, int $currentDepth, ?int $maxDepth = null): array
    {
        if ($maxDepth !== null && $currentDepth >= $maxDepth) {
            return [];
        }
        
        $tree = [];
        
        foreach ($categories as $category) {
            if ($category->getParentId() == $parentId && !$category->isDeleted()) {
                $node = CategoryResponse::fromEntity($category, [
                    'with_product_count' => true,
                    'with_children_count' => true
                ])->toArray();
                
                $node['depth'] = $currentDepth;
                $node['children'] = $this->buildCategoryTree(
                    $categories,
                    $category->getId(),
                    $currentDepth + 1,
                    $maxDepth
                );
                
                $tree[] = $node;
            }
        }
        
        // Sort by sort_order
        usort($tree, function($a, $b) {
            return $a['sort_order'] <=> $b['sort_order'];
        });
        
        return $tree;
    }
    
    /**
     * Build navigation tree with product counts
     */
    private function buildNavigationTree(array $categories, int $parentId, int $currentDepth, int $maxDepth): array
    {
        if ($currentDepth >= $maxDepth) {
            return [];
        }
        
        $tree = [];
        
        foreach ($categories as $category) {
            if ($category->getParentId() == $parentId && $category->isActive() && !$category->isDeleted()) {
                $node = [
                    'id' => $category->getId(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                    'icon' => $category->getIcon(),
                    'product_count' => $category->getProductCount() ?? 0,
                    'depth' => $currentDepth,
                    'children' => $this->buildNavigationTree(
                        $categories,
                        $category->getId(),
                        $currentDepth + 1,
                        $maxDepth
                    )
                ];
                
                $tree[] = $node;
            }
        }
        
        // Sort by product count (descending)
        usort($tree, function($a, $b) {
            return $b['product_count'] <=> $a['product_count'];
        });
        
        return $tree;
    }
    
    /**
     * Get child categories
     */
    private function getChildCategories(int $parentId, bool $includeInactive): array
    {
        $categories = $this->getCategoriesByParent($parentId, !$includeInactive);
        
        if (!$includeInactive) {
            $categories = array_filter($categories, function($category) {
                return $category->isActive();
            });
        }
        
        return $categories;
    }
    
    /**
     * Get categories by parent ID
     */
    private function getCategoriesByParent(?int $parentId, bool $activeOnly = true): array
    {
        // In a real implementation, this would query the database
        // For MVP, we'll fetch all and filter
        
        $allCategories = $activeOnly ? 
            $this->categoryModel->findActive() :
            $this->categoryModel->findAll();
        
        return array_filter($allCategories, function($category) use ($parentId) {
            return $category->getParentId() == $parentId && !$category->isDeleted();
        });
    }
    
    /**
     * Count categories by parent
     */
    private function countCategoriesByParent(?int $parentId): int
    {
        $categories = $this->getCategoriesByParent($parentId, true);
        return count($categories);
    }
    
    /**
     * Calculate category depth
     */
    private function calculateCategoryDepth(int $categoryId, int $currentDepth = 0): int
    {
        $category = $this->categoryModel->find($categoryId);
        
        if (!$category || $category->getParentId() === self::ROOT_PARENT_ID) {
            return $currentDepth;
        }
        
        return $this->calculateCategoryDepth($category->getParentId(), $currentDepth + 1);
    }
    
    /**
     * Check for circular reference
     */
    private function isCircularReference(int $categoryId, int $potentialParentId): bool
    {
        if ($categoryId === $potentialParentId) {
            return true;
        }
        
        $parentCategory = $this->categoryModel->find($potentialParentId);
        
        while ($parentCategory && $parentCategory->getParentId() !== self::ROOT_PARENT_ID) {
            if ($parentCategory->getParentId() === $categoryId) {
                return true;
            }
            $parentCategory = $this->categoryModel->find($parentCategory->getParentId());
        }
        
        return false;
    }
    
    /**
     * Reparent orphaned categories when parent is deleted
     */
    private function reparentOrphanedCategories(int $deletedParentId, int $newParentId): void
    {
        $orphanedCategories = $this->getChildCategories($deletedParentId, true);
        
        foreach ($orphanedCategories as $category) {
            $category->setParentId($newParentId);
            $category->markAsUpdated();
            $this->categoryModel->save($category);
        }
    }
    
    /**
     * Sort categories by field
     */
    private function sortCategories(array $categories, string $sortBy, string $sortDirection): array
    {
        usort($categories, function($a, $b) use ($sortBy, $sortDirection) {
            $getter = 'get' . str_replace('_', '', ucwords($sortBy, '_'));
            
            if (!method_exists($a, $getter) || !method_exists($b, $getter)) {
                return 0;
            }
            
            $valueA = $a->$getter();
            $valueB = $b->$getter();
            
            $comparison = $valueA <=> $valueB;
            
            return $sortDirection === 'DESC' ? -$comparison : $comparison;
        });
        
        return $categories;
    }
    
    /**
     * Execute bulk state change operation
     */
    private function executeBulkStateChange(
        array $categoryIds,
        int $adminId,
        string $operation,
        callable $operationCallback,
        string $auditNote
    ): array {
        // Validate bulk operation
        $validationErrors = $this->validationService->validateBulkOperation(
            $categoryIds,
            'category',
            $operation === 'archive' ? ValidationService::CONTEXT_ARCHIVE : ValidationService::CONTEXT_UPDATE,
            $adminId
        );
        
        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'BULK_' . strtoupper($operation) . '_VALIDATION',
                "Bulk $operation validation failed",
                ['errors' => $validationErrors]
            );
        }
        
        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($categoryIds)
        ];
        
        $this->db->transStart();
        
        try {
            $admin = $this->getAdminModel()->find($adminId);
            
            foreach ($categoryIds as $categoryId) {
                try {
                    $existingCategory = $this->categoryModel->find($categoryId);
                    
                    if (!$existingCategory) {
                        $results['failed'][] = [
                            'category_id' => $categoryId,
                            'error' => 'Category not found'
                        ];
                        continue;
                    }
                    
                    // Execute the operation
                    $resultCategory = $operationCallback($categoryId, $adminId);
                    
                    // Log audit for this category
                    $oldState = $existingCategory->isActive() ? 'ACTIVE' : 'INACTIVE';
                    $newState = $resultCategory->isActive() ? 'ACTIVE' : 'INACTIVE';
                    
                    $this->auditService->logStateTransition(
                        AuditService::ENTITY_CATEGORY,
                        $categoryId,
                        $oldState,
                        $newState,
                        $admin,
                        ['bulk_operation' => true],
                        $auditNote
                    );
                    
                    $results['successful'][] = [
                        'category_id' => $categoryId,
                        'from_state' => $oldState,
                        'to_state' => $newState
                    ];
                    
                } catch (Exception $e) {
                    $results['failed'][] = [
                        'category_id' => $categoryId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            $this->db->transComplete();
            
            // Clear caches
            $this->clearCategoryCaches();
            $this->clearNavigationCaches();
            
            // Publish bulk event
            $this->publishEvent("category.bulk_$operation", [
                'category_ids' => $categoryIds,
                'admin_id' => $adminId,
                'operation' => $operation,
                'results' => $results,
                'timestamp' => new DateTimeImmutable()
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            $this->db->transRollback();
            
            $this->logError("Bulk $operation failed", [
                'category_ids' => $categoryIds,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);
            
            throw new DomainException(
                'BULK_' . strtoupper($operation) . '_FAILED',
                "Bulk $operation failed: " . $e->getMessage(),
                ['results' => $results],
                500,
                $e
            );
        }
    }
    
    /**
     * Clear category caches
     */
    private function clearCategoryCaches(): void
    {
        $this->cacheService->deleteMultiple([
            $this->getCacheKey('tree_*'),
            $this->getCacheKey('list_*'),
            $this->getCacheKey('find_*'),
            $this->getCacheKey('stats_*'),
            $this->getCacheKey('search_*'),
        ]);
    }
    
    /**
     * Clear navigation caches
     */
    private function clearNavigationCaches(): void
    {
        $this->cacheService->deleteMultiple([
            $this->getCacheKey('navigation_*'),
        ]);
    }
    
    /**
     * Generate cache key
     */
    private function getCacheKey(string $suffix): string
    {
        return self::CACHE_PREFIX . $suffix;
    }
    
    /**
     * Publish event for loose coupling
     */
    private function publishEvent(string $eventType, array $payload): void
    {
        if (!$this->config['enable_events']) {
            return;
        }
        
        try {
            $event = [
                'event' => $eventType,
                'payload' => $payload,
                'timestamp' => (new DateTimeImmutable())->format('c'),
                'source' => 'CategoryService'
            ];
            
            // Log event for debugging
            if ($this->config['log_events']) {
                $this->logEvent($event);
            }
            
        } catch (Exception $e) {
            $this->logError('Event publishing failed', [
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Lazy-load AdminModel
     */
    private function getAdminModel()
    {
        // In a real implementation, inject via constructor
        return model(\App\Models\AdminModel::class);
    }
    
    /**
     * Log error with context
     */
    private function logError(string $message, array $context = []): void
    {
        error_log(sprintf(
            '[CategoryService Error] %s: %s',
            $message,
            json_encode($context, JSON_PRETTY_PRINT)
        ));
    }
    
    /**
     * Log event for debugging
     */
    private function logEvent(array $event): void
    {
        error_log(sprintf(
            '[CategoryService Event] %s',
            json_encode($event, JSON_PRETTY_PRINT)
        ));
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'cache_ttl' => self::CACHE_TTL,
            'cache_ttl_find' => self::CACHE_TTL,
            'cache_ttl_tree' => self::TREE_CACHE_TTL,
            'cache_ttl_list' => self::CACHE_TTL,
            'cache_ttl_navigation' => self::NAVIGATION_CACHE_TTL,
            'enable_events' => true,
            'log_events' => false,
            'max_category_depth' => self::MAX_CATEGORY_DEPTH,
            'max_categories_per_level' => self::MAX_CATEGORIES_PER_LEVEL,
            'auto_reparent_orphans' => true,
            'validate_hierarchy' => true,
        ];
    }
    
    /**
     * Create CategoryService instance with default dependencies
     */
    public static function create(): self
    {
        $categoryModel = model(CategoryModel::class);
        $productModel = model(ProductModel::class);
        $validationService = ValidationService::create();
        $auditService = AuditService::create();
        $cacheService = CacheService::create();
        $db = \Config\Database::connect();
        
        return new self(
            $categoryModel,
            $productModel,
            $validationService,
            $auditService,
            $cacheService,
            $db
        );
    }
}