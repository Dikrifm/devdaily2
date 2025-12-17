<?php

namespace App\Repositories\Interfaces;

use App\Entities\Link;
use App\Exceptions\LinkNotFoundException;

interface LinkRepositoryInterface
{
    // ==================== BASIC CRUD OPERATIONS ====================
    
    /**
     * Find link by ID
     *
     * @param int $id Link ID
     * @param bool $withTrashed Include soft deleted links
     * @return Link|null
     */
    public function find(int $id, bool $withTrashed = false): ?Link;
    
    /**
     * Find link by product and marketplace
     *
     * @param int $productId Product ID
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active links
     * @return Link|null
     */
    public function findByProductAndMarketplace(
        int $productId, 
        int $marketplaceId, 
        bool $activeOnly = true
    ): ?Link;
    
    /**
     * Find link by URL (exact match)
     *
     * @param string $url Link URL
     * @param bool $withTrashed Include soft deleted links
     * @return Link|null
     */
    public function findByUrl(string $url, bool $withTrashed = false): ?Link;
    
    /**
     * Get all links with filtering
     *
     * @param array $filters [
     *     'product_id' => int,
     *     'marketplace_id' => int,
     *     'active' => bool,
     *     'store_name' => string,
     *     'min_price' => float,
     *     'max_price' => float,
     *     'has_badge' => bool,
     *     'needs_validation' => bool,
     *     'needs_price_update' => bool
     * ]
     * @param string $sortBy
     * @param string $sortDirection
     * @param bool $withTrashed Include soft deleted links
     * @return array
     */
    public function findAll(
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'DESC',
        bool $withTrashed = false
    ): array;
    
    /**
     * Save link (create or update)
     *
     * @param Link $link
     * @return Link
     * @throws \RuntimeException
     */
    public function save(Link $link): Link;
    
    /**
     * Delete link
     *
     * @param int $id Link ID
     * @param bool $force Permanent deletion
     * @return bool
     */
    public function delete(int $id, bool $force = false): bool;
    
    /**
     * Restore soft deleted link
     *
     * @param int $id Link ID
     * @return bool
     */
    public function restore(int $id): bool;
    
    /**
     * Check if link exists
     *
     * @param int $id Link ID
     * @param bool $withTrashed Include soft deleted links
     * @return bool
     */
    public function exists(int $id, bool $withTrashed = false): bool;
    
    // ==================== PRODUCT-LINK RELATIONS ====================
    
    /**
     * Find all links for a product
     *
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @param bool $withTrashed Include soft deleted links
     * @param string $sortBy
     * @param string $sortDirection
     * @return array
     */
    public function findByProduct(
        int $productId,
        bool $activeOnly = true,
        bool $withTrashed = false,
        string $sortBy = 'price',
        string $sortDirection = 'ASC'
    ): array;
    
    /**
     * Find active links for a product
     *
     * @param int $productId Product ID
     * @return array
     */
    public function findActiveByProduct(int $productId): array;
    
    /**
     * Count links for a product
     *
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @return int
     */
    public function countByProduct(int $productId, bool $activeOnly = true): int;
    
    /**
     * Check if product has active links
     *
     * @param int $productId Product ID
     * @return bool
     */
    public function productHasActiveLinks(int $productId): bool;
    
    /**
     * Get cheapest link for a product
     *
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @return Link|null
     */
    public function findCheapestByProduct(int $productId, bool $activeOnly = true): ?Link;
    
    /**
     * Get highest rated link for a product
     *
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @return Link|null
     */
    public function findHighestRatedByProduct(int $productId, bool $activeOnly = true): ?Link;
    
    // ==================== MARKETPLACE-LINK RELATIONS ====================
    
    /**
     * Find all links for a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active links
     * @param bool $withTrashed Include soft deleted links
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function findByMarketplace(
        int $marketplaceId,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 50,
        int $offset = 0
    ): array;
    
    /**
     * Count links for a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active links
     * @return int
     */
    public function countByMarketplace(int $marketplaceId, bool $activeOnly = true): int;
    
    // ==================== PRICE MANAGEMENT ====================
    
    /**
     * Update link price
     *
     * @param int $linkId Link ID
     * @param string $newPrice New price (formatted string)
     * @param bool $autoUpdateTimestamp Automatically update last_price_update
     * @return bool
     */
    public function updatePrice(int $linkId, string $newPrice, bool $autoUpdateTimestamp = true): bool;
    
