<?php

namespace App\Repositories\Interfaces;

use App\Entities\Product;
use App\Enums\ProductStatus;

/**
 * Product Repository Interface
 * 
 * Contract for product data access operations.
 * Abstracts the data layer from business logic layer.
 * 
 * @package App\Repositories\Interfaces
 */
interface ProductRepositoryInterface
{
    // ==================== CRUD OPERATIONS ====================

    /**
     * Find product by ID
     * 
     * @param int $id Product ID
     * @param bool $withTrashed Include soft-deleted products
     * @return Product|null
     */
    public function find(int $id, bool $withTrashed = false): ?Product;

    /**
     * Find product by slug
     * 
     * @param string $slug Product slug
     * @param bool $withTrashed Include soft-deleted products
     * @return Product|null
     */
    public function findBySlug(string $slug, bool $withTrashed = false): ?Product;

    /**
     * Find product by ID or slug (flexible lookup)
     * 
     * @param int|string $identifier ID or slug
     * @param bool $adminMode If true, returns any status (for admin)
     * @param bool $withTrashed Include soft-deleted products
     * @return Product|null
     */
    public function findByIdOrSlug($identifier, bool $adminMode = false, bool $withTrashed = false): ?Product;

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
    ): array;

    /**
     * Save product (create or update)
     * 
     * @param Product $product Product entity
     * @return Product Saved product
     */
    public function save(Product $product): Product;

    /**
     * Delete product (soft delete if supported)
     * 
     * @param int $id Product ID
     * @param bool $force Force permanent deletion
     * @return bool Success status
     */
    public function delete(int $id, bool $force = false): bool;

    /**
     * Restore soft-deleted product
     * 
     * @param int $id Product ID
     * @return bool Success status
     */
    public function restore(int $id): bool;

    /**
     * Check if product exists
     * 
     * @param int $id Product ID
     * @param bool $withTrashed Include soft-deleted products
     * @return bool
     */
    public function exists(int $id, bool $withTrashed = false): bool;

    // ==================== BUSINESS OPERATIONS ====================

    /**
     * Find published products for public display
     * 
     * @param int $limit Maximum results
     * @param int $offset Results offset
     * @return Product[]
     */
    public function findPublished(int $limit = 20, int $offset = 0): array;

    /**
     * Find product with its marketplace links (eager loading)
     * 
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @return Product|null
     */
    public function findWithLinks(int $productId, bool $activeOnly = true): ?Product;

    /**
     * Increment product view count
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function incrementViewCount(int $productId): bool;

    /**
     * Update product status with validation
     * 
     * @param int $productId Product ID
     * @param ProductStatus $newStatus New status
     * @param int|null $verifiedBy Admin ID for verification
     * @return bool Success status
     */
    public function updateStatus(int $productId, ProductStatus $newStatus, ?int $verifiedBy = null): bool;

    /**
     * Find products that need maintenance updates
     * 
     * @param string $type 'price' or 'link' or 'both'
     * @param int $limit Maximum results
     * @return Product[]
     */
    public function findNeedsUpdate(string $type = 'both', int $limit = 50): array;

    /**
     * Search products by keyword (public search)
     * 
     * @param string $keyword Search keyword
     * @param int $limit Maximum results
     * @return Product[]
     */
    public function searchByKeyword(string $keyword, int $limit = 20): array;

    /**
     * Get popular products based on view count
     * 
     * @param int $limit Maximum results
     * @param string $period 'all', 'week', 'month'
     * @return Product[]
     */
    public function getPopular(int $limit = 10, string $period = 'all'): array;

    /**
     * Find products by category
     * 
     * @param int $categoryId Category ID
     * @param int $limit Maximum results
     * @param int $offset Results offset
     * @return Product[]
     */
    public function findByCategory(int $categoryId, int $limit = 20, int $offset = 0): array;

    /**
     * Mark product price as checked
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function markPriceChecked(int $productId): bool;

    /**
     * Mark product links as checked
     * 
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function markLinksChecked(int $productId): bool;

    // ==================== STATISTICS & AGGREGATION ====================

    /**
     * Count products by status
     * 
     * @param bool $withTrashed Include soft-deleted products
     * @return array [status => count]
     */
    public function countByStatus(bool $withTrashed = false): array;

    /**
     * Count published products
     * 
     * @return int
     */
    public function countPublished(): int;

    /**
     * Count total products
     * 
     * @param bool $withTrashed Include soft-deleted products
     * @return int
     */
    public function countAll(bool $withTrashed = false): int;

    /**
     * Get product statistics for dashboard
     * 
     * @return array
     */
    public function getStats(): array;

    // ==================== BATCH OPERATIONS ====================

    /**
     * Update multiple products in batch
     * 
     * @param array $ids Product IDs
     * @param array $data Update data
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $ids, array $data): int;

    /**
     * Archive multiple products in batch
     * 
     * @param array $ids Product IDs
     * @return int Number of archived products
     */
    public function bulkArchive(array $ids): int;

    /**
     * Publish multiple products in batch
     * 
     * @param array $ids Product IDs
     * @return int Number of published products
     */
    public function bulkPublish(array $ids): int;

    // ==================== VALIDATION OPERATIONS ====================

    /**
     * Check if slug is unique
     * 
     * @param string $slug Slug to check
     * @param int|null $excludeId Product ID to exclude from check
     * @return bool True if unique
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool;

    /**
     * Validate product before save
     * 
     * @param Product $product Product entity
     * @return array Validation result [valid: bool, errors: string[]]
     */
    public function validate(Product $product): array;

    /**
     * Check business rule: maximum 300 products
     * 
     * @return array [can_create: bool, current_count: int, max_allowed: int]
     */
    public function checkProductLimit(): array;
}