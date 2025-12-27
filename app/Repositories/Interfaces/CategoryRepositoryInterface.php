<?php

namespace App\Repositories\Interfaces;

use App\Entities\Category;
use App\Repositories\BaseRepositoryInterface;

/**
 * Category Repository Interface
 * 
 * Contract for Category-specific data operations with caching and transaction management.
 * Extends BaseRepositoryInterface with type-specific Category operations including tree structure.
 * 
 * @extends BaseRepositoryInterface<Category>
 */
interface CategoryRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find Category by slug
     * 
     * @param string $slug
     * @return Category|null
     */
    public function findBySlug(string $slug): ?Category;

    /**
     * Find Category with parent information
     * 
     * @param int $id
     * @return Category|null
     */
    public function findWithParent(int $id): ?Category;

    /**
     * Find all root categories (parent_id = null or 0)
     * 
     * @return array<Category>
     */
    public function findRootCategories(): array;

    /**
     * Find subcategories of a parent category
     * 
     * @param int $parentId
     * @return array<Category>
     */
    public function findSubCategories(int $parentId): array;

    /**
     * Find complete category tree with hierarchy
     * 
     * @return array<array<string, mixed>>
     */
    public function findCategoryTree(): array;

    /**
     * Find categories with product counts
     * 
     * @return array<Category>
     */
    public function findWithProductCounts(): array;

    /**
     * Find categories with children counts
     * 
     * @return array<Category>
     */
    public function findWithChildrenCounts(): array;

    /**
     * Find category with all counts (products and children)
     * 
     * @param int $id
     * @return Category|null
     */
    public function findWithAllCounts(int $id): ?Category;

    /**
     * Find all descendants of a category
     * 
     * @param int $categoryId
     * @return array<Category>
     */
    public function findDescendants(int $categoryId): array;

    /**
     * Find category path from root to category
     * 
     * @param int $categoryId
     * @return array<Category>
     */
    public function findCategoryPath(int $categoryId): array;

    /**
     * Check if category is ancestor of another category
     * 
     * @param int $ancestorId
     * @param int $descendantId
     * @return bool
     */
    public function isAncestorOf(int $ancestorId, int $descendantId): bool;

    /**
     * Bulk reparent categories
     * 
     * @param array<int|string> $categoryIds
     * @param int $newParentId
     * @return int
     */
    public function bulkReparent(array $categoryIds, int $newParentId): int;

    /**
     * Bulk update sort order
     * 
     * @param array<int, int> $sortOrders Array of [categoryId => sortOrder]
     * @return int
     */
    public function bulkUpdateSortOrder(array $sortOrders): int;

    /**
     * Bulk set active/inactive status
     * 
     * @param array<int|string> $categoryIds
     * @param bool $active
     * @return int
     */
    public function bulkSetActive(array $categoryIds, bool $active): int;

    /**
     * Check if slug exists
     * 
     * @param string $slug
     * @param int|null $excludeId
     * @return bool
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool;

    /**
     * Check if parent ID is valid (exists and not creating circular reference)
     * 
     * @param int $parentId
     * @return bool
     */
    public function isValidParent(int $parentId): bool;

    /**
     * Check preconditions before deletion
     * 
     * @param int $categoryId
     * @return array<string, mixed>
     */
    public function checkDeletionPreconditions(int $categoryId): array;

    /**
     * Get total count of active categories
     * 
     * @return int
     */
    public function getTotalActiveCount(): int;

    /**
     * Get maximum depth of category tree
     * 
     * @return int
     */
    public function getMaxDepth(): int;

    /**
     * Generate unique slug from name
     * 
     * @param string $name
     * @param int|null $excludeId
     * @return string
     */
    public function generateSlug(string $name, ?int $excludeId = null): string;

    /**
     * Validate slug format
     * 
     * @param string $slug
     * @return bool
     */
    public function isValidSlugFormat(string $slug): bool;

    /**
     * Get all IDs in subtree (including the category itself and all descendants)
     * 
     * @param int $parentId
     * @return array<int>
     */
    public function getSubtreeIds(int $parentId): array;

    /**
     * Activate category
     * 
     * @param int|string $id
     * @return bool
     */
    public function activate(int|string $id): bool;

    /**
     * Deactivate category
     * 
     * @param int|string $id
     * @return bool
     */
    public function deactivate(int|string $id): bool;

    /**
     * Find all active categories
     * 
     * @return array<Category>
     */
    public function findActiveCategories(): array;

    /**
     * Find all inactive categories
     * 
     * @return array<Category>
     */
    public function findInactiveCategories(): array;

    /**
     * Check if category is in use (has products or children)
     * 
     * @param int $categoryId
     * @return bool
     */
    public function isInUse(int $categoryId): bool;

    /**
     * Get category statistics
     * 
     * @return array<string, mixed>
     */
    public function getStatistics(): array;

    /**
     * Create sample category for testing
     * 
     * @param array<string, mixed> $overrides
     * @return Category
     */
    public function createSample(array $overrides = []): Category;
}