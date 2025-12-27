<?php

namespace App\Services\Product\Cache;

use App\Services\CacheService;
use App\Services\Product\Cache\ProductCacheInvalidator;
use App\Services\Product\Cache\ProductCacheKeyGenerator;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Entities\Product;
use CodeIgniter\I18n\Time;
use Psr\Log\LoggerInterface;
use Closure;

/**
 * ProductCacheManager - Unified Cache Management for Product Domain
 * 
 * Layer: Service Cache Component (L1/L2/L3 Strategy Management)
 * Responsibility: Implements 3-level cache strategy with intelligent coordination
 * 
 * @package App\Services\Product\Cache
 */
class ProductCacheManager
{
    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var ProductCacheInvalidator
     */
    private ProductCacheInvalidator $cacheInvalidator;

    /**
     * @var ProductCacheKeyGenerator
     */
    private ProductCacheKeyGenerator $keyGenerator;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Cache configuration
     */
    private array $config = [
        'levels' => [
            'entity' => [
                'enabled' => true,
                'ttl' => 2400, // 40 minutes
                'strategy' => 'cache_aside',
            ],
            'query' => [
                'enabled' => true,
                'ttl' => 3600, // 1 hour
                'strategy' => 'query_result',
            ],
            'aggregate' => [
                'enabled' => true,
                'ttl' => 7200, // 2 hours
                'strategy' => 'write_behind',
            ],
        ],
        'optimization' => [
            'prefetch_threshold' => 0.7, // 70% cache hit rate triggers prefetch
            'warming_enabled' => true,
            'compression' => true,
        ],
    ];

    /**
     * Cache statistics
     */
    private array $statistics = [
        'total_operations' => 0,
        'hits' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
        'misses' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
        'writes' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
        'invalidations' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
        'memory_usage' => 0,
        'performance_impact' => 0, // milliseconds saved
    ];

    /**
     * Constructor with Dependency Injection
     * 
     * @param CacheService $cacheService
     * @param ProductCacheInvalidator $cacheInvalidator
     * @param ProductCacheKeyGenerator $keyGenerator
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface $logger
     * @param array|null $config Custom configuration
     */
    public function __construct(
        CacheService $cacheService,
        ProductCacheInvalidator $cacheInvalidator,
        ProductCacheKeyGenerator $keyGenerator,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger,
        ?array $config = null
    ) {
        $this->cacheService = $cacheService;
        $this->cacheInvalidator = $cacheInvalidator;
        $this->keyGenerator = $keyGenerator;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
        
        if ($config !== null) {
            $this->config = array_replace_recursive($this->config, $config);
        }
    }

    // ==================== L1: ENTITY CACHE METHODS ====================

