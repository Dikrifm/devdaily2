<?php

namespace App\Contracts;

use App\DTOs\Requests\Link\CreateLinkRequest;
use App\DTOs\Requests\Link\UpdateLinkRequest;
use App\DTOs\Requests\Link\BulkLinkUpdateRequest;
use App\DTOs\Responses\LinkResponse;
use App\DTOs\Responses\LinkAnalyticsResponse;
use App\Entities\Link;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Link Service Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for link business operations.
 * Defines the protocol for all link-related business logic including affiliate tracking,
 * price monitoring, and marketplace integration.
 *
 * @package App\Contracts
 */
interface LinkInterface extends BaseInterface
{
    // ==================== CRUD OPERATIONS ====================

    /**
     * Create a new affiliate link with business validation
     *
     * @param CreateLinkRequest $request
     * @return LinkResponse
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function createLink(CreateLinkRequest $request): LinkResponse;

    /**
     * Update an existing affiliate link with business validation
     *
     * @param UpdateLinkRequest $request
     * @return LinkResponse
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function updateLink(UpdateLinkRequest $request): LinkResponse;

    /**
     * Delete a link
     *
     * @param int $linkId
     * @param bool $force Force delete ignoring preconditions
     * @return bool
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function deleteLink(int $linkId, bool $force = false): bool;

    /**
     * Archive a link (soft delete)
     *
     * @param int $linkId
     * @return bool
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function archiveLink(int $linkId): bool;

    /**
     * Restore an archived link
     *
     * @param int $linkId
     * @return LinkResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function restoreLink(int $linkId): LinkResponse;

    // ==================== QUERY OPERATIONS ====================

    /**
     * Get link by ID with full hydration
     *
     * @param int $linkId
     * @return LinkResponse
     * @throws NotFoundException
     */
    public function getLink(int $linkId): LinkResponse;

    /**
     * Get all links for a specific product
     *
     * @param int $productId
     * @param bool $activeOnly
     * @return array<LinkResponse>
     */
    public function getProductLinks(int $productId, bool $activeOnly = true): array;

    /**
     * Get all links for a specific marketplace
     *
     * @param int $marketplaceId
     * @param bool $activeOnly
     * @return array<LinkResponse>
     */
    public function getMarketplaceLinks(int $marketplaceId, bool $activeOnly = true): array;

    /**
     * Get link by product and marketplace combination
     *
     * @param int $productId
     * @param int $marketplaceId
     * @return LinkResponse|null
     */
    public function getLinkByProductAndMarketplace(int $productId, int $marketplaceId): ?LinkResponse;

    /**
     * Search links with advanced filters
     *
     * @param array $filters
     * @param int $page
     * @param int $perPage
     * @return array{links: array<LinkResponse>, pagination: array}
     */
    public function searchLinks(array $filters = [], int $page = 1, int $perPage = 25): array;

    /**
     * Get paginated list of links with sorting options
     *
     * @param string $sortBy
     * @param string $sortDirection
     * @param int $page
     * @param int $perPage
     * @return array{links: array<LinkResponse>, pagination: array}
     */
    public function getLinksPaginated(
        string $sortBy = 'created_at',
        string $sortDirection = 'desc',
        int $page = 1,
        int $perPage = 25
    ): array;

    // ==================== BATCH OPERATIONS ====================

    /**
     * Bulk update link status (active/inactive)
     *
     * @param array<int> $linkIds
     * @param bool $active
     * @return int Number of updated links
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkUpdateStatus(array $linkIds, bool $active): int;

    /**
     * Bulk update link prices
     *
     * @param BulkLinkUpdateRequest $request
     * @return array{updated: int, failed: array<int, string>}
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkUpdatePrices(BulkLinkUpdateRequest $request): array;

    /**
     * Bulk create links for multiple products and marketplaces
     *
     * @param array<array{product_id: int, marketplace_id: int, store_name: string, url: string, price: string}> $linksData
     * @return array{created: int, failed: array<int, string>}
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkCreateLinks(array $linksData): array;

    /**
     * Bulk archive links
     *
     * @param array<int> $linkIds
     * @return int Number of archived links
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkArchiveLinks(array $linkIds): int;

    /**
     * Bulk restore links
     *
     * @param array<int> $linkIds
     * @return int Number of restored links
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkRestoreLinks(array $linkIds): int;

    // ==================== PRICE MONITORING OPERATIONS ====================

    /**
     * Update link price with validation
     *
     * @param int $linkId
     * @param string $newPrice
     * @param bool $autoUpdateTimestamp
     * @return LinkResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function updateLinkPrice(int $linkId, string $newPrice, bool $autoUpdateTimestamp = true): LinkResponse;

    /**
     * Get links needing price updates
     *
     * @param int $limit
     * @param int $maxAgeHours
     * @return array<LinkResponse>
     */
    public function getLinksNeedingPriceUpdate(int $limit = 100, int $maxAgeHours = 24): array;