    /**
     * Update price and track price history
     *
     * @param int $linkId Link ID
     * @param string $newPrice New price
     * @param string $changeReason Reason for price change
     * @param int|null $adminId Admin who changed the price
     * @return array [success => bool, price_change => float, old_price => string]
     */
    public function updatePriceWithHistory(
        int $linkId,
        string $newPrice,
        string $changeReason = 'manual_update',
        ?int $adminId = null
    ): array;
    
    /**
     * Get price history for a link
     *
     * @param int $linkId Link ID
     * @param int $limit History entries limit
     * @param string $period Time period (7d, 30d, 90d, all)
     * @return array
     */
    public function getPriceHistory(int $linkId, int $limit = 50, string $period = 'all'): array;
    
    /**
     * Find links that need price update
     *
     * @param int $maxAgeHours Links older than X hours
     * @param int $limit Result limit
     * @param bool $activeOnly Only active links
     * @return array
     */
    public function findNeedsPriceUpdate(int $maxAgeHours = 24, int $limit = 100, bool $activeOnly = true): array;
    
    /**
     * Mark price as checked/updated
     *
     * @param int $linkId Link ID
     * @return bool
     */
    public function markPriceChecked(int $linkId): bool;
    
    /**
     * Get price statistics for a product
     *
     * @param int $productId Product ID
     * @param bool $activeOnly Only active links
     * @return array [min, max, avg, median, count]
     */
    public function getPriceStatisticsByProduct(int $productId, bool $activeOnly = true): array;
    
    // ==================== VALIDATION & STATUS MANAGEMENT ====================
    
    /**
     * Validate link URL and status
     *
     * @param int $linkId Link ID
     * @param bool $force Force re-validation
     * @return array [is_valid => bool, status_code => int, message => string, last_validation => string]
     */
    public function validate(int $linkId, bool $force = false): array;
    
    /**
     * Mark link as validated
     *
     * @param int $linkId Link ID
     * @param bool $isValid Validation result
     * @param string|null $validationNotes
     * @return bool
     */
    public function markAsValidated(int $linkId, bool $isValid = true, ?string $validationNotes = null): bool;
    
    /**
     * Find links that need validation
     *
     * @param int $maxAgeHours Links older than X hours
     * @param int $limit Result limit
     * @param bool $activeOnly Only active links
     * @return array
     */
    public function findNeedsValidation(int $maxAgeHours = 48, int $limit = 100, bool $activeOnly = true): array;
    
    /**
     * Set link active status
     *
     * @param int $linkId Link ID
     * @param bool $active Active status
     * @param string|null $reason Reason for status change
     * @return bool
     */
    public function setActiveStatus(int $linkId, bool $active, ?string $reason = null): bool;
    
    /**
     * Activate link
     *
     * @param int $linkId Link ID
     * @param string|null $reason Reason for activation
     * @return bool
     */
    public function activate(int $linkId, ?string $reason = null): bool;
    
    /**
     * Deactivate link
     *
     * @param int $linkId Link ID
     * @param string|null $reason Reason for deactivation
     * @return bool
     */
    public function deactivate(int $linkId, ?string $reason = null): bool;
    
    /**
     * Check if link is valid and accessible
     *
     * @param int $linkId Link ID
     * @return bool
     */
    public function isValid(int $linkId): bool;
    
    // ==================== CLICK & AFFILIATE TRACKING ====================
    
    /**
     * Increment click count
     *
     * @param int $linkId Link ID
     * @param int $increment Amount to increment
     * @return bool
     */
    public function incrementClicks(int $linkId, int $increment = 1): bool;
    
    /**
     * Record affiliate click
     *
     * @param int $linkId Link ID
     * @param string $ipAddress User IP
     * @param string|null $userAgent User agent
     * @param array $metadata Additional metadata
     * @return bool
     */
    public function recordClick(
        int $linkId,
        string $ipAddress,
        ?string $userAgent = null,
        array $metadata = []
    ): bool;
    
    /**
     * Increment sold count
     *
     * @param int $linkId Link ID
     * @param int $increment Amount to increment
     * @return bool
     */
    public function incrementSoldCount(int $linkId, int $increment = 1): bool;
    
    /**
     * Add affiliate revenue
     *
     * @param int $linkId Link ID
     * @param string $amount Revenue amount
     * @param string $currency Currency code
     * @param string|null $transactionId Transaction ID
     * @param array $metadata Additional metadata
     * @return bool
     */
    public function addAffiliateRevenue(
        int $linkId,
        string $amount,
        string $currency = 'IDR',
        ?string $transactionId = null,
        array $metadata = []
    ): bool;
    
