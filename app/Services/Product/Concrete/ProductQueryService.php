<?php

namespace App\Services\Product\Concrete;

use App\Services\BaseService;
use App\Contracts\ProductQueryInterface;
use App\DTOs\Queries\ProductQuery;
use App\DTOs\Responses\ProductResponse;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Exceptions\AuthorizationException;
use CodeIgniter\I18n\Time;
use Closure;

/**
 * ProductQueryService - Concrete Implementation for Product Search, Filtering & Listing Operations
 * Layer 5: Business Orchestrator (Query-specific)
 * Implements ONLY methods from ProductQueryInterface
 * Optimized for read-only operations with L2/L3 Caching
 * 
 * @package App\Services\Product\Concrete
 */
class ProductQueryService extends BaseService implements ProductQueryInterface
{
    private ProductRepositoryInterface $productRepository;
    private CategoryRepositoryInterface $categoryRepository;
    private LinkRepositoryInterface $linkRepository;
    private MarketplaceRepositoryInterface $marketplaceRepository;
    
    private array $queryStats = [
        'queries_executed' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'total_results' => 0,
        'average_execution_time_ms' => 0
    ];
    
    private float $totalExecutionTime = 0;
    
    public function __construct(
        \CodeIgniter\Database\ConnectionInterface $db,
        \App\Contracts\CacheInterface $cache,
        \App\Services\AuditService $auditService,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        LinkRepositoryInterface $linkRepository,
        MarketplaceRepositoryInterface $marketplaceRepository
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->linkRepository = $linkRepository;
        $this->marketplaceRepository = $marketplaceRepository;
    }
    
    // ==================== REQUIRED BY BASE SERVICE ====================
    
    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'ProductQueryService';
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(\App\DTOs\BaseDTO $dto, array $context = []): array
    {
        // Query service doesn't have business rules for DTOs
        return [];
    }
    
    // ==================== QUERY & SEARCH OPERATIONS ====================
    
    /**
     * {@inheritDoc}
     */
    public function listProducts(ProductQuery $query, bool $adminMode = false): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        if ($adminMode) {
            $this->authorize('product.list');
        }
        