    /**
     * Mark link as price checked
     *
     * @param int $linkId
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function markPriceChecked(int $linkId): bool;

    /**
     * Validate link price format and business rules
     *
     * @param string $price
     * @param int|null $productId
     * @return array{valid: bool, normalized: string, errors: array<string>}
     */
    public function validatePrice(string $price, ?int $productId = null): array;

    /**
     * Compare link price with market price
     *
     * @param int $linkId
     * @return array{
     *     link_price: string,
     *     market_price: string,
     *     difference: string,
     *     percentage: float,
     *     is_cheaper: bool,
     *     is_expensive: bool
     * }
     * @throws NotFoundException
     */
    public function comparePriceWithMarket(int $linkId): array;

    // ==================== AFFILIATE & TRACKING OPERATIONS ====================

    /**
     * Record a click on a link (for affiliate tracking)
     *
     * @param int $linkId
     * @param array $trackingData
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function recordClick(int $linkId, array $trackingData = []): bool;

    /**
     * Record a sale/conversion for affiliate tracking
     *
     * @param int $linkId
     * @param string $orderId
     * @param string $revenue
     * @param array $metadata
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function recordConversion(int $linkId, string $orderId, string $revenue, array $metadata = []): bool;

    /**
     * Calculate affiliate revenue for a link
     *
     * @param int $linkId
     * @param float|null $customCommissionRate
     * @return string Calculated revenue
     * @throws NotFoundException
     */
    public function calculateAffiliateRevenue(int $linkId, ?float $customCommissionRate = null): string;

    /**
     * Update affiliate revenue with commission
     *
     * @param int $linkId
     * @param float $commissionRate
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function updateRevenueWithCommission(int $linkId, float $commissionRate): bool;

    /**
     * Get click-through rate for a link
     *
     * @param int $linkId
     * @param float $totalProductViews
     * @return float
     * @throws NotFoundException
     */
    public function getClickThroughRate(int $linkId, float $totalProductViews = 0): float;

    /**
     * Get revenue per click for a link
     *
     * @param int $linkId
     * @return string
     * @throws NotFoundException
     */
    public function getRevenuePerClick(int $linkId): string;

    // ==================== VALIDATION OPERATIONS ====================

    /**
     * Validate link URL
     *
     * @param string $url
     * @param int|null $marketplaceId
     * @return array{valid: bool, normalized: string, errors: array<string>}
     */
    public function validateUrl(string $url, ?int $marketplaceId = null): array;

    /**
     * Validate store name uniqueness
     *
     * @param string $storeName
     * @param int $productId
     * @param int $marketplaceId
     * @param int|null $excludeLinkId
     * @return bool
     */
    public function isStoreNameUnique(string $storeName, int $productId, int $marketplaceId, ?int $excludeLinkId = null): bool;

    /**
     * Mark link as validated (quality check)
     *
     * @param int $linkId
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function markAsValidated(int $linkId): bool;

    /**
     * Mark link as invalid (failed validation)
     *
     * @param int $linkId
     * @param string $reason
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function markAsInvalid(int $linkId, string $reason): bool;

    /**
     * Get links needing validation
     *
     * @param int $limit
     * @param int $maxAgeHours
     * @return array<LinkResponse>
     */
    public function getLinksNeedingValidation(int $limit = 100, int $maxAgeHours = 72): array;

    // ==================== ANALYTICS & REPORTING OPERATIONS ====================

    /**
     * Get link analytics with performance metrics
     *
     * @param int $linkId
     * @param string $period
     * @return LinkAnalyticsResponse
     * @throws NotFoundException
     */
    public function getLinkAnalytics(int $linkId, string $period = '30d'): LinkAnalyticsResponse;

