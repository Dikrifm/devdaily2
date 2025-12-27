<?php

namespace App\Contracts;

use App\DTOs\Requests\Category\CreateCategoryRequest;
use App\DTOs\Requests\Category\UpdateCategoryRequest;
use App\DTOs\Responses\CategoryResponse;
use App\DTOs\Responses\CategoryTreeResponse;
use App\Entities\Category;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Category Service Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for category business operations.
 * Defines the protocol for all category-related business logic with strict type safety.
 *
 * @package App\Contracts
 */
interface CategoryInterface extends BaseInterface
{
    // ==================== CRUD OPERATIONS ====================

    /**
     * Create a new category with business validation
     *
     * @param CreateCategoryRequest $request
     * @return CategoryResponse
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function createCategory(CreateCategoryRequest $request): CategoryResponse;

    /**
     * Update an existing category with business validation
     *
     * @param UpdateCategoryRequest $request
     * @return CategoryResponse
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function updateCategory(UpdateCategoryRequest $request): CategoryResponse;

    /**
     * Delete a category with preconditions check
     *
     * @param int $categoryId
     * @param bool $force Force delete ignoring preconditions
     * @return bool
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function deleteCategory(int $categoryId, bool $force = false): bool;

    /**
     * Archive a category (soft delete)
     *
     * @param int $categoryId
     * @return bool
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function archiveCategory(int $categoryId): bool;

    /**
     * Restore an archived category
     *
     * @param int $categoryId
     * @return CategoryResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function restoreCategory(int $categoryId): CategoryResponse;

    // ==================== QUERY OPERATIONS ====================

    /**
     * Get category by ID with full hydration
     *
     * @param int $categoryId
     * @return CategoryResponse
     * @throws NotFoundException
     */
    public function getCategory(int $categoryId): CategoryResponse;

    /**
     * Get category by slug with full hydration
     *
     * @param string $slug
     * @return CategoryResponse
     * @throws NotFoundException
     */
    public function getCategoryBySlug(string $slug): CategoryResponse;

    /**
     * Get paginated list of categories with filters
     *
     * @param array $filters
     * @param int $page
     * @param int $perPage
     * @return array{categories: array<CategoryResponse>, pagination: array}
     */
    public function getCategories(array $filters = [], int $page = 1, int $perPage = 25): array;

    /**
     * Get category tree (hierarchy) for navigation
     *
     * @param bool $activeOnly
     * @return array<CategoryTreeResponse>
     */
    public function getCategoryTree(bool $activeOnly = true): array;

    /**
     * Get root categories (categories without parent)
     *
     * @param bool $activeOnly
     * @return array<CategoryResponse>
     */
    public function getRootCategories(bool $activeOnly = true): array;

    /**
     * Get subcategories for a parent category
     *
     * @param int $parentId
     * @param bool $activeOnly
     * @return array<CategoryResponse>
     * @throws NotFoundException
     */
    public function getSubcategories(int $parentId, bool $activeOnly = true): array;

    /**
     * Search categories by name or slug
     *
     * @param string $searchTerm
     * @param int $limit
     * @return array<CategoryResponse>
     */
    public function searchCategories(string $searchTerm, int $limit = 20): array;

    // ==================== BATCH OPERATIONS ====================

    /**
     * Bulk update category status (active/inactive)
     *
     * @param array<int> $categoryIds
     * @param bool $active
     * @return int Number of updated categories
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkUpdateStatus(array $categoryIds, bool $active): int;

    /**
     * Bulk reparent categories
     *
     * @param array<int> $categoryIds
     * @param int $newParentId
     * @return int Number of reparented categories
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function bulkReparent(array $categoryIds, int $newParentId): int;

    /**
     * Bulk update sort order
     *
     * @param array<int, int> $sortOrders Mapping of category ID to sort order
     * @return int Number of updated categories
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkUpdateSortOrder(array $sortOrders): int;

    /**
     * Bulk archive categories
     *
     * @param array<int> $categoryIds
     * @return int Number of archived categories
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkArchive(array $categoryIds): int;

    /**
     * Bulk restore categories
     *
     * @param array<int> $categoryIds
     * @return int Number of restored categories
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkRestore(array $categoryIds): int;

    // ==================== BUSINESS LOGIC OPERATIONS ====================

    /**
     * Validate if category can be deleted (check preconditions)
     *
     * @param int $categoryId
     * @return array{
     *     can_delete: bool,
     *     has_products: bool,
     *     has_subcategories: bool,
     *     product_count: int,
     *     subcategory_count: int
     * }
     * @throws NotFoundException
     */
    public function validateDeletion(int $categoryId): array;

