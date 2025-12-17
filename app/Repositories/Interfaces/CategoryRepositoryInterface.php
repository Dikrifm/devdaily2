<?php

namespace App\Repositories\Interfaces;

use App\Entities\Category;
use App\Exceptions\CategoryNotFoundException;

interface CategoryRepositoryInterface
{
    // ==================== BASIC CRUD OPERATIONS ====================
    
    /**
     * Find category by ID
     *
     * @param int $id Category ID
     * @param bool $withTrashed Include soft deleted categories
     * @return Category|null
     */
    public function find(int $id, bool $withTrashed = false): ?Category;
    
    /**
     * Find category by slug
     *
     * @param string $slug Category slug
     * @param bool $withTrashed Include soft deleted categories
     * @return Category|null
     */
    public function findBySlug(string $slug, bool $withTrashed = false): ?Category;
    
    /**
     * Find category by ID or slug
     *
     * @param mixed $identifier ID or slug
     * @param bool $withTrashed Include soft deleted categories
     * @return Category|null
     */
    public function findByIdOrSlug($identifier, bool $withTrashed = false): ?Category;
    
    /**
     * Get all categories with filtering
     *
     * @param array $filters
     * @param string $sortBy
     * @param string $sortDirection
     * @param bool $withTrashed Include soft deleted categories
     * @return array
     */
    public function findAll(
        array $filters = [],
        string $sortBy = 'sort_order',
        string $sortDirection = 'ASC',
        bool $withTrashed = false
    ): array;
    
    /**
     * Save category (create or update)
     *
     * @param Category $category
     * @return Category
     * @throws \RuntimeException
     */
    public function save(Category $category): Category;
    
    /**
     * Delete category
     *
     * @param int $id Category ID
     * @param bool $force Permanent deletion
     * @return bool
     */
    public function delete(int $id, bool $force = false): bool;
    
    /**
     * Restore soft deleted category
     *
     * @param int $id Category ID
     * @return bool
     */
    public function restore(int $id): bool;
    
    /**
     * Check if category exists
     *
     * @param int $id Category ID
     * @param bool $withTrashed Include soft deleted categories
     * @return bool
     */
    public function exists(int $id, bool $withTrashed = false): bool;
    
    // ==================== TREE & HIERARCHY OPERATIONS ====================
    
    /**
     * Get category tree (nested hierarchy)
     *
     * @param int|null $parentId Root parent ID (null for root level)
     * @param bool $includeInactive Include inactive categories
     * @param int|null $maxDepth Maximum depth (null for unlimited)
     * @param bool $withTrashed Include soft deleted categories
     * @return array Nested tree structure
     */
    public function getTree(
        ?int $parentId = null,
        bool $includeInactive = false,
        ?int $maxDepth = null,
        bool $withTrashed = false
    ): array;
    
    /**
     * Get flattened category tree (with depth indicator)
     *
     * @param bool $includeInactive Include inactive categories
     * @param string $indicator Depth indicator (default: '--')
     * @return array
     */
    public function getFlattenedTree(
        bool $includeInactive = false,
        string $indicator = '--'
    ): array;
    
