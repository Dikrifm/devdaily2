<?php

namespace App\Services;

use App\Contracts\CategoryInterface;
use App\DTOs\Requests\Category\CreateCategoryRequest;
use App\DTOs\Requests\Category\UpdateCategoryRequest;
use App\DTOs\Responses\CategoryResponse;
use App\DTOs\Responses\CategoryTreeResponse;
use App\Entities\Category;
use App\Enums\ProductStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Validators\CategoryValidator;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Category Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation for category business operations.
 * Manages category lifecycle, hierarchy, and business rules with atomic transactions and caching.
 *
 * @package App\Services
 */
class CategoryService extends BaseService implements CategoryInterface
{
    /**
     * Category repository for data persistence
     *
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $categoryRepository;

    /**
     * Category validator for business rule validation
     *
     * @var CategoryValidator
     */
    private CategoryValidator $categoryValidator;

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db
     * @param CacheInterface $cache
     * @param AuditService $auditService
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryValidator $categoryValidator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        CategoryRepositoryInterface $categoryRepository,
        CategoryValidator $categoryValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->categoryRepository = $categoryRepository;
        $this->categoryValidator = $categoryValidator;
        
        $this->validateCategoryDependencies();
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function createCategory(CreateCategoryRequest $request): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.create');
        
        // Validate DTO
        $this->validateDTOOrFail($request, ['context' => 'create']);
        
        return $this->transaction(function () use ($request) {
            // Generate slug if not provided
            $slug = $request->slug ?? $this->generateSlug($request->name);
            
            // Validate slug availability
            if (!$this->isSlugAvailable($slug)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Slug sudah digunakan oleh kategori lain',
                    ['field' => 'slug', 'value' => $slug]
                );
            }
            
            // Create entity
            $category = new Category($request->name, $slug);
            
            // Set optional properties
            if ($request->parentId !== null) {
                // Validate parent exists and is not circular
                $this->validateHierarchy(0, $request->parentId);
                $category->setParentId($request->parentId);
            }
            
            if ($request->icon !== null) {
                $category->setIcon($request->icon);
            }
            
            if ($request->sortOrder !== null) {
                $category->setSortOrder($request->sortOrder);
            }
            
            if ($request->active !== null) {
                $category->setActive($request->active);
            }
            
            // Business rule validation
            $businessErrors = $this->validateCategoryBusinessRules($category, 'create');
            if (!empty($businessErrors)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Validasi bisnis kategori gagal',
                    ['errors' => $businessErrors]
                );
            }
            
            // Save to repository
            $savedCategory = $this->categoryRepository->save($category);
            
            // Queue cache invalidation
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            if ($request->parentId) {
                $this->queueCacheOperation($this->getCategoryCacheKey($request->parentId));
            }
            