    /**
     * Get product link statistics
     *
     * @param int $productId
     * @return array{
     *     total_links: int,
     *     active_links: int,
     *     total_clicks: int,
     *     total_sales: int,
     *     total_revenue: string,
     *     average_rating: float,
     *     cheapest_price: string,
     *     most_expensive_price: string
     * }
     */
    public function getProductLinkStatistics(int $productId): array;

    /**
     * Get marketplace link statistics
     *
     * @param int $marketplaceId
     * @return array{
     *     total_links: int,
     *     active_links: int,
     *     total_clicks: int,
     *     total_sales: int,
     *     total_revenue: string,
     *     average_rating: float,
     *     click_through_rate: float
     * }
     */
    public function getMarketplaceLinkStatistics(int $marketplaceId): array;

    /**
     * Get top performing links
     *
     * @param string $metric 'clicks'|'sales'|'revenue'|'rating'
     * @param string $period
     * @param int $limit
     * @return array<LinkResponse>
     */
    public function getTopPerformingLinks(string $metric = 'revenue', string $period = 'month', int $limit = 10): array;

    /**
     * Get link performance comparison
     *
     * @param array<int> $linkIds
     * @param string $period
     * @return array<int, array>
     */
    public function getPerformanceComparison(array $linkIds, string $period = 'month'): array;

    /**
     * Generate link performance report
     *
     * @param array $filters
     * @param string $format 'array'|'csv'|'json'
     * @return array|string
     * @throws DomainException
     */
    public function generatePerformanceReport(array $filters = [], string $format = 'array');

    // ==================== BADGE OPERATIONS ====================

    /**
     * Assign marketplace badge to link
     *
     * @param int $linkId
     * @param int $badgeId
     * @return LinkResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function assignBadge(int $linkId, int $badgeId): LinkResponse;

    /**
     * Remove marketplace badge from link
     *
     * @param int $linkId
     * @return LinkResponse
     * @throws AuthorizationException
     * @throws NotFoundException
     * @throws DomainException
     */
    public function removeBadge(int $linkId): LinkResponse;

    /**
     * Get links with specific badges
     *
     * @param array<int> $badgeIds
     * @param bool $activeOnly
     * @param int $limit
     * @return array<LinkResponse>
     */
    public function getLinksWithBadges(array $badgeIds, bool $activeOnly = true, int $limit = 50): array;

    // ==================== HEALTH & MAINTENANCE OPERATIONS ====================

    /**
     * Check link health status
     *
     * @param int $linkId
     * @return array{
     *     status: string,
     *     last_price_update: string|null,
     *     last_validation: string|null,
     *     needs_price_update: bool,
     *     needs_validation: bool,
     *     is_active: bool,
     *     url_status: string
     * }
     * @throws NotFoundException
     */
    public function checkLinkHealth(int $linkId): array;

    /**
     * Perform link health check batch
     *
     * @param int $batchSize
     * @return array{checked: int, healthy: int, unhealthy: int, errors: array}
     */
    public function performHealthCheckBatch(int $batchSize = 100): array;

    /**
     * Clean up old/invalid links
     *
     * @param int $daysInactive
     * @param bool $dryRun
     * @return array{cleaned: int, archived: int, errors: array}
     * @throws AuthorizationException
     */
    public function cleanupOldLinks(int $daysInactive = 90, bool $dryRun = true): array;

    /**
     * Revalidate all links for a product
     *
     * @param int $productId
     * @return array{validated: int, invalid: int, errors: array}
     * @throws AuthorizationException
     * @throws NotFoundException
     */
    public function revalidateProductLinks(int $productId): array;

    // ==================== BUSINESS RULE VALIDATION ====================

    /**
     * Validate link business rules
     *
     * @param Link $link
     * @param string $context 'create'|'update'|'delete'|'archive'
     * @return array<string, string[]> Validation errors
     */
    public function validateLinkBusinessRules(Link $link, string $context): array;

    /**
     * Check if link can be archived
     *
     * @param int $linkId
     * @return bool
     * @throws NotFoundException
     */
    public function canArchiveLink(int $linkId): bool;

    /**
     * Check if link can be deleted
     *
     * @param int $linkId
     * @return array{can_delete: bool, has_sales: bool, has_clicks: bool, sale_count: int, click_count: int}
     * @throws NotFoundException
     */
    public function validateLinkDeletion(int $linkId): array;
}