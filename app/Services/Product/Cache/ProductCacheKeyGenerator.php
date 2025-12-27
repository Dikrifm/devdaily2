<?php

namespace App\Services\Product\Cache;

use App\Entities\Product;
use App\Enums\ProductStatus;
use App\DTOs\Queries\ProductQuery;
use CodeIgniter\I18n\Time;

/**
 * ProductCacheKeyGenerator - Standardized Cache Key Generation for Product Domain
 * 
 * Layer 5: Cache Strategy Component
 * Generates consistent cache keys for L1 (Entity), L2 (Query), L3 (Compute) caching
 * 
 * Pattern: {entity}:{tenant}:{id}:{version}:{suffix}
 * 
 * @package App\Services\Product\Cache
 */
class ProductCacheKeyGenerator
{
    /**
     * Cache version for cache invalidation strategy
     * 
     * @var string
     */
    private const CACHE_VERSION = 'v4';

    /**
     * Default tenant identifier (for multi-tenant systems)
     * 
     * @var string
     */
    private string $tenantId = 'default';

    /**
     * Separator for cache key parts
     * 
     * @var string
     */
    private const KEY_SEPARATOR = ':';

    /**
     * Set tenant identifier for multi-tenant cache isolation
     * 
     * @param string $tenantId
     * @return self
     */
    public function setTenant(string $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }

    /**
     * Get tenant identifier
     * 
     * @return string
     */
    public function getTenant(): string
    {
        return $this->tenantId;
    }

    // ==================== L1: ENTITY CACHE KEYS ====================

    /**
     * Generate L1 cache key for single product entity
     * Pattern: product:{tenant}:{id}:v4
     * TTL: Short (40 minutes)
     * 
     * @param int $productId
     * @return string
     */
    public function forEntity(int $productId): string
    {
        return $this->buildKey(['product', $this->tenantId, $productId, self::CACHE_VERSION]);
    }

    /**
     * Generate L1 cache key for product with relations
     * Pattern: product:{tenant}:{id}:v4:relations:{relation_hash}
     * 
     * @param int $productId
     * @param array $relations
     * @return string
     */
    public function forEntityWithRelations(int $productId, array $relations = []): string
    {
        $keyParts = ['product', $this->tenantId, $productId, self::CACHE_VERSION];
        
        if (!empty($relations)) {
            sort($relations);
            $relationHash = md5(implode(',', $relations));
            array_push($keyParts, 'relations', $relationHash);
        }
        
        return $this->buildKey($keyParts);
    }

    /**
     * Generate L1 cache key for product by slug
     * Pattern: product:{tenant}:slug:{slug}:v4
     * 
     * @param string $slug
     * @return string
     */
    public function forEntityBySlug(string $slug): string
    {
        return $this->buildKey(['product', $this->tenantId, 'slug', $slug, self::CACHE_VERSION]);
    }

    /**
     * Generate L1 cache key for multiple product entities
     * Pattern: product:{tenant}:bulk:{id_hash}:v4
     * 
     * @param array $productIds
     * @return string
     */
    public function forEntityBulk(array $productIds): string
    {
        sort($productIds);
        $idHash = md5(implode(',', $productIds));
        
        return $this->buildKey(['product', $this->tenantId, 'bulk', $idHash, self::CACHE_VERSION]);
    }

    // ==================== L2: QUERY CACHE KEYS ====================