            // Audit log
            $this->audit(
                'category.create',
                'category',
                $savedCategory->getId(),
                null,
                $savedCategory->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'parent_id' => $request->parentId,
                    'slug' => $slug
                ]
            );
            
            return CategoryResponse::fromEntity($savedCategory);
        }, 'create_category');
    }

    /**
     * {@inheritDoc}
     */
    public function updateCategory(UpdateCategoryRequest $request): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.update');
        
        // Validate DTO
        $this->validateDTOOrFail($request, ['context' => 'update']);
        
        return $this->transaction(function () use ($request) {
            // Get existing category
            $existingCategory = $this->getEntity(
                $this->categoryRepository,
                $request->categoryId
            );
            
            // Store old values for audit
            $oldValues = $existingCategory->toArray();
            
            // Update properties
            $category = clone $existingCategory;
            
            if ($request->name !== null) {
                $category->setName($request->name);
            }
            
            if ($request->slug !== null) {
                // Validate slug availability
                if (!$this->isSlugAvailable($request->slug, $request->categoryId)) {
                    throw ValidationException::forBusinessRule(
                        $this->getServiceName(),
                        'Slug sudah digunakan oleh kategori lain',
                        ['field' => 'slug', 'value' => $request->slug]
                    );
                }
                $category->setSlug($request->slug);
            }
            
            if ($request->parentId !== null) {
                // Validate hierarchy (no circular reference)
                $this->validateHierarchy($request->categoryId, $request->parentId);
                $category->setParentId($request->parentId);
            }
            
            if ($request->icon !== null) {
                $category->setIcon($request->icon);
            }
            
            if ($request->sortOrder !== null) {
                $category->setSortOrder($request->sortOrder);
            }
            
            if ($request->active !== null) {
                $category->setActive($request->active);
            }
            
            // Business rule validation
            $businessErrors = $this->validateCategoryBusinessRules($category, 'update');
            if (!empty($businessErrors)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Validasi bisnis kategori gagal',
                    ['errors' => $businessErrors]
                );
            }
            
            // Save updates
            $updatedCategory = $this->categoryRepository->save($category);
            
            // Queue cache invalidation
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            $this->queueCacheOperation($this->getCategoryCacheKey($request->categoryId));
            if ($existingCategory->getParentId() !== $request->parentId) {
                $this->queueCacheOperation($this->getCategoryCacheKey($existingCategory->getParentId()));
                if ($request->parentId) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($request->parentId));
                }
            }
            
            // Audit log
            $this->audit(
                'category.update',
                'category',
                $request->categoryId,
                $oldValues,
                $updatedCategory->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'changed_fields' => array_keys(array_diff_assoc($updatedCategory->toArray(), $oldValues))
                ]
            );
            
            return CategoryResponse::fromEntity($updatedCategory);
        }, 'update_category');
    }

    /**
     * {@inheritDoc}
     */
    public function deleteCategory(int $categoryId, bool $force = false): bool
    {
        // Authorization check
        $this->authorize('category.delete');
        
        return $this->transaction(function () use ($categoryId, $force) {
            // Get category
            $category = $this->getEntity(
                $this->categoryRepository,
                $categoryId
            );
            
            // Check preconditions
            if (!$force) {
                $preconditions = $this->validateDeletion($categoryId);
                if (!$preconditions['can_delete']) {
                    throw new DomainException(
                        'Kategori tidak dapat dihapus karena masih memiliki produk atau subkategori',
                        'CATEGORY_DELETION_PRECONDITION_FAILED',
                        $preconditions
                    );
                }
            }
            
            // Store for audit
            $oldValues = $category->toArray();
            
            // Perform deletion
            $result = $this->categoryRepository->delete($categoryId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation('category:*');
                $this->queueCacheOperation('category_tree:*');
                $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
                if ($category->getParentId()) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($category->getParentId()));
                }
                
                // Audit log
                $this->audit(
                    'category.delete',
                    'category',
                    $categoryId,
                    $oldValues,
                    null,
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'force' => $force,
                        'parent_id' => $category->getParentId()
                    ]
                );
            }
            
            return $result;
        }, 'delete_category');
    }

    /**
     * {@inheritDoc}
     */
    public function archiveCategory(int $categoryId): bool
    {
        // Authorization check
        $this->authorize('category.archive');
        
        return $this->transaction(function () use ($categoryId) {
            // Get category
            $category = $this->getEntity(
                $this->categoryRepository,
                $categoryId
            );
            
            // Check if can be archived
            if (!$this->canArchive($categoryId)) {
                throw new DomainException(
                    'Kategori tidak dapat diarsipkan karena masih memiliki produk aktif',
                    'CATEGORY_ARCHIVE_PRECONDITION_FAILED'
                );
            }
            
            // Store for audit
            $oldValues = $category->toArray();
            
            // Archive category
            $category->archive();
            $result = $this->categoryRepository->save($category) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation('category:*');
                $this->queueCacheOperation('category_tree:*');
                $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
                if ($category->getParentId()) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($category->getParentId()));
                }
                
                // Audit log
                $this->audit(
                    'category.archive',
                    'category',
                    $categoryId,
                    $oldValues,
                    $category->toArray(),
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'parent_id' => $category->getParentId()
                    ]
                );
            }
            
            return $result;
        }, 'archive_category');
    }

    /**
     * {@inheritDoc}
     */
    public function restoreCategory(int $categoryId): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.restore');
        
        return $this->transaction(function () use ($categoryId) {
            // Get category (including archived)
            $category = $this->categoryRepository->findById($categoryId);
            if ($category === null) {
                throw NotFoundException::forEntity('Category', $categoryId);
            }
            
            // Store for audit
            $oldValues = $category->toArray();
            
            // Restore category
            $category->restore();
            $restoredCategory = $this->categoryRepository->save($category);
            
            if ($restoredCategory === null) {
                throw new DomainException(
                    'Gagal mengembalikan kategori',
                    'CATEGORY_RESTORE_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
            if ($restoredCategory->getParentId()) {
                $this->queueCacheOperation($this->getCategoryCacheKey($restoredCategory->getParentId()));
            }
            
            // Audit log
            $this->audit(
                'category.restore',
                'category',
                $categoryId,
                $oldValues,
                $restoredCategory->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'parent_id' => $restoredCategory->getParentId()
                ]
            );
            
            return CategoryResponse::fromEntity($restoredCategory);
        }, 'restore_category');
    }

    // ==================== QUERY OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function getCategory(int $categoryId): CategoryResponse
    {
        $category = $this->getEntity(
            $this->categoryRepository,
            $categoryId
        );
        
        return CategoryResponse::fromEntity($category);
    }

    /**
     * {@inheritDoc}
     */
    public function getCategoryBySlug(string $slug): CategoryResponse
    {
        $category = $this->categoryRepository->findBySlug($slug);
        if ($category === null) {
            throw NotFoundException::forEntity('Category', $slug);
        }
        
        return CategoryResponse::fromEntity($category);
    }

    /**
     * {@inheritDoc}
     */
    public function getCategories(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('list', ['filters' => $filters, 'page' => $page, 'perPage' => $perPage]),
            function () use ($filters, $page, $perPage) {
                $result = $this->categoryRepository->paginateWithFilters($filters, $perPage, $page);
                
                $categories = array_map(
                    fn($category) => CategoryResponse::fromEntity($category),
                    $result['data'] ?? []
                );
                
                return [
                    'categories' => $categories,
                    'pagination' => [
                        'total' => $result['total'] ?? 0,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => $result['last_page'] ?? 1,
                        'from' => $result['from'] ?? 0,
                        'to' => $result['to'] ?? 0
                    ]
                ];
            },
            300 // Cache for 5 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getCategoryTree(bool $activeOnly = true): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('tree', ['active_only' => $activeOnly]),
            function () use ($activeOnly) {
                $categories = $this->categoryRepository->findCategoryTree();
                
                // Filter if active only
                if ($activeOnly) {
                    $categories = array_filter($categories, fn($cat) => $cat->isActive());
                }
                
                // Build tree structure
                $tree = [];
                $indexed = [];
                
                // Index all categories by ID
                foreach ($categories as $category) {
                    $indexed[$category->getId()] = [
                        'category' => CategoryTreeResponse::fromEntity($category),
                        'children' => []
                    ];
                }
                
                // Build hierarchy
                foreach ($indexed as $id => $node) {
                    $parentId = $categories[$id - 1]->getParentId();
                    
                    if ($parentId && isset($indexed[$parentId])) {
                        $indexed[$parentId]['children'][] = $node;
                    } else {
                        $tree[] = $node;
                    }
                }
                
                return $tree;
            },
            600 // Cache for 10 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getRootCategories(bool $activeOnly = true): array
    {
        $categories = $this->categoryRepository->findRootCategories();
        
        if ($activeOnly) {
            $categories = array_filter($categories, fn($cat) => $cat->isActive());
        }
        
        return array_map(
            fn($category) => CategoryResponse::fromEntity($category),
            $categories
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getSubcategories(int $parentId, bool $activeOnly = true): array
    {
        // Validate parent exists
        $parent = $this->getEntity($this->categoryRepository, $parentId);
        
        $subcategories = $this->categoryRepository->findSubCategories($parentId);
        
        if ($activeOnly) {
            $subcategories = array_filter($subcategories, fn($cat) => $cat->isActive());
        }
        
        return array_map(
            fn($category) => CategoryResponse::fromEntity($category),
            $subcategories
        );
    }

    /**
     * {@inheritDoc}
     */
    public function searchCategories(string $searchTerm, int $limit = 20): array
    {
        if (strlen(trim($searchTerm)) < 2) {
            return [];
        }
        
        $categories = $this->categoryRepository->searchByTerm($searchTerm, $limit);
        
        return array_map(
            fn($category) => CategoryResponse::fromEntity($category),
            $categories
        );
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $categoryIds, bool $active): int
    {
        // Authorization check
        $this->authorize('category.bulk_update');
        
        return $this->transaction(function () use ($categoryIds, $active) {
            $count = $this->categoryRepository->bulkSetActive($categoryIds, $active);
            
            if ($count > 0) {
                // Queue cache invalidation
                $this->queueCacheOperation('category:*');
                $this->queueCacheOperation('category_tree:*');
                
                // Audit log
                $this->audit(
                    'category.bulk_update_status',
                    'category',
                    0,
                    null,
                    ['category_ids' => $categoryIds, 'active' => $active],
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'count' => $count,
                        'action' => $active ? 'activate' : 'deactivate'
                    ]
                );
            }
            
            return $count;
        }, 'bulk_update_category_status');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkReparent(array $categoryIds, int $newParentId): int
    {
        // Authorization check
        $this->authorize('category.bulk_update');
        
        return $this->transaction(function () use ($categoryIds, $newParentId) {
            // Validate new parent exists (if not null/0)
            if ($newParentId > 0) {
                $parent = $this->getEntity($this->categoryRepository, $newParentId);
                
                // Check for circular references
                foreach ($categoryIds as $categoryId) {
                    if ($categoryId == $newParentId) {
                        throw new DomainException(
                            'Kategori tidak dapat menjadi induk dari dirinya sendiri',
                            'CIRCULAR_REFERENCE'
                        );
                    }
                    
                    $descendants = $this->categoryRepository->findDescendants($categoryId);
                    foreach ($descendants as $descendant) {
                        if ($descendant->getId() == $newParentId) {
                            throw new DomainException(
                                'Kategori tidak dapat menjadi induk dari kategori turunannya',
                                'CIRCULAR_REFERENCE'
                            );
                        }
                    }
                }
            }
            
            $count = $this->categoryRepository->bulkReparent($categoryIds, $newParentId);
            
            if ($count > 0) {
                // Queue cache invalidation
                $this->queueCacheOperation('category:*');
                $this->queueCacheOperation('category_tree:*');
                foreach ($categoryIds as $categoryId) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
                }
                if ($newParentId > 0) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($newParentId));
                }
                
                // Audit log
                $this->audit(
                    'category.bulk_reparent',
                    'category',
                    0,
                    null,
                    ['category_ids' => $categoryIds, 'new_parent_id' => $newParentId],
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'count' => $count
                    ]
                );
            }
            
            return $count;
        }, 'bulk_reparent_categories');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateSortOrder(array $sortOrders): int
    {
        // Authorization check
        $this->authorize('category.bulk_update');
        
        return $this->transaction(function () use ($sortOrders) {
            $count = $this->categoryRepository->bulkUpdateSortOrder($sortOrders);
            
            if ($count > 0) {
                // Queue cache invalidation for all affected categories
                $this->queueCacheOperation('category:*');
                $this->queueCacheOperation('category_tree:*');
                foreach (array_keys($sortOrders) as $categoryId) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
                }
                
                // Audit log
                $this->audit(
                    'category.bulk_update_sort_order',
                    'category',
                    0,
                    null,
                    ['sort_orders' => $sortOrders],
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'count' => $count
                    ]
                );
            }
            
            return $count;
        }, 'bulk_update_sort_order');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $categoryIds): int
    {
        // Authorization check
        $this->authorize('category.bulk_archive');
        
        return $this->batchOperation(
            $categoryIds,
            function ($categoryId) {
                return $this->archiveCategory($categoryId);
            },
            50,
            null
        );
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $categoryIds): int
    {
        // Authorization check
        $this->authorize('category.bulk_restore');
        
        $restoredCount = 0;
        
        foreach ($categoryIds as $categoryId) {
            try {
                $this->restoreCategory($categoryId);
                $restoredCount++;
            } catch (\Exception $e) {
                log_message('error', sprintf(
                    'Failed to restore category %d: %s',
                    $categoryId,
                    $e->getMessage()
                ));
            }
        }
        
        return $restoredCount;
    }

    // ==================== BUSINESS LOGIC OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateDeletion(int $categoryId): array
    {
        $category = $this->getEntity($this->categoryRepository, $categoryId);
        
        // Get product count (active products only)
        $productCount = $this->categoryRepository->countProducts($categoryId, true);
        
        // Get subcategory count (active only)
        $subcategories = $this->categoryRepository->findSubCategories($categoryId);
        $activeSubcategories = array_filter($subcategories, fn($cat) => $cat->isActive());
        $subcategoryCount = count($activeSubcategories);
        
        $canDelete = ($productCount === 0 && $subcategoryCount === 0);
        
        return [
            'can_delete' => $canDelete,
            'has_products' => $productCount > 0,
            'has_subcategories' => $subcategoryCount > 0,
            'product_count' => $productCount,
            'subcategory_count' => $subcategoryCount,
            'category_name' => $category->getName(),
            'category_slug' => $category->getSlug()
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function canArchive(int $categoryId): bool
    {
        $category = $this->getEntity($this->categoryRepository, $categoryId);
        
        // Cannot archive if category has active products
        $productCount = $this->categoryRepository->countProducts($categoryId, true);
        
        return $productCount === 0;
    }

    /**
     * {@inheritDoc}
     */
    public function isSlugAvailable(string $slug, ?int $excludeCategoryId = null): bool
    {
        return !$this->categoryRepository->slugExists($slug, $excludeCategoryId);
    }

    /**
     * {@inheritDoc}
     */
    public function generateSlug(string $name, ?int $excludeCategoryId = null): string
    {
        return $this->categoryRepository->generateSlug($name, $excludeCategoryId);
    }

    /**
     * {@inheritDoc}
     */
    public function moveCategory(int $categoryId, ?int $newParentId, int $newSortOrder): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.update');
        
        return $this->transaction(function () use ($categoryId, $newParentId, $newSortOrder) {
            // Get category
            $category = $this->getEntity($this->categoryRepository, $categoryId);
            $oldValues = $category->toArray();
            
            // Validate hierarchy if parent is changing
            if ($category->getParentId() !== $newParentId) {
                $this->validateHierarchy($categoryId, $newParentId);
                $category->setParentId($newParentId);
            }
            
            // Update sort order
            $category->setSortOrder($newSortOrder);
            
            // Save changes
            $updatedCategory = $this->categoryRepository->save($category);
            
            // Queue cache invalidation
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
            if ($oldValues['parent_id'] !== $newParentId) {
                $this->queueCacheOperation($this->getCategoryCacheKey($oldValues['parent_id']));
                if ($newParentId) {
                    $this->queueCacheOperation($this->getCategoryCacheKey($newParentId));
                }
            }
            
            // Audit log
            $this->audit(
                'category.move',
                'category',
                $categoryId,
                $oldValues,
                $updatedCategory->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'old_parent_id' => $oldValues['parent_id'],
                    'new_parent_id' => $newParentId,
                    'new_sort_order' => $newSortOrder
                ]
            );
            
            return CategoryResponse::fromEntity($updatedCategory);
        }, 'move_category');
    }

    /**
     * {@inheritDoc}
     */
    public function rebuildCategoryTree(): array
    {
        // Authorization check - only super admin
        $this->authorize('system.maintenance');
        
        return $this->transaction(function () {
            $repaired = 0;
            $errors = [];
            
            // Get all categories
            $categories = $this->categoryRepository->findAll();
            
            // Check for circular references
            foreach ($categories as $category) {
                try {
                    $this->validateHierarchy($category->getId(), $category->getParentId());
                } catch (DomainException $e) {
                    $errors[] = [
                        'category_id' => $category->getId(),
                        'category_name' => $category->getName(),
                        'error' => $e->getMessage(),
                        'code' => $e->getCode()
                    ];
                }
            }
            
            // Fix orphaned categories (parent doesn't exist)
            foreach ($categories as $category) {
                $parentId = $category->getParentId();
                if ($parentId && !$this->categoryRepository->exists($parentId)) {
                    // Reset to root
                    $category->setParentId(null);
                    $this->categoryRepository->save($category);
                    $repaired++;
                    
                    $errors[] = [
                        'category_id' => $category->getId(),
                        'category_name' => $category->getName(),
                        'action' => 'reset_parent_to_root',
                        'invalid_parent_id' => $parentId
                    ];
                }
            }
            
            // Clear cache
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            
            // Audit log
            $this->audit(
                'system.rebuild_category_tree',
                'system',
                0,
                null,
                ['repaired' => $repaired, 'errors_count' => count($errors)],
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'action' => 'rebuild_category_tree'
                ]
            );
            
            return [
                'repaired' => $repaired,
                'errors' => $errors,
                'total_categories' => count($categories)
            ];
        }, 'rebuild_category_tree');
    }

    /**
     * {@inheritDoc}
     */
    public function validateHierarchy(int $categoryId, ?int $parentId): bool
    {
        // Null parent is always valid (root category)
        if ($parentId === null || $parentId === 0) {
            return true;
        }
        
        // Category cannot be its own parent
        if ($categoryId === $parentId) {
            throw new DomainException(
                'Kategori tidak dapat menjadi induk dari dirinya sendiri',
                'CIRCULAR_REFERENCE'
            );
        }
        
        // Check if parent exists
        $parent = $this->categoryRepository->findById($parentId);
        if ($parent === null) {
            throw new DomainException(
                'Kategori induk tidak ditemukan',
                'PARENT_NOT_FOUND'
            );
        }
        
        // Check for circular reference (category cannot be ancestor of its parent)
        $descendants = $this->categoryRepository->findDescendants($categoryId);
        foreach ($descendants as $descendant) {
            if ($descendant->getId() === $parentId) {
                throw new DomainException(
                    'Kategori tidak dapat menjadi induk dari kategori turunannya',
                    'CIRCULAR_REFERENCE'
                );
            }
        }
        
        return true;
    }

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('statistics'),
            function () {
                $total = $this->categoryRepository->count();
                $active = $this->categoryRepository->count(['active' => true]);
                $archived = $this->categoryRepository->count(['deleted_at IS NOT NULL' => null]);
                
                $rootCategories = $this->categoryRepository->findRootCategories();
                $rootCount = count(array_filter($rootCategories, fn($cat) => $cat->isActive()));
                
                $maxDepth = $this->categoryRepository->getMaxDepth();
                
                // Calculate average products per category
                $categoriesWithCounts = $this->categoryRepository->findWithProductCounts();
                $totalProducts = 0;
                $categoriesWithProducts = 0;
                
                foreach ($categoriesWithCounts as $category) {
                    $productCount = $category->getProductCount() ?? 0;
                    if ($productCount > 0) {
                        $totalProducts += $productCount;
                        $categoriesWithProducts++;
                    }
                }
                
                $averageProducts = $categoriesWithProducts > 0 
                    ? round($totalProducts / $categoriesWithProducts, 2) 
                    : 0;
                
                // Count categories without products
                $withoutProducts = 0;
                foreach ($categoriesWithCounts as $category) {
                    if (($category->getProductCount() ?? 0) === 0) {
                        $withoutProducts++;
                    }
                }
                
                return [
                    'total_categories' => $total,
                    'active_categories' => $active,
                    'archived_categories' => $archived,
                    'root_categories' => $rootCount,
                    'max_depth' => $maxDepth,
                    'average_products_per_category' => $averageProducts,
                    'categories_without_products' => $withoutProducts,
                    'categories_with_products' => $categoriesWithProducts,
                    'total_products_in_categories' => $totalProducts
                ];
            },
            1800 // Cache for 30 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getUsageStatistics(bool $includeArchived = false): array
    {
        $categories = $this->categoryRepository->findWithProductCounts();
        
        $stats = [];
        foreach ($categories as $category) {
            if (!$includeArchived && !$category->isActive()) {
                continue;
            }
            
            $subcategories = $this->categoryRepository->findSubCategories($category->getId());
            $activeSubcategories = array_filter($subcategories, fn($cat) => $cat->isActive());
            
            $stats[] = [
                'category_id' => $category->getId(),
                'category_name' => $category->getName(),
                'category_slug' => $category->getSlug(),
                'product_count' => $category->getProductCount() ?? 0,
                'subcategory_count' => count($activeSubcategories),
                'is_active' => $category->isActive(),
                'is_archived' => $category->isDeleted()
            ];
        }
        
        return $stats;
    }

    /**
     * {@inheritDoc}
     */
    public function getGrowthStatistics(string $period = 'month', int $limit = 12): array
    {
        // This would typically query created_at timestamps
        // For MVP, we'll return mock data
        $growth = [];
        $now = Time::now();
        
        for ($i = $limit - 1; $i >= 0; $i--) {
            $date = clone $now;
            
            switch ($period) {
                case 'day':
                    $date->subDays($i);
                    $periodLabel = $date->format('Y-m-d');
                    break;
                case 'week':
                    $date->subWeeks($i);
                    $periodLabel = 'Week ' . $date->format('W, Y');
                    break;
                case 'month':
                    $date->subMonths($i);
                    $periodLabel = $date->format('Y-m');
                    break;
                case 'year':
                    $date->subYears($i);
                    $periodLabel = $date->format('Y');
                    break;
                default:
                    $periodLabel = 'Period ' . ($i + 1);
            }
            
            // Mock data - in production, this would query the database
            $growth[] = [
                'period' => $periodLabel,
                'count' => rand(0, 10), // Random for demonstration
                'cumulative' => ($i === $limit - 1) ? rand(50, 100) : ($growth[count($growth) - 2]['cumulative'] + rand(0, 10))
            ];
        }
        
        return $growth;
    }

    // ==================== ADMIN OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function activateCategory(int $categoryId): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.activate');
        
        return $this->transaction(function () use ($categoryId) {
            $category = $this->getEntity($this->categoryRepository, $categoryId);
            $oldValues = $category->toArray();
            
            $category->activate();
            $activatedCategory = $this->categoryRepository->save($category);
            
            // Queue cache invalidation
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
            if ($category->getParentId()) {
                $this->queueCacheOperation($this->getCategoryCacheKey($category->getParentId()));
            }
            
            // Audit log
            $this->audit(
                'category.activate',
                'category',
                $categoryId,
                $oldValues,
                $activatedCategory->toArray(),
                ['admin_id' => $this->getCurrentAdminId()]
            );
            
            return CategoryResponse::fromEntity($activatedCategory);
        }, 'activate_category');
    }

    /**
     * {@inheritDoc}
     */
    public function deactivateCategory(int $categoryId): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.deactivate');
        
        return $this->transaction(function () use ($categoryId) {
            $category = $this->getEntity($this->categoryRepository, $categoryId);
            
            // Check if category has active products
            $productCount = $this->categoryRepository->countProducts($categoryId, true);
            if ($productCount > 0) {
                throw new DomainException(
                    'Kategori tidak dapat dinonaktifkan karena masih memiliki produk aktif',
                    'CATEGORY_DEACTIVATE_PRECONDITION_FAILED',
                    ['product_count' => $productCount]
                );
            }
            
            $oldValues = $category->toArray();
            $category->deactivate();
            $deactivatedCategory = $this->categoryRepository->save($category);
            
            // Queue cache invalidation
            $this->queueCacheOperation('category:*');
            $this->queueCacheOperation('category_tree:*');
            $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
            if ($category->getParentId()) {
                $this->queueCacheOperation($this->getCategoryCacheKey($category->getParentId()));
            }
            
            // Audit log
            $this->audit(
                'category.deactivate',
                'category',
                $categoryId,
                $oldValues,
                $deactivatedCategory->toArray(),
                ['admin_id' => $this->getCurrentAdminId()]
            );
            
            return CategoryResponse::fromEntity($deactivatedCategory);
        }, 'deactivate_category');
    }

    /**
     * {@inheritDoc}
     */
    public function updateCategoryIcon(int $categoryId, string $icon): CategoryResponse
    {
        // Authorization check
        $this->authorize('category.update');
        
        return $this->transaction(function () use ($categoryId, $icon) {
            $category = $this->getEntity($this->categoryRepository, $categoryId);
            $oldValues = $category->toArray();
            
            $category->setIcon($icon);
            $updatedCategory = $this->categoryRepository->save($category);
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->getCategoryCacheKey($categoryId));
            
            // Audit log
            $this->audit(
                'category.update_icon',
                'category',
                $categoryId,
                $oldValues,
                $updatedCategory->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'new_icon' => $icon
                ]
            );
            
            return CategoryResponse::fromEntity($updatedCategory);
        }, 'update_category_icon');
    }

    // ==================== IMPORT/EXPORT OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function importCategories(array $categories, bool $skipDuplicates = true): array
    {
        // Authorization check
        $this->authorize('category.import');
        
        $result = [
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
            'details' => []
        ];
        
        return $this->batchOperation(
            $categories,
            function ($categoryData, $index) use ($skipDuplicates, &$result) {
                try {
                    $name = $categoryData['name'] ?? '';
                    $slug = $categoryData['slug'] ?? $this->generateSlug($name);
                    $parentSlug = $categoryData['parent_slug'] ?? null;
                    $icon = $categoryData['icon'] ?? 'fas fa-folder';
                    $sortOrder = $categoryData['sort_order'] ?? 0;
                    
                    // Check if category already exists
                    $existing = $this->categoryRepository->findBySlug($slug);
                    if ($existing && $skipDuplicates) {
                        $result['skipped']++;
                        $result['details'][] = [
                            'index' => $index,
                            'slug' => $slug,
                            'status' => 'skipped',
                            'reason' => 'already_exists'
                        ];
                        return null;
                    }
                    
                    // Find parent by slug
                    $parentId = null;
                    if ($parentSlug) {
                        $parent = $this->categoryRepository->findBySlug($parentSlug);
                        if ($parent) {
                            $parentId = $parent->getId();
                        } else {
                            throw new DomainException(
                                "Parent category with slug '{$parentSlug}' not found",
                                'PARENT_NOT_FOUND'
                            );
                        }
                    }
                    
                    // Create category
                    $category = new Category($name, $slug);
                    $category->setIcon($icon);
                    $category->setSortOrder($sortOrder);
                    $category->setActive(true);
                    
                    if ($parentId) {
                        $category->setParentId($parentId);
                    }
                    
                    // Save category
                    $savedCategory = $this->categoryRepository->save($category);
                    
                    $result['imported']++;
                    $result['details'][] = [
                        'index' => $index,
                        'slug' => $slug,
                        'status' => 'imported',
                        'category_id' => $savedCategory->getId()
                    ];
                    
                    return $savedCategory;
                    
                } catch (\Exception $e) {
                    $result['errors'][] = [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'data' => $categoryData
                    ];
                    
                    if (!$skipDuplicates) {
                        throw $e;
                    }
                    
                    return null;
                }
            },
            50,
            null
        );
        
        // Clear cache after import
        $this->queueCacheOperation('category:*');
        $this->queueCacheOperation('category_tree:*');
        
        // Audit log
        $this->audit(
            'category.import',
            'category',
            0,
            null,
            ['import_summary' => $result],
            [
                'admin_id' => $this->getCurrentAdminId(),
                'total_attempted' => count($categories)
            ]
        );
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function exportCategories(?array $categoryIds = null, bool $includeHierarchy = true): array
    {
        // Authorization check
        $this->authorize('category.export');
        
        $categories = $categoryIds 
            ? $this->categoryRepository->findByIds($categoryIds)
            : $this->categoryRepository->findAll();
        
        $exportData = [];
        
        foreach ($categories as $category) {
            $item = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'slug' => $category->getSlug(),
                'icon' => $category->getIcon(),
                'sort_order' => $category->getSortOrder(),
                'active' => $category->isActive(),
                'created_at' => $category->getCreatedAt()?->format('Y-m-d H:i:s'),
                'updated_at' => $category->getUpdatedAt()?->format('Y-m-d H:i:s')
            ];
            
            if ($includeHierarchy) {
                $item['parent_id'] = $category->getParentId();
                
                // Add parent slug if available
                if ($category->getParentId()) {
                    $parent = $this->categoryRepository->findById($category->getParentId());
                    if ($parent) {
                        $item['parent_slug'] = $parent->getSlug();
                    }
                }
            }
            
            $exportData[] = $item;
        }
        
        // Audit log
        $this->audit(
            'category.export',
            'category',
            0,
            null,
            ['export_count' => count($exportData), 'include_hierarchy' => $includeHierarchy],
            [
                'admin_id' => $this->getCurrentAdminId(),
                'category_ids_count' => $categoryIds ? count($categoryIds) : 'all'
            ]
        );
        
        return $exportData;
    }

    // ==================== VALIDATION OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function isNameUnique(string $name, ?int $excludeCategoryId = null): bool
    {
        // Implementation would check database for name uniqueness
        // For now, return true as placeholder
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function validateCategoryBusinessRules(Category $category, string $context): array
    {
        $errors = [];
        
        // Context-specific validations
        switch ($context) {
            case 'create':
                // Check daily limit for category creation
                if (!$this->checkDailyCategoryLimit($this->getCurrentAdminId())) {
                    $errors['limit'] = ['Daily category creation limit reached'];
                }
                break;
                
            case 'update':
                // Cannot change parent to descendant
                if ($category->getParentId()) {
                    try {
                        $this->validateHierarchy($category->getId(), $category->getParentId());
                    } catch (DomainException $e) {
                        $errors['parent_id'] = [$e->getMessage()];
                    }
                }
                break;
                
            case 'delete':
                // Check if category can be deleted
                $preconditions = $this->validateDeletion($category->getId());
                if (!$preconditions['can_delete']) {
                    $errors['deletion'] = ['Category cannot be deleted'];
                }
                break;
                
            case 'archive':
                // Check if category can be archived
                if (!$this->canArchive($category->getId())) {
                    $errors['archive'] = ['Category cannot be archived'];
                }
                break;
        }
        
        // General business rules
        if (strlen($category->getName()) < 2) {
            $errors['name'] = ['Category name must be at least 2 characters'];
        }
        
        if (strlen($category->getName()) > 100) {
            $errors['name'] = ['Category name must not exceed 100 characters'];
        }
        
        if ($category->getSortOrder() < 0) {
            $errors['sort_order'] = ['Sort order must be positive'];
        }
        
        if ($category->getSortOrder() > 1000) {
            $errors['sort_order'] = ['Sort order must not exceed 1000'];
        }
        
        return $errors;
    }

    // ==================== BASE SERVICE ABSTRACT METHOD IMPLEMENTATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        if ($dto instanceof CreateCategoryRequest) {
            // Create-specific validation
            $category = new Category($dto->name, $dto->slug ?? $this->generateSlug($dto->name));
            
            if ($dto->parentId !== null) {
                $category->setParentId($dto->parentId);
            }
            
            if ($dto->icon !== null) {
                $category->setIcon($dto->icon);
            }
            
            if ($dto->sortOrder !== null) {
                $category->setSortOrder($dto->sortOrder);
            }
            
            if ($dto->active !== null) {
                $category->setActive($dto->active);
            }
            
            return $this->validateCategoryBusinessRules($category, 'create');
            
        } elseif ($dto instanceof UpdateCategoryRequest) {
            // Update-specific validation
            $existingCategory = $this->getEntity($this->categoryRepository, $dto->categoryId, false);
            
            if ($existingCategory === null) {
                return ['category_id' => ['Category not found']];
            }
            
            $category = clone $existingCategory;
            
            if ($dto->name !== null) {
                $category->setName($dto->name);
            }
            
            if ($dto->slug !== null) {
                $category->setSlug($dto->slug);
            }
            
            if ($dto->parentId !== null) {
                $category->setParentId($dto->parentId);
            }
            
            if ($dto->icon !== null) {
                $category->setIcon($dto->icon);
            }
            
            if ($dto->sortOrder !== null) {
                $category->setSortOrder($dto->sortOrder);
            }
            
            if ($dto->active !== null) {
                $category->setActive($dto->active);
            }
            
            return $this->validateCategoryBusinessRules($category, 'update');
        }
        
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'CategoryService';
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Validate category service dependencies
     *
     * @return void
     * @throws RuntimeException
     */
    private function validateCategoryDependencies(): void
    {
        if (!$this->categoryRepository instanceof CategoryRepositoryInterface) {
            throw new RuntimeException('Invalid category repository dependency');
        }
        
        if (!$this->categoryValidator instanceof CategoryValidator) {
            throw new RuntimeException('Invalid category validator dependency');
        }
        
        log_message('debug', sprintf(
            '[%s] Category service dependencies validated successfully',
            $this->getServiceName()
        ));
    }

    /**
     * Check daily category creation limit for admin
     *
     * @param int|null $adminId
     * @return bool
     */
    private function checkDailyCategoryLimit(?int $adminId): bool
    {
        if ($adminId === null) {
            return true; // System operations have no limit
        }
        
        // Get today's category creation count for this admin
        // This would query the audit log in production
        $today = Time::now()->format('Y-m-d');
        $cacheKey = $this->getServiceCacheKey('daily_limit', ['admin_id' => $adminId, 'date' => $today]);
        
        $count = $this->withCaching($cacheKey, function () use ($adminId, $today) {
            // Query audit log for category.create actions today
            // Placeholder - returns random number for demonstration
            return rand(0, 5);
        }, 300); // Cache for 5 minutes
        
        // Limit: 10 categories per day per admin
        return $count < 10;
    }

    /**
     * Generate cache key for category
     *
     * @param int $categoryId
     * @return string
     */
    private function getCategoryCacheKey(int $categoryId): string
    {
        return sprintf('category:%d:v3', $categoryId);
    }
}