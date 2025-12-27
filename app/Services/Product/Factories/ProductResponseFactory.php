<?php

namespace App\Services\Product\Factories;

use App\DTOs\Responses\ProductResponse;
use App\DTOs\Responses\ProductDetailResponse;
use App\DTOs\Responses\BulkActionResult;
use App\DTOs\Responses\PaginatedResponse;
use App\DTOs\Responses\ErrorResponse;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Enums\ProductBulkActionType;
use CodeIgniter\I18n\Time;
use App\Services\UrlGeneratorService;

/**
 * ProductResponseFactory - DTO Transformation Factory for Product Domain
 * 
 * Layer: Service Factory Component (DTO Transformation)
 * Responsibility: Transforms entities to presentation-ready DTOs with proper formatting
 * 
 * @package App\Services\Product\Factories
 */
class ProductResponseFactory
{
    /**
     * @var UrlGeneratorService
     */
    private UrlGeneratorService $urlGenerator;

    /**
     * @var array Configuration for response formatting
     */
    private array $config = [
        'date_format' => 'Y-m-d H:i:s',
        'price_format' => [
            'decimals' => 2,
            'decimal_separator' => ',',
            'thousands_separator' => '.',
            'currency_symbol' => 'Rp',
            'currency_position' => 'before', // 'before' or 'after'
        ],
        'image_urls' => [
            'base_path' => '/uploads/products/',
            'placeholder' => '/images/product-placeholder.jpg',
            'sizes' => ['thumb' => 150, 'medium' => 400, 'large' => 800],
        ],
        'security' => [
            'admin_fields' => ['cost_price', 'profit_margin', 'internal_notes', 'deleted_at'],
            'public_fields' => ['id', 'name', 'slug', 'description', 'market_price', 'image', 'category_id', 'status'],
        ],
    ];

    /**
     * Constructor with Dependency Injection
     * 
     * @param UrlGeneratorService $urlGenerator
     * @param array|null $config Custom configuration
     */
    public function __construct(UrlGeneratorService $urlGenerator, ?array $config = null)
    {
        $this->urlGenerator = $urlGenerator;
        
        if ($config !== null) {
            $this->config = array_replace_recursive($this->config, $config);
        }
    }

    // ==================== BASIC TRANSFORMATION METHODS ====================

    /**
     * Create ProductResponse from Product entity
     * 
     * @param Product $product
     * @param array $context {
     *     @var bool $adminMode Include admin-only fields
     *     @var bool $includeTrashed Include deleted products
     *     @var array $additionalFields Additional fields to include
     * }
     * @return ProductResponse
     */
    public function fromEntity(Product $product, array $context = []): ProductResponse
    {
        $adminMode = $context['adminMode'] ?? false;
        $includeTrashed = $context['includeTrashed'] ?? false;
        
        // Skip deleted products unless explicitly requested
        if (!$includeTrashed && $product->isDeleted()) {
            throw new \RuntimeException("Cannot create response for deleted product");
        }
        
        // Prepare base data
        $data = $this->extractBaseData($product, $adminMode);
        
        // Add calculated fields
        $data = $this->addCalculatedFields($data, $product, $context);
        
        // Add URLs
        $data = $this->addUrls($data, $product, $context);
        
        // Filter fields based on context
        $data = $this->filterFieldsByContext($data, $adminMode);
        
        // Create response DTO
        return ProductResponse::fromArray($data);
    }

    /**
     * Create ProductResponse from array data
     * 
     * @param array $data
     * @param array $context
     * @return ProductResponse
     */
    public function fromArray(array $data, array $context = []): ProductResponse
    {
        // Validate required fields
        $this->validateResponseData($data);
        
        // Format data
        $data = $this->formatResponseData($data, $context);
        
        // Add metadata if requested
        if ($context['addMetadata'] ?? false) {
            $data = $this->addResponseMetadata($data, $context);
        }
        
        return ProductResponse::fromArray($data);
    }

    /**
     * Create collection of ProductResponse from array of entities
     * 
     * @param array<Product> $products
     * @param array $context
     * @return array<ProductResponse>
     */
    public function toCollection(array $products, array $context = []): array
    {
        $collection = [];
        
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }
            
