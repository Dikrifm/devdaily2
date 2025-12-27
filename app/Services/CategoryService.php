<?php

namespace App\Services;

use App\DTOs\Requests\Category\CreateCategoryRequest;
use App\DTOs\Requests\Category\UpdateCategoryRequest;
use App\DTOs\Responses\CategoryResponse;
use App\DTOs\Responses\CategoryTreeResponse;
use App\DTOs\Responses\BulkActionResult;
use App\DTOs\Queries\PaginationQuery;
use App\Entities\Category;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Validators\SlugValidator;
use App\Validators\CategoryValidator;
use InvalidArgumentException;

/**
 * CategoryService - Business orchestrator for category operations
 * 
 * Layer 5: Business Orchestrator
 * - Manages database transactions for category operations
 * - Coordinates business validation and rules
 * - Uses DTOs for input/output
 */
class CategoryService
{
    /**
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $categoryRepository;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var TransactionService
     */
    private TransactionService $transactionService;

    /**
     * @var CategoryValidator
     */
    private CategoryValidator $categoryValidator;

    /**
     * @var SlugValidator
     */
    private SlugValidator $slugValidator;

    /**
     * @var AuditService
     */
    private AuditService $auditService;

    /**
     * Constructor with Dependency Injection
     * 
     * @param CategoryRepositoryInterface $categoryRepository
     * @param ProductRepositoryInterface $productRepository
     * @param TransactionService $transactionService
     * @param CategoryValidator $categoryValidator
     * @param SlugValidator $slugValidator
     * @param AuditService $auditService
     */
    public function __construct(
        CategoryRepositoryInterface $categoryRepository,
        ProductRepositoryInterface $productRepository,
        TransactionService $transactionService,
        CategoryValidator $categoryValidator,
        SlugValidator $slugValidator,
        AuditService $auditService
    ) {
        $this->categoryRepository = $categoryRepository;
        $this->productRepository = $productRepository;
        $this->transactionService = $transactionService;
        $this->categoryValidator = $categoryValidator;
        $this->slugValidator = $slugValidator;
        $this->auditService = $auditService;
    }