    /**
     * Get click statistics
     *
     * @param int $linkId Link ID
     * @param string $period Time period (today, 7d, 30d, 90d, all)
     * @return array [clicks, unique_clicks, conversion_rate, revenue]
     */
    public function getClickStats(int $linkId, string $period = '30d'): array;
    
    /**
     * Calculate click-through rate
     *
     * @param int $linkId Link ID
     * @param int $productViews Total product views for context
     * @param string $period Time period
     * @return float CTR percentage
     */
    public function calculateClickThroughRate(int $linkId, int $productViews = 0, string $period = '30d'): float;
    
    /**
     * Get revenue statistics
     *
     * @param int $linkId Link ID
     * @param string $period Time period
     * @return array [revenue, commission, transactions]
     */
    public function getRevenueStats(int $linkId, string $period = '30d'): array;
    
    // ==================== MARKETPLACE BADGE MANAGEMENT ====================
    
    /**
     * Assign marketplace badge to link
     *
     * @param int $linkId Link ID
     * @param int $badgeId Marketplace badge ID
     * @return bool
     */
    public function assignBadge(int $linkId, int $badgeId): bool;
    
    /**
     * Remove marketplace badge from link
     *
     * @param int $linkId Link ID
     * @return bool
     */
    public function removeBadge(int $linkId): bool;
    
    /**
     * Get links with specific badge
     *
     * @param int $badgeId Marketplace badge ID
     * @param bool $activeOnly Only active links
     * @param int $limit Result limit
     * @return array
     */
    public function findByBadge(int $badgeId, bool $activeOnly = true, int $limit = 50): array;
    
    // ==================== SEARCH & FILTER ====================
    
    /**
     * Search links by store name or URL
     *
     * @param string $keyword Search term
     * @param bool $activeOnly Only active links
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function search(
        string $keyword,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array;
    
    /**
     * Find links by store name (partial match)
     *
     * @param string $storeName Store name
     * @param bool $exactMatch Exact match only
     * @param bool $activeOnly Only active links
     * @param int $limit Result limit
     * @return array
     */
    public function findByStoreName(
        string $storeName,
        bool $exactMatch = false,
        bool $activeOnly = true,
        int $limit = 50
    ): array;
    
    /**
     * Find links by price range
     *
     * @param float $minPrice Minimum price
     * @param float $maxPrice Maximum price
     * @param bool $activeOnly Only active links
     * @param int $limit Result limit
     * @return array
     */
    public function findByPriceRange(
        float $minPrice,
        float $maxPrice,
        bool $activeOnly = true,
        int $limit = 100
    ): array;
    
    /**
     * Find links by rating
     *
     * @param float $minRating Minimum rating
     * @param bool $activeOnly Only active links
     * @param int $limit Result limit
     * @return array
     */
    public function findByMinRating(float $minRating, bool $activeOnly = true, int $limit = 100): array;
    
    // ==================== STATISTICS & ANALYTICS ====================
    
    /**
     * Get link statistics
     *
     * @param int|null $linkId Link ID (null for system-wide)
     * @return array
     */
    public function getStatistics(?int $linkId = null): array;
    
    /**
     * Count links by status
     *
     * @param bool $withTrashed Include soft deleted links
     * @return array [active => int, inactive => int, needs_validation => int, needs_price_update => int]
     */
    public function countByStatus(bool $withTrashed = false): array;
    
    /**
     * Count total links
     *
     * @param bool $withTrashed Include soft deleted links
     * @return int
     */
    public function countAll(bool $withTrashed = false): int;
    
    /**
     * Count active links
     *
     * @return int
     */
    public function countActive(): int;
    
    /**
     * Get top performing links
     *
     * @param string $metric clicks|revenue|conversion|sold_count
     * @param int $limit Result limit
     * @param string $period Time period
     * @param bool $activeOnly Only active links
     * @return array
     */
    public function getTopPerformers(
        string $metric = 'clicks',
        int $limit = 10,
        string $period = '30d',
        bool $activeOnly = true
    ): array;
    
    /**
     * Get performance trends
     *
     * @param int $linkId Link ID
     * @param string $period Time period
     * @param string $metric clicks|revenue|conversion
     * @return array
     */
    public function getPerformanceTrend(int $linkId, string $period = '30d', string $metric = 'clicks'): array;
    