            try {
                $collection[] = $this->fromEntity($product, $context);
            } catch (\Throwable $e) {
                // Skip invalid products but log error
                // In production, you might want to handle this differently
                continue;
            }
        }
        
        return $collection;
    }

    // ==================== DETAIL RESPONSE METHODS ====================

    /**
     * Create ProductDetailResponse with relations
     * 
     * @param Product $product
     * @param array $relations {
     *     @var \App\Entities\Category|null $category
     *     @var array<\App\Entities\Link> $links
     *     @var array<\App\Entities\Badge> $badges
     *     @var array<\App\Entities\Review> $reviews
     * }
     * @param array $context
     * @return ProductDetailResponse
     */
    public function fromEntityWithRelations(Product $product, array $relations = [], array $context = []): ProductDetailResponse
    {
        // Create base response
        $baseResponse = $this->fromEntity($product, $context);
        $baseData = $baseResponse->toArray();
        
        // Add relations
        $baseData['relations'] = $this->processRelations($relations, $context);
        
        // Add additional detail fields
        $baseData = $this->addDetailFields($baseData, $product, $context);
        
        // Add analytics data if admin mode
        if ($context['adminMode'] ?? false) {
            $baseData = $this->addAnalyticsData($baseData, $product, $context);
        }
        
        return ProductDetailResponse::fromArray($baseData);
    }

    /**
     * Create full detail response with all available data
     * 
     * @param Product $product
     * @param array $allRelations
     * @param array $context
     * @return ProductDetailResponse
     */
    public function withFullDetails(Product $product, array $allRelations = [], array $context = []): ProductDetailResponse
    {
        $context['includeAll'] = true;
        
        // Get base response with relations
        $response = $this->fromEntityWithRelations($product, $allRelations, $context);
        $data = $response->toArray();
        
        // Add additional computed data
        $data['computed'] = $this->computeProductMetrics($product, $allRelations);
        
        // Add audit trail if admin
        if ($context['adminMode'] ?? false) {
            $data['audit_trail'] = $this->getAuditTrail($product->getId());
        }
        
        // Add recommendations
        $data['recommendations'] = $this->generateRecommendations($product, $allRelations);
        
        return ProductDetailResponse::fromArray($data);
    }

    // ==================== LIST & PAGINATION RESPONSE METHODS ====================

    /**
     * Create list response with metadata
     * 
     * @param array<ProductResponse> $items
     * @param array $metadata {
     *     @var int $total
     *     @var int $per_page
     *     @var int $current_page
     *     @var array $filters
     *     @var array $sorting
     * }
     * @return array
     */
    public function createListResponse(array $items, array $metadata = []): array
    {
        $response = [
            'data' => $items,
            'meta' => $this->buildListMetadata($metadata),
            'links' => $this->buildPaginationLinks($metadata),
        ];
        
        // Add timing information
        $response['meta']['generated_at'] = Time::now()->format($this->config['date_format']);
        $response['meta']['count'] = count($items);
        
        return $response;
    }

    /**
     * Create paginated response
     * 
     * @param array<Product> $products
     * @param array $pagination {
     *     @var int $total
     *     @var int $per_page
     *     @var int $current_page
     *     @var int $last_page
     *     @var int $from
     *     @var int $to
     * }
     * @param array $context
     * @return PaginatedResponse
     */
    public function createPaginatedResponse(array $products, array $pagination, array $context = []): PaginatedResponse
    {
        // Transform products to responses
        $items = $this->toCollection($products, $context);
        
        // Build pagination data
        $paginationData = [
            'total' => $pagination['total'] ?? 0,
            'per_page' => $pagination['per_page'] ?? 20,
            'current_page' => $pagination['current_page'] ?? 1,
            'last_page' => $pagination['last_page'] ?? 1,
            'from' => $pagination['from'] ?? 0,
            'to' => $pagination['to'] ?? 0,
        ];
        
        // Build links
        $links = $this->buildPaginationLinks($paginationData);
        
        // Build metadata
        $metadata = array_merge($paginationData, [
            'filters' => $context['filters'] ?? [],
            'sorting' => $context['sorting'] ?? ['created_at' => 'desc'],
            'generated_at' => Time::now()->format($this->config['date_format']),
        ]);
        
        return new PaginatedResponse(
            $items,
            $metadata,
            $links
        );
    }

    /**
     * Create search results response
     * 
     * @param array<Product> $products
     * @param string $query
     * @param array $filters
     * @param array $context
     * @return array
     */
    public function createSearchResponse(array $products, string $query, array $filters = [], array $context = []): array
    {
        $items = $this->toCollection($products, $context);
        
        return [
            'data' => $items,
            'meta' => [
                'query' => $query,
                'filters' => $filters,
                'total_results' => count($items),
                'search_time' => $context['search_time'] ?? 0,
                'suggestions' => $this->generateSearchSuggestions($query, $items),
            ],
            'links' => [
                'self' => $this->urlGenerator->generate('product.search', ['q' => $query]),
            ],
        ];
    }

    // ==================== BULK OPERATION RESPONSE METHODS ====================

    /**
     * Create BulkActionResult response
     * 
     * @param ProductBulkActionType $action
     * @param int $successCount
     * @param int $failedCount
     * @param int $skippedCount
     * @param float $duration
     * @param array $details {
     *     @var array $success Successful item IDs
     *     @var array $failed Failed items with errors
     *     @var array $skipped Skipped item IDs
     * }
     * @return BulkActionResult
     */
    public function createBulkActionResult(
        ProductBulkActionType $action,
        int $successCount,
        int $failedCount,
        int $skippedCount,
        float $duration,
        array $details = []
    ): BulkActionResult {
        return new BulkActionResult(
            $action,
            $successCount,
            $failedCount,
            $skippedCount,
            $duration,
            $details
        );
    }

    /**
     * Create batch operation result
     * 
     * @param string $operation
     * @param array $results {
     *     @var array $processed
     *     @var array $succeeded
     *     @var array $failed
     * }
     * @param array $context
     * @return array
     */
    public function createBatchResult(string $operation, array $results, array $context = []): array
    {
        $total = count($results['processed'] ?? []);
        $succeeded = count($results['succeeded'] ?? []);
        $failed = count($results['failed'] ?? []);
        
        return [
            'operation' => $operation,
            'summary' => [
                'total' => $total,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'success_rate' => $total > 0 ? round(($succeeded / $total) * 100, 2) : 0,
            ],
            'details' => [
                'succeeded' => $results['succeeded'] ?? [],
                'failed' => $results['failed'] ?? [],
            ],
            'duration' => $context['duration'] ?? 0,
            'timestamp' => Time::now()->format($this->config['date_format']),
            'recommendations' => $this->generateBatchRecommendations($operation, $results),
        ];
    }

    // ==================== ERROR RESPONSE METHODS ====================

    /**
     * Create standardized error response
     * 
     * @param string $code
     * @param string $message
     * @param array $details
     * @param int $statusCode
     * @return ErrorResponse
     */
    public function createErrorResponse(
        string $code,
        string $message,
        array $details = [],
        int $statusCode = 400
    ): ErrorResponse {
        return new ErrorResponse(
            $code,
            $message,
            $details,
            $statusCode
        );
    }

    /**
     * Create validation error response
     * 
     * @param array $errors Field errors
     * @param string $message
     * @return ErrorResponse
     */
    public function createValidationError(array $errors, string $message = 'Validation failed'): ErrorResponse
    {
        return $this->createErrorResponse(
            'VALIDATION_ERROR',
            $message,
            ['errors' => $errors],
            422
        );
    }

    /**
     * Create not found error response
     * 
     * @param string $resource
     * @param mixed $id
     * @return ErrorResponse
     */
    public function createNotFoundError(string $resource, $id = null): ErrorResponse
    {
        $message = $id !== null 
            ? "{$resource} with ID {$id} not found"
            : "{$resource} not found";
            
        return $this->createErrorResponse(
            'NOT_FOUND',
            $message,
            ['resource' => $resource, 'id' => $id],
            404
        );
    }

    /**
     * Create unauthorized error response
     * 
     * @param string $permission
     * @return ErrorResponse
     */
    public function createUnauthorizedError(string $permission = ''): ErrorResponse
    {
        $message = !empty($permission) 
            ? "Unauthorized: Missing permission '{$permission}'"
            : 'Unauthorized access';
            
        return $this->createErrorResponse(
            'UNAUTHORIZED',
            $message,
            ['permission' => $permission],
            403
        );
    }

    // ==================== HELPER METHODS ====================

    /**
     * Extract base data from product entity
     * 
     * @param Product $product
     * @param bool $adminMode
     * @return array
     */
    private function extractBaseData(Product $product, bool $adminMode): array
    {
        $data = [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'description' => $product->getDescription(),
            'market_price' => $product->getMarketPrice(),
            'category_id' => $product->getCategoryId(),
            'status' => $product->getStatus()->value,
            'status_label' => $product->getStatus()->label(),
            'image' => $product->getImage(),
            'image_source_type' => $product->getImageSourceType()?->value,
            'view_count' => $product->getViewCount(),
            'created_at' => $product->getCreatedAt()?->format($this->config['date_format']),
            'updated_at' => $product->getUpdatedAt()?->format($this->config['date_format']),
        ];
        
        // Add admin-only fields
        if ($adminMode) {
            $data = array_merge($data, [
                'cost_price' => $product->getCostPrice(),
                'profit_margin' => $product->getProfitMargin(),
                'internal_notes' => $product->getInternalNotes(),
                'verified_by' => $product->getVerifiedBy(),
                'verified_at' => $product->getVerifiedAt()?->format($this->config['date_format']),
                'published_at' => $product->getPublishedAt()?->format($this->config['date_format']),
                'deleted_at' => $product->getDeletedAt()?->format($this->config['date_format']),
            ]);
        }
        
        return $data;
    }

    /**
     * Add calculated fields to response
     * 
     * @param array $data
     * @param Product $product
     * @param array $context
     * @return array
     */
    private function addCalculatedFields(array $data, Product $product, array $context): array
    {
        // Format price
        $data['market_price_formatted'] = $this->formatPrice($product->getMarketPrice());
        
        // Calculate discount if sale price exists
        if ($product->getSalePrice() !== null) {
            $data['sale_price'] = $product->getSalePrice();
            $data['sale_price_formatted'] = $this->formatPrice($product->getSalePrice());
            $data['discount_percentage'] = $this->calculateDiscountPercentage(
                $product->getMarketPrice(),
                $product->getSalePrice()
            );
        }
        
        // Add computed fields
        $data['is_published'] = $product->isPublished();
        $data['is_verified'] = $product->isVerified();
        $data['is_archived'] = $product->isArchived();
        $data['is_deleted'] = $product->isDeleted();
        
        // Add age in days
        if ($product->getCreatedAt() !== null) {
            $data['age_days'] = $product->getCreatedAt()->diff(new \DateTime())->days;
        }
        
        return $data;
    }

    /**
     * Add URLs to response
     * 
     * @param array $data
     * @param Product $product
     * @param array $context
     * @return array
     */
    private function addUrls(array $data, Product $product, array $context): array
    {
        $data['urls'] = [
            'self' => $this->urlGenerator->generate('product.show', ['slug' => $product->getSlug()]),
            'api' => $this->urlGenerator->generate('api.product.show', ['id' => $product->getId()]),
            'admin' => $context['adminMode'] ?? false 
                ? $this->urlGenerator->generate('admin.product.edit', ['id' => $product->getId()])
                : null,
        ];
        
        // Add image URLs
        if ($product->getImage()) {
            $data['image_urls'] = $this->generateImageUrls($product->getImage());
        }
        
        // Add edit URLs if admin
        if ($context['adminMode'] ?? false) {
            $data['urls']['edit'] = $this->urlGenerator->generate('admin.product.edit', ['id' => $product->getId()]);
            $data['urls']['delete'] = $this->urlGenerator->generate('admin.product.delete', ['id' => $product->getId()]);
        }
        
        return $data;
    }

    /**
     * Filter fields based on context
     * 
     * @param array $data
     * @param bool $adminMode
     * @return array
     */
    private function filterFieldsByContext(array $data, bool $adminMode): array
    {
        if ($adminMode) {
            return $data; // Return all fields for admin
        }
        
        // Filter out admin-only fields for public
        foreach ($this->config['security']['admin_fields'] as $field) {
            unset($data[$field]);
        }
        
        return $data;
    }

    /**
     * Process relations for detail response
     * 
     * @param array $relations
     * @param array $context
     * @return array
     */
    private function processRelations(array $relations, array $context): array
    {
        $processed = [];
        
        foreach ($relations as $relationName => $relationData) {
            if (empty($relationData)) {
                $processed[$relationName] = null;
                continue;
            }
            
            if (is_array($relationData)) {
                $processed[$relationName] = array_map(
                    function ($item) use ($context) {
                        return $this->transformRelationItem($item, $context);
                    },
                    $relationData
                );
            } else {
                $processed[$relationName] = $this->transformRelationItem($relationData, $context);
            }
        }
        
        return $processed;
    }

    /**
     * Transform relation item to array
     * 
     * @param mixed $item
     * @param array $context
     * @return array
     */
    private function transformRelationItem($item, array $context): array
    {
        if (is_array($item)) {
            return $item;
        }
        
        if (method_exists($item, 'toArray')) {
            return $item->toArray();
        }
        
        if ($item instanceof \App\Entities\BaseEntity) {
            $data = $item->toArray();
            
            // Add URLs for relations
            if (method_exists($item, 'getSlug') || method_exists($item, 'getId')) {
                $data['url'] = $this->generateRelationUrl($item);
            }
            
            return $data;
        }
        
        return (array) $item;
    }

    /**
     * Format price according to configuration
     * 
     * @param float|null $price
     * @return string
     */
    private function formatPrice(?float $price): string
    {
        if ($price === null) {
            return '';
        }
        
        $formatted = number_format(
            $price,
            $this->config['price_format']['decimals'],
            $this->config['price_format']['decimal_separator'],
            $this->config['price_format']['thousands_separator']
        );
        
        if ($this->config['price_format']['currency_position'] === 'before') {
            return $this->config['price_format']['currency_symbol'] . ' ' . $formatted;
        }
        
        return $formatted . ' ' . $this->config['price_format']['currency_symbol'];
    }

    /**
     * Generate image URLs for different sizes
     * 
     * @param string $imagePath
     * @return array
     */
    private function generateImageUrls(string $imagePath): array
    {
        $baseUrl = rtrim($this->config['image_urls']['base_path'], '/') . '/';
        
        $urls = [
            'original' => $baseUrl . $imagePath,
            'placeholder' => $this->config['image_urls']['placeholder'],
        ];
        
        // Generate size-specific URLs
        foreach ($this->config['image_urls']['sizes'] as $size => $dimension) {
            $urls[$size] = $this->generateResizedImageUrl($imagePath, $size);
        }
        
        return $urls;
    }

    /**
     * Generate resized image URL (simplified - in production would use image service)
     * 
     * @param string $imagePath
     * @param string $size
     * @return string
     */
    private function generateResizedImageUrl(string $imagePath, string $size): string
    {
        $baseUrl = rtrim($this->config['image_urls']['base_path'], '/') . '/';
        
        // Extract filename and extension
        $pathInfo = pathinfo($imagePath);
        $filename = $pathInfo['filename'];
        $extension = $pathInfo['extension'] ?? '';
        
        // Generate resized filename
        $resizedFilename = "{$filename}_{$size}.{$extension}";
        
        return $baseUrl . 'resized/' . $resizedFilename;
    }

    /**
     * Generate URL for relation item
     * 
     * @param object $item
     * @return string|null
     */
    private function generateRelationUrl(object $item): ?string
    {
        if ($item instanceof \App\Entities\Category) {
            return $this->urlGenerator->generate('category.show', ['slug' => $item->getSlug()]);
        }
        
        if ($item instanceof \App\Entities\Marketplace) {
            return $this->urlGenerator->generate('marketplace.show', ['id' => $item->getId()]);
        }
        
        return null;
    }

    /**
     * Calculate discount percentage
     * 
     * @param float $originalPrice
     * @param float $salePrice
     * @return float
     */
    private function calculateDiscountPercentage(float $originalPrice, float $salePrice): float
    {
        if ($originalPrice <= 0) {
            return 0;
        }
        
        $discount = (($originalPrice - $salePrice) / $originalPrice) * 100;
        return round(max(0, min(100, $discount)), 2);
    }

    /**
     * Add detail-specific fields
     * 
     * @param array $data
     * @param Product $product
     * @param array $context
     * @return array
     */
    private function addDetailFields(array $data, Product $product, array $context): array
    {
        // Add SEO fields
        $data['seo'] = [
            'title' => $product->getName(),
            'description' => substr($product->getDescription() ?? '', 0, 160),
            'keywords' => $this->generateKeywords($product),
        ];
        
        // Add social sharing data
        $data['social'] = [
            'share_url' => $this->urlGenerator->generate('product.show', ['slug' => $product->getSlug()], true),
            'share_title' => $product->getName(),
            'share_description' => substr($product->getDescription() ?? '', 0, 200),
            'share_image' => $product->getImage() 
                ? $this->generateImageUrls($product->getImage())['medium']
                : $this->config['image_urls']['placeholder'],
        ];
        
        return $data;
    }

    /**
     * Add analytics data for admin
     * 
     * @param array $data
     * @param Product $product
     * @param array $context
     * @return array
     */
    private function addAnalyticsData(array $data, Product $product, array $context): array
    {
        $data['analytics'] = [
            'performance' => [
                'views_today' => $this->getViewsToday($product->getId()),
                'views_week' => $this->getViewsThisWeek($product->getId()),
                'conversion_rate' => $this->getConversionRate($product->getId()),
            ],
            'engagement' => [
                'avg_time_on_page' => $this->getAverageTimeOnPage($product->getId()),
                'bounce_rate' => $this->getBounceRate($product->getId()),
            ],
        ];
        
        return $data;
    }

    /**
     * Compute product metrics
     * 
     * @param Product $product
     * @param array $relations
     * @return array
     */
    private function computeProductMetrics(Product $product, array $relations): array
    {
        $metrics = [
            'score' => [
                'completeness' => $this->calculateCompletenessScore($product),
                'quality' => $this->calculateQualityScore($product, $relations),
                'performance' => $this->calculatePerformanceScore($product),
            ],
            'health' => [
                'has_image' => !empty($product->getImage()),
                'has_description' => !empty($product->getDescription()),
                'has_price' => $product->getMarketPrice() > 0,
                'has_category' => $product->getCategoryId() !== null,
                'links_count' => count($relations['links'] ?? []),
            ],
        ];
        
        return $metrics;
    }

    /**
     * Build list metadata
     * 
     * @param array $metadata
     * @return array
     */
    private function buildListMetadata(array $metadata): array
    {
        $defaults = [
            'total' => 0,
            'per_page' => 20,
            'current_page' => 1,
            'last_page' => 1,
            'from' => 0,
            'to' => 0,
            'filters' => [],
            'sorting' => ['created_at' => 'desc'],
        ];
        
        return array_merge($defaults, $metadata);
    }

    /**
     * Build pagination links
     * 
     * @param array $pagination
     * @return array
     */
    private function buildPaginationLinks(array $pagination): array
    {
        $links = [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => null,
            'self' => null,
        ];
        
        $currentPage = $pagination['current_page'] ?? 1;
        $lastPage = $pagination['last_page'] ?? 1;
        
        // These would be generated based on actual routes
        // For now, return template URLs
        
        return array_filter($links);
    }

    /**
     * Generate search suggestions
     * 
     * @param string $query
     * @param array $results
     * @return array
     */
    private function generateSearchSuggestions(string $query, array $results): array
    {
        $suggestions = [];
        
        // Generate alternative queries
        if (count($results) < 5) {
            $suggestions['alternatives'] = [
                'check_spelling' => 'Check spelling',
                'broader_terms' => 'Try broader terms',
                'fewer_keywords' => 'Use fewer keywords',
            ];
        }
        
        // Generate category suggestions based on results
        $categories = [];
        foreach ($results as $result) {
            if (isset($result['category_id']) && $result['category_id']) {
                $categories[] = $result['category_id'];
            }
        }
        
        if (!empty($categories)) {
            $suggestions['related_categories'] = array_unique($categories);
        }
        
        return $suggestions;
    }

    /**
     * Generate batch operation recommendations
     * 
     * @param string $operation
     * @param array $results
     * @return array
     */
    private function generateBatchRecommendations(string $operation, array $results): array
    {
        $recommendations = [];
        
        $failedCount = count($results['failed'] ?? []);
        
        if ($failedCount > 0) {
            $recommendations[] = "{$failedCount} items failed. Consider retrying with error correction.";
        }
        
        if ($operation === 'import' && !empty($results['failed'])) {
            $recommendations[] = 'Check import file format and required fields';
        }
        
        return $recommendations;
    }

    /**
     * Validate response data
     * 
     * @param array $data
     * @return void
     * @throws \InvalidArgumentException
     */
    private function validateResponseData(array $data): void
    {
        $required = ['id', 'name', 'slug'];
        
        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }
    }

    /**
     * Format response data
     * 
     * @param array $data
     * @param array $context
     * @return array
     */
    private function formatResponseData(array $data, array $context): array
    {
        // Format dates
        $dateFields = ['created_at', 'updated_at', 'verified_at', 'published_at', 'deleted_at'];
        
        foreach ($dateFields as $field) {
            if (isset($data[$field]) && $data[$field] instanceof \DateTimeInterface) {
                $data[$field] = $data[$field]->format($this->config['date_format']);
            }
        }
        
        // Format prices
        if (isset($data['market_price'])) {
            $data['market_price_formatted'] = $this->formatPrice((float) $data['market_price']);
        }
        
        return $data;
    }

    /**
     * Add response metadata
     * 
     * @param array $data
     * @param array $context
     * @return array
     */
    private function addResponseMetadata(array $data, array $context): array
    {
        $data['_metadata'] = [
            'version' => 'v1',
            'generated_at' => Time::now()->format($this->config['date_format']),
            'context' => $context,
        ];
        
        return $data;
    }

    /**
     * Generate keywords for product
     * 
     * @param Product $product
     * @return array
     */
    private function generateKeywords(Product $product): array
    {
        $keywords = [$product->getName()];
        
        // Add category name if available
        if ($product->getCategory()) {
            $keywords[] = $product->getCategory()->getName();
        }
        
        // Add price-based keywords
        $keywords[] = $this->formatPrice($product->getMarketPrice()) . ' product';
        
        return array_unique($keywords);
    }

    // ==================== STUB METHODS FOR DEMONSTRATION ====================

    /**
     * Get views today (stub - would query analytics in production)
     */
    private function getViewsToday(int $productId): int
    {
        return rand(0, 100);
    }

    /**
     * Get views this week (stub)
     */
    private function getViewsThisWeek(int $productId): int
    {
        return rand(0, 500);
    }

    /**
     * Get conversion rate (stub)
     */
    private function getConversionRate(int $productId): float
    {
        return rand(1, 30) / 100; // 1-30%
    }

    /**
     * Get average time on page (stub)
     */
    private function getAverageTimeOnPage(int $productId): int
    {
        return rand(30, 300); // 30-300 seconds
    }

    /**
     * Get bounce rate (stub)
     */
    private function getBounceRate(int $productId): float
    {
        return rand(30, 80) / 100; // 30-80%
    }

    /**
     * Calculate completeness score (stub)
     */
    private function calculateCompletenessScore(Product $product): int
    {
        $score = 0;
        $score += !empty($product->getName()) ? 25 : 0;
        $score += !empty($product->getDescription()) ? 25 : 0;
        $score += $product->getMarketPrice() > 0 ? 25 : 0;
        $score += !empty($product->getImage()) ? 25 : 0;
        
        return $score;
    }

    /**
     * Calculate quality score (stub)
     */
    private function calculateQualityScore(Product $product, array $relations): int
    {
        $score = 50; // Base score
        
        // Add points for relations
        if (!empty($relations['links'])) {
            $score += 20;
        }
        
        if (!empty($relations['badges'])) {
            $score += 15;
        }
        
        if (!empty($relations['reviews'])) {
            $score += 15;
        }
        
        return min(100, $score);
    }

    /**
     * Calculate performance score (stub)
     */
    private function calculatePerformanceScore(Product $product): int
    {
        $views = $product->getViewCount();
        
        if ($views > 1000) return 90;
        if ($views > 500) return 70;
        if ($views > 100) return 50;
        if ($views > 10) return 30;
        
        return 10;
    }

    /**
     * Get audit trail (stub)
     */
    private function getAuditTrail(int $productId): array
    {
        return [
            ['action' => 'created', 'by' => 'System', 'at' => Time::now()->subDays(30)->format($this->config['date_format'])],
            ['action' => 'updated', 'by' => 'Admin', 'at' => Time::now()->subDays(15)->format($this->config['date_format'])],
            ['action' => 'published', 'by' => 'Admin', 'at' => Time::now()->subDays(10)->format($this->config['date_format'])],
        ];
    }

    /**
     * Generate recommendations (stub)
     */
    private function generateRecommendations(Product $product, array $relations): array
    {
        $recommendations = [];
        
        if (empty($product->getImage())) {
            $recommendations[] = 'Add product image to improve engagement';
        }
        
        if (empty($relations['links'])) {
            $recommendations[] = 'Add marketplace links to increase sales channels';
        }
        
        if (strlen($product->getDescription() ?? '') < 100) {
            $recommendations[] = 'Enhance product description for better SEO';
        }
        
        return $recommendations;
    }

    /**
     * Get factory configuration
     * 
     * @return array
     */
    public function getConfiguration(): array
    {
        return $this->config;
    }

    /**
     * Update factory configuration
     * 
     * @param array $config
     * @return void
     */
    public function updateConfiguration(array $config): void
    {
        $this->config = array_replace_recursive($this->config, $config);
    }
}