    /**
     * Get child categories
     *
     * @param int $parentId Parent category ID
     * @param bool $activeOnly Only active categories
     * @param bool $withTrashed Include soft deleted categories
     * @return array
     */
    public function getChildren(
        int $parentId,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array;
    
    /**
     * Get parent categories (path to root)
     *
     * @param int $categoryId Starting category ID
     * @param bool $includeSelf Include starting category in result
     * @return array Ordered from immediate parent to root
     */
    public function getParentPath(int $categoryId, bool $includeSelf = false): array;
    
    /**
     * Check if category is descendant of another
     *
     * @param int $categoryId Category ID to check
     * @param int $parentId Potential parent ID
     * @return bool
     */
    public function isDescendantOf(int $categoryId, int $parentId): bool;
    
    /**
     * Check for circular reference
     *
     * @param int $categoryId Category ID
     * @param int $newParentId Proposed new parent ID
     * @return bool True if circular reference would occur
     */
    public function wouldCreateCircularReference(int $categoryId, int $newParentId): bool;
    
    /**
     * Move category to new parent
     *
     * @param int $categoryId Category ID to move
     * @param int $newParentId New parent category ID
     * @return bool
     */
    public function moveToParent(int $categoryId, int $newParentId): bool;
    
    // ==================== STATUS & ACTIVATION OPERATIONS ====================
    
    /**
     * Activate category
     *
     * @param int $categoryId Category ID
     * @return bool
     */
    public function activate(int $categoryId): bool;
    
    /**
     * Deactivate category
     *
     * @param int $categoryId Category ID
     * @return bool
     */
    public function deactivate(int $categoryId): bool;
    
    /**
     * Archive category (soft delete with special handling)
     *
     * @param int $categoryId Category ID
     * @return bool
     */
    public function archive(int $categoryId): bool;
    
    /**
     * Bulk update category status
     *
     * @param array $categoryIds Array of category IDs
     * @param string $status New status (active/inactive/archived)
     * @return int Number of affected rows
     */
    public function bulkUpdateStatus(array $categoryIds, string $status): int;
    
    // ==================== SORTING & ORDERING ====================
    
    /**
     * Update category sort order
     *
     * @param int $categoryId Category ID
     * @param int $newSortOrder New sort order value
     * @return bool
     */
    public function updateSortOrder(int $categoryId, int $newSortOrder): bool;
    
    /**
     * Reorder sibling categories
     *
     * @param int $parentId Parent category ID
     * @param array $orderData Associative array [categoryId => newOrder]
     * @return bool
     */
    public function reorderSiblings(int $parentId, array $orderData): bool;
    
    // ==================== SEARCH & FILTER ====================
    
    /**
     * Search categories by keyword
     *
     * @param string $keyword Search term
     * @param bool $searchDescription Also search in descriptions
     * @param bool $activeOnly Only active categories
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function search(
        string $keyword,
        bool $searchDescription = false,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array;
    
    /**
     * Find categories by IDs
     *
     * @param array $categoryIds Array of category IDs
     * @param bool $activeOnly Only active categories
     * @param bool $withTrashed Include soft deleted categories
     * @return array
     */
    public function findByIds(
        array $categoryIds,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array;
    
    /**
     * Get categories with product count
     *
     * @param bool $activeOnly Only active categories
     * @param bool $includeEmpty Include categories with 0 products
     * @param int $limit Result limit
     * @return array Categories with product_count field
     */
    public function withProductCount(
        bool $activeOnly = true,
        bool $includeEmpty = false,
        int $limit = 50
    ): array;
    
    // ==================== STATISTICS & ANALYTICS ====================
    
    /**
     * Get category statistics
     *
     * @param int $categoryId Category ID (null for system-wide)
     * @return array
     */
    public function getStatistics(?int $categoryId = null): array;
    
    /**
     * Count categories by status
     *
     * @param bool $withTrashed Include soft deleted categories
     * @return array [status => count]
     */
    public function countByStatus(bool $withTrashed = false): array;
    
    /**
     * Count total categories
     *
     * @param bool $withTrashed Include soft deleted categories
     * @return int
     */
    public function countAll(bool $withTrashed = false): int;
    
    /**
     * Count active categories
     *
     * @return int
     */
    public function countActive(): int;
    
    /**
     * Get category depth statistics
     *
     * @return array [depth => count]
     */
    public function getDepthStatistics(): array;
    
    // ==================== VALIDATION & BUSINESS RULES ====================
    
    /**
     * Check if category can be deleted
     *
     * @param int $categoryId Category ID
     * @return array [can_delete => bool, reasons => string[], affected_products => int]
     */
    public function canDelete(int $categoryId): array;
    
    /**
     * Check if category can be archived
     *
     * @param int $categoryId Category ID
     * @return array [can_archive => bool, reasons => string[]]
     */
    public function canArchive(int $categoryId): array;
    
    /**
     * Check if slug is unique
     *
     * @param string $slug Slug to check
     * @param int|null $excludeId Category ID to exclude (for updates)
     * @return bool
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool;
    
    /**
     * Validate category business rules
     *
     * @param Category $category
     * @return array [is_valid => bool, errors => string[]]
     */
    public function validate(Category $category): array;
    
    // ==================== BATCH & BULK OPERATIONS ====================
    
    /**
     * Bulk update categories
     *
     * @param array $categoryIds Array of category IDs
     * @param array $updateData Data to update
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $categoryIds, array $updateData): int;
    
    /**
     * Bulk delete categories
     *
     * @param array $categoryIds Array of category IDs
     * @param bool $force Permanent deletion
     * @return int Number of deleted rows
     */
    public function bulkDelete(array $categoryIds, bool $force = false): int;
    
    /**
     * Bulk restore categories
     *
     * @param array $categoryIds Array of category IDs
     * @return int Number of restored rows
     */
    public function bulkRestore(array $categoryIds): int;
    
    // ==================== CACHE MANAGEMENT ====================
    
    /**
     * Clear category caches
     *
     * @param int|null $categoryId Specific category ID (null for all)
     * @return void
     */
    public function clearCache(?int $categoryId = null): void;
    
    /**
     * Get cache TTL setting
     *
     * @return int Cache TTL in seconds
     */
    public function getCacheTtl(): int;
    
    /**
     * Set cache TTL
     *
     * @param int $ttl Cache TTL in seconds
     * @return self
     */
    public function setCacheTtl(int $ttl): self;
    
    // ==================== NAVIGATION & UI ====================
    
    /**
     * Get navigation categories (optimized for menus)
     *
     * @param int $maxDepth Maximum depth
     * @param int $limitPerLevel Limit per hierarchy level
     * @return array
     */
    public function getNavigation(int $maxDepth = 2, int $limitPerLevel = 15): array;
    
    /**
     * Get breadcrumb trail for category
     *
     * @param int $categoryId Category ID
     * @param bool $includeCurrent Include current category
     * @return array
     */
    public function getBreadcrumbs(int $categoryId, bool $includeCurrent = true): array;
    
    /**
     * Get category suggestions for dropdowns
     *
     * @param string|null $query Search query
     * @param int $limit Result limit
     * @return array [id => name, ...]
     */
    public function getSuggestions(?string $query = null, int $limit = 20): array;
}