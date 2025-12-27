<?php

namespace App\Repositories\Interfaces;

use App\Repositories\BaseRepositoryInterface;
use App\Entities\ProductBadge;
use App\Entities\BaseEntity;
use App\DTOs\Queries\PaginationQuery;
use RuntimeException;

/**
 * ProductBadge Repository Interface
 *
 * Data Orchestrator Layer (Layer 3): Interface for ProductBadge persistence operations.
 * Handles many-to-many relationship between Product and Badge entities.
 *
 * @extends App\Repositories\BaseRepositoryInterface<ProductBadge>
 */
interface ProductBadgeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find ProductBadge association by composite key
     *
     * @param int $productId
     * @param int $badgeId
     * @return ProductBadge|null
     */
    public function findByCompositeKey(int $productId, int $badgeId): ?ProductBadge;

    /**
     * Find all badges associated with a product
     *
     * @param int $productId
     * @return array<ProductBadge>
     */
    public function findByProductId(int $productId): array;

    /**
     * Find all products associated with a badge
     *
     * @param int $badgeId
     * @return array<ProductBadge>
     */
    public function findByBadgeId(int $badgeId): array;

    /**
     * Associate a badge with a product
     *
     * @param int $productId
     * @param int $badgeId
     * @param int|null $assignedBy Admin ID who assigned the badge
     * @return ProductBadge
     * @throws RuntimeException If association already exists
     */
    public function associate(int $productId, int $badgeId, ?int $assignedBy = null): ProductBadge;

    /**
     * Dissociate a badge from a product
     *
     * @param int $productId
     * @param int $badgeId
     * @return bool True if disassociation was successful
     */
    public function dissociate(int $productId, int $badgeId): bool;

    /**
     * Sync badges for a product (replace all existing badges)
     *
     * @param int $productId
     * @param array<int> $badgeIds Array of badge IDs to associate
     * @param int|null $assignedBy Admin ID who performed the sync
     * @return array<ProductBadge> New associations created
     */
    public function syncForProduct(int $productId, array $badgeIds, ?int $assignedBy = null): array;

    /**
     * Sync products for a badge (replace all existing products)
     *
     * @param int $badgeId
     * @param array<int> $productIds Array of product IDs to associate
     * @param int|null $assignedBy Admin ID who performed the sync
     * @return array<ProductBadge> New associations created
     */
    public function syncForBadge(int $badgeId, array $productIds, ?int $assignedBy = null): array;

    /**
     * Remove all badges from a product
     *
     * @param int $productId
     * @return int Number of associations removed
     */
    public function removeAllForProduct(int $productId): int;

    /**
     * Remove all products from a badge
     *
     * @param int $badgeId
     * @return int Number of associations removed
     */
    public function removeAllForBadge(int $badgeId): int;

    /**
     * Check if association exists
     *
     * @param int $productId
     * @param int $badgeId
     * @return bool
     */
    public function associationExists(int $productId, int $badgeId): bool;

    /**
     * Count badges for a product
     *
     * @param int $productId
     * @return int
     */
    public function countBadgesForProduct(int $productId): int;

    /**
     * Count products for a badge
     *
     * @param int $badgeId
     * @return int
     */
    public function countProductsForBadge(int $badgeId): int;

    /**
     * Find associations for multiple products
     *
     * @param array<int> $productIds
     * @return array<ProductBadge> Indexed by productId_badgeId
     */
    public function findForMultipleProducts(array $productIds): array;

    /**
     * Find associations for multiple badges
     *
     * @param array<int> $badgeIds
     * @return array<ProductBadge> Indexed by productId_badgeId
     */
    public function findForMultipleBadges(array $badgeIds): array;

    /**
     * Get product IDs for a badge with pagination
     *
     * @param int $badgeId
     * @param PaginationQuery $query
     * @return array{
     *     data: array<int>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         from: int,
     *         to: int
     *     }
     * }
     */
    public function paginateProductIdsForBadge(int $badgeId, PaginationQuery $query): array;

    /**
     * Get badge IDs for a product with pagination
     *
     * @param int $productId
     * @param PaginationQuery $query
     * @return array{
     *     data: array<int>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         from: int,
     *         to: int
     *     }
     * }
     */
    public function paginateBadgeIdsForProduct(int $productId, PaginationQuery $query): array;

    /**
     * Get product-badge association statistics
     *
     * @return array{
     *     total_associations: int,
     *     average_badges_per_product: float,
     *     average_products_per_badge: float,
     *     most_used_badges: array<array{id: int, count: int}>,
     *     products_with_most_badges: array<array{id: int, count: int}>
     * }
     */
    public function getStatistics(): array;

    /**
     * Bulk create associations
     *
     * @param array<array{product_id: int, badge_id: int, assigned_by?: int|null}> $associations
     * @return int Number of associations created
     */
    public function bulkCreate(array $associations): int;

    /**
     * Bulk delete associations
     *
     * @param array<array{product_id: int, badge_id: int}> $associations
     * @return int Number of associations deleted
     */
    public function bulkDeleteAssociations(array $associations): int;
}