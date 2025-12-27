<?php

namespace App\Repositories\Interfaces;

use App\Entities\Link;
use App\Repositories\BaseRepositoryInterface;

/**
 * Link Repository Interface
 * 
 * Contract for Link data orchestration layer with caching strategy.
 * Follows "Isolation via Interface" principle - all Link repositories must implement this.
 * 
 * @template-extends BaseRepositoryInterface<Link>
 */
interface LinkRepositoryInterface extends BaseRepositoryInterface
{
    // ============================================
    // CORE REPOSITORY METHODS (Inherited from Base)
    // ============================================
    // Note: These methods are inherited but type-hinted for Link entity

    /**
     * Find Link by ID
     * 
     * @param int|string $id
     * @param bool $useCache Use cache if available
     * @return Link|null
     */
    public function find($id, bool $useCache = true): ?Link;

    /**
     * Find Link by ID or throw exception
     * 
     * @param int|string $id
     * @param bool $useCache Use cache if available
     * @return Link
     * @throws \RuntimeException If link not found
     */
    public function findOrFail($id, bool $useCache = true): Link;

    /**
     * Find all Links with optional conditions
     * 
     * @param array<string, mixed> $conditions Search conditions [field => value]
     * @param int|null $limit Result limit
     * @param int $offset Result offset
     * @param bool $useCache Use cache if available
     * @return array<Link>
     */
    public function findAll(
        array $conditions = [], 
        ?int $limit = null, 
        int $offset = 0,
        bool $useCache = true
    ): array;

    /**
     * Save Link entity
     * 
     * @param Link $entity Link entity to save
     * @return bool True on success
     */
    public function save(Link $entity): bool;

    /**
     * Find first Link matching conditions
     * 
     * @param array<string, mixed> $conditions Search conditions
     * @param bool $useCache Use cache if available
     * @return Link|null
     */
    public function first(array $conditions = [], bool $useCache = true): ?Link;

    // ============================================
    // BUSINESS-SPECIFIC QUERY METHODS
    // ============================================

    /**
     * Find active links for a product
     * 
     * @param int $productId Product ID
     * @param bool $useCache Use cache if available
     * @return array<Link> Active links for the product
     */
    public function findActiveForProduct(int $productId, bool $useCache = true): array;

    /**
     * Find link by product and marketplace combination
     * 
     * @param int $productId Product ID
     * @param int $marketplaceId Marketplace ID
     * @param bool $useCache Use cache if available
     * @return Link|null Link if exists
     */
    public function findByProductAndMarketplace(
        int $productId, 
        int $marketplaceId, 
        bool $useCache = true
    ): ?Link;

    /**
     * Find links that need price updates (24+ hours old)
     * 
     * @param int $marketplaceId Specific marketplace (0 for all)
     * @param int $limit Maximum results
     * @param bool $useCache Use cache if available
     * @return array<Link> Links needing price updates
     */
    public function findNeedingPriceUpdate(
        int $marketplaceId = 0, 
        int $limit = 50, 
        bool $useCache = true
    ): array;

    /**
     * Find links that need validation (48+ hours old)
     * 
     * @param int $limit Maximum results
     * @param bool $useCache Use cache if available
     * @return array<Link> Links needing validation
     */
    public function findNeedingValidation(int $limit = 100, bool $useCache = true): array;

    /**
     * Find top performing links by revenue
     * 
     * @param int $limit Maximum results
     * @param float $minRevenue Minimum revenue threshold
     * @param bool $useCache Use cache if available
     * @return array<Link> Top performing links
     */
    public function findTopPerforming(
        int $limit = 10, 
        float $minRevenue = 10000.00, 
        bool $useCache = true
    ): array;

    /**
     * Find links with marketplace badges
     * 
     * @param int $limit Maximum results
     * @param bool $useCache Use cache if available
     * @return array<Link> Links with badges
     */
    public function findWithBadges(int $limit = 50, bool $useCache = true): array;

    /**
     * Find links by marketplace with performance metrics
     * 
     * @param int $marketplaceId Marketplace ID
     * @param string $orderBy Order by field (revenue, clicks, sold, rating)
     * @param string $direction Order direction (ASC/DESC)
     * @param bool $useCache Use cache if available
     * @return array<Link> Sorted links
     */
    public function findByMarketplaceSorted(
        int $marketplaceId, 
        string $orderBy = 'revenue', 
        string $direction = 'DESC',
        bool $useCache = true
    ): array;