    /**
     * Validate if category can be archived
     *
     * @param int $categoryId
     * @return bool
     * @throws NotFoundException
     */
    public function canArchive(int $categoryId): bool;

    /**
     * Check if slug is available for use
     *
     * @param string $slug
     * @param int|null $excludeCategoryId
     * @return bool
     */
    public function isSlugAvailable(string $slug, ?int $excludeCategoryId = null): bool;

    /**
     * Generate a unique slug from category name
     *
     * @param string $name
     * @param int|null $excludeCategoryId
     * @return string
     */
    public function generateSlug(string $name, ?int $excludeCategoryId = null): string;

    /**
     * Move category to new position in hierarchy
     *
     * @param int $categoryId
     * @param int|null $newParentId
     * @param int $newSortOrder
     * @return CategoryResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function moveCategory(int $categoryId, ?int $newParentId, int $newSortOrder): CategoryResponse;

    /**
     * Rebuild category tree (fix hierarchy inconsistencies)
     *
     * @return array{repaired: int, errors: array}
     * @throws AuthorizationException
     */
    public function rebuildCategoryTree(): array;

    /**
     * Validate category hierarchy (no circular references)
     *
     * @param int $categoryId
     * @param int|null $parentId
     * @return bool
     * @throws DomainException
     */
    public function validateHierarchy(int $categoryId, ?int $parentId): bool;

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * Get category statistics
     *
     * @return array{
     *     total_categories: int,
     *     active_categories: int,
     *     archived_categories: int,
     *     root_categories: int,
     *     max_depth: int,
     *     average_products_per_category: float,
     *     categories_without_products: int
     * }
     */
    public function getStatistics(): array;

    /**
     * Get category usage statistics (products count per category)
     *
     * @param bool $includeArchived
     * @return array<int, array{category_id: int, category_name: string, product_count: int, subcategory_count: int}>
     */
    public function getUsageStatistics(bool $includeArchived = false): array;

    /**
     * Get category growth over time
     *
     * @param string $period 'day', 'week', 'month', 'year'
     * @param int $limit
     * @return array<array{period: string, count: int}>
     */
    public function getGrowthStatistics(string $period = 'month', int $limit = 12): array;

    // ==================== ADMIN OPERATIONS ====================

    /**
     * Activate category
     *
     * @param int $categoryId
     * @return CategoryResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function activateCategory(int $categoryId): CategoryResponse;

    /**
     * Deactivate category
     *
     * @param int $categoryId
     * @return CategoryResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function deactivateCategory(int $categoryId): CategoryResponse;

    /**
     * Update category icon
     *
     * @param int $categoryId
     * @param string $icon
     * @return CategoryResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function updateCategoryIcon(int $categoryId, string $icon): CategoryResponse;

    // ==================== IMPORT/EXPORT OPERATIONS ====================

    /**
     * Import categories from structured data
     *
     * @param array<array{name: string, slug?: string, parent_slug?: string|null, icon?: string, sort_order?: int}> $categories
     * @param bool $skipDuplicates
     * @return array{imported: int, skipped: int, errors: array}
     * @throws AuthorizationException
     */
    public function importCategories(array $categories, bool $skipDuplicates = true): array;

    /**
     * Export categories to structured format
     *
     * @param array<int>|null $categoryIds
     * @param bool $includeHierarchy
     * @return array<array>
     */
    public function exportCategories(?array $categoryIds = null, bool $includeHierarchy = true): array;

    // ==================== VALIDATION OPERATIONS ====================

    /**
     * Validate category name uniqueness
     *
     * @param string $name
     * @param int|null $excludeCategoryId
     * @return bool
     */
    public function isNameUnique(string $name, ?int $excludeCategoryId = null): bool;

    /**
     * Validate category business rules (beyond basic validation)
     *
     * @param Category $category
     * @param string $context 'create'|'update'|'delete'|'archive'
     * @return array<string, string[]> Validation errors
     */
    public function validateCategoryBusinessRules(Category $category, string $context): array;
}