    /**
     * Get marketplace comparison statistics
     *
     * @param int $productId Product ID
     * @return array [marketplace_id => [price, rating, store_count, avg_rating]]
     */
    public function getMarketplaceComparison(int $productId): array;
    
    // ==================== BATCH & BULK OPERATIONS ====================
    
    /**
     * Bulk update links
     *
     * @param array $linkIds Array of link IDs
     * @param array $updateData Data to update
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $linkIds, array $updateData): int;
    
    /**
     * Bulk activate links
     *
     * @param array $linkIds Array of link IDs
     * @param string|null $reason Reason for activation
     * @return int Number of activated links
     */
    public function bulkActivate(array $linkIds, ?string $reason = null): int;
    
    /**
     * Bulk deactivate links
     *
     * @param array $linkIds Array of link IDs
     * @param string|null $reason Reason for deactivation
     * @return int Number of deactivated links
     */
    public function bulkDeactivate(array $linkIds, ?string $reason = null): int;
    
    /**
     * Bulk validate links
     *
     * @param array $linkIds Array of link IDs
     * @param bool $force Force re-validation
     * @return array [processed => int, valid => int, invalid => int, errors => array]
     */
    public function bulkValidate(array $linkIds, bool $force = false): array;
    
    /**
     * Bulk update prices
     *
     * @param array $linkIds Array of link IDs
     * @param string $price New price
     * @param string $changeReason Reason for price change
     * @return array [processed => int, updated => int, errors => array]
     */
    public function bulkUpdatePrices(array $linkIds, string $price, string $changeReason = 'bulk_update'): array;
    
    /**
     * Bulk delete links
     *
     * @param array $linkIds Array of link IDs
     * @param bool $force Permanent deletion
     * @return int Number of deleted links
     */
    public function bulkDelete(array $linkIds, bool $force = false): int;
    
    // ==================== VALIDATION & BUSINESS RULES ====================
    
    /**
     * Check if link can be deleted
     *
     * @param int $linkId Link ID
     * @return array [can_delete => bool, reasons => string[], affiliate_data => bool]
     */
    public function canDelete(int $linkId): array;
    
    /**
     * Validate link URL format
     *
     * @param string $url URL to validate
     * @return array [is_valid => bool, errors => string[], normalized_url => string]
     */
    public function validateUrl(string $url): array;
    
    /**
     * Check if URL already exists
     *
     * @param string $url URL to check
     * @param int|null $excludeLinkId Link ID to exclude (for updates)
     * @return bool
     */
    public function urlExists(string $url, ?int $excludeLinkId = null): bool;
    
    /**
     * Validate link business rules
     *
     * @param Link $link
     * @return array [is_valid => bool, errors => string[]]
     */
    public function validate(Link $link): array;
    
    /**
     * Check daily click limit
     *
     * @param int $linkId Link ID
     * @param int $maxClicks Maximum clicks per day
     * @return array [within_limit => bool, current_clicks => int, limit => int]
     */
    public function checkDailyClickLimit(int $linkId, int $maxClicks = 1000): array;
    
    // ==================== CACHE MANAGEMENT ====================
    
    /**
     * Clear link caches
     *
     * @param int|null $linkId Specific link ID (null for all)
     * @param int|null $productId Clear product-related caches
     * @return void
     */
    public function clearCache(?int $linkId = null, ?int $productId = null): void;
    
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
    
    // ==================== UTILITY METHODS ====================
    
    /**
     * Generate affiliate tracking URL
     *
     * @param int $linkId Link ID
     * @param array $params Additional tracking parameters
     * @return string|null
     */
    public function generateTrackingUrl(int $linkId, array $params = []): ?string;
    
    /**
     * Get link health status
     *
     * @param int $linkId Link ID
     * @return array [status => string, issues => array, last_check => string]
     */
    public function getHealthStatus(int $linkId): array;
    
    /**
     * Find duplicate links (same product + marketplace)
     *
     * @param int $productId Product ID
     * @param int $marketplaceId Marketplace ID
     * @param bool $includeInactive Include inactive links
     * @return array
     */
    public function findDuplicates(int $productId, int $marketplaceId, bool $includeInactive = false): array;
    
    /**
     * Get links with expiring validation
     *
     * @param int $daysThreshold Days until validation expires
     * @param int $limit Result limit
     * @return array
     */
    public function findExpiringValidation(int $daysThreshold = 7, int $limit = 100): array;
    
    /**
     * Get link summary for quick views
     *
     * @param int $linkId Link ID
     * @return array
     */
    public function getSummary(int $linkId): array;
}