    /**
     * Generate L2 cache key for product listing query
     * Pattern: product:{tenant}:list:{query_hash}:v4
     * TTL: Medium (1-24 hours)
     * 
     * @param ProductQuery $query
     * @param bool $adminMode
     * @return string
     */
    public function forListQuery(ProductQuery $query, bool $adminMode = false): string
    {
        $queryData = $query->toArray();
        $queryData['admin_mode'] = $adminMode;
        
        $queryHash = $this->hashQueryParameters($queryData);
        
        return $this->buildKey(['product', $this->tenantId, 'list', $queryHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L2 cache key for product search
     * Pattern: product:{tenant}:search:{search_hash}:v4
     * 
     * @param string $keyword
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @param bool $adminMode
     * @return string
     */
    public function forSearch(
        string $keyword,
        array $filters = [],
        int $limit = 20,
        int $offset = 0,
        bool $adminMode = false
    ): string {
        $searchParams = [
            'keyword' => $keyword,
            'filters' => $filters,
            'limit' => $limit,
            'offset' => $offset,
            'admin_mode' => $adminMode
        ];
        
        $searchHash = $this->hashQueryParameters($searchParams);
        
        return $this->buildKey(['product', $this->tenantId, 'search', $searchHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L2 cache key for products by category
     * Pattern: product:{tenant}:category:{category_id}:{include_sub}:{limit}:{offset}:{published_only}:v4
     * 
     * @param int $categoryId
     * @param bool $includeSubcategories
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return string
     */
    public function forCategory(
        int $categoryId,
        bool $includeSubcategories = false,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): string {
        $params = [
            'category_id' => $categoryId,
            'include_sub' => $includeSubcategories,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'category', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L2 cache key for products by status
     * Pattern: product:{tenant}:status:{status}:{limit}:{offset}:{admin_mode}:v4
     * 
     * @param ProductStatus $status
     * @param int|null $limit
     * @param int $offset
     * @param bool $adminMode
     * @return string
     */
    public function forStatus(
        ProductStatus $status,
        ?int $limit = null,
        int $offset = 0,
        bool $adminMode = false
    ): string {
        $params = [
            'status' => $status->value,
            'limit' => $limit,
            'offset' => $offset,
            'admin_mode' => $adminMode
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'status', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L2 cache key for popular products
     * Pattern: product:{tenant}:popular:{limit}:{published_only}:{period}:v4
     * 
     * @param int $limit
     * @param bool $publishedOnly
     * @param string $period
     * @return string
     */
    public function forPopular(
        int $limit = 10,
        bool $publishedOnly = true,
        string $period = 'week'
    ): string {
        $params = [
            'limit' => $limit,
            'published_only' => $publishedOnly,
            'period' => $period
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'popular', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L2 cache key for published products
     * Pattern: product:{tenant}:published:{limit}:{offset}:{order_hash}:v4
     * 
     * @param int|null $limit
     * @param int $offset
     * @param array $orderBy
     * @return string
     */
    public function forPublished(
        ?int $limit = null,
        int $offset = 0,
        array $orderBy = ['published_at' => 'DESC']
    ): string {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
            'order_by' => $orderBy
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'published', $paramHash, self::CACHE_VERSION]);
    }

    // ==================== L3: COMPUTE CACHE KEYS ====================

    /**
     * Generate L3 cache key for product statistics
     * Pattern: product:{tenant}:stats:{period}:{include_graph}:v4
     * TTL: Variable (Custom berdasarkan period)
     * 
     * @param string $period
     * @param bool $includeGraphData
     * @return string
     */
    public function forStatistics(string $period = 'month', bool $includeGraphData = false): string
    {
        $params = [
            'period' => $period,
            'include_graph' => $includeGraphData
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'stats', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L3 cache key for dashboard statistics
     * Pattern: product:{tenant}:dashboard:{timestamp}:v4
     * TTL: Short (5 minutes untuk real-time data)
     * 
     * @param array $options
     * @return string
     */
    public function forDashboard(array $options = []): string
    {
        // Round timestamp to nearest 5 minutes for cache sharing
        $timestamp = floor(time() / 300) * 300;
        $optionsHash = $this->hashQueryParameters($options);
        
        return $this->buildKey(['product', $this->tenantId, 'dashboard', $timestamp, $optionsHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L3 cache key for business intelligence data
     * Pattern: product:{tenant}:bi:{dimensions_hash}:{metrics_hash}:{filters_hash}:v4
     * 
     * @param array $dimensions
     * @param array $metrics
     * @param array $filters
     * @return string
     */
    public function forBusinessIntelligence(
        array $dimensions = [],
        array $metrics = [],
        array $filters = []
    ): string {
        $params = [
            'dimensions' => $dimensions,
            'metrics' => $metrics,
            'filters' => $filters
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'bi', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L3 cache key for product recommendations
     * Pattern: product:{tenant}:recommend:{product_id}:{limit}:{criteria_hash}:v4
     * 
     * @param int $currentProductId
     * @param int $limit
     * @param array $criteria
     * @return string
     */
    public function forRecommendations(
        int $currentProductId,
        int $limit = 4,
        array $criteria = ['category', 'popular']
    ): string {
        $params = [
            'current_id' => $currentProductId,
            'limit' => $limit,
            'criteria' => $criteria
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'recommend', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate L3 cache key for product health score
     * Pattern: product:{tenant}:health:{product_id}:{timestamp}:v4
     * 
     * @param int $productId
     * @return string
     */
    public function forHealthScore(int $productId): string
    {
        // Round timestamp to nearest hour
        $timestamp = floor(time() / 3600) * 3600;
        
        return $this->buildKey(['product', $this->tenantId, 'health', $productId, $timestamp, self::CACHE_VERSION]);
    }

    // ==================== MAINTENANCE CACHE KEYS ====================

    /**
     * Generate cache key for products needing price update
     * Pattern: product:{tenant}:maintenance:price_update:{days_threshold}:{limit}:v4
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return string
     */
    public function forPriceUpdateMaintenance(
        int $daysThreshold = 7,
        int $limit = 50,
        bool $publishedOnly = true
    ): string {
        $params = [
            'days_threshold' => $daysThreshold,
            'limit' => $limit,
            'published_only' => $publishedOnly
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'maintenance', 'price_update', $paramHash, self::CACHE_VERSION]);
    }

    /**
     * Generate cache key for products needing link validation
     * Pattern: product:{tenant}:maintenance:link_validation:{days_threshold}:{limit}:v4
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return string
     */
    public function forLinkValidationMaintenance(
        int $daysThreshold = 14,
        int $limit = 50,
        bool $publishedOnly = true
    ): string {
        $params = [
            'days_threshold' => $daysThreshold,
            'limit' => $limit,
            'published_only' => $publishedOnly
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['product', $this->tenantId, 'maintenance', 'link_validation', $paramHash, self::CACHE_VERSION]);
    }

    // ==================== SERVICE CACHE KEYS ====================

    /**
     * Generate cache key for service-level operations
     * Pattern: service:product:{service_name}:{operation}:{params_hash}:v4
     * 
     * @param string $serviceName
     * @param string $operation
     * @param array $parameters
     * @return string
     */
    public function forServiceOperation(
        string $serviceName,
        string $operation,
        array $parameters = []
    ): string {
        $params = [
            'service' => $serviceName,
            'operation' => $operation,
            'params' => $parameters
        ];
        
        $paramHash = $this->hashQueryParameters($params);
        
        return $this->buildKey(['service', 'product', $paramHash, self::CACHE_VERSION]);
    }

    // ==================== PATTERN GENERATION ====================

    /**
     * Generate pattern for invalidating all product caches
     * Pattern: product:{tenant}:*:v4
     * 
     * @return string
     */
    public function patternForAll(): string
    {
        return $this->buildKey(['product', $this->tenantId, '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating specific product caches
     * Pattern: product:{tenant}:{product_id}:*:v4
     * 
     * @param int $productId
     * @return string
     */
    public function patternForProduct(int $productId): string
    {
        return $this->buildKey(['product', $this->tenantId, $productId, '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating product list caches
     * Pattern: product:{tenant}:list:*:v4
     * 
     * @return string
     */
    public function patternForLists(): string
    {
        return $this->buildKey(['product', $this->tenantId, 'list', '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating product search caches
     * Pattern: product:{tenant}:search:*:v4
     * 
     * @return string
     */
    public function patternForSearches(): string
    {
        return $this->buildKey(['product', $this->tenantId, 'search', '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating product category caches
     * Pattern: product:{tenant}:category:*:v4
     * 
     * @return string
     */
    public function patternForCategories(): string
    {
        return $this->buildKey(['product', $this->tenantId, 'category', '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating product status caches
     * Pattern: product:{tenant}:status:*:v4
     * 
     * @return string
     */
    public function patternForStatuses(): string
    {
        return $this->buildKey(['product', $this->tenantId, 'status', '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating product statistics caches
     * Pattern: product:{tenant}:stats:*:v4
     * 
     * @return string
     */
    public function patternForStatistics(): string
    {
        return $this->buildKey(['product', $this->tenantId, 'stats', '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for invalidating service caches
     * Pattern: service:product:*:v4
     * 
     * @return string
     */
    public function patternForServices(): string
    {
        return $this->buildKey(['service', 'product', '*', self::CACHE_VERSION]);
    }

    /**
     * Generate pattern for specific cache level
     * 
     * @param string $level 'entity', 'query', 'compute', 'service'
     * @return string
     */
    public function patternForLevel(string $level): string
    {
        $patterns = [
            'entity' => $this->patternForAll(),
            'query' => $this->buildKey(['product', $this->tenantId, '*', '*', self::CACHE_VERSION]),
            'compute' => $this->buildKey(['product', $this->tenantId, '*', '*', '*', self::CACHE_VERSION]),
            'service' => $this->patternForServices()
        ];
        
        return $patterns[$level] ?? $this->patternForAll();
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Build cache key from parts
     * 
     * @param array $parts
     * @return string
     */
    private function buildKey(array $parts): string
    {
        return implode(self::KEY_SEPARATOR, array_filter($parts, 'strlen'));
    }

    /**
     * Hash query parameters for cache key
     * 
     * @param array $parameters
     * @return string
     */
    private function hashQueryParameters(array $parameters): string
    {
        // Normalize array for consistent hashing
        $this->normalizeArray($parameters);
        
        // Use MD5 for speed, collision risk acceptable for cache keys
        return md5(serialize($parameters));
    }

    /**
     * Normalize array for consistent hashing
     * - Sort associative arrays by key
     * - Convert objects to arrays
     * - Remove null values (optional)
     * 
     * @param mixed $data
     * @return void
     */
    private function normalizeArray(&$data): void
    {
        if (is_array($data)) {
            // Sort by keys for associative arrays
            if (array_keys($data) !== range(0, count($data) - 1)) {
                ksort($data);
            }
            
            // Recursively normalize elements
            foreach ($data as &$value) {
                $this->normalizeArray($value);
            }
        } elseif (is_object($data)) {
            // Convert objects to arrays
            if (method_exists($data, 'toArray')) {
                $data = $data->toArray();
                $this->normalizeArray($data);
            } else {
                $data = (array) $data;
                $this->normalizeArray($data);
            }
        }
    }

    /**
     * Get cache TTL based on cache level and context
     * 
     * @param string $cacheKey
     * @param string $level 'entity', 'query', 'compute'
     * @param array $context
     * @return int TTL in seconds
     */
    public function getTtlForKey(string $cacheKey, string $level, array $context = []): int
    {
        $baseTtl = [
            'entity' => 2400,      // 40 minutes
            'query' => 3600,       // 1 hour
            'compute' => 7200,     // 2 hours
            'service' => 1800      // 30 minutes
        ];
        
        $ttl = $baseTtl[$level] ?? 3600;
        
        // Adjust TTL based on context
        if (isset($context['admin_mode']) && $context['admin_mode']) {
            // Shorter TTL for admin data
            $ttl = max(300, $ttl / 2);
        }
        
        if (isset($context['realtime']) && $context['realtime']) {
            // Very short TTL for real-time data
            $ttl = 60; // 1 minute
        }
        
        // Dynamic TTL based on access time
        $hour = (int) date('G');
        if ($hour >= 9 && $hour <= 17) {
            // Business hours - shorter TTL
            $ttl = max(600, $ttl / 2);
        } else {
            // Off-hours - longer TTL
            $ttl = min(86400, $ttl * 2); // Max 24 hours
        }
        
        return $ttl;
    }

    /**
     * Get cache key metadata for debugging/monitoring
     * 
     * @param string $cacheKey
     * @return array
     */
    public function parseKey(string $cacheKey): array
    {
        $parts = explode(self::KEY_SEPARATOR, $cacheKey);
        
        $metadata = [
            'full_key' => $cacheKey,
            'parts' => $parts,
            'entity' => $parts[0] ?? null,
            'tenant' => $parts[1] ?? null,
            'level' => $this->detectLevel($cacheKey),
            'version' => $parts[count($parts) - 1] ?? null,
            'is_pattern' => strpos($cacheKey, '*') !== false
        ];
        
        // Detect specific key types
        if (isset($parts[2])) {
            if (is_numeric($parts[2])) {
                $metadata['product_id'] = (int) $parts[2];
                $metadata['key_type'] = 'entity';
            } elseif ($parts[2] === 'slug') {
                $metadata['slug'] = $parts[3] ?? null;
                $metadata['key_type'] = 'entity_slug';
            } elseif (in_array($parts[2], ['list', 'search', 'category', 'status', 'popular', 'published'])) {
                $metadata['query_type'] = $parts[2];
                $metadata['key_type'] = 'query';
            } elseif (in_array($parts[2], ['stats', 'dashboard', 'bi', 'recommend', 'health'])) {
                $metadata['compute_type'] = $parts[2];
                $metadata['key_type'] = 'compute';
            } elseif ($parts[2] === 'maintenance') {
                $metadata['maintenance_type'] = $parts[3] ?? null;
                $metadata['key_type'] = 'maintenance';
            }
        }
        
        return $metadata;
    }

    /**
     * Detect cache level from key
     * 
     * @param string $cacheKey
     * @return string 'entity'|'query'|'compute'|'service'|'unknown'
     */
    private function detectLevel(string $cacheKey): string
    {
        $parts = explode(self::KEY_SEPARATOR, $cacheKey);
        
        if (count($parts) < 3) {
            return 'unknown';
        }
        
        if ($parts[0] === 'service') {
            return 'service';
        }
        
        if (is_numeric($parts[2]) || $parts[2] === 'slug') {
            return 'entity';
        }
        
        if (in_array($parts[2], ['list', 'search', 'category', 'status', 'popular', 'published'])) {
            return 'query';
        }
        
        if (in_array($parts[2], ['stats', 'dashboard', 'bi', 'recommend', 'health'])) {
            return 'compute';
        }
        
        return 'unknown';
    }

    /**
     * Validate cache key format
     * 
     * @param string $cacheKey
     * @return bool
     */
    public function isValidKey(string $cacheKey): bool
    {
        // Basic validation
        if (empty($cacheKey) || strlen($cacheKey) > 255) {
            return false;
        }
        
        // Check for invalid characters
        if (preg_match('/[^\w:\-*]/', $cacheKey)) {
            return false;
        }
        
        // Check structure
        $parts = explode(self::KEY_SEPARATOR, $cacheKey);
        
        if (count($parts) < 3) {
            return false;
        }
        
        // Check version
        $lastPart = end($parts);
        if (!preg_match('/^v\d+$/', $lastPart)) {
            return false;
        }
        
        return true;
    }

    /**
     * Generate cache key with dynamic TTL hint
     * 
     * @param string $baseKey
     * @param int $ttl
     * @return string
     */
    public function withTtlHint(string $baseKey, int $ttl): string
    {
        // Add TTL hint as comment (for debugging/monitoring)
        // Not part of actual key comparison
        return $baseKey . '#' . $ttl;
    }

    /**
     * Remove TTL hint from cache key
     * 
     * @param string $keyWithHint
     * @return string
     */
    public function withoutTtlHint(string $keyWithHint): string
    {
        $pos = strpos($keyWithHint, '#');
        return $pos !== false ? substr($keyWithHint, 0, $pos) : $keyWithHint;
    }

    /**
     * Get cache key components for analytics
     * 
     * @param string $cacheKey
     * @return array
     */
    public function getKeyMetrics(string $cacheKey): array
    {
        $metadata = $this->parseKey($cacheKey);
        
        return [
            'length' => strlen($cacheKey),
            'parts_count' => count($metadata['parts']),
            'level' => $metadata['level'],
            'estimated_size' => $this->estimateKeySize($cacheKey),
            'collision_risk' => $this->calculateCollisionRisk($cacheKey)
        ];
    }

    /**
     * Estimate cache entry size based on key pattern
     * 
     * @param string $cacheKey
     * @return int Estimated size in bytes
     */
    private function estimateKeySize(string $cacheKey): int
    {
        $level = $this->detectLevel($cacheKey);
        
        $sizeMap = [
            'entity' => 2048,      // ~2KB per entity
            'query' => 10240,      // ~10KB per query result
            'compute' => 51200,    // ~50KB per compute result
            'service' => 5120,     // ~5KB per service result
            'unknown' => 1024      // Default 1KB
        ];
        
        return $sizeMap[$level] ?? 1024;
    }

    /**
     * Calculate collision risk based on key entropy
     * 
     * @param string $cacheKey
     * @return float Risk score 0-1 (lower is better)
     */
    private function calculateCollisionRisk(string $cacheKey): float
    {
        $parts = explode(self::KEY_SEPARATOR, $cacheKey);
        
        // Count unique parts
        $uniqueParts = array_unique($parts);
        $entropy = count($uniqueParts) / max(1, count($parts));
        
        // Check for sequential IDs (higher collision risk)
        $hasSequentialId = false;
        foreach ($parts as $part) {
            if (is_numeric($part) && $part < 1000) {
                $hasSequentialId = true;
                break;
            }
        }
        
        $risk = 1.0 - $entropy;
        if ($hasSequentialId) {
            $risk += 0.2;
        }
        
        return min(1.0, max(0.0, $risk));
    }
}