    /**
     * Get product entity from cache (L1 Cache - Cache-Aside Pattern)
     * 
     * @param int $productId
     * @param bool $throwIfNotFound
     * @return Product|null
     */
    public function getEntity(int $productId, bool $throwIfNotFound = false): ?Product
    {
        $this->statistics['total_operations']++;
        $startTime = microtime(true);
        
        try {
            if (!$this->config['levels']['entity']['enabled']) {
                return null;
            }
            
            $cacheKey = $this->keyGenerator->generateEntityKey($productId);
            $cachedData = $this->cacheService->get($cacheKey);
            
            if ($cachedData !== null) {
                // Cache hit
                $this->statistics['hits']['entity']++;
                
                $duration = (microtime(true) - $startTime) * 1000;
                $this->statistics['performance_impact'] += $duration;
                
                $this->logger->debug("L1 Cache HIT for product {$productId}", [
                    'key' => $cacheKey,
                    'hit_time_ms' => round($duration, 2),
                ]);
                
                return $cachedData instanceof Product ? $cachedData : null;
            }
            
            // Cache miss
            $this->statistics['misses']['entity']++;
            
            $this->logger->debug("L1 Cache MISS for product {$productId}", [
                'key' => $cacheKey,
            ]);
            
            if ($throwIfNotFound) {
                throw new \RuntimeException("Product {$productId} not found in cache");
            }
            
            return null;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to get entity from cache", [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Set product entity to cache (L1 Cache)
     * 
     * @param Product $product
     * @param array $options {
     *     @var int|null $ttl Custom TTL
     *     @var bool $async Store asynchronously
     *     @var array $tags Cache tags for invalidation
     * }
     * @return bool
     */
    public function setEntity(Product $product, array $options = []): bool
    {
        $this->statistics['total_operations']++;
        
        try {
            if (!$this->config['levels']['entity']['enabled']) {
                return false;
            }
            
            $cacheKey = $this->keyGenerator->generateEntityKey($product->getId());
            $ttl = $options['ttl'] ?? $this->config['levels']['entity']['ttl'];
            
            $success = $this->cacheService->set($cacheKey, $product, $ttl);
            
            if ($success) {
                $this->statistics['writes']['entity']++;
                
                $this->logger->debug("L1 Cache SET for product {$product->getId()}", [
                    'key' => $cacheKey,
                    'ttl' => $ttl,
                    'product_data' => $product->toArray(),
                ]);
            }
            
            return $success;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to set entity to cache", [
                'product_id' => $product->getId(),
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Remember product entity with cache-aside pattern (L1 Cache)
     * 
     * @param int $productId
     * @param Closure $callback Callback to fetch if not in cache
     * @param array $options Cache options
     * @return Product|null
     */
    public function rememberEntity(int $productId, Closure $callback, array $options = []): ?Product
    {
        $this->statistics['total_operations']++;
        
        try {
            // Try to get from cache first
            $cachedProduct = $this->getEntity($productId);
            
            if ($cachedProduct !== null) {
                return $cachedProduct;
            }
            
            // Cache miss, fetch from callback
            $product = $callback();
            
            if ($product instanceof Product) {
                // Store in cache for future requests
                $this->setEntity($product, $options);
            }
            
            return $product;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to remember entity", [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to callback on error
            try {
                return $callback();
            } catch (\Throwable $callbackError) {
                $this->logger->critical("Callback failed in rememberEntity", [
                    'product_id' => $productId,
                    'error' => $callbackError->getMessage(),
                ]);
                
                return null;
            }
        }
    }

    // ==================== L2: QUERY CACHE METHODS ====================

    /**
     * Get query results from cache (L2 Cache - Query Result Caching)
     * 
     * @param string $queryType Type of query (search, list, by_category, etc.)
     * @param array $parameters Query parameters
     * @return array|null
     */
    public function getQuery(string $queryType, array $parameters = []): ?array
    {
        $this->statistics['total_operations']++;
        $startTime = microtime(true);
        
        try {
            if (!$this->config['levels']['query']['enabled']) {
                return null;
            }
            
            $cacheKey = $this->keyGenerator->generateQueryKey($queryType, $parameters);
            $cachedData = $this->cacheService->get($cacheKey);
            
            if ($cachedData !== null) {
                // Cache hit
                $this->statistics['hits']['query']++;
                
                $duration = (microtime(true) - $startTime) * 1000;
                $this->statistics['performance_impact'] += $duration;
                
                $this->logger->debug("L2 Cache HIT for query {$queryType}", [
                    'key' => $cacheKey,
                    'parameters' => $parameters,
                    'hit_time_ms' => round($duration, 2),
                    'result_count' => is_array($cachedData) ? count($cachedData) : 'N/A',
                ]);
                
                return $cachedData;
            }
            
            // Cache miss
            $this->statistics['misses']['query']++;
            
            $this->logger->debug("L2 Cache MISS for query {$queryType}", [
                'key' => $cacheKey,
                'parameters' => $parameters,
            ]);
            
            return null;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to get query from cache", [
                'query_type' => $queryType,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Set query results to cache (L2 Cache)
     * 
     * @param string $queryType
     * @param array $parameters
     * @param array $results Query results
     * @param array $options {
     *     @var int|null $ttl Custom TTL
     *     @var array $tags Cache tags
     *     @var bool $compress Compress large results
     * }
     * @return bool
     */
    public function setQuery(string $queryType, array $parameters, array $results, array $options = []): bool
    {
        $this->statistics['total_operations']++;
        
        try {
            if (!$this->config['levels']['query']['enabled']) {
                return false;
            }
            
            $cacheKey = $this->keyGenerator->generateQueryKey($queryType, $parameters);
            $ttl = $options['ttl'] ?? $this->config['levels']['query']['ttl'];
            
            // Optional compression for large results
            if (($options['compress'] ?? false) && count($results) > 100) {
                $results = $this->compressResults($results);
            }
            
            $success = $this->cacheService->set($cacheKey, $results, $ttl);
            
            if ($success) {
                $this->statistics['writes']['query']++;
                
                $this->logger->debug("L2 Cache SET for query {$queryType}", [
                    'key' => $cacheKey,
                    'ttl' => $ttl,
                    'result_count' => count($results),
                    'compressed' => $options['compress'] ?? false,
                ]);
            }
            
            return $success;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to set query to cache", [
                'query_type' => $queryType,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Remember query results with cache-aside pattern (L2 Cache)
     * 
     * @param string $queryType
     * @param array $parameters
     * @param Closure $callback Callback to execute if cache miss
     * @param array $options Cache options
     * @return array
     */
    public function rememberQuery(string $queryType, array $parameters, Closure $callback, array $options = []): array
    {
        $this->statistics['total_operations']++;
        
        try {
            // Try to get from cache first
            $cachedResults = $this->getQuery($queryType, $parameters);
            
            if ($cachedResults !== null) {
                return $cachedResults;
            }
            
            // Cache miss, execute callback
            $results = $callback();
            
            if (is_array($results)) {
                // Store in cache for future requests
                $this->setQuery($queryType, $parameters, $results, $options);
            }
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to remember query", [
                'query_type' => $queryType,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to callback on error
            try {
                return $callback();
            } catch (\Throwable $callbackError) {
                $this->logger->critical("Callback failed in rememberQuery", [
                    'query_type' => $queryType,
                    'error' => $callbackError->getMessage(),
                ]);
                
                return [];
            }
        }
    }

    // ==================== L3: AGGREGATE CACHE METHODS ====================

    /**
     * Get aggregate/compute data from cache (L3 Cache - Write-Behind Pattern)
     * 
     * @param string $aggregateType Type of aggregate (statistics, metrics, etc.)
     * @param array $parameters Aggregate parameters
     * @return mixed|null
     */
    public function getAggregate(string $aggregateType, array $parameters = [])
    {
        $this->statistics['total_operations']++;
        $startTime = microtime(true);
        
        try {
            if (!$this->config['levels']['aggregate']['enabled']) {
                return null;
            }
            
            $cacheKey = $this->keyGenerator->generateAggregateKey($aggregateType, $parameters);
            $cachedData = $this->cacheService->get($cacheKey);
            
            if ($cachedData !== null) {
                // Cache hit
                $this->statistics['hits']['aggregate']++;
                
                $duration = (microtime(true) - $startTime) * 1000;
                $this->statistics['performance_impact'] += $duration;
                
                $this->logger->debug("L3 Cache HIT for aggregate {$aggregateType}", [
                    'key' => $cacheKey,
                    'parameters' => $parameters,
                    'hit_time_ms' => round($duration, 2),
                ]);
                
                return $cachedData;
            }
            
            // Cache miss
            $this->statistics['misses']['aggregate']++;
            
            $this->logger->debug("L3 Cache MISS for aggregate {$aggregateType}", [
                'key' => $cacheKey,
                'parameters' => $parameters,
            ]);
            
            return null;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to get aggregate from cache", [
                'aggregate_type' => $aggregateType,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }

    /**
     * Set aggregate data to cache (L3 Cache - Write-Behind)
     * 
     * @param string $aggregateType
     * @param array $parameters
     * @param mixed $data Aggregate data
     * @param array $options {
     *     @var int|null $ttl Custom TTL
     *     @var bool $async Store asynchronously (write-behind)
     *     @var callable $on_async_error Error handler for async writes
     * }
     * @return bool
     */
    public function setAggregate(string $aggregateType, array $parameters, $data, array $options = []): bool
    {
        $this->statistics['total_operations']++;
        
        try {
            if (!$this->config['levels']['aggregate']['enabled']) {
                return false;
            }
            
            $cacheKey = $this->keyGenerator->generateAggregateKey($aggregateType, $parameters);
            $ttl = $options['ttl'] ?? $this->config['levels']['aggregate']['ttl'];
            
            $success = false;
            
            // Write-behind strategy for expensive aggregates
            if ($options['async'] ?? true) {
                // Schedule async write
                $success = $this->scheduleAsyncWrite($cacheKey, $data, $ttl, $options);
            } else {
                // Immediate write
                $success = $this->cacheService->set($cacheKey, $data, $ttl);
            }
            
            if ($success) {
                $this->statistics['writes']['aggregate']++;
                
                $this->logger->debug("L3 Cache SET for aggregate {$aggregateType}", [
                    'key' => $cacheKey,
                    'ttl' => $ttl,
                    'async' => $options['async'] ?? true,
                    'data_type' => gettype($data),
                ]);
            }
            
            return $success;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to set aggregate to cache", [
                'aggregate_type' => $aggregateType,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Remember aggregate data with write-behind pattern (L3 Cache)
     * 
     * @param string $aggregateType
     * @param array $parameters
     * @param Closure $callback Expensive computation callback
     * @param array $options Cache options
     * @return mixed
     */
    public function rememberAggregate(string $aggregateType, array $parameters, Closure $callback, array $options = [])
    {
        $this->statistics['total_operations']++;
        
        try {
            // Try to get from cache first
            $cachedData = $this->getAggregate($aggregateType, $parameters);
            
            if ($cachedData !== null) {
                return $cachedData;
            }
            
            // Cache miss, execute expensive computation
            $data = $callback();
            
            // Store in cache asynchronously (write-behind)
            $this->setAggregate($aggregateType, $parameters, $data, array_merge([
                'async' => true,
            ], $options));
            
            return $data;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to remember aggregate", [
                'aggregate_type' => $aggregateType,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to callback on error (synchronous)
            try {
                return $callback();
            } catch (\Throwable $callbackError) {
                $this->logger->critical("Callback failed in rememberAggregate", [
                    'aggregate_type' => $aggregateType,
                    'error' => $callbackError->getMessage(),
                ]);
                
                return null;
            }
        }
    }

    // ==================== MULTI-LEVEL CACHE METHODS ====================

    /**
     * Multi-level cache get with fallback strategy
     * 
     * @param int $productId
     * @param string $operationType Operation context
     * @param Closure $primaryCallback Callback for primary data source
     * @param array $options {
     *     @var bool $try_l1_first Try L1 cache first
     *     @var bool $populate_lower_levels Populate lower levels on miss
     *     @var array $query_params Additional parameters for query cache
     * }
     * @return mixed
     */
    public function multiLevelGet(int $productId, string $operationType, Closure $primaryCallback, array $options = [])
    {
        $this->statistics['total_operations']++;
        $startTime = microtime(true);
        
        try {
            $result = null;
            $cacheLevelUsed = null;
            
            // Strategy: Try L1 → L2 → Primary Source
            if ($options['try_l1_first'] ?? true) {
                // Try L1 Cache
                $result = $this->getEntity($productId);
                if ($result !== null) {
                    $cacheLevelUsed = 'L1';
                }
            }
            
            // If L1 miss, try L2 Cache
            if ($result === null && ($options['try_l2'] ?? true)) {
                $queryParams = array_merge(
                    ['product_id' => $productId],
                    $options['query_params'] ?? []
                );
                
                $result = $this->getQuery($operationType, $queryParams);
                if ($result !== null) {
                    $cacheLevelUsed = 'L2';
                }
            }
            
            // If all caches miss, get from primary source
            if ($result === null) {
                $result = $primaryCallback();
                $cacheLevelUsed = 'Primary';
                
                // Populate caches if enabled
                if ($options['populate_lower_levels'] ?? true) {
                    $this->populateCaches($productId, $result, $operationType, $options);
                }
            }
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logger->debug("Multi-level cache get completed", [
                'product_id' => $productId,
                'operation_type' => $operationType,
                'cache_level_used' => $cacheLevelUsed,
                'duration_ms' => round($duration, 2),
                'result_type' => gettype($result),
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->logger->error("Multi-level cache get failed", [
                'product_id' => $productId,
                'operation_type' => $operationType,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to primary source
            try {
                return $primaryCallback();
            } catch (\Throwable $callbackError) {
                $this->logger->critical("Primary callback failed in multiLevelGet", [
                    'product_id' => $productId,
                    'error' => $callbackError->getMessage(),
                ]);
                
                return null;
            }
        }
    }

    /**
     * Populate multiple cache levels after primary fetch
     * 
     * @param int $productId
     * @param mixed $data
     * @param string $context
     * @param array $options
     * @return void
     */
    private function populateCaches(int $productId, $data, string $context, array $options): void
    {
        try {
            // Populate L1 Cache if data is a Product entity
            if ($data instanceof Product && ($options['populate_l1'] ?? true)) {
                $this->setEntity($data, [
                    'ttl' => $options['l1_ttl'] ?? null,
                ]);
            }
            
            // Populate L2 Cache for query results
            if (is_array($data) && ($options['populate_l2'] ?? true)) {
                $queryParams = array_merge(
                    ['product_id' => $productId],
                    $options['query_params'] ?? []
                );
                
                $this->setQuery($context, $queryParams, $data, [
                    'ttl' => $options['l2_ttl'] ?? null,
                ]);
            }
            
            $this->logger->debug("Cache population completed", [
                'product_id' => $productId,
                'context' => $context,
                'l1_populated' => $options['populate_l1'] ?? true,
                'l2_populated' => $options['populate_l2'] ?? true,
            ]);
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to populate caches", [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ==================== CACHE STRATEGY & OPTIMIZATION ====================

    /**
     * Get cache strategy for operation type
     * 
     * @param string $operationType
     * @param array $context
     * @return array{
     *     level: string,
     *     ttl: int,
     *     strategy: string,
     *     enabled: bool,
     *     recommendations: array<string>
     * }
     */
    public function getCacheStrategy(string $operationType, array $context = []): array
    {
        $strategies = [
            'product.get' => [
                'level' => 'entity',
                'ttl' => 2400,
                'strategy' => 'cache_aside',
                'enabled' => true,
            ],
            'product.list' => [
                'level' => 'query',
                'ttl' => 1800,
                'strategy' => 'query_result',
                'enabled' => true,
            ],
            'product.search' => [
                'level' => 'query',
                'ttl' => 900,
                'strategy' => 'query_result',
                'enabled' => true,
            ],
            'product.statistics' => [
                'level' => 'aggregate',
                'ttl' => 3600,
                'strategy' => 'write_behind',
                'enabled' => true,
            ],
            'product.metrics' => [
                'level' => 'aggregate',
                'ttl' => 1800,
                'strategy' => 'write_through',
                'enabled' => true,
            ],
        ];
        
        $strategy = $strategies[$operationType] ?? [
            'level' => 'query',
            'ttl' => 1800,
            'strategy' => 'query_result',
            'enabled' => true,
        ];
        
        // Adjust based on context
        if (isset($context['admin_mode']) && $context['admin_mode']) {
            $strategy['ttl'] = min($strategy['ttl'], 300); // 5 minutes max for admin
        }
        
        // Add recommendations
        $strategy['recommendations'] = $this->generateRecommendations($operationType, $context);
        
        return $strategy;
    }

    /**
     * Determine if operation should be cached
     * 
     * @param string $operationType
     * @param array $context
     * @return bool
     */
    public function shouldCache(string $operationType, array $context = []): bool
    {
        // Don't cache in certain contexts
        if (isset($context['no_cache']) && $context['no_cache']) {
            return false;
        }
        
        // Admin operations have shorter or no cache
        if (isset($context['admin_mode']) && $context['admin_mode']) {
            return $this->config['levels']['entity']['enabled']; // Only L1 for admin
        }
        
        // Check operation frequency
        $frequency = $this->getOperationFrequency($operationType);
        
        // Only cache frequently accessed operations
        return $frequency > 0.1; // 10% threshold
    }

    /**
     * Warm cache for frequently accessed data
     * 
     * @param array $criteria {
     *     @var string $strategy 'popular', 'recent', 'scheduled'
     *     @var int $limit Number of items to warm
     *     @var array $levels Levels to warm ['entity', 'query', 'aggregate']
     *     @var bool $background Run in background
     * }
     * @return array{
     *     total_warmed: int,
     *     levels_warmed: array<string, int>,
     *     duration_ms: float,
     *     estimated_impact: string
     * }
     */
    public function warmCache(array $criteria = []): array
    {
        $startTime = microtime(true);
        
        try {
            $strategy = $criteria['strategy'] ?? 'popular';
            $limit = $criteria['limit'] ?? 100;
            $levels = $criteria['levels'] ?? ['entity', 'query'];
            
            $results = [
                'total_warmed' => 0,
                'levels_warmed' => [],
                'duration_ms' => 0,
                'estimated_impact' => '0ms',
            ];
            
            // Get popular products to warm
            $products = $this->productRepository->findPopular($limit, 0, true, false);
            
            foreach ($levels as $level) {
                $levelCount = 0;
                
                switch ($level) {
                    case 'entity':
                        // Warm entity cache
                        foreach ($products as $product) {
                            if ($this->setEntity($product)) {
                                $levelCount++;
                            }
                        }
                        break;
                        
                    case 'query':
                        // Warm common queries
                        $levelCount += $this->warmCommonQueries($products);
                        break;
                        
                    case 'aggregate':
                        // Warm aggregate caches
                        $levelCount += $this->warmAggregates();
                        break;
                }
                
                $results['levels_warmed'][$level] = $levelCount;
                $results['total_warmed'] += $levelCount;
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $results['duration_ms'] = $duration;
            
            // Estimate performance impact
            $estimatedSavings = $results['total_warmed'] * 50; // Assume 50ms saved per cache hit
            $results['estimated_impact'] = "{$estimatedSavings}ms";
            
            $this->logger->info("Cache warming completed", [
                'strategy' => $strategy,
                'results' => $results,
                'criteria' => $criteria,
            ]);
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->logger->error("Cache warming failed", [
                'criteria' => $criteria,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'total_warmed' => 0,
                'levels_warmed' => [],
                'duration_ms' => 0,
                'estimated_impact' => '0ms',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Prefetch cache for predicted future access
     * 
     * @param array $predictions Predictions from analytics
     * @param array $options Prefetch options
     * @return array Prefetch results
     */
    public function prefetch(array $predictions, array $options = []): array
    {
        // Implementation would use machine learning predictions
        // For now, implement simple pattern-based prefetch
        
        $results = [
            'prefetched' => 0,
            'hits_improved' => 0,
        ];
        
        // Simple implementation: prefetch products accessed together
        if (isset($predictions['frequently_accessed_together'])) {
            foreach ($predictions['frequently_accessed_together'] as $productId) {
                $this->getEntity($productId); // This will cache if enabled
                $results['prefetched']++;
            }
        }
        
        return $results;
    }

    // ==================== METRICS & MONITORING ====================

    /**
     * Get comprehensive cache metrics
     * 
     * @return array{
     *     statistics: array,
     *     hit_rates: array<string, float>,
     *     performance_impact_ms: float,
     *     recommendations: array<string>,
     *     configuration: array
     * }
     */
    public function getCacheMetrics(): array
    {
        // Calculate hit rates
        $hitRates = [];
        foreach (['entity', 'query', 'aggregate'] as $level) {
            $total = $this->statistics['hits'][$level] + $this->statistics['misses'][$level];
            $hitRates[$level] = $total > 0 
                ? round(($this->statistics['hits'][$level] / $total) * 100, 2)
                : 0;
        }
        
        // Generate recommendations
        $recommendations = $this->generateOptimizationRecommendations($hitRates);
        
        return [
            'statistics' => $this->statistics,
            'hit_rates' => $hitRates,
            'performance_impact_ms' => round($this->statistics['performance_impact'], 2),
            'recommendations' => $recommendations,
            'configuration' => $this->config,
            'timestamp' => Time::now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Reset cache statistics
     * 
     * @return void
     */
    public function resetStatistics(): void
    {
        $this->statistics = [
            'total_operations' => 0,
            'hits' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
            'misses' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
            'writes' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
            'invalidations' => ['entity' => 0, 'query' => 0, 'aggregate' => 0],
            'memory_usage' => 0,
            'performance_impact' => 0,
        ];
        
        $this->logger->debug("Cache statistics reset");
    }

    // ==================== HELPER METHODS ====================

    /**
     * Schedule asynchronous cache write (write-behind)
     * 
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @param array $options
     * @return bool
     */
    private function scheduleAsyncWrite(string $key, $value, int $ttl, array $options): bool
    {
        // In production, this would use a message queue or background job
        // For now, implement immediate write with logging
        
        try {
            $success = $this->cacheService->set($key, $value, $ttl);
            
            if ($success) {
                $this->logger->debug("Async write scheduled", [
                    'key' => $key,
                    'ttl' => $ttl,
                ]);
            }
            
            return $success;
            
        } catch (\Throwable $e) {
            if (isset($options['on_async_error']) && is_callable($options['on_async_error'])) {
                $options['on_async_error']($e, $key, $value);
            }
            
            $this->logger->error("Async write failed", [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Compress results for storage
     * 
     * @param array $results
     * @return array
     */
    private function compressResults(array $results): array
    {
        // Simple compression by removing null values and empty arrays
        $compressed = array_filter($results, function ($item) {
            return $item !== null && $item !== [] && $item !== '';
        });
        
        return $compressed;
    }

    /**
     * Generate optimization recommendations
     * 
     * @param array $hitRates
     * @return array<string>
     */
    private function generateOptimizationRecommendations(array $hitRates): array
    {
        $recommendations = [];
        
        // Check each cache level
        foreach ($hitRates as $level => $rate) {
            if ($rate < 30) {
                $recommendations[] = "Consider disabling {$level} cache (hit rate: {$rate}%)";
            } elseif ($rate > 80) {
                $recommendations[] = "Increase {$level} cache TTL (hit rate: {$rate}%)";
            }
        }
        
        // Overall recommendations
        if ($this->statistics['total_operations'] < 100) {
            $recommendations[] = "Insufficient data for optimization recommendations";
        }
        
        return $recommendations;
    }

    /**
     * Generate cache recommendations for operation
     * 
     * @param string $operationType
     * @param array $context
     * @return array<string>
     */
    private function generateRecommendations(string $operationType, array $context): array
    {
        $recommendations = [];
        
        switch ($operationType) {
            case 'product.get':
                $recommendations[] = "Use L1 cache with 40-minute TTL";
                $recommendations[] = "Implement cache-aside pattern";
                break;
                
            case 'product.list':
                $recommendations[] = "Use L2 cache with 30-minute TTL";
                $recommendations[] = "Cache query results with parameter hash";
                break;
                
            case 'product.statistics':
                $recommendations[] = "Use L3 cache with write-behind pattern";
                $recommendations[] = "Schedule periodic cache updates";
                break;
        }
        
        return $recommendations;
    }

    /**
     * Get operation frequency from analytics
     * 
     * @param string $operationType
     * @return float Frequency (0-1)
     */
    private function getOperationFrequency(string $operationType): float
    {
        // In production, this would query analytics database
        // For now, return default frequencies
        
        $frequencies = [
            'product.get' => 0.8,
            'product.list' => 0.6,
            'product.search' => 0.4,
            'product.statistics' => 0.2,
        ];
        
        return $frequencies[$operationType] ?? 0.3;
    }

    /**
     * Warm common queries
     * 
     * @param array $products
     * @return int
     */
    private function warmCommonQueries(array $products): int
    {
        $count = 0;
        
        // Warm popular products query
        if (!empty($products)) {
            $this->setQuery('popular', [], $products);
            $count++;
        }
        
        // Warm published products query
        $published = array_filter($products, function($product) {
            return $product->isPublished();
        });
        
        if (!empty($published)) {
            $this->setQuery('published', [], $published);
            $count++;
        }
        
        return $count;
    }

    /**
     * Warm aggregate caches
     * 
     * @return int
     */
    private function warmAggregates(): int
    {
        $count = 0;
        
        // Warm statistics
        $stats = $this->productRepository->getStatistics('day');
        if (!empty($stats)) {
            $this->setAggregate('statistics', ['period' => 'day'], $stats);
            $count++;
        }
        
        // Warm dashboard metrics
        $metrics = [
            'total_products' => count($this->productRepository->findAll()),
            'published_count' => count($this->productRepository->findByStatus('published')),
        ];
        
        $this->setAggregate('dashboard', [], $metrics);
        $count++;
        
        return $count;
    }

    /**
     * Get cache configuration
     * 
     * @return array
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }

    /**
     * Update cache configuration
     * 
     * @param array $config
     * @return void
     */
    public function updateConfiguration(array $config): void
    {
        $this->config = array_replace_recursive($this->config, $config);
        
        $this->logger->info("Cache configuration updated", [
            'new_config' => $this->config,
        ]);
    }
}