    /**
     * Create a new category
     * 
     * @param CreateCategoryRequest $request
     * @param int $createdBy Admin ID who created the category
     * @return CategoryResponse
     * @throws ValidationException|DomainException
     */
    public function create(CreateCategoryRequest $request, int $createdBy): CategoryResponse
    {
        // Validate business rules
        $this->categoryValidator->validateCreate($request, $createdBy);

        // Check if maximum category limit reached
        if ($this->categoryRepository->isMaxLimitReached()) {
            throw new DomainException('Maximum category limit (15) reached. Cannot create new category.');
        }

        // Validate slug
        if (!$this->slugValidator->isValid($request->slug)) {
            throw new ValidationException('Invalid slug format. Slug can only contain lowercase letters, numbers, and hyphens.');
        }

        // Check if slug is already used
        if ($this->categoryRepository->isSlugUsed($request->slug)) {
            throw new ValidationException("Slug '{$request->slug}' is already in use.");
        }

        // Check if name is already used
        if ($this->categoryRepository->isNameUsed($request->name)) {
            throw new ValidationException("Category name '{$request->name}' is already in use.");
        }

        // Validate parent category if provided
        if ($request->parent_id !== null && $request->parent_id > 0) {
            $parentCategory = $this->categoryRepository->find($request->parent_id);
            if ($parentCategory === null) {
                throw new NotFoundException("Parent category with ID {$request->parent_id} not found.");
            }
            
            if ($parentCategory->isDeleted()) {
                throw new DomainException("Cannot create sub-category under archived parent category.");
            }
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($request, $createdBy) {
            // Create category entity
            $category = new Category($request->name, $request->slug);
            
            // Set properties
            $category->setIcon($request->icon ?? 'fas fa-folder')
                    ->setSortOrder($request->sort_order ?? 0)
                    ->setActive($request->active ?? true)
                    ->setParentId($request->parent_id ?? 0);

            // Validate entity
            $validationResult = $category->validate();
            if (!$validationResult['valid']) {
                throw new ValidationException('Category validation failed', $validationResult['errors']);
            }

            // Save category
            $saved = $this->categoryRepository->save($category);
            if (!$saved) {
                throw new DomainException('Failed to save category.');
            }

            // Audit log
            $this->auditService->logCategoryCreated($category->getId(), $createdBy, [
                'name' => $category->getName(),
                'slug' => $category->getSlug(),
                'parent_id' => $category->getParentId(),
            ]);

            // Return response DTO
            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Update an existing category
     * 
     * @param int $categoryId
     * @param UpdateCategoryRequest $request
     * @param int $updatedBy Admin ID who updated the category
     * @return CategoryResponse
     * @throws NotFoundException|ValidationException|DomainException
     */
    public function update(int $categoryId, UpdateCategoryRequest $request, int $updatedBy): CategoryResponse
    {
        // Get existing category
        $category = $this->categoryRepository->findOrFail($categoryId);

        // Validate business rules
        $this->categoryValidator->validateUpdate($category, $request, $updatedBy);

        // Prevent circular reference in parent hierarchy
        if ($request->parent_id !== null && $request->parent_id > 0) {
            if ($request->parent_id === $categoryId) {
                throw new DomainException('Category cannot be its own parent.');
            }
            
            // Check for circular reference in hierarchy
            $this->validateNoCircularReference($categoryId, $request->parent_id);
        }

        // Validate slug if changed
        if ($request->slug !== null && $request->slug !== $category->getSlug()) {
            if (!$this->slugValidator->isValid($request->slug)) {
                throw new ValidationException('Invalid slug format. Slug can only contain lowercase letters, numbers, and hyphens.');
            }
            
            if ($this->categoryRepository->isSlugUsed($request->slug, $categoryId)) {
                throw new ValidationException("Slug '{$request->slug}' is already in use.");
            }
        }

        // Validate name if changed
        if ($request->name !== null && $request->name !== $category->getName()) {
            if ($this->categoryRepository->isNameUsed($request->name, $categoryId)) {
                throw new ValidationException("Category name '{$request->name}' is already in use.");
            }
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($category, $request, $updatedBy) {
            // Track changes for audit
            $changes = $category->getChanges();

            // Update category properties
            if ($request->name !== null) {
                $category->setName($request->name);
            }
            
            if ($request->slug !== null) {
                $category->setSlug($request->slug);
            }
            
            if ($request->icon !== null) {
                $category->setIcon($request->icon);
            }
            
            if ($request->sort_order !== null) {
                $category->setSortOrder($request->sort_order);
            }
            
            if ($request->active !== null) {
                $category->setActive($request->active);
            }
            
            if ($request->parent_id !== null) {
                $category->setParentId($request->parent_id);
            }

            // Validate entity
            $validationResult = $category->validate();
            if (!$validationResult['valid']) {
                throw new ValidationException('Category validation failed', $validationResult['errors']);
            }

            // Save category
            $saved = $this->categoryRepository->save($category);
            if (!$saved) {
                throw new DomainException('Failed to update category.');
            }

            // Audit log
            $changes = array_merge($changes, $category->getChanges());
            if (!empty($changes)) {
                $this->auditService->logCategoryUpdated($category->getId(), $updatedBy, $changes);
            }

            // Return response DTO
            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Delete a category
     * 
     * @param int $categoryId
     * @param int $deletedBy Admin ID who deleted the category
     * @param bool $force Hard delete (bypass soft delete)
     * @return bool
     * @throws NotFoundException|DomainException
     */
    public function delete(int $categoryId, int $deletedBy, bool $force = false): bool
    {
        $category = $this->categoryRepository->findOrFail($categoryId);

        // Check if category can be deleted
        $canDeleteResult = $this->categoryRepository->canDelete($categoryId);
        if (!$canDeleteResult['can_delete']) {
            throw new DomainException($canDeleteResult['reason'] ?? 'Category cannot be deleted.');
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($category, $categoryId, $deletedBy, $force) {
            if ($force) {
                // Hard delete
                $success = $this->categoryRepository->delete($categoryId, false);
            } else {
                // Soft delete (archive)
                $success = $this->categoryRepository->archive($categoryId, $deletedBy);
            }

            if (!$success) {
                throw new DomainException('Failed to delete category.');
            }

            // Audit log
            $this->auditService->logCategoryDeleted($categoryId, $deletedBy, [
                'name' => $category->getName(),
                'force' => $force,
            ]);

            return true;
        });
    }

    /**
     * Find category by ID
     * 
     * @param int $categoryId
     * @param bool $withCounts Include product and children counts
     * @return CategoryResponse
     * @throws NotFoundException
     */
    public function find(int $categoryId, bool $withCounts = false): CategoryResponse
    {
        if ($withCounts) {
            // We need to get category with counts
            // Since repository doesn't have findWithCounts for ID, we'll get basic then add counts
            $category = $this->categoryRepository->findOrFail($categoryId);
            
            // Get additional counts
            $productCount = $this->productRepository->countByCategory($categoryId, true);
            $childrenCount = $this->categoryRepository->countChildren($categoryId, true);
            
            // Set counts on entity (temporary for response)
            $category->setProductCount($productCount);
            $category->setChildrenCount($childrenCount);
            
            return $this->createCategoryResponse($category);
        }
        
        $category = $this->categoryRepository->findOrFail($categoryId);
        return $this->createCategoryResponse($category);
    }

    /**
     * Find category by slug
     * 
     * @param string $slug
     * @param bool $activeOnly
     * @param bool $withCounts
     * @return CategoryResponse
     * @throws NotFoundException
     */
    public function findBySlug(string $slug, bool $activeOnly = true, bool $withCounts = false): CategoryResponse
    {
        if ($withCounts) {
            $category = $this->categoryRepository->findWithCounts($slug, $activeOnly);
        } else {
            $category = $this->categoryRepository->findBySlug($slug, $activeOnly);
        }
        
        if ($category === null) {
            throw new NotFoundException("Category with slug '{$slug}' not found.");
        }
        
        return $this->createCategoryResponse($category);
    }

    /**
     * Find all active categories
     * 
     * @param int $limit
     * @param bool $withCounts
     * @return array<CategoryResponse>
     */
    public function findAllActive(int $limit = 15, bool $withCounts = false): array
    {
        if ($withCounts) {
            $categories = $this->categoryRepository->withProductCount($limit, true);
        } else {
            $categories = $this->categoryRepository->findActive($limit);
        }
        
        return array_map(
            fn($category) => $this->createCategoryResponse($category),
            $categories
        );
    }

    /**
     * Get category tree (hierarchical structure)
     * 
     * @param bool $activeOnly
     * @return CategoryTreeResponse
     */
    public function getTree(bool $activeOnly = true): CategoryTreeResponse
    {
        $treeData = $this->categoryRepository->getTree($activeOnly);
        
        // Convert to response DTO
        return CategoryTreeResponse::fromTreeData($treeData);
    }

    /**
     * Activate a category
     * 
     * @param int $categoryId
     * @param int $activatedBy Admin ID who activated the category
     * @return CategoryResponse
     * @throws NotFoundException|DomainException
     */
    public function activate(int $categoryId, int $activatedBy): CategoryResponse
    {
        $category = $this->categoryRepository->findOrFail($categoryId);

        if ($category->isActive()) {
            throw new DomainException('Category is already active.');
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($categoryId, $activatedBy) {
            $success = $this->categoryRepository->activate($categoryId);
            
            if (!$success) {
                throw new DomainException('Failed to activate category.');
            }

            // Get updated category
            $category = $this->categoryRepository->findOrFail($categoryId);

            // Audit log
            $this->auditService->logCategoryActivated($categoryId, $activatedBy);

            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Deactivate a category
     * 
     * @param int $categoryId
     * @param int $deactivatedBy Admin ID who deactivated the category
     * @return CategoryResponse
     * @throws NotFoundException|DomainException
     */
    public function deactivate(int $categoryId, int $deactivatedBy): CategoryResponse
    {
        $category = $this->categoryRepository->findOrFail($categoryId);

        if (!$category->isActive()) {
            throw new DomainException('Category is already inactive.');
        }

        // Check if category has active products
        $productCount = $this->productRepository->countByCategory($categoryId, true);
        if ($productCount > 0) {
            throw new DomainException("Cannot deactivate category with {$productCount} published product(s).");
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($categoryId, $deactivatedBy) {
            $success = $this->categoryRepository->deactivate($categoryId);
            
            if (!$success) {
                throw new DomainException('Failed to deactivate category.');
            }

            // Get updated category
            $category = $this->categoryRepository->findOrFail($categoryId);

            // Audit log
            $this->auditService->logCategoryDeactivated($categoryId, $deactivatedBy);

            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Archive a category (soft delete)
     * 
     * @param int $categoryId
     * @param int $archivedBy Admin ID who archived the category
     * @return CategoryResponse
     * @throws NotFoundException|DomainException
     */
    public function archive(int $categoryId, int $archivedBy): CategoryResponse
    {
        $category = $this->categoryRepository->findOrFail($categoryId);

        if ($category->isDeleted()) {
            throw new DomainException('Category is already archived.');
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($categoryId, $archivedBy) {
            $success = $this->categoryRepository->archive($categoryId, $archivedBy);
            
            if (!$success) {
                throw new DomainException('Failed to archive category.');
            }

            // Get updated category
            $category = $this->categoryRepository->find($categoryId, false);

            // Audit log
            $this->auditService->logCategoryArchived($categoryId, $archivedBy);

            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Restore an archived category
     * 
     * @param int $categoryId
     * @param int $restoredBy Admin ID who restored the category
     * @return CategoryResponse
     * @throws NotFoundException|DomainException
     */
    public function restore(int $categoryId, int $restoredBy): CategoryResponse
    {
        $category = $this->categoryRepository->find($categoryId, false);
        
        if ($category === null) {
            throw new NotFoundException("Category with ID {$categoryId} not found.");
        }
        
        if (!$category->isDeleted()) {
            throw new DomainException('Category is not archived.');
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($categoryId, $restoredBy) {
            $success = $this->categoryRepository->restore($categoryId, $restoredBy);
            
            if (!$success) {
                throw new DomainException('Failed to restore category.');
            }

            // Get updated category
            $category = $this->categoryRepository->findOrFail($categoryId);

            // Audit log
            $this->auditService->logCategoryRestored($categoryId, $restoredBy);

            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Update category sort order
     * 
     * @param array $orderData [category_id => new_sort_order]
     * @param int $updatedBy Admin ID who updated the order
     * @return bool
     * @throws DomainException
     */
    public function updateSortOrder(array $orderData, int $updatedBy): bool
    {
        if (empty($orderData)) {
            throw new DomainException('No order data provided.');
        }

        // Validate all category IDs exist
        $categoryIds = array_keys($orderData);
        $categories = $this->categoryRepository->findByIds($categoryIds, false);
        
        if (count($categories) !== count($categoryIds)) {
            $foundIds = array_map(fn($c) => $c->getId(), $categories);
            $missingIds = array_diff($categoryIds, $foundIds);
            throw new NotFoundException('Categories not found: ' . implode(', ', $missingIds));
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($orderData, $updatedBy) {
            $success = $this->categoryRepository->updateSortOrder($orderData);
            
            if (!$success) {
                throw new DomainException('Failed to update sort order.');
            }

            // Audit log
            $this->auditService->logCategoryOrderUpdated($updatedBy, [
                'order_data' => $orderData,
                'affected_categories' => count($orderData),
            ]);

            return true;
        });
    }

    /**
     * Move category to new parent
     * 
     * @param int $categoryId
     * @param int $newParentId 0 for root, >0 for parent category
     * @param int $movedBy Admin ID who moved the category
     * @return CategoryResponse
     * @throws NotFoundException|DomainException
     */
    public function moveCategory(int $categoryId, int $newParentId, int $movedBy): CategoryResponse
    {
        $category = $this->categoryRepository->findOrFail($categoryId);

        // Check if moving to same parent
        if ($category->getParentId() === $newParentId) {
            throw new DomainException('Category is already under this parent.');
        }

        // Prevent moving to self
        if ($newParentId === $categoryId) {
            throw new DomainException('Category cannot be its own parent.');
        }

        // Check for circular reference
        if ($newParentId > 0) {
            $this->validateNoCircularReference($categoryId, $newParentId);
        }

        // Start transaction
        return $this->transactionService->execute(function () use ($category, $newParentId, $movedBy) {
            $category->setParentId($newParentId);
            
            $success = $this->categoryRepository->save($category);
            if (!$success) {
                throw new DomainException('Failed to move category.');
            }

            // Audit log
            $this->auditService->logCategoryMoved($category->getId(), $movedBy, [
                'old_parent_id' => $category->getParentId(),
                'new_parent_id' => $newParentId,
            ]);

            return $this->createCategoryResponse($category);
        });
    }

    /**
     * Get category statistics
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        return $this->categoryRepository->getStats();
    }

    /**
     * Get category usage (products per category)
     * 
     * @param bool $activeOnly
     * @return array
     */
    public function getCategoryUsage(bool $activeOnly = true): array
    {
        return $this->categoryRepository->getCategoryUsage($activeOnly);
    }

    /**
     * Get navigation categories (for menus)
     * 
     * @param int $limit
     * @return array<CategoryResponse>
     */
    public function getNavigation(int $limit = 10): array
    {
        $categories = $this->categoryRepository->getNavigation($limit, true);
        
        return array_map(
            fn($category) => $this->createCategoryResponse($category),
            $categories
        );
    }

    /**
     * Get category options for dropdown/select
     * 
     * @param bool $activeOnly
     * @param bool $includeRoot
     * @param int|null $excludeCategoryId
     * @return array
     */
    public function getOptions(
        bool $activeOnly = true,
        bool $includeRoot = true,
        ?int $excludeCategoryId = null
    ): array {
        return $this->categoryRepository->getOptions($activeOnly, $includeRoot, $excludeCategoryId);
    }

    /**
     * Validate if category can be deleted
     * 
     * @param int $categoryId
     * @return array
     */
    public function validateCanDelete(int $categoryId): array
    {
        return $this->categoryRepository->canDelete($categoryId);
    }

    /**
     * Validate if category can be archived
     * 
     * @param int $categoryId
     * @return array
     */
    public function validateCanArchive(int $categoryId): array
    {
        return $this->categoryRepository->canArchive($categoryId);
    }

    /**
     * Search categories
     * 
     * @param string $keyword
     * @param array $filters
     * @param PaginationQuery $pagination
     * @return array
     */
    public function search(string $keyword, array $filters = [], PaginationQuery $pagination): array
    {
        $categories = $this->categoryRepository->search(
            $keyword,
            $filters,
            $pagination->per_page,
            $pagination->getOffset()
        );

        $total = $this->categoryRepository->countTotal();

        $categoryResponses = array_map(
            fn($category) => $this->createCategoryResponse($category),
            $categories
        );

        return [
            'categories' => $categoryResponses,
            'total' => $total,
            'page' => $pagination->page,
            'per_page' => $pagination->per_page,
            'total_pages' => ceil($total / $pagination->per_page),
        ];
    }

    /**
     * Bulk update category status
     * 
     * @param array $categoryIds
     * @param string $action 'activate' or 'deactivate'
     * @param int $changedBy
     * @return BulkActionResult
     */
    public function bulkUpdateStatus(array $categoryIds, string $action, int $changedBy): BulkActionResult
    {
        if (empty($categoryIds)) {
            throw new ValidationException('No category IDs provided.');
        }

        if (!in_array($action, ['activate', 'deactivate'])) {
            throw new DomainException('Invalid action. Must be "activate" or "deactivate".');
        }

        $result = new BulkActionResult();

        // Start transaction
        $this->transactionService->begin();

        try {
            foreach ($categoryIds as $categoryId) {
                try {
                    $category = $this->categoryRepository->find($categoryId, false);
                    
                    if ($category === null) {
                        $result->addFailed($categoryId, 'Category not found');
                        continue;
                    }

                    if ($action === 'activate') {
                        if ($category->isActive()) {
                            $result->addSkipped($categoryId, 'Already active');
                            continue;
                        }
                        $success = $this->categoryRepository->activate($categoryId);
                    } else {
                        if (!$category->isActive()) {
                            $result->addSkipped($categoryId, 'Already inactive');
                            continue;
                        }
                        $success = $this->categoryRepository->deactivate($categoryId);
                    }

                    if ($success) {
                        $result->addSuccess($categoryId);
                        
                        // Audit log per category
                        $auditAction = $action === 'activate' ? 'activated' : 'deactivated';
                        $this->auditService->logCategoryStatusChanged($categoryId, $changedBy, $auditAction);
                    } else {
                        $result->addFailed($categoryId, "Failed to {$action}");
                    }
                } catch (\Exception $e) {
                    $result->addFailed($categoryId, $e->getMessage());
                }
            }

            $this->transactionService->commit();
            
        } catch (\Exception $e) {
            $this->transactionService->rollback();
            throw new DomainException('Bulk operation failed: ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Create CategoryResponse from Category entity
     * 
     * @param Category $category
     * @return CategoryResponse
     * @private
     */
    private function createCategoryResponse(Category $category): CategoryResponse
    {
        return CategoryResponse::fromEntity($category);
    }

    /**
     * Validate no circular reference in category hierarchy
     * 
     * @param int $categoryId
     * @param int $newParentId
     * @return void
     * @throws DomainException
     * @private
     */
    private function validateNoCircularReference(int $categoryId, int $newParentId): void
    {
        // Get all children of the category
        $children = $this->getAllChildren($categoryId);
        
        // Check if new parent is among the children
        if (in_array($newParentId, $children)) {
            throw new DomainException('Circular reference detected: Cannot move category under its own child.');
        }
    }

    /**
     * Get all children IDs of a category (recursive)
     * 
     * @param int $categoryId
     * @return array<int>
     * @private
     */
    private function getAllChildren(int $categoryId): array
    {
        $children = [];
        $directChildren = $this->categoryRepository->findByParent($categoryId, false, 0, false);
        
        foreach ($directChildren as $child) {
            $children[] = $child->getId();
            $children = array_merge($children, $this->getAllChildren($child->getId()));
        }
        
        return $children;
    }

    /**
     * Get root categories
     * 
     * @param bool $activeOnly
     * @param int $limit
     * @return array<CategoryResponse>
     */
    public function getRoots(bool $activeOnly = true, int $limit = 10): array
    {
        $categories = $this->categoryRepository->findRoots($activeOnly, $limit);
        
        return array_map(
            fn($category) => $this->createCategoryResponse($category),
            $categories
        );
    }

    /**
     * Get sub-categories of a parent
     * 
     * @param int $parentId
     * @param bool $activeOnly
     * @param int $limit
     * @return array<CategoryResponse>
     */
    public function getSubCategories(int $parentId, bool $activeOnly = true, int $limit = 20): array
    {
        $categories = $this->categoryRepository->findByParent($parentId, $activeOnly, $limit);
        
        return array_map(
            fn($category) => $this->createCategoryResponse($category),
            $categories
        );
    }

    /**
     * Check if maximum category limit is reached
     * 
     * @return bool
     */
    public function isMaxLimitReached(): bool
    {
        return $this->categoryRepository->isMaxLimitReached();
    }
}