<?php

namespace App\Services\Product\Concrete;

use App\Services\BaseService;
use App\Contracts\ProductMaintenanceInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\ProductBadgeRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Services\TransactionService;
use App\Services\CacheService;
use App\Services\Product\Cache\ProductCacheManager;
use App\Services\Product\Cache\ProductCacheInvalidator;
use App\Services\Product\Cache\ProductCacheKeyGenerator;
use App\Services\Product\Factories\ProductResponseFactory;
use App\Services\PaginationService;
use App\DTOs\BaseDTO;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use CodeIgniter\I18n\Time;
use CodeIgniter\HTTP\Files\UploadedFile;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * ProductMaintenanceService - Implementation of ProductMaintenanceInterface
 * 
 * Handles system maintenance, cache management, statistics calculation, import/export,
 * and utility operations for the Product domain (Layer 5 - Business Orchestrator).
 * 
 * @package App\Services\Product\Concrete
 */
class ProductMaintenanceService extends BaseService implements ProductMaintenanceInterface
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;
    
    /**
     * @var ProductBadgeRepositoryInterface
     */
    private ProductBadgeRepositoryInterface $productBadgeRepository;
    
    /**
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $categoryRepository;
    
    /**
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;
    
    /**
     * @var MarketplaceRepositoryInterface
     */
    private MarketplaceRepositoryInterface $marketplaceRepository;
    
    /**
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;
    
    /**
     * @var ProductCacheManager
     */
    private ProductCacheManager $productCacheManager;
    
    /**
     * @var ProductCacheInvalidator
     */
    private ProductCacheInvalidator $productCacheInvalidator;
    
    /**
     * @var ProductCacheKeyGenerator
     */
    private ProductCacheKeyGenerator $productCacheKeyGenerator;
    
    /**
     * @var ProductResponseFactory
     */
    private ProductResponseFactory $productResponseFactory;
    
    /**
     * @var PaginationService
     */
    private PaginationService $paginationService;
    
    /**
     * @var array Service configuration
     */
    private array $config = [
        'cache' => [
            'entity_ttl' => 2400,        // 40 minutes
            'query_ttl' => 3600,         // 1 hour
            'compute_ttl' => 7200,       // 2 hours
            'max_memory_mb' => 256,
        ],
        'maintenance' => [
            'auto_archive_days' => 365,
            'cleanup_orphaned_days' => 30,
            'max_backup_count' => 10,
        ],
        'monitoring' => [
            'alert_thresholds' => [
                'error_rate' => 0.05,    // 5%
                'response_time_ms' => 1000,
                'cache_hit_rate' => 0.7, // 70%
            ],
        ],
    ];
    
    /**
     * @var array Service statistics
     */
    private array $stats = [
        'cache_operations' => 0,
        'maintenance_operations' => 0,
        'import_export_operations' => 0,
        'backup_operations' => 0,
        'diagnostics_run' => 0,
    ];

    /**
     * Constructor
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductBadgeRepositoryInterface $productBadgeRepository
     * @param TransactionService $transactionService
     */
        /**
     * Constructor with Explicit Dependency Injection
     * (Semua dependency wajib dideklarasikan di sini agar Testable)
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductBadgeRepositoryInterface $productBadgeRepository,
        TransactionService $transactionService,
        // Dependency Tambahan (Pindahan dari initializeDependencies)
        CategoryRepositoryInterface $categoryRepository,
        LinkRepositoryInterface $linkRepository,
        MarketplaceRepositoryInterface $marketplaceRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        ProductCacheManager $productCacheManager,
        ProductCacheInvalidator $productCacheInvalidator,
        ProductCacheKeyGenerator $productCacheKeyGenerator,
        ProductResponseFactory $productResponseFactory,
        PaginationService $paginationService
    ) {
        parent::__construct($transactionService); // Asumsi BaseService butuh transaction service
        
        $this->productRepository = $productRepository;
        $this->productBadgeRepository = $productBadgeRepository;
        
        // Assign Dependency Tambahan
        $this->categoryRepository = $categoryRepository;
        $this->linkRepository = $linkRepository;
        $this->marketplaceRepository = $marketplaceRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->productCacheManager = $productCacheManager;
        $this->productCacheInvalidator = $productCacheInvalidator;
        $this->productCacheKeyGenerator = $productCacheKeyGenerator;
        $this->productResponseFactory = $productResponseFactory;
        $this->paginationService = $paginationService;
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'ProductMaintenanceService';
    }

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        $errors = [];
        
        // Add business rule validations specific to maintenance operations
        // Example: Validate backup configuration, import settings, etc.
        
        return $errors;
    }

    // ==================== CACHE MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function clearAllProductCaches(): array
    {
        $this->authorize('product.maintenance.clear_cache');
        
        return $this->transaction(function () {
            $this->stats['cache_operations']++;
            
            // Clear entity caches (L1)
            $entityPattern = $this->productCacheKeyGenerator->patternForAll();
            $entityCleared = $this->productCacheInvalidator->invalidateAll(['levels' => ['entity']]);
            
            // Clear query caches (L2)
            $queryPattern = $this->productCacheKeyGenerator->patternForLists();
            $queryCleared = $this->productCacheInvalidator->invalidateAll(['levels' => ['query']]);
            
            // Clear compute caches (L3)
            $computePattern = $this->productCacheKeyGenerator->patternForStatistics();
            $computeCleared = $this->productCacheInvalidator->invalidateAll(['levels' => ['compute']]);
            
            // Calculate total cleared
            $totalCleared = array_sum($entityCleared) + array_sum($queryCleared) + array_sum($computeCleared);
            
            // Get cache stats for size estimation
            $cacheStats = $this->getCacheStatistics();
            $totalSize = $cacheStats['memory_usage'] ?? '0 KB';
            
            return [
                'cleared' => $totalCleared,
                'levels' => [
                    'entity' => array_sum($entityCleared),
                    'query' => array_sum($queryCleared),
                    'compute' => array_sum($computeCleared),
                ],
                'total_size' => $totalSize,
                'timestamp' => Time::now()->toDateTimeString(),
            ];
        });
    }

    /**
     * {@inheritDoc}
     */
    public function clearProductCache(int $productId, array $options = []): bool
    {
        $this->authorize('product.maintenance.clear_cache', $productId);
        
        return $this->transaction(function () use ($productId, $options) {
            $this->stats['cache_operations']++;
            
            // Default options
            $defaultOptions = [
                'clear_related' => true,
                'levels' => ['entity', 'query', 'compute'],
            ];
            
            $options = array_merge($defaultOptions, $options);
            
            // Clear entity cache for this product
            $entityCleared = $this->productCacheInvalidator->invalidateEntity($productId, $options);
            
            // Clear related caches if requested
            if ($options['clear_related']) {
                $relatedPatterns = [
                    $this->productCacheKeyGenerator->patternForProduct($productId),
                    $this->productCacheKeyGenerator->patternForCategories(),
                    $this->productCacheKeyGenerator->patternForLists(),
                ];
                
                foreach ($relatedPatterns as $pattern) {
                    $this->productCacheInvalidator->invalidateQueryCache([$pattern], $options);
                }
            }
            
            // Audit the operation
            $this->audit(
                'CACHE_CLEARED',
                'Product',
                $productId,
                null,
                ['options' => $options, 'timestamp' => Time::now()->toDateTimeString()]
            );
            
            return $entityCleared;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function clearCacheMatching(string $pattern, array $options = []): array
    {
        $this->authorize('product.maintenance.clear_cache');
        
        return $this->transaction(function () use ($pattern, $options) {
            $this->stats['cache_operations']++;
            
            $defaultOptions = [
                'dry_run' => false,
                'limit' => 1000,
            ];
            
            $options = array_merge($defaultOptions, $options);
            
            // Validate pattern
            if (!$this->productCacheKeyGenerator->isValidKey($pattern) && 
                !$this->productCacheInvalidator->validatePattern($pattern)) {
                throw new ValidationException(
                    'Invalid cache pattern format',
                    'INVALID_CACHE_PATTERN',
                    ['pattern' => $pattern]
                );
            }
            
            if ($options['dry_run']) {
                // Simulate without actual clearing
                $matchedKeys = $this->simulateCacheClear($pattern, $options['limit']);
                $matchedCount = count($matchedKeys);
                
                return [
                    'matched' => $matchedCount,
                    'cleared' => 0,
                    'sample_keys' => array_slice($matchedKeys, 0, 10),
                    'dry_run' => true,
                ];
            }
            
            // Actually clear cache
            $result = $this->productCacheInvalidator->invalidateQueryCache([$pattern], $options);
            
            // Get sample keys (simulate to get some examples)
            $sampleKeys = $this->simulateCacheClear($pattern, 10);
            
            return [
                'matched' => $result['total'] ?? 0,
                'cleared' => $result['cleared'] ?? 0,
                'sample_keys' => $sampleKeys,
                'dry_run' => false,
            ];
        });
    }

    /**
     * {@inheritDoc}
     */
    public function warmProductCaches(array $productIds, array $options = []): array
    {
        $this->authorize('product.maintenance.warm_cache');
        
        return $this->transaction(function () use ($productIds, $options) {
            $this->stats['cache_operations']++;
            
            $defaultOptions = [
                'levels' => ['entity', 'query'],
                'include_relations' => true,
                'priority' => 5,
                'background' => false,
            ];
            
            $options = array_merge($defaultOptions, $options);
            
            $totalProducts = count($productIds);
            $warmedCount = 0;
            $failedCount = 0;
            
            // Warm entity caches
            if (in_array('entity', $options['levels'])) {
                foreach ($productIds as $productId) {
                    try {
                        $product = $this->productRepository->findById($productId);
                        if ($product) {
                            $this->productCacheManager->setEntity($product, $options);
                            $warmedCount++;
                        }
                    } catch (Throwable $e) {
                        $failedCount++;
                        log_message('error', sprintf(
                            'Failed to warm cache for product %d: %s',
                            $productId,
                            $e->getMessage()
                        ));
                    }
                }
            }
            
            // Warm query caches if requested
            if (in_array('query', $options['levels']) && $options['include_relations']) {
                $this->warmQueryCaches($productIds);
            }
            
            // Calculate estimated improvement (simplified)
            $estimatedImprovement = $this->calculateCacheWarmImprovement($warmedCount);
            
            // Get current cache size
            $cacheStats = $this->getCacheStatistics();
            $cacheSize = $cacheStats['memory_usage'] ?? '0 KB';
            
            return [
                'total' => $totalProducts,
                'warmed' => $warmedCount,
                'failed' => $failedCount,
                'estimated_improvement' => $estimatedImprovement,
                'cache_size' => $cacheSize,
                'levels_warmed' => $options['levels'],
                'timestamp' => Time::now()->toDateTimeString(),
            ];
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheStatistics(): array
    {
        $this->authorize('product.maintenance.view_stats');
        
        try {
            // Get cache manager stats
            $cacheMetrics = $this->productCacheManager->getCacheMetrics();
            
            // Get invalidator stats
            $invalidatorStats = $this->productCacheInvalidator->getInvalidationStats();
            
            // Calculate hit rates
            $totalHits = ($cacheMetrics['hits'] ?? 0) + ($invalidatorStats['hits'] ?? 0);
            $totalMisses = ($cacheMetrics['misses'] ?? 0) + ($invalidatorStats['misses'] ?? 0);
            $totalAccess = $totalHits + $totalMisses;
            $hitRate = $totalAccess > 0 ? $totalHits / $totalAccess : 0;
            
            // Estimate memory usage (simplified)
            $memoryUsage = $this->estimateCacheMemoryUsage();
            
            // Get top cache keys (sample)
            $topKeys = $this->getTopCacheKeys(10);
            
            // Generate recommendations
            $recommendations = $this->generateCacheRecommendations($hitRate, $cacheMetrics);
            
            return [
                'total_keys' => $cacheMetrics['total_keys'] ?? 0,
                'memory_usage' => $memoryUsage,
                'hit_rate' => round($hitRate * 100, 2),
                'levels' => [
                    'entity' => [
                        'hits' => $cacheMetrics['entity_hits'] ?? 0,
                        'misses' => $cacheMetrics['entity_misses'] ?? 0,
                        'size' => $this->formatBytes($cacheMetrics['entity_size'] ?? 0),
                    ],
                    'query' => [
                        'hits' => $cacheMetrics['query_hits'] ?? 0,
                        'misses' => $cacheMetrics['query_misses'] ?? 0,
                        'size' => $this->formatBytes($cacheMetrics['query_size'] ?? 0),
                    ],
                    'compute' => [
                        'hits' => $cacheMetrics['compute_hits'] ?? 0,
                        'misses' => $cacheMetrics['compute_misses'] ?? 0,
                        'size' => $this->formatBytes($cacheMetrics['compute_size'] ?? 0),
                    ],
                ],
                'top_keys' => $topKeys,
                'recommendations' => $recommendations,
                'generated_at' => Time::now()->toDateTimeString(),
            ];
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to get cache statistics: %s',
                $e->getMessage()
            ));
            
            // Return minimal stats
            return [
                'total_keys' => 0,
                'memory_usage' => '0 KB',
                'hit_rate' => 0.0,
                'levels' => [
                    'entity' => ['hits' => 0, 'misses' => 0, 'size' => '0 KB'],
                    'query' => ['hits' => 0, 'misses' => 0, 'size' => '0 KB'],
                    'compute' => ['hits' => 0, 'misses' => 0, 'size' => '0 KB'],
                ],
                'top_keys' => [],
                'recommendations' => ['Unable to generate recommendations due to error'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function optimizeCacheConfiguration(array $constraints = []): array
    {
        $this->authorize('product.maintenance.optimize_cache');
        
        // Current configuration
        $currentConfig = $this->config['cache'];
        
        // Apply constraints
        $optimizedConfig = array_merge($currentConfig, $constraints);
        
        // Validate constraints
        $validationErrors = $this->validateCacheConstraints($optimizedConfig);
        
        if (!empty($validationErrors)) {
            throw new ValidationException(
                'Invalid cache optimization constraints',
                'INVALID_CACHE_CONSTRAINTS',
                ['errors' => $validationErrors]
            );
        }
        
        // Calculate expected improvement
        $currentPerformance = $this->estimateCachePerformance($currentConfig);
        $optimizedPerformance = $this->estimateCachePerformance($optimizedConfig);
        $expectedImprovement = $optimizedPerformance - $currentPerformance;
        
        // Determine changes required
        $changesRequired = $this->calculateConfigChanges($currentConfig, $optimizedConfig);
        
        return [
            'current_config' => $currentConfig,
            'optimized_config' => $optimizedConfig,
            'expected_improvement' => round($expectedImprovement * 100, 2) . '%',
            'changes_required' => $changesRequired,
            'validation_errors' => $validationErrors,
            'estimated_impact' => $this->estimateConfigImpact($currentConfig, $optimizedConfig),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function preloadFrequentProductCache(array $criteria = []): array
    {
        $this->authorize('product.maintenance.preload_cache');
        
        return $this->transaction(function () use ($criteria) {
            $this->stats['cache_operations']++;
            
            $defaultCriteria = [
                'limit' => 100,
                'strategy' => 'popular',
                'include_relations' => true,
            ];
            
            $criteria = array_merge($defaultCriteria, $criteria);
            
            // Get products based on strategy
            $productIds = $this->getProductsByStrategy(
                $criteria['strategy'],
                $criteria['limit']
            );
            
            // Preload caches
            $preloadedCount = $this->preloadProductCaches($productIds, $criteria);
            
            // Estimate impact
            $estimatedImpact = $this->estimatePreloadImpact($preloadedCount, $criteria['strategy']);
            
            return [
                'preloaded' => $preloadedCount,
                'estimated_impact' => $estimatedImpact,
                'strategy' => $criteria['strategy'],
                'products_preloaded' => count($productIds),
                'timestamp' => Time::now()->toDateTimeString(),
            ];
        });
    }

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * {@inheritDoc}
     */
    public function getProductStatistics(string $period = 'month', array $options = []): array
    {
        $this->authorize('product.maintenance.view_stats');
        
        try {
            $defaultOptions = [
                'include_graph_data' => true,
                'include_trends' => true,
                'include_forecast' => false,
                'filters' => [],
            ];
            
            $options = array_merge($defaultOptions, $options);
            
            // Get basic statistics
            $summary = $this->getProductSummary();
            
            // Get trends if requested
            $trends = $options['include_trends'] 
                ? $this->getProductTrends($period, $options['filters'])
                : [];
            
            // Get period data
            $periodData = $this->getPeriodData($period, $options['filters']);
            
            // Generate forecast if requested
            $forecast = $options['include_forecast']
                ? $this->generateForecast($periodData, $period)
                : [];
            
            // Generate cache key for this statistics request
            $cacheKey = $this->productCacheKeyGenerator->forStatistics($period, $options['include_graph_data']);
            
            return [
                'summary' => $summary,
                'trends' => $trends,
                'period_data' => $periodData,
                'forecast' => $forecast,
                'generated_at' => Time::now()->toDateTimeString(),
                'cache_key' => $cacheKey,
                'period' => $period,
                'filters_applied' => $options['filters'],
            ];
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to get product statistics: %s',
                $e->getMessage()
            ));
            
            return [
                'summary' => $this->getMinimalSummary(),
                'trends' => [],
                'period_data' => [],
                'generated_at' => Time::now()->toDateTimeString(),
                'error' => $e->getMessage(),
                'note' => 'Statistics may be incomplete due to error',
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getDashboardStatistics(array $options = []): array
    {
        $this->authorize('product.maintenance.view_dashboard');
        
        try {
            $defaultOptions = [
                'realtime' => false,
                'include_charts' => true,
                'timezone' => app_timezone(),
            ];
            
            $options = array_merge($defaultOptions, $options);
            
            // Get overview statistics
            $overview = $this->getDashboardOverview($options['realtime']);
            
            // Get recent activity
            $recentActivity = $this->getRecentActivity(10, $options['timezone']);
            
            // Get system alerts
            $alerts = $this->getSystemAlerts(['severity' => 'warning,critical']);
            
            // Get performance metrics
            $performance = $this->getDashboardPerformance();
            
            // Generate chart configurations if requested
            $charts = $options['include_charts']
                ? $this->generateDashboardCharts()
                : [];
            
            return [
                'overview' => $overview,
                'recent_activity' => $recentActivity,
                'alerts' => $alerts,
                'performance' => $performance,
                'charts' => $charts,
                'generated_at' => Time::now()->toDateTimeString(),
                'timezone' => $options['timezone'],
            ];
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to get dashboard statistics: %s',
                $e->getMessage()
            ));
            
            return [
                'overview' => $this->getMinimalOverview(),
                'recent_activity' => [],
                'alerts' => [['id' => 'error', 'message' => 'Failed to load dashboard data']],
                'performance' => ['status' => 'error', 'message' => $e->getMessage()],
                'charts' => [],
                'generated_at' => Time::now()->toDateTimeString(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceMetrics(string $period = 'week'): array
    {
        $this->authorize('product.maintenance.view_performance');
        
        try {
            // Get response times (simplified - would come from monitoring system)
            $responseTimes = $this->getResponseTimes($period);
            
            // Get error rates
            $errorRates = $this->getErrorRates($period);
            
            // Get cache efficiency
            $cacheEfficiency = $this->getCacheEfficiency($period);
            
            // Get database performance
            $databasePerformance = $this->getDatabasePerformance($period);
            
            // Generate recommendations
            $recommendations = $this->generatePerformanceRecommendations(
                $responseTimes,
                $errorRates,
                $cacheEfficiency,
                $databasePerformance
            );
            
            return [
                'response_times' => $responseTimes,
                'error_rates' => $errorRates,
                'cache_efficiency' => $cacheEfficiency,
                'database_performance' => $databasePerformance,
                'recommendations' => $recommendations,
                'period' => $period,
                'analyzed_at' => Time::now()->toDateTimeString(),
            ];
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to get performance metrics: %s',
                $e->getMessage()
            ));
            
            return [
                'response_times' => ['average' => 0, 'p95' => 0, 'p99' => 0],
                'error_rates' => ['overall' => 0, 'by_operation' => []],
                'cache_efficiency' => ['hit_rate' => 0, 'miss_rate' => 0],
                'database_performance' => ['status' => 'error'],
                'recommendations' => ['Unable to generate recommendations due to error'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getBusinessIntelligenceData(array $dimensions = [], array $metrics = [], array $filters = []): array
    {
        $this->authorize('product.maintenance.view_bi');
        
        try {
            // Default dimensions and metrics if not specified
            if (empty($dimensions)) {
                $dimensions = ['category', 'status', 'time'];
            }
            
            if (empty($metrics)) {
                $metrics = ['count', 'price_average', 'views'];
            }
            
            // Generate BI data based on dimensions and metrics
            $biData = $this->generateBIData($dimensions, $metrics, $filters);
            
            // Generate summary
            $summary = $this->generateBISummary($biData, $dimensions, $metrics);
            
            return [
                'data' => $biData,
                'dimensions' => $dimensions,
                'metrics' => $metrics,
                'summary' => $summary,
                'filters_applied' => $filters,
                'generated_at' => Time::now()->toDateTimeString(),
            ];
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to get business intelligence data: %s',
                $e->getMessage()
            ));
            
            return [
                'data' => [],
                'dimensions' => $dimensions,
                'metrics' => $metrics,
                'summary' => ['error' => $e->getMessage()],
                'filters_applied' => $filters,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function calculateProductHealthScore(int $productId): array
    {
        $this->authorize('product.maintenance.health_check', $productId);
        
        return $this->transaction(function () use ($productId) {
            // Get product data
            $product = $this->productRepository->findById($productId);
            
            if (!$product) {
                throw NotFoundException::forEntity('Product', $productId);
            }
            
            // Calculate health score components
            $components = [
                'data_completeness' => $this->calculateDataCompleteness($product),
                'image_quality' => $this->calculateImageQuality($product),
                'price_competitiveness' => $this->calculatePriceCompetitiveness($product),
                'link_quality' => $this->calculateLinkQuality($product),
                'seo_optimization' => $this->calculateSeoOptimization($product),
            ];
            
            // Calculate overall score (weighted average)
            $totalScore = 0;
            $totalWeight = 0;
            
            foreach ($components as $component) {
                $totalScore += $component['score'] * $component['weight'];
                $totalWeight += $component['weight'];
            }
            
            $overallScore = $totalWeight > 0 ? round($totalScore / $totalWeight) : 0;
            
            // Determine overall health
            $overallHealth = $this->determineHealthStatus($overallScore);
            
            // Generate recommendations
            $recommendations = $this->generateHealthRecommendations($components, $overallScore);
            
            return [
                'score' => $overallScore,
                'components' => $components,
                'recommendations' => $recommendations,
                'overall_health' => $overallHealth,
                'product_id' => $productId,
                'calculated_at' => Time::now()->toDateTimeString(),
            ];
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemHealthStatus(): array
    {
        $this->authorize('product.maintenance.system_health');
        
        try {
            // Run health checks
            $checks = $this->runSystemHealthChecks();
            
            // Determine overall status
            $overallStatus = $this->determineOverallStatus($checks);
            
            // Get system metrics
            $metrics = $this->getSystemMetrics();
            
            // Get active alerts
            $alerts = $this->getSystemAlerts(['status' => 'active']);
            
            return [
                'status' => $overallStatus,
                'checks' => $checks,
                'metrics' => $metrics,
                'alerts' => $alerts,
                'checked_at' => Time::now()->toDateTimeString(),
                'service' => $this->getServiceName(),
            ];
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to get system health status: %s',
                $e->getMessage()
            ));
            
            return [
                'status' => 'error',
                'checks' => [
                    'health_check' => [
                        'status' => 'error',
                        'message' => 'Failed to run health checks: ' . $e->getMessage(),
                        'timestamp' => Time::now()->toDateTimeString(),
                    ],
                ],
                'metrics' => [],
                'alerts' => [
                    [
                        'id' => 'health_check_failed',
                        'type' => 'system',
                        'severity' => 'critical',
                        'message' => 'Failed to check system health',
                        'created_at' => Time::now()->toDateTimeString(),
                    ],
                ],
                'error' => $e->getMessage(),
            ];
        }
    }

    // ==================== HELPER METHODS ====================

    /**
     * Simulate cache clear for dry run
     *
     * @param string $pattern
     * @param int $limit
     * @return array
     */
    private function simulateCacheClear(string $pattern, int $limit = 1000): array
    {
        // This is a simplified simulation
        // In production, you might query cache store for matching keys
        $sampleKeys = [];
        
        for ($i = 0; $i < min(10, $limit); $i++) {
            $sampleKeys[] = str_replace('*', 'sample_' . $i, $pattern);
        }
        
        return $sampleKeys;
    }

    /**
     * Warm query caches for products
     *
     * @param array $productIds
     * @return int
     */
    private function warmQueryCaches(array $productIds): int
    {
        $warmedCount = 0;
        
        try {
            // Warm category queries
            $warmedCount += $this->warmCategoryQueries($productIds);
            
            // Warm marketplace queries
            $warmedCount += $this->warmMarketplaceQueries($productIds);
            
            // Warm search queries
            $warmedCount += $this->warmSearchQueries($productIds);
            
        } catch (Throwable $e) {
            log_message('error', sprintf(
                'Failed to warm query caches: %s',
                $e->getMessage()
            ));
        }
        
        return $warmedCount;
    }

    /**
     * Calculate cache warm improvement
     *
     * @param int $warmedCount
     * @return string
     */
    private function calculateCacheWarmImprovement(int $warmedCount): string
    {
        // Simplified calculation
        if ($warmedCount < 10) {
            return 'Low impact (1-5% improvement)';
        } elseif ($warmedCount < 50) {
            return 'Medium impact (5-15% improvement)';
        } else {
            return 'High impact (15-30% improvement)';
        }
    }

    /**
     * Estimate cache memory usage
     *
     * @return string
     */
    private function estimateCacheMemoryUsage(): string
    {
        // Simplified estimation
        // In production, use cache system's memory stats
        $estimatedBytes = rand(1000000, 10000000); // 1MB to 10MB
        return $this->formatBytes($estimatedBytes);
    }

    /**
     * Get top cache keys
     *
     * @param int $limit
     * @return array
     */
    private function getTopCacheKeys(int $limit): array
    {
        // Sample top keys (in production, get from cache statistics)
        $sampleKeys = [
            [
                'key' => 'product:entity:123:v4',
                'hits' => rand(100, 1000),
                'size' => $this->formatBytes(rand(1000, 10000)),
                'ttl' => 2400,
            ],
            [
                'key' => 'product:search:category:electronics:v4',
                'hits' => rand(50, 500),
                'size' => $this->formatBytes(rand(5000, 50000)),
                'ttl' => 3600,
            ],
        ];
        
        return array_slice($sampleKeys, 0, $limit);
    }

    /**
     * Generate cache recommendations
     *
     * @param float $hitRate
     * @param array $metrics
     * @return array
     */
    private function generateCacheRecommendations(float $hitRate, array $metrics): array
    {
        $recommendations = [];
        
        if ($hitRate < 0.7) {
            $recommendations[] = 'Cache hit rate is low (' . round($hitRate * 100, 1) . '%). Consider increasing cache TTL or implementing cache warming.';
        }
        
        if (($metrics['entity_size'] ?? 0) > 10000000) { // 10MB
            $recommendations[] = 'Entity cache size is large. Consider implementing cache compression or reducing TTL.';
        }
        
        if (($metrics['query_hits'] ?? 0) < ($metrics['entity_hits'] ?? 0) / 2) {
            $recommendations[] = 'Query cache usage is low compared to entity cache. Review query patterns.';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Cache performance is optimal. No immediate actions needed.';
        }
        
        return $recommendations;
    }

    /**
     * Validate cache constraints
     *
     * @param array $config
     * @return array
     */
    private function validateCacheConstraints(array $config): array
    {
        $errors = [];
        
        if ($config['entity_ttl'] < 60) {
            $errors[] = 'Entity TTL must be at least 60 seconds';
        }
        
        if ($config['max_memory_mb'] < 64) {
            $errors[] = 'Maximum memory must be at least 64MB';
        }
        
        if ($config['entity_ttl'] > $config['query_ttl']) {
            $errors[] = 'Entity TTL should not exceed query TTL';
        }
        
        return $errors;
    }

    /**
     * Estimate cache performance
     *
     * @param array $config
     * @return float
     */
    private function estimateCachePerformance(array $config): float
    {
        // Simplified performance estimation
        $performance = 0.5; // Base 50%
        
        // Adjust based on TTL
        $performance += min($config['entity_ttl'] / 3600, 0.3); // Up to 30% for longer TTL
        
        // Adjust based on memory
        $performance += min($config['max_memory_mb'] / 1024, 0.2); // Up to 20% for more memory
        
        return min($performance, 0.95); // Cap at 95%
    }

    /**
     * Calculate configuration changes
     *
     * @param array $current
     * @param array $optimized
     * @return array
     */
    private function calculateConfigChanges(array $current, array $optimized): array
    {
        $changes = [];
        
        foreach ($optimized as $key => $value) {
            if (!isset($current[$key]) || $current[$key] !== $value) {
                $changes[] = sprintf(
                    '%s: %s -> %s',
                    $key,
                    $current[$key] ?? 'not set',
                    $value
                );
            }
        }
        
        return $changes;
    }

    /**
     * Estimate configuration impact
     *
     * @param array $current
     * @param array $optimized
     * @return array
     */
    private function estimateConfigImpact(array $current, array $optimized): array
    {
        $impact = [];
        
        if (($optimized['entity_ttl'] ?? 0) > ($current['entity_ttl'] ?? 0)) {
            $increase = $optimized['entity_ttl'] - $current['entity_ttl'];
            $impact[] = sprintf(
                'Entity cache TTL increased by %d seconds (%.1f hours)',
                $increase,
                $increase / 3600
            );
        }
        
        if (($optimized['max_memory_mb'] ?? 0) > ($current['max_memory_mb'] ?? 0)) {
            $increase = $optimized['max_memory_mb'] - $current['max_memory_mb'];
            $impact[] = sprintf(
                'Cache memory increased by %d MB',
                $increase
            );
        }
        
        return $impact;
    }

    /**
     * Get products by strategy
     *
     * @param string $strategy
     * @param int $limit
     * @return array
     */
    private function getProductsByStrategy(string $strategy, int $limit): array
    {
        switch ($strategy) {
            case 'popular':
                return $this->getPopularProductIds($limit);
            case 'recent':
                return $this->getRecentProductIds($limit);
            case 'scheduled':
                return $this->getScheduledProductIds($limit);
            default:
                return $this->getRandomProductIds($limit);
        }
    }

    /**
     * Preload product caches
     *
     * @param array $productIds
     * @param array $criteria
     * @return int
     */
    private function preloadProductCaches(array $productIds, array $criteria): int
    {
        $preloadedCount = 0;
        
        foreach ($productIds as $productId) {
            try {
                // Load product and cache it
                $product = $this->productRepository->findById($productId);
                if ($product) {
                    $this->productCacheManager->setEntity($product, ['preload' => true]);
                    $preloadedCount++;
                }
            } catch (Throwable $e) {
                // Continue with other products
            }
        }
        
        return $preloadedCount;
    }

    /**
     * Estimate preload impact
     *
     * @param int $preloadedCount
     * @param string $strategy
     * @return string
     */
    private function estimatePreloadImpact(int $preloadedCount, string $strategy): string
    {
        $impactMap = [
            'popular' => 'high',
            'recent' => 'medium',
            'scheduled' => 'low',
            'random' => 'variable',
        ];
        
        $impact = $impactMap[$strategy] ?? 'unknown';
        
        return sprintf(
            '%s impact - %d products preloaded with %s strategy',
            $impact,
            $preloadedCount,
            $strategy
        );
    }

    /**
     * Format bytes to human readable
     *
     * @param int $bytes
     * @param int $precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    // ==================== TEMPLATE METHODS (TO BE IMPLEMENTED) ====================

    /**
     * Get product summary
     *
     * @return array
     */
    private function getProductSummary(): array
    {
        // Implementation would query database
        return [
            'total_products' => $this->productRepository->countByStatus(null, false),
            'published_products' => $this->productRepository->countByStatus(\App\Enums\ProductStatus::PUBLISHED),
            'draft_products' => $this->productRepository->countByStatus(\App\Enums\ProductStatus::DRAFT),
            'verified_products' => $this->productRepository->countByStatus(\App\Enums\ProductStatus::VERIFIED),
            'archived_products' => $this->productRepository->countByStatus(\App\Enums\ProductStatus::ARCHIVED),
            'average_price' => 0.0, // Would calculate from database
            'total_views' => 0, // Would sum from analytics
        ];
    }

    /**
     * Get minimal summary (fallback)
     *
     * @return array
     */
    private function getMinimalSummary(): array
    {
        return [
            'total_products' => 0,
            'published_products' => 0,
            'draft_products' => 0,
            'verified_products' => 0,
            'archived_products' => 0,
            'average_price' => 0.0,
            'total_views' => 0,
            'note' => 'Summary data unavailable',
        ];
    }

    /**
     * Get product trends
     *
     * @param string $period
     * @param array $filters
     * @return array
     */
    private function getProductTrends(string $period, array $filters): array
    {
        // Implementation would analyze trends
        return [
            'growth_rate' => 0.0,
            'trend_direction' => 'stable',
            'peak_periods' => [],
            'seasonal_patterns' => [],
        ];
    }

    /**
     * Get period data
     *
     * @param string $period
     * @param array $filters
     * @return array
     */
    private function getPeriodData(string $period, array $filters): array
    {
        // Implementation would query time-series data
        return [];
    }

    /**
     * Generate forecast
     *
     * @param array $periodData
     * @param string $period
     * @return array
     */
    private function generateForecast(array $periodData, string $period): array
    {
        // Implementation would use forecasting algorithm
        return [
            'next_period_estimate' => 0,
            'confidence_interval' => [0, 0],
            'method' => 'simple_average',
        ];
    }

    /**
     * Get dashboard overview
     *
     * @param bool $realtime
     * @return array
     */
    private function getDashboardOverview(bool $realtime): array
    {
        return [
            'active_products' => 0,
            'today_views' => 0,
            'today_conversions' => 0,
            'system_status' => 'healthy',
            'last_updated' => Time::now()->toDateTimeString(),
        ];
    }

    /**
     * Get minimal overview (fallback)
     *
     * @return array
     */
    private function getMinimalOverview(): array
    {
        return [
            'active_products' => 0,
            'today_views' => 0,
            'today_conversions' => 0,
            'system_status' => 'unknown',
            'last_updated' => Time::now()->toDateTimeString(),
            'note' => 'Overview data unavailable',
        ];
    }

    /**
     * Get recent activity
     *
     * @param int $limit
     * @param string $timezone
     * @return array
     */
    private function getRecentActivity(int $limit, string $timezone): array
    {
        // Implementation would query audit logs
        return [];
    }

    /**
     * Get dashboard performance
     *
     * @return array
     */
    private function getDashboardPerformance(): array
    {
        return [
            'response_time' => 0.0,
            'error_rate' => 0.0,
            'cache_hit_rate' => 0.0,
            'status' => 'normal',
        ];
    }

    /**
     * Generate dashboard charts
     *
     * @return array
     */
    private function generateDashboardCharts(): array
    {
        return [
            'product_growth' => ['type' => 'line', 'data' => []],
            'category_distribution' => ['type' => 'pie', 'data' => []],
            'performance_metrics' => ['type' => 'bar', 'data' => []],
        ];
    }

    /**
     * Get response times
     *
     * @param string $period
     * @return array
     */
    private function getResponseTimes(string $period): array
    {
        // Implementation would query performance metrics
        return [
            'average' => 0.0,
            'p95' => 0.0,
            'p99' => 0.0,
            'min' => 0.0,
            'max' => 0.0,
        ];
    }

    /**
     * Get error rates
     *
     * @param string $period
     * @return array
     */
    private function getErrorRates(string $period): array
    {
        // Implementation would query error logs
        return [
            'overall' => 0.0,
            'by_operation' => [],
            'trend' => 'stable',
        ];
    }

    /**
     * Get cache efficiency
     *
     * @param string $period
     * @return array
     */
    private function getCacheEfficiency(string $period): array
    {
        $stats = $this->getCacheStatistics();
        
        return [
            'hit_rate' => $stats['hit_rate'] / 100,
            'miss_rate' => 1 - ($stats['hit_rate'] / 100),
            'levels' => $stats['levels'],
        ];
    }

    /**
     * Get database performance
     *
     * @param string $period
     * @return array
     */
    private function getDatabasePerformance(string $period): array
    {
        // Implementation would query database metrics
        return [
            'query_count' => 0,
            'slow_queries' => 0,
            'connection_usage' => 0.0,
            'status' => 'healthy',
        ];
    }

    /**
     * Generate performance recommendations
     *
     * @param array $responseTimes
     * @param array $errorRates
     * @param array $cacheEfficiency
     * @param array $databasePerformance
     * @return array
     */
    private function generatePerformanceRecommendations(
        array $responseTimes,
        array $errorRates,
        array $cacheEfficiency,
        array $databasePerformance
    ): array
    {
        $recommendations = [];
        
        if ($responseTimes['average'] > 1000) {
            $recommendations[] = 'High response time detected. Consider optimizing database queries or implementing caching.';
        }
        
        if ($errorRates['overall'] > 0.05) {
            $recommendations[] = 'Error rate is high. Review error logs and fix underlying issues.';
        }
        
        if ($cacheEfficiency['hit_rate'] < 0.7) {
            $recommendations[] = 'Cache hit rate is low. Consider increasing cache TTL or implementing cache warming.';
        }
        
        return $recommendations;
    }

    /**
     * Generate BI data
     *
     * @param array $dimensions
     * @param array $metrics
     * @param array $filters
     * @return array
     */
    private function generateBIData(array $dimensions, array $metrics, array $filters): array
    {
        // Implementation would generate BI data based on dimensions and metrics
        return [];
    }

    /**
     * Generate BI summary
     *
     * @param array $data
     * @param array $dimensions
     * @param array $metrics
     * @return array
     */
    private function generateBISummary(array $data, array $dimensions, array $metrics): array
    {
        return [
            'data_points' => count($data),
            'dimensions_used' => count($dimensions),
            'metrics_calculated' => count($metrics),
            'generated_at' => Time::now()->toDateTimeString(),
        ];
    }

    /**
     * Calculate data completeness
     *
     * @param object $product
     * @return array
     */
    private function calculateDataCompleteness(object $product): array
    {
        // Simplified calculation
        $requiredFields = ['name', 'description', 'price', 'category_id', 'image_url'];
        $filledFields = 0;
        
        foreach ($requiredFields as $field) {
            if (!empty($product->$field ?? null)) {
                $filledFields++;
            }
        }
        
        $score = round(($filledFields / count($requiredFields)) * 100);
        
        return [
            'score' => $score,
            'weight' => 30,
            'details' => [
                'required_fields' => count($requiredFields),
                'filled_fields' => $filledFields,
                'missing_fields' => array_diff($requiredFields, array_keys(get_object_vars($product))),
            ],
        ];
    }

    /**
     * Calculate image quality
     *
     * @param object $product
     * @return array
     */
    private function calculateImageQuality(object $product): array
    {
        $score = empty($product->image_url ?? null) ? 0 : 100;
        
        return [
            'score' => $score,
            'weight' => 20,
            'details' => [
                'has_image' => !empty($product->image_url),
                'image_url' => $product->image_url ?? null,
            ],
        ];
    }

    /**
     * Calculate price competitiveness
     *
     * @param object $product
     * @return array
     */
    private function calculatePriceCompetitiveness(object $product): array
    {
        // Simplified calculation
        $price = $product->price ?? 0;
        $score = $price > 0 ? 100 : 0;
        
        return [
            'score' => $score,
            'weight' => 25,
            'details' => [
                'price' => $price,
                'has_price' => $price > 0,
            ],
        ];
    }

    /**
     * Calculate link quality
     *
     * @param object $product
     * @return array
     */
    private function calculateLinkQuality(object $product): array
    {
        // Would query link repository
        $links = $this->linkRepository->findByProduct($product->id ?? 0);
        $activeLinks = array_filter($links, fn($link) => $link->is_active ?? false);
        
        $score = count($activeLinks) > 0 ? 100 : 0;
        
        return [
            'score' => $score,
            'weight' => 15,
            'details' => [
                'total_links' => count($links),
                'active_links' => count($activeLinks),
            ],
        ];
    }

    /**
     * Calculate SEO optimization
     *
     * @param object $product
     * @return array
     */
    private function calculateSeoOptimization(object $product): array
    {
        // Check for SEO fields
        $seoFields = ['meta_title', 'meta_description', 'slug'];
        $filledSeoFields = 0;
        
        foreach ($seoFields as $field) {
            if (!empty($product->$field ?? null)) {
                $filledSeoFields++;
            }
        }
        
        $score = round(($filledSeoFields / count($seoFields)) * 100);
        
        return [
            'score' => $score,
            'weight' => 10,
            'details' => [
                'seo_fields' => count($seoFields),
                'filled_seo_fields' => $filledSeoFields,
            ],
        ];
    }

    /**
     * Determine health status
     *
     * @param int $score
     * @return string
     */
    private function determineHealthStatus(int $score): string
    {
        if ($score >= 80) return 'excellent';
        if ($score >= 60) return 'good';
        if ($score >= 40) return 'fair';
        if ($score >= 20) return 'poor';
        return 'critical';
    }

    /**
     * Generate health recommendations
     *
     * @param array $components
     * @param int $overallScore
     * @return array
     */
    private function generateHealthRecommendations(array $components, int $overallScore): array
    {
        $recommendations = [];
        
        foreach ($components as $name => $component) {
            if ($component['score'] < 60) {
                $recommendations[] = sprintf(
                    'Improve %s (current score: %d%%)',
                    str_replace('_', ' ', $name),
                    $component['score']
                );
            }
        }
        
        if ($overallScore < 60) {
            $recommendations[] = 'Overall product health needs improvement';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Product health is good. Keep up the good work!';
        }
        
        return $recommendations;
    }

    /**
     * Run system health checks
     *
     * @return array
     */
    private function runSystemHealthChecks(): array
    {
        $checks = [];
        $timestamp = Time::now()->toDateTimeString();
        
        // Database connection check
        try {
            $this->db->connect();
            $checks['database_connection'] = [
                'status' => 'healthy',
                'message' => 'Database connection successful',
                'timestamp' => $timestamp,
            ];
        } catch (Throwable $e) {
            $checks['database_connection'] = [
                'status' => 'critical',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'timestamp' => $timestamp,
            ];
        }
        
        // Cache availability check
        try {
            $available = $this->cache->isAvailable();
            $checks['cache_availability'] = [
                'status' => $available ? 'healthy' : 'warning',
                'message' => $available ? 'Cache system available' : 'Cache system may be degraded',
                'timestamp' => $timestamp,
            ];
        } catch (Throwable $e) {
            $checks['cache_availability'] = [
                'status' => 'critical',
                'message' => 'Cache check failed: ' . $e->getMessage(),
                'timestamp' => $timestamp,
            ];
        }
        
        // Repository access check
        try {
            $count = $this->productRepository->count();
            $checks['repository_access'] = [
                'status' => 'healthy',
                'message' => sprintf('Repository access successful (%d products)', $count),
                'timestamp' => $timestamp,
            ];
        } catch (Throwable $e) {
            $checks['repository_access'] = [
                'status' => 'critical',
                'message' => 'Repository access failed: ' . $e->getMessage(),
                'timestamp' => $timestamp,
            ];
        }
        
        return $checks;
    }

    /**
     * Determine overall status
     *
     * @param array $checks
     * @return string
     */
    private function determineOverallStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        
        if (in_array('critical', $statuses)) {
            return 'critical';
        }
        
        if (in_array('warning', $statuses)) {
            return 'warning';
        }
        
        return 'healthy';
    }

    /**
     * Get system metrics
     *
     * @return array
     */
    private function getSystemMetrics(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - LARAVEL_START,
            'database_connections' => 1,
            'cache_size' => $this->estimateCacheMemoryUsage(),
        ];
    }

    /**
     * Get popular product IDs
     *
     * @param int $limit
     * @return array
     */
    private function getPopularProductIds(int $limit): array
    {
        // Implementation would query popular products
        return range(1, min($limit, 10));
    }

    /**
     * Get recent product IDs
     *
     * @param int $limit
     * @return array
     */
    private function getRecentProductIds(int $limit): array
    {
        // Implementation would query recent products
        return range(100, 100 + min($limit, 10));
    }

    /**
     * Get scheduled product IDs
     *
     * @param int $limit
     * @return array
     */
    private function getScheduledProductIds(int $limit): array
    {
        // Implementation would query scheduled products
        return range(200, 200 + min($limit, 10));
    }

    /**
     * Get random product IDs
     *
     * @param int $limit
     * @return array
     */
    private function getRandomProductIds(int $limit): array
    {
        // Implementation would query random products
        $ids = [];
        for ($i = 0; $i < $limit; $i++) {
            $ids[] = rand(1, 1000);
        }
        return $ids;
    }

    /**
     * Warm category queries
     *
     * @param array $productIds
     * @return int
     */
    private function warmCategoryQueries(array $productIds): int
    {
        // Implementation would warm category-related caches
        return 0;
    }

    /**
     * Warm marketplace queries
     *
     * @param array $productIds
     * @return int
     */
    private function warmMarketplaceQueries(array $productIds): int
    {
        // Implementation would warm marketplace-related caches
        return 0;
    }

    /**
     * Warm search queries
     *
     * @param array $productIds
     * @return int
     */
    private function warmSearchQueries(array $productIds): int
    {
        // Implementation would warm search-related caches
        return 0;
    }

    // Note: The remaining 36 methods from the interface will be implemented in subsequent responses
    // due to character limits. Each section (Maintenance Operations, Import/Export, etc.)
    // will be implemented separately.
}