        $cacheKey = $this->generateQueryCacheKey('list_products', [
            'query' => $query->getCacheKey(),
            'admin_mode' => $adminMode
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($query, $adminMode, $startTime) {
            $filters = $query->toRepositoryFilters();
            
            // Adjust filters for admin mode
            if ($adminMode) {
                $filters['include_trashed'] = $query->getIncludeTrashed();
                $filters['admin_mode'] = true;
            } else {
                // Public mode: only published, not trashed
                $filters['status'] = [ProductStatus::PUBLISHED->value];
                $filters['include_trashed'] = false;
            }
            
            // Execute paginated query
            $paginationQuery = new \App\DTOs\Queries\PaginationQuery(
                $query->getPage() ?? 1,
                $query->getPerPage() ?? 20
            );
            
            $result = $this->productRepository->paginate($paginationQuery, $filters);
            
            // Convert entities to responses
            $data = array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => $adminMode]),
                $result['data']
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return [
                'data' => $data,
                'pagination' => $result['pagination'],
                'filters' => $query->toFilterSummary(),
                'metadata' => [
                    'query_hash' => $query->getCacheKey(),
                    'cache_key' => $cacheKey,
                    'execution_time_ms' => round($executionTime, 2)
                ]
            ];
        }, $adminMode ? 60 : 300); // 1 min for admin, 5 min for public
        
        $this->queryStats['total_results'] += count($result['data']);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function searchProducts(
        string $keyword, 
        array $filters = [], 
        int $limit = 20, 
        int $offset = 0,
        bool $adminMode = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        if ($adminMode) {
            $this->authorize('product.search');
        }
        
        $cacheKey = $this->generateQueryCacheKey('search_products', [
            'keyword' => md5($keyword),
            'filters' => md5(serialize($filters)),
            'limit' => $limit,
            'offset' => $offset,
            'admin_mode' => $adminMode
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($keyword, $filters, $limit, $offset, $adminMode, $startTime) {
            // Add admin mode filter
            if (!$adminMode) {
                $filters['status'] = [ProductStatus::PUBLISHED->value];
            }
            
            $products = $this->productRepository->search(
                $keyword,
                $filters,
                $limit,
                $offset,
                ['name' => 'ASC'],
                true // use cache
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => $adminMode]),
                $products
            );
        }, 300); // 5 minutes cache
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function advancedSearch(
        array $criteria = [],
        array $orderBy = ['created_at' => 'DESC'],
        ?int $limit = null,
        int $offset = 0,
        bool $adminMode = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        if ($adminMode) {
            $this->authorize('product.search.advanced');
        }
        
        $cacheKey = $this->generateQueryCacheKey('advanced_search', [
            'criteria' => md5(serialize($criteria)),
            'order_by' => md5(serialize($orderBy)),
            'limit' => $limit,
            'offset' => $offset,
            'admin_mode' => $adminMode
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($criteria, $orderBy, $limit, $offset, $adminMode, $startTime) {
            // Set default status filter for non-admin
            if (!$adminMode && !isset($criteria['status'])) {
                $criteria['status'] = [ProductStatus::PUBLISHED->value];
            }
            
            $products = $this->productRepository->findByCriteria(
                $criteria,
                $orderBy,
                $limit,
                $offset
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => $adminMode]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function fullTextSearch(
        string $searchTerm,
        array $fields = ['name', 'description'],
        int $limit = 20,
        int $offset = 0,
        bool $useWildcards = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('full_text_search', [
            'term' => md5($searchTerm),
            'fields' => md5(serialize($fields)),
            'limit' => $limit,
            'offset' => $offset,
            'wildcards' => $useWildcards
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($searchTerm, $fields, $limit, $offset, $useWildcards, $startTime) {
            // Only published products for public search
            $filters = ['status' => [ProductStatus::PUBLISHED->value]];
            
            $products = $this->productRepository->fullTextSearch(
                $searchTerm,
                $fields,
                $filters,
                $limit,
                $offset,
                $useWildcards
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== CATEGORY-BASED QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByCategory(
        int $categoryId,
        bool $includeSubcategories = false,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_by_category', [
            'category_id' => $categoryId,
            'include_sub' => $includeSubcategories,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $categoryId, 
            $includeSubcategories, 
            $limit, 
            $offset, 
            $publishedOnly,
            $startTime
        ) {
            $products = $this->productRepository->findByCategory(
                $categoryId,
                $includeSubcategories,
                $limit,
                $offset,
                $publishedOnly,
                true
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600); // 10 minutes cache
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByCategories(
        array $categoryIds,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_by_categories', [
            'category_ids' => md5(serialize($categoryIds)),
            'operator' => $operator,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $categoryIds,
            $operator,
            $limit,
            $offset,
            $publishedOnly,
            $startTime
        ) {
            $products = $this->productRepository->findByCategories(
                $categoryIds,
                $operator,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getUncategorizedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('uncategorized_products', [
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findUncategorized(
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== MARKETPLACE-BASED QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByMarketplace(
        int $marketplaceId,
        bool $activeLinksOnly = true,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_by_marketplace', [
            'marketplace_id' => $marketplaceId,
            'active_links' => $activeLinksOnly,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $marketplaceId,
            $activeLinksOnly,
            $limit,
            $offset,
            $publishedOnly,
            $startTime
        ) {
            $products = $this->productRepository->findByMarketplace(
                $marketplaceId,
                $activeLinksOnly,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByMarketplaces(
        array $marketplaceIds,
        string $operator = 'OR',
        bool $activeLinksOnly = true,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_by_marketplaces', [
            'marketplace_ids' => md5(serialize($marketplaceIds)),
            'operator' => $operator,
            'active_links' => $activeLinksOnly,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $marketplaceIds,
            $operator,
            $activeLinksOnly,
            $limit,
            $offset,
            $publishedOnly,
            $startTime
        ) {
            $products = $this->productRepository->findByMarketplaces(
                $marketplaceIds,
                $operator,
                $activeLinksOnly,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsWithoutLinks(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_without_links', [
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findWithoutLinks(
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== STATUS-BASED QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getPublishedProducts(
        ?int $limit = null, 
        int $offset = 0, 
        array $orderBy = ['published_at' => 'DESC']
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('published_products', [
            'limit' => $limit,
            'offset' => $offset,
            'order' => md5(serialize($orderBy))
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $orderBy, $startTime) {
            $products = $this->productRepository->findPublished(
                $limit,
                $offset,
                $orderBy,
                true
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300); // 5 minutes cache
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByStatus(
        ProductStatus $status,
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false,
        bool $adminMode = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        if ($adminMode) {
            $this->authorize('product.list');
        }
        
        $cacheKey = $this->generateQueryCacheKey('products_by_status', [
            'status' => $status->value,
            'limit' => $limit,
            'offset' => $offset,
            'include_relations' => $includeRelations,
            'admin_mode' => $adminMode
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $status, 
            $limit, 
            $offset, 
            $includeRelations, 
            $adminMode,
            $startTime
        ) {
            $products = $this->productRepository->findByStatus(
                $status,
                $limit,
                $offset
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => $adminMode]),
                $products
            );
        }, $adminMode ? 60 : 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByStatuses(
        array $statuses,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.list');
        
        $cacheKey = $this->generateQueryCacheKey('products_by_statuses', [
            'statuses' => md5(serialize(array_map(fn($s) => $s->value, $statuses))),
            'operator' => $operator,
            'limit' => $limit,
            'offset' => $offset,
            'include_relations' => $includeRelations
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $statuses,
            $operator,
            $limit,
            $offset,
            $includeRelations,
            $startTime
        ) {
            $statusValues = array_map(fn($s) => $s->value, $statuses);
            $products = $this->productRepository->findByStatuses(
                $statusValues,
                $operator,
                $limit,
                $offset
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60); // 1 minute for admin queries
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getDraftProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.list');
        
        $cacheKey = $this->generateQueryCacheKey('draft_products', [
            'limit' => $limit,
            'offset' => $offset,
            'include_relations' => $includeRelations
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $includeRelations, $startTime) {
            $products = $this->productRepository->findByStatus(
                ProductStatus::DRAFT,
                $limit,
                $offset
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getPendingVerificationProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.list');
        
        $cacheKey = $this->generateQueryCacheKey('pending_verification_products', [
            'limit' => $limit,
            'offset' => $offset,
            'include_relations' => $includeRelations
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $includeRelations, $startTime) {
            $products = $this->productRepository->findByStatus(
                ProductStatus::PENDING_VERIFICATION,
                $limit,
                $offset
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getVerifiedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.list');
        
        $cacheKey = $this->generateQueryCacheKey('verified_products', [
            'limit' => $limit,
            'offset' => $offset,
            'include_relations' => $includeRelations
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $includeRelations, $startTime) {
            $products = $this->productRepository->findByStatus(
                ProductStatus::VERIFIED,
                $limit,
                $offset
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getArchivedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.list');
        
        $cacheKey = $this->generateQueryCacheKey('archived_products', [
            'limit' => $limit,
            'offset' => $offset,
            'include_relations' => $includeRelations
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $includeRelations, $startTime) {
            $products = $this->productRepository->findByStatus(
                ProductStatus::ARCHIVED,
                $limit,
                $offset,
                true // include trashed
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== POPULARITY & TRENDING ====================
    
    /**
     * {@inheritDoc}
     */
    public function getPopularProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('popular_products', [
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findPopular(
                $limit,
                0,
                $publishedOnly,
                true
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600); // 10 minutes cache
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getTrendingProducts(int $limit = 10, string $period = 'week'): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('trending_products', [
            'limit' => $limit,
            'period' => $period
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $period, $startTime) {
            // Calculate date range based on period
            $endDate = new \DateTimeImmutable();
            $startDate = match($period) {
                'day' => $endDate->modify('-1 day'),
                'week' => $endDate->modify('-1 week'),
                'month' => $endDate->modify('-1 month'),
                default => $endDate->modify('-1 week'),
            };
            
            $products = $this->productRepository->findTrending(
                $startDate,
                $endDate,
                $limit
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300); // 5 minutes cache
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getRecentlyAddedProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('recently_added_products', [
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findRecentlyAdded(
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getRecentlyUpdatedProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('recently_updated_products', [
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findRecentlyUpdated(
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getFeaturedProducts(int $limit = 5): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('featured_products', [
            'limit' => $limit
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $startTime) {
            // Featured products are marked in metadata or have specific badges
            $products = $this->productRepository->findFeatured(
                $limit
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600); // 10 minutes cache
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== PRICE-BASED QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getProductsByPriceRange(
        float $minPrice,
        float $maxPrice,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_by_price_range', [
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $minPrice,
            $maxPrice,
            $limit,
            $offset,
            $publishedOnly,
            $startTime
        ) {
            $products = $this->productRepository->findByPriceRange(
                $minPrice,
                $maxPrice,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsBelowPrice(
        float $price,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_below_price', [
            'price' => $price,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($price, $limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findBelowPrice(
                $price,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsAbovePrice(
        float $price,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_above_price', [
            'price' => $price,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($price, $limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findAbovePrice(
                $price,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getCheapestProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('cheapest_products', [
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findCheapest(
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getMostExpensiveProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('most_expensive_products', [
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findMostExpensive(
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 300);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== MAINTENANCE QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getProductsNeedingPriceUpdate(
        int $daysThreshold = 7,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.maintenance.view');
        
        $cacheKey = $this->generateQueryCacheKey('products_needing_price_update', [
            'days_threshold' => $daysThreshold,
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($daysThreshold, $limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findNeedsPriceUpdate(
                $daysThreshold,
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsNeedingLinkValidation(
        int $daysThreshold = 14,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.maintenance.view');
        
        $cacheKey = $this->generateQueryCacheKey('products_needing_link_validation', [
            'days_threshold' => $daysThreshold,
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($daysThreshold, $limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findNeedsLinkValidation(
                $daysThreshold,
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsWithMissingImages(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.maintenance.view');
        
        $cacheKey = $this->generateQueryCacheKey('products_with_missing_images', [
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findWithMissingImages(
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsWithMissingDescriptions(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.maintenance.view');
        
        $cacheKey = $this->generateQueryCacheKey('products_with_missing_descriptions', [
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findWithMissingDescriptions(
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsWithOutdatedInfo(
        int $daysThreshold = 30,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.maintenance.view');
        
        $cacheKey = $this->generateQueryCacheKey('products_with_outdated_info', [
            'days_threshold' => $daysThreshold,
            'limit' => $limit,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($daysThreshold, $limit, $publishedOnly, $startTime) {
            $products = $this->productRepository->findWithOutdatedInfo(
                $daysThreshold,
                $limit,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== RELATION-BASED QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getProductsWithBadges(
        array $badgeIds,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_with_badges', [
            'badge_ids' => md5(serialize($badgeIds)),
            'operator' => $operator,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $badgeIds,
            $operator,
            $limit,
            $offset,
            $publishedOnly,
            $startTime
        ) {
            $products = $this->productRepository->findWithBadges(
                $badgeIds,
                $operator,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsWithMarketplaceBadges(
        array $marketplaceBadgeIds,
        string $operator = 'OR',
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('products_with_marketplace_badges', [
            'marketplace_badge_ids' => md5(serialize($marketplaceBadgeIds)),
            'operator' => $operator,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use (
            $marketplaceBadgeIds,
            $operator,
            $limit,
            $offset,
            $publishedOnly,
            $startTime
        ) {
            // This would require marketplace badge repository
            // For now, return empty array or mock
            $products = []; // $this->productRepository->findWithMarketplaceBadges(...)
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 600);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsVerifiedBy(
        int $adminId,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $this->authorize('product.list');
        
        $cacheKey = $this->generateQueryCacheKey('products_verified_by', [
            'admin_id' => $adminId,
            'limit' => $limit,
            'offset' => $offset,
            'published_only' => $publishedOnly
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($adminId, $limit, $offset, $publishedOnly, $startTime) {
            $products = $this->productRepository->findVerifiedBy(
                $adminId,
                $limit,
                $offset,
                $publishedOnly
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
                $products
            );
        }, 60);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== RECOMMENDATION QUERIES ====================
    
    /**
     * {@inheritDoc}
     */
    public function getProductRecommendations(
        int $currentProductId,
        int $limit = 4,
        array $criteria = ['category', 'popular']
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('product_recommendations', [
            'current_id' => $currentProductId,
            'limit' => $limit,
            'criteria' => $criteria
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($currentProductId, $limit, $criteria, $startTime) {
            $products = $this->productRepository->getRecommendations(
                $currentProductId,
                $limit,
                $criteria
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 1800); // 30 minutes cache for recommendations
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getSimilarProducts(
        int $productId,
        int $limit = 4,
        array $similarityFactors = ['category', 'price_range']
    ): array {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('similar_products', [
            'product_id' => $productId,
            'limit' => $limit,
            'similarity_factors' => $similarityFactors
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($productId, $limit, $similarityFactors, $startTime) {
            $products = $this->productRepository->findSimilar(
                $productId,
                $limit,
                $similarityFactors
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 1800);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getFrequentlyBoughtTogether(int $productId, int $limit = 3): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('frequently_bought_together', [
            'product_id' => $productId,
            'limit' => $limit
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($productId, $limit, $startTime) {
            // This would require order/transaction data
            // For now, return similar products as fallback
            $products = $this->productRepository->findSimilar(
                $productId,
                $limit,
                ['category', 'price_range']
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 1800);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getCrossSellProducts(int $productId, int $limit = 4): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('cross_sell_products', [
            'product_id' => $productId,
            'limit' => $limit
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($productId, $limit, $startTime) {
            // Cross-sell: products from complementary categories
            $product = $this->productRepository->findById($productId);
            if (!$product) {
                return [];
            }
            
            $products = $this->productRepository->findCrossSell(
                $productId,
                $product->getCategoryId(),
                $limit
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 1800);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getUpsellProducts(int $productId, int $limit = 4): array
    {
        $this->queryStats['queries_executed']++;
        $startTime = microtime(true);
        
        $cacheKey = $this->generateQueryCacheKey('upsell_products', [
            'product_id' => $productId,
            'limit' => $limit
        ]);
        
        $result = $this->withCaching($cacheKey, function() use ($productId, $limit, $startTime) {
            // Upsell: higher-priced products in same category
            $product = $this->productRepository->findById($productId);
            if (!$product) {
                return [];
            }
            
            $price = $product->getMarketPrice();
            $categoryId = $product->getCategoryId();
            
            $products = $this->productRepository->findUpsell(
                $productId,
                $categoryId,
                $price,
                $limit
            );
            
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->totalExecutionTime += $executionTime;
            
            return array_map(
                fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => false]),
                $products
            );
        }, 1800);
        
        $this->queryStats['total_results'] += count($result);
        return $result;
    }
    
    // ==================== AGGREGATION & STATISTICS ====================
    
    /**
     * {@inheritDoc}
     */
    public function countProducts(array $criteria = [], bool $includeArchived = false): int
    {
        $this->queryStats['queries_executed']++;
        
        $cacheKey = $this->generateQueryCacheKey('count_products', [
            'criteria' => md5(serialize($criteria)),
            'include_archived' => $includeArchived
        ]);
        
        return $this->withCaching($cacheKey, function() use ($criteria, $includeArchived) {
            return $this->productRepository->count($criteria, $includeArchived);
        }, 300);
    }
    
    /**
     * {@inheritDoc}
     */
    public function countProductsByStatus(?ProductStatus $status = null, bool $includeArchived = false)
    {
        $this->queryStats['queries_executed']++;
        
        if ($status === null) {
            $cacheKey = $this->generateQueryCacheKey('count_products_by_status_all', [
                'include_archived' => $includeArchived
            ]);
            
            return $this->withCaching($cacheKey, function() use ($includeArchived) {
                $counts = [];
                foreach (ProductStatus::cases() as $productStatus) {
                    $counts[$productStatus->value] = $this->productRepository->countByStatus($productStatus, $includeArchived);
                }
                return $counts;
            }, 300);
        }
        
        $cacheKey = $this->generateQueryCacheKey('count_products_by_status', [
            'status' => $status->value,
            'include_archived' => $includeArchived
        ]);
        
        return $this->withCaching($cacheKey, function() use ($status, $includeArchived) {
            return $this->productRepository->countByStatus($status, $includeArchived);
        }, 300);
    }
    
    /**
     * {@inheritDoc}
     */
    public function countProductsByCategory(?int $categoryId = null, bool $publishedOnly = false)
    {
        $this->queryStats['queries_executed']++;
        
        if ($categoryId === null) {
            $cacheKey = $this->generateQueryCacheKey('count_products_by_category_all', [
                'published_only' => $publishedOnly
            ]);
            
            return $this->withCaching($cacheKey, function() use ($publishedOnly) {
                return $this->productRepository->countByCategoryAll($publishedOnly);
            }, 600);
        }
        
        $cacheKey = $this->generateQueryCacheKey('count_products_by_category', [
            'category_id' => $categoryId,
            'published_only' => $publishedOnly
        ]);
        
        return $this->withCaching($cacheKey, function() use ($categoryId, $publishedOnly) {
            return $this->productRepository->countByCategory($categoryId, $publishedOnly);
        }, 600);
    }
    
    /**
     * {@inheritDoc}
     */
    public function getPriceStatistics(array $criteria = []): array
    {
        $this->queryStats['queries_executed']++;
        
        $cacheKey = $this->generateQueryCacheKey('price_statistics', [
            'criteria' => md5(serialize($criteria))
        ]);
        
        return $this->withCaching($cacheKey, function() use ($criteria) {
            return $this->productRepository->getPriceStatistics($criteria);
        }, 600);
    }
    
    /**
     * {@inheritDoc}
     */
    public function getViewStatistics(array $criteria = []): array
    {
        $this->queryStats['queries_executed']++;
        
        $cacheKey = $this->generateQueryCacheKey('view_statistics', [
            'criteria' => md5(serialize($criteria))
        ]);
        
        return $this->withCaching($cacheKey, function() use ($criteria) {
            return $this->productRepository->getViewStatistics($criteria);
        }, 600);
    }
    
    // ==================== CACHE MANAGEMENT ====================
    
    /**
     * {@inheritDoc}
     */
    public function clearQueryCaches(array $patterns = []): int
    {
        $totalCleared = 0;
        
        if (empty($patterns)) {
            // Clear all query caches for this service
            $patterns = [$this->getServiceName() . ':*'];
        }
        
        foreach ($patterns as $pattern) {
            $cleared = $this->cache->deleteMatching($pattern);
            $totalCleared += $cleared;
        }
        
        // Also clear repository query caches
        $totalCleared += $this->productRepository->clearQueryCache();
        
        return $totalCleared;
    }
    
    /**
     * {@inheritDoc}
     */
    public function warmQueryCaches(array $queryConfigs = []): array
    {
        $results = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0
        ];
        
        // Default queries to warm if none provided
        if (empty($queryConfigs)) {
            $queryConfigs = [
                ['method' => 'getPopularProducts', 'params' => [10, true]],
                ['method' => 'getPublishedProducts', 'params' => [20, 0, ['published_at' => 'DESC']]],
                ['method' => 'getRecentlyAddedProducts', 'params' => [10, true]],
            ];
        }
        
        foreach ($queryConfigs as $config) {
            try {
                $method = $config['method'];
                $params = $config['params'] ?? [];
                
                if (method_exists($this, $method)) {
                    call_user_func_array([$this, $method], $params);
                    $results['successful']++;
                } else {
                    $results['failed']++;
                    log_message('warning', "Query warming failed: Method {$method} not found");
                }
                
                $results['total']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                log_message('error', "Query warming failed for config: " . json_encode($config) . " - " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getQueryCacheStatistics(): array
    {
        $totalQueries = $this->queryStats['queries_executed'];
        $cacheHits = $this->queryStats['cache_hits'];
        $cacheMisses = $this->queryStats['cache_misses'];
        $cacheHitRate = $totalQueries > 0 ? ($cacheHits / $totalQueries) * 100 : 0;
        
        $avgExecutionTime = $totalQueries > 0 ? $this->totalExecutionTime / $totalQueries : 0;
        
        return [
            'total_queries' => $totalQueries,
            'cached_queries' => $cacheHits,
            'cache_hit_rate' => round($cacheHitRate, 2),
            'memory_usage' => $this->getCacheMemoryUsage(),
            'most_frequent_queries' => $this->getMostFrequentQueries(),
            'average_execution_time_ms' => round($avgExecutionTime, 2),
            'total_execution_time_ms' => round($this->totalExecutionTime, 2)
        ];
    }
    
    // ==================== UTILITY METHODS ====================
    
    /**
     * {@inheritDoc}
     */
    public function generateQueryCacheKey(string $operation, array $parameters = []): string
    {
        $paramHash = md5(serialize($parameters));
        return sprintf('%s:query:%s:%s', $this->getServiceName(), $operation, $paramHash);
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateQueryParameters(array $parameters, string $context = 'search'): array
    {
        $errors = [];
        $warnings = [];
        
        // Common validation rules
        if (isset($parameters['limit']) && ($parameters['limit'] < 1 || $parameters['limit'] > 1000)) {
            $errors[] = 'Limit must be between 1 and 1000';
        }
        
        if (isset($parameters['offset']) && $parameters['offset'] < 0) {
            $errors[] = 'Offset cannot be negative';
        }
        
        if (isset($parameters['min_price']) && $parameters['min_price'] < 0) {
            $errors[] = 'Minimum price cannot be negative';
        }
        
        if (isset($parameters['max_price']) && $parameters['max_price'] < 0) {
            $errors[] = 'Maximum price cannot be negative';
        }
        
        if (isset($parameters['min_price']) && isset($parameters['max_price']) 
            && $parameters['min_price'] > $parameters['max_price']) {
            $errors[] = 'Minimum price cannot be greater than maximum price';
        }
        
        // Context-specific validations
        switch ($context) {
            case 'search':
                if (isset($parameters['keyword']) && strlen($parameters['keyword']) < 2) {
                    $warnings[] = 'Search keyword should be at least 2 characters';
                }
                break;
                
            case 'filter':
                if (isset($parameters['status']) && !in_array($parameters['status'], array_map(fn($s) => $s->value, ProductStatus::cases()))) {
                    $errors[] = 'Invalid status value';
                }
                break;
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function buildFilterSummary(ProductQuery $query): array
    {
        return $query->toFilterSummary();
    }
    
    /**
     * {@inheritDoc}
     */
    public function getQueryExecutionPlan(ProductQuery $query, bool $explain = false): array
    {
        // This would return the SQL execution plan for debugging
        // For now, return basic information
        $filters = $query->toRepositoryFilters();
        
        $plan = [
            'query_type' => 'SELECT',
            'filters_applied' => array_keys($filters),
            'sort_order' => $query->getOrderBy() ?? ['created_at' => 'DESC'],
            'pagination' => [
                'page' => $query->getPage() ?? 1,
                'per_page' => $query->getPerPage() ?? 20
            ],
            'estimated_rows' => $this->estimateQueryRows($filters)
        ];
        
        if ($explain) {
            // This would execute EXPLAIN SQL and return the result
            $plan['explain_output'] = $this->productRepository->explainQuery($filters);
        }
        
        return $plan;
    }
    
    // ==================== BATCH QUERY OPERATIONS ====================
    
    /**
     * {@inheritDoc}
     */
    public function batchQuery(array $queries, bool $parallel = false): array
    {
        $results = [];
        
        if ($parallel) {
            // Execute queries in parallel (if supported by environment)
            // For now, execute sequentially
            foreach ($queries as $key => $query) {
                $results[$key] = $this->listProducts($query, false);
            }
        } else {
            foreach ($queries as $key => $query) {
                $results[$key] = $this->listProducts($query, false);
            }
        }
        
        return $results;
    }
    
    /**
     * {@inheritDoc}
     */
    public function streamQueryResults(ProductQuery $query, callable $callback, int $batchSize = 100): int
    {
        $totalProcessed = 0;
        $page = 1;
        
        while (true) {
            $query->setPage($page)->setPerPage($batchSize);
            $result = $this->listProducts($query, false);
            
            $products = $result['data'];
            if (empty($products)) {
                break;
            }
            
            // Process batch
            foreach ($products as $product) {
                $callback($product);
            }
            
            $totalProcessed += count($products);
            
            // Check if we've reached the end
            if (count($products) < $batchSize) {
                break;
            }
            
            $page++;
        }
        
        return $totalProcessed;
    }
    
    /**
     * {@inheritDoc}
     */
    public function getQueryPerformanceMetrics(ProductQuery $query): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        // Execute query to get metrics
        $result = $this->listProducts($query, false);
        
        $executionTime = (microtime(true) - $startTime) * 1000;
        $memoryUsed = memory_get_usage(true) - $startMemory;
        
        // Analyze query complexity
        $filters = $query->toRepositoryFilters();
        $complexity = $this->analyzeQueryComplexity($filters);
        
        // Check cache hit
        $cacheKey = $this->generateQueryCacheKey('list_products', [
            'query' => $query->getCacheKey(),
            'admin_mode' => false
        ]);
        
        $cacheHit = $this->cache->get($cacheKey) !== null;
        
        return [
            'execution_time_ms' => round($executionTime, 2),
            'memory_usage_mb' => round($memoryUsed / 1024 / 1024, 2),
            'result_count' => count($result['data']),
            'cache_hit' => $cacheHit,
            'query_complexity' => $complexity,
            'recommendations' => $this->generateQueryRecommendations($complexity, $executionTime, count($result['data']))
        ];
    }
    
    // ==================== PRIVATE HELPER METHODS ====================
    
    private function estimateQueryRows(array $filters): int
    {
        // Simple estimation based on filter count and types
        $baseEstimate = 1000;
        
        if (isset($filters['status'])) {
            $baseEstimate *= 0.3;
        }
        
        if (isset($filters['category_id'])) {
            $baseEstimate *= 0.5;
        }
        
        if (isset($filters['marketplace_id'])) {
            $baseEstimate *= 0.7;
        }
        
        return max(10, min(10000, (int)$baseEstimate));
    }
    
    private function analyzeQueryComplexity(array $filters): string
    {
        $complexityScore = 0;
        
        // Add points for each filter type
        if (isset($filters['keyword'])) $complexityScore += 1;
        if (isset($filters['status'])) $complexityScore += 1;
        if (isset($filters['category_id'])) $complexityScore += 1;
        if (isset($filters['marketplace_id'])) $complexityScore += 2;
        if (isset($filters['min_price']) || isset($filters['max_price'])) $complexityScore += 1;
        if (isset($filters['include_subcategories'])) $complexityScore += 2;
        
        if ($complexityScore <= 2) return 'simple';
        if ($complexityScore <= 4) return 'moderate';
        return 'complex';
    }
    
    private function generateQueryRecommendations(string $complexity, float $executionTime, int $resultCount): array
    {
        $recommendations = [];
        
        if ($executionTime > 1000) {
            $recommendations[] = 'Query execution time is high. Consider adding indexes or optimizing filters.';
        }
        
        if ($complexity === 'complex' && $resultCount > 1000) {
            $recommendations[] = 'Complex query with large result set. Consider implementing pagination or limiting results.';
        }
        
        if ($resultCount === 0 && $executionTime > 100) {
            $recommendations[] = 'Query returned no results but took significant time. Review filter criteria.';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Query performance is acceptable.';
        }
        
        return $recommendations;
    }
    
    private function getCacheMemoryUsage(): string
    {
        // This would query cache system for memory usage
        // For now, return placeholder
        return 'N/A';
    }
    
    private function getMostFrequentQueries(): array
    {
        // This would track and return most frequent queries
        // For now, return placeholder
        return [
            ['query' => 'getPopularProducts', 'count' => $this->queryStats['queries_executed']],
            ['query' => 'getPublishedProducts', 'count' => $this->queryStats['queries_executed'] / 2],
            ['query' => 'searchProducts', 'count' => $this->queryStats['queries_executed'] / 3]
        ];
    }
    
    /**
     * Override withCaching to track cache hits/misses
     */
    protected function withCaching(string $cacheKey, Closure $callback, ?int $ttl = null): mixed
    {
        $fullKey = $this->getServiceCacheKey($cacheKey);
        
        // Try cache first
        $cached = $this->cache->get($fullKey);
        if ($cached !== null) {
            $this->queryStats['cache_hits']++;
            return $cached;
        }
        
        $this->queryStats['cache_misses']++;
        
        // Execute and cache
        $result = $callback();
        $this->cache->set($fullKey, $result, $ttl ?? 3600);
        
        return $result;
    }
    
    /**
     * Get query service statistics
     */
    public function getQueryStats(): array
    {
        $this->queryStats['average_execution_time_ms'] = $this->queryStats['queries_executed'] > 0 
            ? round($this->totalExecutionTime / $this->queryStats['queries_executed'], 2)
            : 0;
            
        return array_merge($this->queryStats, [
            'service_name' => $this->getServiceName(),
            'initialized_at' => $this->getInitializedAt(),
            'total_execution_time_ms' => round($this->totalExecutionTime, 2)
        ]);
    }
}