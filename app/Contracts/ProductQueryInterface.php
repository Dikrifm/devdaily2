<?php

namespace App\Contracts;

use App\DTOs\Queries\ProductQuery;
use App\DTOs\Responses\ProductResponse;
use App\Enums\ProductStatus;
use App\Exceptions\AuthorizationException;

/**
 * ProductQueryInterface - Contract for Product Search, Filtering & Listing Operations
 * 
 * Handles all read-only operations: search, filtering, pagination, and data retrieval.
 * Optimized for performance with caching strategies (L2/L3 Cache).
 * 
 * @package App\Contracts
 */
interface ProductQueryInterface extends BaseInterface
{
    // ==================== QUERY & SEARCH OPERATIONS ====================

    /**
     * List products with filtering and pagination
     * 
     * @param ProductQuery $query
     * @param bool $adminMode
     * @return array{
     *     data: array<ProductResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         from: int,
     *         to: int
     *     },
     *     filters: array,
     *     metadata: array{
     *         query_hash: string,
     *         cache_key: string,
     *         execution_time_ms: float
     *     }
     * }
     */
    public function listProducts(ProductQuery $query, bool $adminMode = false): array;

    /**
     * Search products with advanced filtering
     * 
     * @param string $keyword
     * @param array $filters Additional filters [status, category_id, etc.]
     * @param int $limit
     * @param int $offset
     * @param bool $adminMode
     * @return array<ProductResponse>
     */
    public function searchProducts(
        string $keyword, 
        array $filters = [], 
        int $limit = 20, 
        int $offset = 0,
        bool $adminMode = false
    ): array;

    /**
     * Advanced search with full query builder capabilities
     * 
     * @param array $criteria Search criteria
     * @param array $orderBy [field => direction]
     * @param int|null $limit
     * @param int $offset
     * @param bool $adminMode
     * @return array<ProductResponse>
     */
    public function advancedSearch(
        array $criteria = [],
        array $orderBy = ['created_at' => 'DESC'],
        ?int $limit = null,
        int $offset = 0,
        bool $adminMode = false
    ): array;

    /**
     * Full-text search across multiple fields
     * 
     * @param string $searchTerm
     * @param array $fields Fields to search [name, description, slug]
     * @param int $limit
     * @param int $offset
     * @param bool $useWildcards
     * @return array<ProductResponse>
     */
    public function fullTextSearch(
        string $searchTerm,
        array $fields = ['name', 'description'],
        int $limit = 20,
        int $offset = 0,
        bool $useWildcards = true
    ): array;

    // ==================== CATEGORY-BASED QUERIES ====================

    /**
     * Get products by category
     * 
     * @param int $categoryId
     * @param bool $includeSubcategories
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsByCategory(
        int $categoryId,
        bool $includeSubcategories = false,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products by multiple categories
     * 
     * @param array<int> $categoryIds
     * @param string $operator 'AND' or 'OR'
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsByCategories(
        array $categoryIds,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products without category (uncategorized)
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getUncategorizedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    // ==================== MARKETPLACE-BASED QUERIES ====================

    /**
     * Get products by marketplace
     * 
     * @param int $marketplaceId
     * @param bool $activeLinksOnly
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsByMarketplace(
        int $marketplaceId,
        bool $activeLinksOnly = true,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products by multiple marketplaces
     * 
     * @param array<int> $marketplaceIds
     * @param string $operator 'AND' or 'OR'
     * @param bool $activeLinksOnly
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsByMarketplaces(
        array $marketplaceIds,
        string $operator = 'OR',
        bool $activeLinksOnly = true,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products without marketplace links
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsWithoutLinks(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    // ==================== STATUS-BASED QUERIES ====================

    /**
     * Get published products with pagination
     * 
     * @param int|null $limit
     * @param int $offset
     * @param array $orderBy
     * @return array<ProductResponse>
     */
    public function getPublishedProducts(
        ?int $limit = null, 
        int $offset = 0, 
        array $orderBy = ['published_at' => 'DESC']
    ): array;

    /**
     * Get products by status
     * 
     * @param ProductStatus $status
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @param bool $adminMode
     * @return array<ProductResponse>
     */
    public function getProductsByStatus(
        ProductStatus $status,
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false,
        bool $adminMode = false
    ): array;

