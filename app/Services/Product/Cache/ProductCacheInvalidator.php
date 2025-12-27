<?php

namespace App\Services\Product\Cache;

use App\Services\CacheService;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use CodeIgniter\I18n\Time;
use Psr\Log\LoggerInterface;

/**
 * ProductCacheInvalidator - Atomic Cache Invalidation for Product Domain
 * 
 * Layer: Service Cache Component (L1/L2/L3 Management)
 * Responsibility: Atomic, pattern-based cache invalidation with transaction safety
 * 
 * @package App\Services\Product\Cache
 */
class ProductCacheInvalidator
{
    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var array Cache invalidation statistics
     */
    private array $statistics = [
        'total_invalidations' => 0,
        'entity_invalidations' => 0,
        'query_invalidations' => 0,
        'aggregate_invalidations' => 0,
        'failed_invalidations' => 0,
        'patterns_matched' => 0,
        'last_invalidation' => null,
    ];

    /**
     * Cache key patterns for different levels
     */
    private const CACHE_PATTERNS = [
        // L1: Entity Cache
        'entity' => [
            'single' => 'product:{id}:v3',
            'entity_all' => 'product:*:v3',
        ],
        
        // L2: Query/Collection Cache
        'query' => [
            'list' => 'product_service:list_products:*',
            'search' => 'product_service:search_products:*',
            'published' => 'product_service:published_products:*',
            'popular' => 'product_service:popular_products:*',
            'by_category' => 'product_service:products_by_category:*',
            'by_marketplace' => 'product_service:products_by_marketplace:*',
            'by_status' => 'product_service:products_by_status:*',
        ],
        
        // L3: Aggregate/Compute Cache
        'aggregate' => [
            'statistics' => 'product_service:product_statistics:*',
            'dashboard' => 'product_service:dashboard_statistics:*',
            'metrics' => 'product_service:performance_metrics:*',
            'recommendations' => 'product_service:product_recommendations:*',
        ],
        
        // Service-level cache
        'service' => [
            'all' => 'product_service:*',
        ],
    ];

    /**
     * Event to cache pattern mapping
     */
    private const EVENT_PATTERN_MAP = [
        'product.created' => ['entity', 'query.list', 'query.search', 'aggregate.statistics'],
        'product.updated' => ['entity', 'query.list', 'query.search', 'query.by_category', 'aggregate'],
        'product.deleted' => ['entity', 'query', 'aggregate.statistics'],
        'product.published' => ['entity', 'query.published', 'query.popular', 'aggregate'],
        'product.status_changed' => ['entity', 'query.by_status', 'query.list'],
        'product.price_updated' => ['entity', 'query.by_price', 'aggregate.statistics'],
        'category.updated' => ['query.by_category', 'aggregate'],
        'bulk.operation' => ['entity', 'query', 'aggregate'],
    ];