    // ============================================
    // STATISTICS & ANALYTICS METHODS
    // ============================================

    /**
     * Get link statistics for dashboard
     * 
     * @param bool $useCache Use cache if available
     * @return array<string, mixed> Statistics array
     */
    public function getStatistics(bool $useCache = true): array;

    /**
     * Get product link statistics
     * 
     * @param int $productId Product ID
     * @param bool $useCache Use cache if available
     * @return array<string, mixed> Product link stats
     */
    public function getProductLinkStatistics(int $productId, bool $useCache = true): array;

    /**
     * Get marketplace link statistics
     * 
     * @param int $marketplaceId Marketplace ID
     * @param bool $useCache Use cache if available
     * @return array<string, mixed> Marketplace link stats
     */
    public function getMarketplaceLinkStatistics(int $marketplaceId, bool $useCache = true): array;

    // ============================================
    // BUSINESS OPERATIONS METHODS
    // ============================================

    /**
     * Increment click counter for a link
     * 
     * @param int $linkId Link ID
     * @return bool True on success
     */
    public function incrementClicks(int $linkId): bool;

    /**
     * Update link price with automatic timestamp
     * 
     * @param int $linkId Link ID
     * @param string $price New price (decimal string)
     * @return bool True on success
     */
    public function updatePrice(int $linkId, string $price): bool;

    /**
     * Update affiliate revenue using commission rate
     * 
     * @param int $linkId Link ID
     * @param float $commissionRate Decimal rate (e.g., 0.05 for 5%)
     * @return bool True on success
     */
    public function updateRevenueWithCommission(int $linkId, float $commissionRate): bool;

    /**
     * Bulk update link statuses
     * 
     * @param array<int> $linkIds Array of link IDs
     * @param bool $active New active status
     * @return int Number of updated links
     */
    public function bulkUpdateStatus(array $linkIds, bool $active): int;

    /**
     * Validate and mark link as validated
     * 
     * @param int $linkId Link ID
     * @return bool True on success
     */
    public function markAsValidated(int $linkId): bool;

    /**
     * Archive link (soft delete with business rules)
     * 
     * @param int $linkId Link ID
     * @return bool True on success
     */
    public function archive(int $linkId): bool;

    /**
     * Restore archived link
     * 
     * @param int $linkId Link ID
     * @return bool True on success
     */
    public function restore(int $linkId): bool;

    // ============================================
    // CACHE MANAGEMENT METHODS
    // ============================================

    /**
     * Delete all cache entries for a product
     * 
     * @param int $productId Product ID
     * @return int Number of cache entries deleted
     */
    public function invalidateProductCache(int $productId): int;

    /**
     * Delete all cache entries for a marketplace
     * 
     * @param int $marketplaceId Marketplace ID
     * @return int Number of cache entries deleted
     */
    public function invalidateMarketplaceCache(int $marketplaceId): int;

    // ============================================
    // BULK OPERATIONS METHODS
    // ============================================

    /**
     * Bulk create links from array data
     * 
     * @param array<array<string, mixed>> $linksData Array of link data
     * @return array<int> Array of created link IDs
     */
    public function bulkCreate(array $linksData): array;

    /**
     * Update prices for multiple links
     * 
     * @param array<int, string> $priceMap Link ID => New price
     * @return int Number of updated links
     */
    public function bulkUpdatePrices(array $priceMap): int;

    // ============================================
    // VALIDATION & INTEGRITY METHODS
    // ============================================

    /**
     * Check if store name is unique for product and marketplace
     * 
     * @param string $storeName Store name
     * @param int $productId Product ID
     * @param int $marketplaceId Marketplace ID
     * @param int|null $excludeLinkId Link ID to exclude (for updates)
     * @return bool True if unique
     */
    public function isStoreNameUnique(
        string $storeName, 
        int $productId, 
        int $marketplaceId,
        ?int $excludeLinkId = null
    ): bool;

    /**
     * Validate link URL is accessible
     * 
     * @param string $url URL to validate
     * @return bool True if accessible
     */
    public function validateUrl(string $url): bool;

    /**
     * Get entity type for this repository (required by BaseRepositoryInterface)
     * 
     * @return string Entity type name
     */
    public function getEntityType(): string;
}