    /**
     * Get products by multiple statuses
     * 
     * @param array<ProductStatus> $statuses
     * @param string $operator 'AND' or 'OR'
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     */
    public function getProductsByStatuses(
        array $statuses,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    /**
     * Get draft products (admin only)
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     * @throws AuthorizationException
     */
    public function getDraftProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    /**
     * Get pending verification products (admin only)
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     * @throws AuthorizationException
     */
    public function getPendingVerificationProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    /**
     * Get verified products (admin only)
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     * @throws AuthorizationException
     */
    public function getVerifiedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    /**
     * Get archived products (admin only)
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     * @throws AuthorizationException
     */
    public function getArchivedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    // ==================== POPULARITY & TRENDING ====================

    /**
     * Get popular products
     * 
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getPopularProducts(int $limit = 10, bool $publishedOnly = true): array;

    /**
     * Get trending products (recently viewed/purchased)
     * 
     * @param int $limit
     * @param string $period 'day', 'week', 'month'
     * @return array<ProductResponse>
     */
    public function getTrendingProducts(int $limit = 10, string $period = 'week'): array;

    /**
     * Get recently added products
     * 
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getRecentlyAddedProducts(int $limit = 10, bool $publishedOnly = true): array;

    /**
     * Get recently updated products
     * 
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getRecentlyUpdatedProducts(int $limit = 10, bool $publishedOnly = true): array;

    /**
     * Get featured products
     * 
     * @param int $limit
     * @return array<ProductResponse>
     */
    public function getFeaturedProducts(int $limit = 5): array;

    // ==================== PRICE-BASED QUERIES ====================

    /**
     * Get products by price range
     * 
     * @param float $minPrice
     * @param float $maxPrice
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsByPriceRange(
        float $minPrice,
        float $maxPrice,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products below price
     * 
     * @param float $price
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsBelowPrice(
        float $price,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products above price
     * 
     * @param float $price
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsAbovePrice(
        float $price,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get cheapest products
     * 
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getCheapestProducts(int $limit = 10, bool $publishedOnly = true): array;

    /**
     * Get most expensive products
     * 
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getMostExpensiveProducts(int $limit = 10, bool $publishedOnly = true): array;

    // ==================== MAINTENANCE QUERIES ====================

    /**
     * Get products needing price update
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsNeedingPriceUpdate(
        int $daysThreshold = 7,
        int $limit = 50,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products needing link validation
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsNeedingLinkValidation(
        int $daysThreshold = 14,
        int $limit = 50,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products with missing images
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsWithMissingImages(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products with missing descriptions
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsWithMissingDescriptions(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products with outdated information
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsWithOutdatedInfo(
        int $daysThreshold = 30,
        int $limit = 50,
        bool $publishedOnly = true
    ): array;

    // ==================== RELATION-BASED QUERIES ====================

    /**
     * Get products with specific badges
     * 
     * @param array<int> $badgeIds
     * @param string $operator 'AND' or 'OR'
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsWithBadges(
        array $badgeIds,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products with marketplace badges
     * 
     * @param array<int> $marketplaceBadgeIds
     * @param string $operator 'AND' or 'OR'
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsWithMarketplaceBadges(
        array $marketplaceBadgeIds,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Get products verified by specific admin
     * 
     * @param int $adminId
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<ProductResponse>
     */
    public function getProductsVerifiedBy(
        int $adminId,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    // ==================== RECOMMENDATION QUERIES ====================

    /**
     * Get product recommendations
     * 
     * @param int $currentProductId
     * @param int $limit
     * @param array $criteria
     * @return array<ProductResponse>
     */
    public function getProductRecommendations(
        int $currentProductId,
        int $limit = 4,
        array $criteria = ['category', 'popular']
    ): array;

    /**
     * Get similar products
     * 
     * @param int $productId
     * @param int $limit
     * @param array $similarityFactors ['category', 'price_range', 'attributes']
     * @return array<ProductResponse>
     */
    public function getSimilarProducts(
        int $productId,
        int $limit = 4,
        array $similarityFactors = ['category', 'price_range']
    ): array;

    /**
     * Get frequently bought together products
     * 
     * @param int $productId
     * @param int $limit
     * @return array<ProductResponse>
     */
    public function getFrequentlyBoughtTogether(int $productId, int $limit = 3): array;

    /**
     * Get cross-sell products
     * 
     * @param int $productId
     * @param int $limit
     * @return array<ProductResponse>
     */
    public function getCrossSellProducts(int $productId, int $limit = 4): array;

    /**
     * Get upsell products
     * 
     * @param int $productId
     * @param int $limit
     * @return array<ProductResponse>
     */
    public function getUpsellProducts(int $productId, int $limit = 4): array;

    // ==================== AGGREGATION & STATISTICS ====================

    /**
     * Count products matching criteria
     * 
     * @param array $criteria
     * @param bool $includeArchived
     * @return int
     */
    public function countProducts(array $criteria = [], bool $includeArchived = false): int;

    /**
     * Count products by status
     * 
     * @param ProductStatus|null $status If null, returns array of all status counts
     * @param bool $includeArchived
     * @return int|array<ProductStatus, int>
     */
    public function countProductsByStatus(?ProductStatus $status = null, bool $includeArchived = false);

    /**
     * Count products by category
     * 
     * @param int|null $categoryId If null, returns array for all categories
     * @param bool $publishedOnly
     * @return int|array
     */
    public function countProductsByCategory(?int $categoryId = null, bool $publishedOnly = false);

    /**
     * Get price statistics
     * 
     * @param array $criteria
     * @return array{
     *     min: float,
     *     max: float,
     *     avg: float,
     *     median: float,
     *     total: int
     * }
     */
    public function getPriceStatistics(array $criteria = []): array;

    /**
     * Get view count statistics
     * 
     * @param array $criteria
     * @return array{
     *     total_views: int,
     *     avg_views: float,
     *     max_views: int,
     *     min_views: int
     * }
     */
    public function getViewStatistics(array $criteria = []): array;

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear query caches for specific patterns
     * 
     * @param array $patterns Cache key patterns to clear
     * @return int Number of cache entries cleared
     */
    public function clearQueryCaches(array $patterns = []): int;

    /**
     * Warm cache for frequently used queries
     * 
     * @param array $queryConfigs Configuration for queries to warm
     * @return array{total: int, successful: int, failed: int}
     */
    public function warmQueryCaches(array $queryConfigs = []): array;

    /**
     * Get cache statistics for query operations
     * 
     * @return array{
     *     total_queries: int,
     *     cached_queries: int,
     *     cache_hit_rate: float,
     *     memory_usage: string,
     *     most_frequent_queries: array
     * }
     */
    public function getQueryCacheStatistics(): array;

    // ==================== UTILITY METHODS ====================

    /**
     * Generate query cache key
     * 
     * @param string $operation
     * @param array $parameters
     * @return string
     */
    public function generateQueryCacheKey(string $operation, array $parameters = []): string;

    /**
     * Validate query parameters
     * 
     * @param array $parameters
     * @param string $context
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateQueryParameters(array $parameters, string $context = 'search'): array;

    /**
     * Build filter summary from query
     * 
     * @param ProductQuery $query
     * @return array<string, mixed>
     */
    public function buildFilterSummary(ProductQuery $query): array;

    /**
     * Get query execution plan (for debugging/optimization)
     * 
     * @param ProductQuery $query
     * @param bool $explain Generate SQL EXPLAIN output
     * @return array
     */
    public function getQueryExecutionPlan(ProductQuery $query, bool $explain = false): array;

    // ==================== BATCH QUERY OPERATIONS ====================

    /**
     * Execute multiple queries in batch
     * 
     * @param array<ProductQuery> $queries
     * @param bool $parallel Execute in parallel if supported
     * @return array<string, mixed> Results keyed by query identifier
     */
    public function batchQuery(array $queries, bool $parallel = false): array;

    /**
     * Stream query results (for large datasets)
     * 
     * @param ProductQuery $query
     * @param callable $callback Callback for each batch
     * @param int $batchSize
     * @return int Total records processed
     */
    public function streamQueryResults(ProductQuery $query, callable $callback, int $batchSize = 100): int;

    /**
     * Get query performance metrics
     * 
     * @param ProductQuery $query
     * @return array{
     *     execution_time_ms: float,
     *     memory_usage_mb: float,
     *     result_count: int,
     *     cache_hit: bool,
     *     query_complexity: string
     * }
     */
    public function getQueryPerformanceMetrics(ProductQuery $query): array;
}