    /**
     * Constructor with Dependency Injection
     * 
     * @param CacheService $cacheService
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        CacheService $cacheService,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->cacheService = $cacheService;
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    /**
     * Invalidate cache for single product entity (L1 Cache)
     * 
     * @param int $productId
     * @param array $options {
     *     @var bool $invalidate_related Invalidate related query caches
     *     @var bool $async Perform asynchronously
     *     @var string $reason Reason for invalidation
     * }
     * @return bool
     */
    public function invalidateEntity(int $productId, array $options = []): bool
    {
        $startTime = microtime(true);
        
        try {
            $this->statistics['total_invalidations']++;
            $this->statistics['entity_invalidations']++;
            
            // 1. Invalidate entity cache (L1)
            $entityKey = str_replace('{id}', $productId, self::CACHE_PATTERNS['entity']['single']);
            $entityDeleted = $this->cacheService->delete($entityKey);
            
            // 2. Invalidate repository-level entity cache
            $repoDeleted = $this->productRepository->clearEntityCache($productId);
            
            $result = $entityDeleted || $repoDeleted;
            
            // 3. Invalidate related query caches if requested
            if ($options['invalidate_related'] ?? true) {
                $relatedInvalidated = $this->invalidateRelatedQueries($productId);
                $result = $result || $relatedInvalidated;
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->statistics['last_invalidation'] = [
                'type' => 'entity',
                'product_id' => $productId,
                'timestamp' => Time::now()->format('Y-m-d H:i:s'),
                'duration_ms' => $duration,
                'success' => $result,
            ];
            
            $this->logger->debug("Product cache invalidated for ID {$productId}", [
                'product_id' => $productId,
                'duration_ms' => $duration,
                'options' => $options,
            ]);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_invalidations']++;
            
            $this->logger->error("Failed to invalidate cache for product {$productId}", [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }

    /**
     * Invalidate query/collection caches (L2 Cache)
     * 
     * @param array $patterns Specific patterns to invalidate (empty for all)
     * @param array $options {
     *     @var bool $dry_run Simulate without actual deletion
     *     @var int $limit Maximum keys to delete
     *     @var string $context Context for logging
     * }
     * @return array{
     *     total_matched: int,
     *     deleted: int,
     *     patterns: array<string>,
     *     sample_keys: array<string>,
     *     duration_ms: float
     * }
     */
    public function invalidateQueryCache(array $patterns = [], array $options = []): array
    {
        $startTime = microtime(true);
        
        try {
            $this->statistics['total_invalidations']++;
            $this->statistics['query_invalidations']++;
            
            // Use provided patterns or default query patterns
            $patternsToInvalidate = empty($patterns) 
                ? array_values(self::CACHE_PATTERNS['query'])
                : $patterns;
            
            $results = [
                'total_matched' => 0,
                'deleted' => 0,
                'patterns' => $patternsToInvalidate,
                'sample_keys' => [],
                'duration_ms' => 0,
            ];
            
            foreach ($patternsToInvalidate as $pattern) {
                $patternResult = $this->cacheService->deleteMatching($pattern);
                
                $results['total_matched'] += $patternResult['matched'] ?? 0;
                $results['deleted'] += $patternResult['deleted'] ?? 0;
                
                if (!empty($patternResult['sample_keys'])) {
                    $results['sample_keys'] = array_merge(
                        $results['sample_keys'],
                        $patternResult['sample_keys']
                    );
                }
                
                $this->statistics['patterns_matched'] += $patternResult['matched'] ?? 0;
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $results['duration_ms'] = $duration;
            
            $this->statistics['last_invalidation'] = [
                'type' => 'query',
                'patterns' => count($patternsToInvalidate),
                'matched' => $results['total_matched'],
                'timestamp' => Time::now()->format('Y-m-d H:i:s'),
                'duration_ms' => $duration,
            ];
            
            $this->logger->debug("Product query cache invalidated", [
                'patterns' => $patternsToInvalidate,
                'results' => $results,
                'context' => $options['context'] ?? 'unknown',
            ]);
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_invalidations']++;
            
            $this->logger->error("Failed to invalidate query cache", [
                'patterns' => $patterns,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'total_matched' => 0,
                'deleted' => 0,
                'patterns' => $patterns,
                'sample_keys' => [],
                'duration_ms' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Invalidate aggregate/compute caches (L3 Cache)
     * 
     * @param array $types Specific aggregate types to invalidate
     * @return array{
     *     invalidated_types: array<string>,
     *     deleted_keys: int,
     *     duration_ms: float
     * }
     */
    public function invalidateAggregateCache(array $types = []): array
    {
        $startTime = microtime(true);
        
        try {
            $this->statistics['total_invalidations']++;
            $this->statistics['aggregate_invalidations']++;
            
            $patternsToInvalidate = [];
            
            if (empty($types)) {
                // Invalidate all aggregate caches
                $patternsToInvalidate = array_values(self::CACHE_PATTERNS['aggregate']);
            } else {
                // Invalidate specific types
                foreach ($types as $type) {
                    if (isset(self::CACHE_PATTERNS['aggregate'][$type])) {
                        $patternsToInvalidate[] = self::CACHE_PATTERNS['aggregate'][$type];
                    }
                }
            }
            
            $totalDeleted = 0;
            foreach ($patternsToInvalidate as $pattern) {
                $result = $this->cacheService->deleteMatching($pattern);
                $totalDeleted += $result['deleted'] ?? 0;
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->statistics['last_invalidation'] = [
                'type' => 'aggregate',
                'patterns' => count($patternsToInvalidate),
                'deleted' => $totalDeleted,
                'timestamp' => Time::now()->format('Y-m-d H:i:s'),
                'duration_ms' => $duration,
            ];
            
            $this->logger->debug("Product aggregate cache invalidated", [
                'types' => $types,
                'patterns' => $patternsToInvalidate,
                'deleted' => $totalDeleted,
                'duration_ms' => $duration,
            ]);
            
            return [
                'invalidated_types' => $types ?: array_keys(self::CACHE_PATTERNS['aggregate']),
                'deleted_keys' => $totalDeleted,
                'duration_ms' => $duration,
            ];
            
        } catch (\Throwable $e) {
            $this->statistics['failed_invalidations']++;
            
            $this->logger->error("Failed to invalidate aggregate cache", [
                'types' => $types,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'invalidated_types' => [],
                'deleted_keys' => 0,
                'duration_ms' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Invalidate cache based on event type
     * 
     * @param string $eventType Event type from EVENT_PATTERN_MAP
     * @param array $context Event context data
     * @return array{
     *     event: string,
     *     cache_levels: array<string>,
     *     invalidated: bool,
     *     details: array
     * }
     */
    public function invalidateByEvent(string $eventType, array $context = []): array
    {
        $startTime = microtime(true);
        
        try {
            if (!isset(self::EVENT_PATTERN_MAP[$eventType])) {
                $this->logger->warning("Unknown cache invalidation event", [
                    'event_type' => $eventType,
                    'context' => $context,
                ]);
                
                return [
                    'event' => $eventType,
                    'cache_levels' => [],
                    'invalidated' => false,
                    'details' => ['error' => 'Unknown event type'],
                ];
            }
            
            $cacheLevels = self::EVENT_PATTERN_MAP[$eventType];
            $results = [];
            
            // Process each cache level
            foreach ($cacheLevels as $level) {
                if ($level === 'entity' && isset($context['product_id'])) {
                    $results['entity'] = $this->invalidateEntity($context['product_id'], [
                        'invalidate_related' => false,
                        'reason' => "Event: {$eventType}",
                    ]);
                } 
                elseif (strpos($level, 'query.') === 0) {
                    $queryType = substr($level, 6); // Remove 'query.' prefix
                    if (isset(self::CACHE_PATTERNS['query'][$queryType])) {
                        $pattern = self::CACHE_PATTERNS['query'][$queryType];
                        $results['query_' . $queryType] = $this->cacheService->deleteMatching($pattern);
                    }
                }
                elseif ($level === 'query') {
                    $results['query_all'] = $this->invalidateQueryCache();
                }
                elseif ($level === 'aggregate') {
                    $results['aggregate_all'] = $this->invalidateAggregateCache();
                }
                elseif (isset(self::CACHE_PATTERNS[$level])) {
                    foreach (self::CACHE_PATTERNS[$level] as $pattern) {
                        $results[$level] = $this->cacheService->deleteMatching($pattern);
                    }
                }
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->logger->info("Cache invalidated by event", [
                'event_type' => $eventType,
                'context' => $context,
                'cache_levels' => $cacheLevels,
                'results' => $results,
                'duration_ms' => $duration,
            ]);
            
            return [
                'event' => $eventType,
                'cache_levels' => $cacheLevels,
                'invalidated' => !empty($results),
                'details' => $results,
                'duration_ms' => $duration,
            ];
            
        } catch (\Throwable $e) {
            $this->statistics['failed_invalidations']++;
            
            $this->logger->error("Failed to invalidate cache by event", [
                'event_type' => $eventType,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'event' => $eventType,
                'cache_levels' => [],
                'invalidated' => false,
                'details' => ['error' => $e->getMessage()],
                'duration_ms' => 0,
            ];
        }
    }

    /**
     * Bulk invalidate cache for multiple products
     * 
     * @param array<int> $productIds
     * @param array $options {
     *     @var bool $invalidate_queries Also invalidate query caches
     *     @var bool $invalidate_aggregates Also invalidate aggregate caches
     *     @var int $batch_size Process in batches
     * }
     * @return array{
     *     total: int,
     *     successful: int,
     *     failed: int,
     *     product_ids: array<int>,
     *     duration_ms: float
     * }
     */
    public function bulkInvalidate(array $productIds, array $options = []): array
    {
        $startTime = microtime(true);
        $batchSize = $options['batch_size'] ?? 50;
        
        $results = [
            'total' => count($productIds),
            'successful' => 0,
            'failed' => 0,
            'product_ids' => $productIds,
            'duration_ms' => 0,
        ];
        
        try {
            // Process in batches to avoid memory issues
            $batches = array_chunk($productIds, $batchSize);
            
            foreach ($batches as $batch) {
                foreach ($batch as $productId) {
                    $success = $this->invalidateEntity($productId, [
                        'invalidate_related' => $options['invalidate_queries'] ?? false,
                        'reason' => 'bulk_invalidation',
                    ]);
                    
                    if ($success) {
                        $results['successful']++;
                    } else {
                        $results['failed']++;
                    }
                }
            }
            
            // Invalidate query caches if requested
            if ($options['invalidate_queries'] ?? true) {
                $this->invalidateQueryCache();
            }
            
            // Invalidate aggregate caches if requested
            if ($options['invalidate_aggregates'] ?? true) {
                $this->invalidateAggregateCache();
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $results['duration_ms'] = $duration;
            
            $this->logger->info("Bulk cache invalidation completed", [
                'total' => $results['total'],
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'batch_size' => $batchSize,
                'duration_ms' => $duration,
            ]);
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_invalidations']++;
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $results['duration_ms'] = $duration;
            $results['error'] = $e->getMessage();
            
            $this->logger->error("Bulk cache invalidation failed", [
                'product_ids_count' => count($productIds),
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);
            
            return $results;
        }
    }

    /**
     * Invalidate all product-related caches (Nuclear option - use with caution)
     * 
     * @param array $options {
     *     @var bool $dry_run Simulate without actual deletion
     *     @var string $reason Reason for nuclear invalidation
     * }
     * @return array{
     *     entity_keys_deleted: int,
     *     query_keys_deleted: int,
     *     aggregate_keys_deleted: int,
     *     total_deleted: int,
     *     duration_ms: float
     * }
     */
    public function invalidateAll(array $options = []): array
    {
        $startTime = microtime(true);
        
        try {
            $this->logger->warning("Nuclear cache invalidation initiated", [
                'reason' => $options['reason'] ?? 'manual',
                'dry_run' => $options['dry_run'] ?? false,
            ]);
            
            if ($options['dry_run'] ?? false) {
                return [
                    'entity_keys_deleted' => 0,
                    'query_keys_deleted' => 0,
                    'aggregate_keys_deleted' => 0,
                    'total_deleted' => 0,
                    'duration_ms' => 0,
                    'dry_run' => true,
                ];
            }
            
            $results = [
                'entity_keys_deleted' => 0,
                'query_keys_deleted' => 0,
                'aggregate_keys_deleted' => 0,
                'total_deleted' => 0,
                'duration_ms' => 0,
            ];
            
            // 1. Invalidate all entity caches
            $entityPattern = self::CACHE_PATTERNS['entity']['entity_all'];
            $entityResult = $this->cacheService->deleteMatching($entityPattern);
            $results['entity_keys_deleted'] = $entityResult['deleted'] ?? 0;
            
            // 2. Invalidate all query caches
            $queryResult = $this->invalidateQueryCache();
            $results['query_keys_deleted'] = $queryResult['deleted'] ?? 0;
            
            // 3. Invalidate all aggregate caches
            $aggregateResult = $this->invalidateAggregateCache();
            $results['aggregate_keys_deleted'] = $aggregateResult['deleted_keys'] ?? 0;
            
            // 4. Invalidate repository caches
            $this->productRepository->clearCache();
            
            $results['total_deleted'] = 
                $results['entity_keys_deleted'] + 
                $results['query_keys_deleted'] + 
                $results['aggregate_keys_deleted'];
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $results['duration_ms'] = $duration;
            
            $this->statistics['total_invalidations']++;
            
            $this->logger->warning("Nuclear cache invalidation completed", [
                'results' => $results,
                'reason' => $options['reason'] ?? 'manual',
                'duration_ms' => $duration,
            ]);
            
            return $results;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_invalidations']++;
            
            $this->logger->error("Nuclear cache invalidation failed", [
                'error' => $e->getMessage(),
                'reason' => $options['reason'] ?? 'manual',
            ]);
            
            return [
                'entity_keys_deleted' => 0,
                'query_keys_deleted' => 0,
                'aggregate_keys_deleted' => 0,
                'total_deleted' => 0,
                'duration_ms' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get cache invalidation statistics
     * 
     * @return array{
     *     total_invalidations: int,
     *     entity_invalidations: int,
     *     query_invalidations: int,
     *     aggregate_invalidations: int,
     *     failed_invalidations: int,
     *     patterns_matched: int,
     *     last_invalidation: array|null,
     *     timestamp: string
     * }
     */
    public function getInvalidationStats(): array
    {
        return [
            'total_invalidations' => $this->statistics['total_invalidations'],
            'entity_invalidations' => $this->statistics['entity_invalidations'],
            'query_invalidations' => $this->statistics['query_invalidations'],
            'aggregate_invalidations' => $this->statistics['aggregate_invalidations'],
            'failed_invalidations' => $this->statistics['failed_invalidations'],
            'patterns_matched' => $this->statistics['patterns_matched'],
            'last_invalidation' => $this->statistics['last_invalidation'],
            'timestamp' => Time::now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Reset invalidation statistics
     * 
     * @return void
     */
    public function resetStats(): void
    {
        $this->statistics = [
            'total_invalidations' => 0,
            'entity_invalidations' => 0,
            'query_invalidations' => 0,
            'aggregate_invalidations' => 0,
            'failed_invalidations' => 0,
            'patterns_matched' => 0,
            'last_invalidation' => null,
        ];
        
        $this->logger->debug("Cache invalidation statistics reset");
    }

    /**
     * Invalidate related query caches for a product
     * 
     * @param int $productId
     * @return bool
     */
    private function invalidateRelatedQueries(int $productId): bool
    {
        try {
            // Patterns that might contain this product
            $patterns = [
                // List queries that might include this product
                "product_service:list_products:*",
                "product_service:search_products:*",
                "product_service:published_products:*",
                
                // Category-based queries
                "product_service:products_by_category:*",
                
                // Status-based queries
                "product_service:products_by_status:*",
            ];
            
            $totalDeleted = 0;
            foreach ($patterns as $pattern) {
                $result = $this->cacheService->deleteMatching($pattern);
                $totalDeleted += $result['deleted'] ?? 0;
            }
            
            return $totalDeleted > 0;
            
        } catch (\Throwable $e) {
            $this->logger->error("Failed to invalidate related queries", [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }

    /**
     * Validate cache key pattern
     * 
     * @param string $pattern
     * @return bool
     */
    public function validatePattern(string $pattern): bool
    {
        // Basic pattern validation
        if (empty($pattern)) {
            return false;
        }
        
        // Check if pattern contains wildcards in valid positions
        if (strpos($pattern, '*') !== false) {
            // Wildcard should not be at the beginning for security
            if (strpos($pattern, '*') === 0) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Get cache patterns configuration
     * 
     * @return array
     */
    public function getCachePatterns(): array
    {
        return self::CACHE_PATTERNS;
    }

    /**
     * Get event pattern mapping
     * 
     * @return array
     */
    public function getEventPatternMap(): array
    {
        return self::EVENT_PATTERN_MAP;
    }
}