<?php

namespace App\Services;

use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Queries\ProductQuery;
use App\DTOs\Requests\Product\CreateProductRequest;
use App\DTOs\Requests\Product\PublishProductRequest;
use App\DTOs\Requests\Product\UpdateProductRequest;
use App\DTOs\Responses\ProductDetailResponse;
use App\DTOs\Responses\ProductResponse;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Exceptions\DomainException;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AdminModel;
use App\Models\AuditLogModel;
use App\Models\BadgeModel;
use App\Models\CategoryModel;
use App\Models\LinkModel;
use App\Models\MarketplaceBadgeModel;
use App\Models\MarketplaceModel;
use App\Models\ProductBadgeModel;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use CodeIgniter\Database\ConnectionInterface;

/**
 * Product Service
 *
 * Comprehensive service layer for product management with business logic,
 * caching, transactions, and workflow management.
 *
 * @package App\Services
 */
class ProductService
{
    // Dependencies
    private ProductRepositoryInterface $productRepository;
    private CacheService $cache;
    private ConnectionInterface $db;

    // Models
    private CategoryModel $categoryModel;
    private LinkModel $linkModel;
    private BadgeModel $badgeModel;
    private ProductBadgeModel $productBadgeModel;
    private MarketplaceModel $marketplaceModel;
    private MarketplaceBadgeModel $marketplaceBadgeModel;
    private AdminModel $adminModel;
    private AuditLogModel $auditLogModel;

    // Configuration
    private array $config;

    // Cache constants
    private const CACHE_TTL = 3600;
    private const CACHE_PREFIX = 'product_service_';

    // Validation constants
    private const MAX_PRODUCTS_PER_DAY = 100;
    private const MIN_PRICE_UPDATE_INTERVAL = 86400; // 24 hours in seconds
    private const MIN_LINK_VALIDATION_INTERVAL = 172800; // 48 hours

    // PERBAIKAN: Tambahkan CacheService sebagai parameter opsional
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ConnectionInterface $db,
        ?CacheService $cacheService = null,
        array $config = []
    ) {
        $this->productRepository = $productRepository;
        $this->db = $db;
        $this->config = array_merge($this->getDefaultConfig(), $config);

        $this->cache = $cacheService;

        $this->initializeModels();
    }

    /*
     * Initialize required models
     */
    private function initializeModels(): void
    {
        $this->categoryModel = model('CategoryModel');
        $this->linkModel = model('LinkModel');
        $this->badgeModel = model('BadgeModel');
        $this->productBadgeModel = model('ProductBadgeModel');
        $this->marketplaceModel = model('MarketplaceModel');
        $this->marketplaceBadgeModel = model('MarketplaceBadgeModel');
        $this->adminModel = model('AdminModel');
        $this->auditLogModel = model('AuditLogModel');
    }

    // PERBAIKAN: Tambahkan method getDefaultConfig() yang hilang
    private function getDefaultConfig(): array
    {
        return [
            'cache_ttl' => self::CACHE_TTL,
            'cache_prefix' => self::CACHE_PREFIX,
            'max_products_per_day' => self::MAX_PRODUCTS_PER_DAY,
            'price_update_interval' => self::MIN_PRICE_UPDATE_INTERVAL,
            'link_validation_interval' => self::MIN_LINK_VALIDATION_INTERVAL,
            //'default_page_size' => self::DEFAULT_PAGE_SIZE,
            //'max_page_size' => self::MAX_PAGE_SIZE,
            'currency' => 'Rp',
            'decimal_separator' => ',',
            'thousand_separator' => '.',
            'base_image_url' => base_url('uploads/products/'),
            'allowed_image_extensions' => ['jpg', 'jpeg', 'png', 'gif'],
            'max_image_size' => 1000, // 2MB
        ];
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * Create a new product
     *
     * @param CreateProductRequest $request
     * @param int $adminId Admin ID performing the action
     * @return Product
     * @throws ValidationException
     */
    public function create(CreateProductRequest $request, int $adminId): Product
    {
        // Validate request
        $validation = $request->validate();
        if (!$validation['valid']) {
            throw ValidationException::forBusinessRule(
                'product_validation',
                'Product validation failed',
                ['errors' => $validation['errors']]
            );
        }

        // Check product limits
        $limitCheck = $this->checkProductLimit($adminId);
        if (!$limitCheck['allowed']) {
            throw new DomainException(
                'Product limit exceeded',
                'PRODUCT_LIMIT_EXCEEDED',
                $limitCheck
            );
        }

        // Sanitize request
        $request->sanitize();

        // Start transaction
        $this->db->transStart();

        try {
            // Convert request to entity
            $productData = $request->toArray();
            $product = Product::fromArray($productData);

            // Set timestamps
            $product->initialize();

            // Validate entity
            $entityValidation = $product->validate();
            if (!$entityValidation['valid']) {
                throw ValidationException::fromEntityValidation(
                    $product,
                    $entityValidation
                );
            }

            // Save to repository
            $savedProduct = $this->productRepository->save($product);

            // Log admin action
            $this->logAdminAction(
                $adminId,
                'create',
                'Product',
                $savedProduct->getId(),
                null,
                $savedProduct->toArray(),
                'Created new product: ' . $savedProduct->getName()
            );

            // Clear relevant caches
            $this->clearProductCaches($savedProduct->getId());

            $this->db->transComplete();

            return $savedProduct;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Update an existing product
     *
     * @param UpdateProductRequest $request
     * @param int $adminId
     * @return Product
     * @throws ProductNotFoundException|ValidationException
     */
    public function update(UpdateProductRequest $request, int $adminId): Product
    {
        // Validate request
        $validation = $request->validate();
        if (!$validation['valid']) {
            throw ValidationException::forBusinessRule(
                'product_validation',
                'Product validation failed',
                ['errors' => $validation['errors']]
            );
        }

        // Check if product exists
        $product = $this->productRepository->find($request->productId);
        if (!$product) {
            throw ProductNotFoundException::forId($request->productId);
        }

        // Check if product can be edited in current status
        if (!$product->getStatus()->canTransitionTo(ProductStatus::from($request->status ?? $product->getStatus()->value))) {
            throw new DomainException(
                'Product cannot be updated in current status',
                'INVALID_STATUS_TRANSITION',
                [
                    'current_status' => $product->getStatus()->value,
                    'target_status' => $request->status?->value,
                ]
            );
        }

        // Start transaction
        $this->db->transStart();

        try {
            // Get old values for audit log
            $oldValues = $product->toArray();

            // Apply updates
            $updateData = $request->toArray();
            foreach ($updateData as $field => $value) {
                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($product, $setter)) {
                    $product->$setter($value);
                }
            }

            // Update timestamps
            $product->markAsUpdated();

            // Validate entity
            $entityValidation = $product->validate();
            if (!$entityValidation['valid']) {
                throw ValidationException::fromEntityValidation(
                    $product,
                    $entityValidation
                );
            }

            // Save to repository
            $updatedProduct = $this->productRepository->save($product);

            // Log admin action
            $this->logAdminAction(
                $adminId,
                'update',
                'Product',
                $updatedProduct->getId(),
                $oldValues,
                $updatedProduct->toArray(),
                'Updated product: ' . $updatedProduct->getName() .
                ' (' . implode(', ', $request->getChangedFields()) . ')'
            );

            // Clear caches
            $this->clearProductCaches($updatedProduct->getId());

            $this->db->transComplete();

            return $updatedProduct;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Delete a product (soft delete)
     *
     * @param int $productId
     * @param int $adminId
     * @param bool $force Force delete (bypass soft delete)
     * @return bool
     * @throws ProductNotFoundException
     */
    public function delete(int $productId, int $adminId, bool $force = false): bool
    {
        // Check if product exists
        $product = $this->productRepository->find($productId, true);
        if (!$product) {
            throw ProductNotFoundException::forId($productId);
        }

        // Check if already deleted
        if ($product->isDeleted() && !$force) {
            throw new DomainException(
                'Product already deleted',
                'ALREADY_DELETED',
                ['product_id' => $productId]
            );
        }

        $this->db->transStart();

        try {
            // Get old values for audit log
            $oldValues = $product->toArray();

            // Perform deletion
            $result = $this->productRepository->delete($productId, $force);

            if ($result) {
                // Log admin action
                $this->logAdminAction(
                    $adminId,
                    $force ? 'force_delete' : 'delete',
                    'Product',
                    $productId,
                    $oldValues,
                    null,
                    ($force ? 'Force deleted' : 'Deleted') . ' product: ' . $product->getName()
                );

                // Clear caches
                $this->clearProductCaches($productId);
                $this->clearAggregateCaches();
            }

            $this->db->transComplete();

            return $result;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Restore a soft-deleted product
     *
     * @param int $productId
     * @param int $adminId
     * @return bool
     * @throws ProductNotFoundException
     */
    public function restore(int $productId, int $adminId): bool
    {
        // Check if product exists (including deleted)
        $product = $this->productRepository->find($productId, true);
        if (!$product) {
            throw ProductNotFoundException::forId($productId);
        }

        // Check if product is deleted
        if (!$product->isDeleted()) {
            throw new DomainException(
                'Product is not deleted',
                'NOT_DELETED',
                ['product_id' => $productId]
            );
        }

        $this->db->transStart();

        try {
            $result = $this->productRepository->restore($productId);

            if ($result) {
                // Log admin action
                $this->logAdminAction(
                    $adminId,
                    'restore',
                    'Product',
                    $productId,
                    null,
                    $product->toArray(),
                    'Restored product: ' . $product->getName()
                );

                // Clear caches
                $this->clearProductCaches($productId);
                $this->clearAggregateCaches();
            }

            $this->db->transComplete();

            return $result;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Find product by ID or slug
     *
     * @param mixed $identifier ID or slug
     * @param bool $adminMode
     * @param array $relations Relations to load
     * @return ProductDetailResponse
     * @throws ProductNotFoundException
     */
    public function find($identifier, bool $adminMode = false, array $relations = []): ProductDetailResponse
    {
        $cacheKey = $this->getCacheKey('find_' . $identifier . '_' .
                     ($adminMode ? 'admin' : 'public') . '_' .
                     implode('_', $relations));

        return $this->cache->remember($cacheKey, function () use ($identifier, $adminMode, $relations) {

            // Find product
            $product = $this->productRepository->findByIdOrSlug($identifier, $adminMode);
            if (!$product) {
                throw ProductNotFoundException::forId($identifier);
            }

            // Load requested relations
            $loadedRelations = [];

            if (in_array('category', $relations)) {
                $loadedRelations['category'] = $product->getCategoryId() ?
                    $this->categoryModel->find($product->getCategoryId()) : null;
            }

            if (in_array('links', $relations)) {
                $loadedRelations['links'] = $this->linkModel->findByProduct(
                    $product->getId(),
                    !$adminMode // Active only for public mode
                );
            }

            if (in_array('badges', $relations)) {
                $loadedRelations['badges'] = $this->productBadgeModel->getProductBadges($product->getId());
            }

            if (in_array('marketplaces', $relations)) {
                $loadedRelations['marketplaces'] = $this->marketplaceModel->findActive();
            }

            if (in_array('marketplace_badges', $relations)) {
                $loadedRelations['marketplace_badges'] = $this->marketplaceBadgeModel->findActive();
            }

            if ($adminMode && in_array('verified_by_admin', $relations) && $product->getVerifiedBy()) {
                $loadedRelations['verified_by_admin'] = $this->adminModel->find($product->getVerifiedBy());
            }

            if ($adminMode && in_array('recent_actions', $relations)) {
                $loadedRelations['recent_actions'] = $this->auditLogModel->getEntityLogs(
                    'Product',
                    $product->getId(),
                    10
                );
            }

            if (in_array('statistics', $relations) && isset($loadedRelations['links'])) {
                $loadedRelations['statistics'] = $this->calculateProductStatistics(
                    $product->getId(),
                    $loadedRelations['links']
                );
            }

            // Create response
            return ProductDetailResponse::fromEntityWithRelations(
                $product,
                $loadedRelations,
                [
                    'admin_mode' => $adminMode,
                    'include_trashed' => $adminMode,
                    'load_category' => in_array('category', $relations),
                    'load_links' => in_array('links', $relations),
                    'load_badges' => in_array('badges', $relations),
                    'load_marketplaces' => in_array('marketplaces', $relations),
                    'load_marketplace_badges' => in_array('marketplace_badges', $relations),
                    'load_verification_info' => in_array('verified_by_admin', $relations),
                    'load_recent_actions' => in_array('recent_actions', $relations),
                    'load_statistics' => in_array('statistics', $relations),
                ]
            );

        }, self::CACHE_TTL);
    }

    /**
     * List products with filtering and pagination
     *
     * @param ProductQuery $query
     * @param PaginationQuery $pagination
     * @param array $relations Relations to load
     * @return array [products: array, metadata: array]
     */
    public function list(ProductQuery $query, PaginationQuery $pagination, array $relations = []): array
    {
        $cacheKey = $this->getCacheKey(
            'list_' . $query->getCacheKey() . '_' .
            $pagination->getCacheKey() . '_' .
            implode('_', $relations)
        );

        return $this->cache->remember($cacheKey, function () use ($query, $pagination, $relations) {

            // Convert query to repository filters
            $filters = $query->toRepositoryFilters();

            // Get products from repository
            $products = $this->productRepository->findAll(
                $filters,
                $pagination->getLimit(),
                $pagination->getOffset(),
                $query->getSortString(),
                $query->getIncludeTrashed()
            );

            // Get total count for pagination
            $totalItems = $this->productRepository->countAll($query->getIncludeTrashed());

            // Update pagination with total items
            $pagination = $pagination->withTotalItems($totalItems);

            // Load relations if requested
            $responses = [];
            if (!empty($relations)) {
                foreach ($products as $product) {
                    $responses[] = $this->find(
                        $product->getId(),
                        $query->isAdminQuery(),
                        $relations
                    );
                }
            } else {
                // Just basic responses
                $config = [
                    'admin_mode' => $query->isAdminQuery(),
                    'include_trashed' => $query->getIncludeTrashed(),
                ];

                $responses = ProductResponse::collection($products, $config);
            }

            // Generate metadata
            $metadata = $pagination->generateMetadata();

            return [
                'data' => $responses,
                'meta' => $metadata['pagination'],
                'query' => $metadata['query'],
                'filters' => $query->toFilterSummary(),
            ];

        }, self::CACHE_TTL);
    }

    // ==================== WORKFLOW OPERATIONS ====================

    /**
     * Publish a product
     *
     * @param PublishProductRequest $request
     * @return Product
     * @throws ProductNotFoundException|ValidationException|DomainException
     */
    public function publish(PublishProductRequest $request): Product
    {
        // Validate basic request
        $validation = $request->validate();
        if (!$validation['valid']) {
            throw ValidationException::forBusinessRule(
                'publish_validation',
                'Publish validation failed',
                ['errors' => $validation['errors']]
            );
        }

        // Get product
        $product = $this->productRepository->find($request->getProductId());
        if (!$product) {
            throw ProductNotFoundException::forId($request->getProductId());
        }

        // Load product links for validation
        $links = $this->linkModel->findActiveByProduct($product->getId());

        // Validate prerequisites
        $prerequisites = $request->validatePrerequisites(
            $product->toArray(),
            $links
        );

        if (!$prerequisites['valid'] && !$request->isForcePublish()) {
            throw ValidationException::forBusinessRule(
                'publish_prerequisites',
                'Publish prerequisites not met',
                ['errors' => $prerequisites['errors']]
            );
        }

        $this->db->transStart();

        try {
            // Get old values for audit log
            $oldValues = $product->toArray();

            // Update product status and timestamps
            $product->publish();

            // Set verified info if not already set
            if (!$product->getVerifiedAt()) {
                $product->setVerifiedAt(new \DateTimeImmutable());
                $product->setVerifiedBy($request->getAdminId());
            }

            // Set published_at based on request
            if ($request->isScheduled()) {
                $product->setPublishedAt($request->getScheduledAt());
            } else {
                $product->setPublishedAt(new \DateTimeImmutable());
            }

            // Save to repository
            $publishedProduct = $this->productRepository->save($product);

            // Log admin action
            $logData = $request->toAdminActionLog($oldValues);
            $this->auditLogModel->logAction($logData);

            // Clear caches
            $this->clearProductCaches($publishedProduct->getId());
            $this->clearAggregateCaches();

            $this->db->transComplete();

            return $publishedProduct;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Verify a product
     *
     * @param int $productId
     * @param int $adminId
     * @param string|null $notes
     * @return Product
     * @throws ProductNotFoundException|DomainException
     */
    public function verify(int $productId, int $adminId, ?string $notes = null): Product
    {
        $product = $this->productRepository->find($productId);
        if (!$product) {
            throw ProductNotFoundException::forId($productId);
        }

        // Check if already verified
        if ($product->isVerified()) {
            throw new DomainException(
                'Product already verified',
                'ALREADY_VERIFIED',
                ['product_id' => $productId]
            );
        }

        // Check if in correct status for verification
        if (!$product->isPendingVerification() && !$product->isDraft()) {
            throw new DomainException(
                'Product must be in draft or pending verification status',
                'INVALID_STATUS_FOR_VERIFICATION',
                ['current_status' => $product->getStatus()->value]
            );
        }

        $this->db->transStart();

        try {
            $oldValues = $product->toArray();

            // Perform verification
            $product->verify($adminId);
            $verifiedProduct = $this->productRepository->save($product);

            // Log admin action
            $this->logAdminAction(
                $adminId,
                'verify',
                'Product',
                $productId,
                $oldValues,
                $verifiedProduct->toArray(),
                'Verified product: ' . $verifiedProduct->getName() .
                ($notes ? ' - ' . $notes : '')
            );

            // Clear caches
            $this->clearProductCaches($productId);

            $this->db->transComplete();

            return $verifiedProduct;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Archive a product
     *
     * @param int $productId
     * @param int $adminId
     * @param string|null $notes
     * @return Product
     * @throws ProductNotFoundException|DomainException
     */
    public function archive(int $productId, int $adminId, ?string $notes = null): Product
    {
        $product = $this->productRepository->find($productId);
        if (!$product) {
            throw ProductNotFoundException::forId($productId);
        }

        // Check if already archived
        if ($product->isArchived()) {
            throw new DomainException(
                'Product already archived',
                'ALREADY_ARCHIVED',
                ['product_id' => $productId]
            );
        }

        $this->db->transStart();

        try {
            $oldValues = $product->toArray();

            // Perform archive
            $product->archive();
            $archivedProduct = $this->productRepository->save($product);

            // Log admin action
            $this->logAdminAction(
                $adminId,
                'archive',
                'Product',
                $productId,
                $oldValues,
                $archivedProduct->toArray(),
                'Archived product: ' . $archivedProduct->getName() .
                ($notes ? ' - ' . $notes : '')
            );

            // Clear caches
            $this->clearProductCaches($productId);
            $this->clearAggregateCaches();

            $this->db->transComplete();

            return $archivedProduct;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Request verification for a product
     *
     * @param int $productId
     * @param int $adminId
     * @return Product
     * @throws ProductNotFoundException|DomainException
     */
    public function requestVerification(int $productId, int $adminId): Product
    {
        $product = $this->productRepository->find($productId);
        if (!$product) {
            throw ProductNotFoundException::forId($productId);
        }

        // Check if in correct status
        if (!$product->isDraft()) {
            throw new DomainException(
                'Only draft products can request verification',
                'INVALID_STATUS_FOR_VERIFICATION_REQUEST',
                ['current_status' => $product->getStatus()->value]
            );
        }

        $this->db->transStart();

        try {
            $oldValues = $product->toArray();

            // Request verification
            $product->requestVerification();
            $updatedProduct = $this->productRepository->save($product);

            // Log admin action
            $this->logAdminAction(
                $adminId,
                'request_verification',
                'Product',
                $productId,
                $oldValues,
                $updatedProduct->toArray(),
                'Requested verification for product: ' . $updatedProduct->getName()
            );

            // Clear caches
            $this->clearProductCaches($productId);

            $this->db->transComplete();

            return $updatedProduct;

        } catch (\Exception $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * Bulk update products
     *
     * @param array $productIds
     * @param array $updateData
     * @param int $adminId
     * @return array [updated: int, failed: array]
     */
    public function bulkUpdate(array $productIds, array $updateData, int $adminId): array
    {
        $updated = 0;
        $failed = [];

        foreach ($productIds as $productId) {
            try {
                // Create update request
                $request = new UpdateProductRequest($productId, $updateData);

                // Validate
                $validation = $request->validate();
                if (!$validation['valid']) {
                    $failed[$productId] = $validation['errors'];
                    continue;
                }

                // Perform update
                $this->update($request, $adminId);
                $updated++;

            } catch (\Exception $e) {
                $failed[$productId] = $e->getMessage();
            }
        }

        // Clear aggregate caches if any updates succeeded
        if ($updated > 0) {
            $this->clearAggregateCaches();
        }

        return [
            'updated' => $updated,
            'failed' => $failed,
            'total' => count($productIds),
        ];
    }

    /**
     * Bulk publish products
     *
     * @param array $productIds
     * @param int $adminId
     * @param bool $force
     * @return array [published: int, failed: array]
     */
    public function bulkPublish(array $productIds, int $adminId, bool $force = false): array
    {
        $published = 0;
        $failed = [];

        foreach ($productIds as $productId) {
            try {
                // Create publish request
                $request = $force ?
                    PublishProductRequest::forForcePublish($productId, $adminId) :
                    PublishProductRequest::forImmediatePublish($productId, $adminId);

                // Perform publish
                $this->publish($request);
                $published++;

            } catch (\Exception $e) {
                $failed[$productId] = $e->getMessage();
            }
        }

        // Clear aggregate caches if any publishes succeeded
        if ($published > 0) {
            $this->clearAggregateCaches();
        }

        return [
            'published' => $published,
            'failed' => $failed,
            'total' => count($productIds),
        ];
    }

    /**
     * Bulk archive products
     *
     * @param array $productIds
     * @param int $adminId
     * @return array [archived: int, failed: array]
     */
    public function bulkArchive(array $productIds, int $adminId): array
    {
        $archived = 0;
        $failed = [];

        foreach ($productIds as $productId) {
            try {
                $this->archive($productId, $adminId);
                $archived++;

            } catch (\Exception $e) {
                $failed[$productId] = $e->getMessage();
            }
        }

        // Clear aggregate caches if any archives succeeded
        if ($archived > 0) {
            $this->clearAggregateCaches();
        }

        return [
            'archived' => $archived,
            'failed' => $failed,
            'total' => count($productIds),
        ];
    }

    /**
     * Bulk delete products
     *
     * @param array $productIds
     * @param int $adminId
     * @param bool $force
     * @return array [deleted: int, failed: array]
     */
    public function bulkDelete(array $productIds, int $adminId, bool $force = false): array
    {
        $deleted = 0;
        $failed = [];

        foreach ($productIds as $productId) {
            try {
                $this->delete($productId, $adminId, $force);
                $deleted++;

            } catch (\Exception $e) {
                $failed[$productId] = $e->getMessage();
            }
        }

        // Clear aggregate caches if any deletes succeeded
        if ($deleted > 0) {
            $this->clearAggregateCaches();
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'total' => count($productIds),
        ];
    }

    // ==================== REPORTING & STATISTICS ====================

    /**
     * Get product statistics
     *
     * @param bool $includeDeleted
     * @return array
     */
    public function getStatistics(bool $includeDeleted = false): array
    {
        $cacheKey = $this->getCacheKey('stats_' . ($includeDeleted ? 'with_deleted' : 'active'));

        return $this->cache->remember($cacheKey, function () use ($includeDeleted) {

            // Get counts by status from repository
            $statusCounts = $this->productRepository->countByStatus($includeDeleted);

            // Get total counts
            $totalProducts = $this->productRepository->countAll($includeDeleted);
            $publishedProducts = $this->productRepository->countPublished();

            // Get recent activity
            $recentCreated = $this->productRepository->findAll(
                [],
                10,
                0,
                'created_at DESC',
                $includeDeleted
            );

            $recentPublished = $this->productRepository->findAll(
                ['status' => [ProductStatus::PUBLISHED->value]],
                10,
                0,
                'published_at DESC',
                $includeDeleted
            );

            // Calculate growth (last 30 days)
            $growth = $this->calculateGrowthStatistics();

            return [
                'counts' => [
                    'total' => $totalProducts,
                    'published' => $publishedProducts,
                    'by_status' => $statusCounts,
                ],
                'activity' => [
                    'recent_created' => array_map(fn ($p) => [
                        'id' => $p->getId(),
                        'name' => $p->getName(),
                        'created_at' => $p->getCreatedAt()?->format('Y-m-d H:i:s'),
                    ], $recentCreated),
                    'recent_published' => array_map(fn ($p) => [
                        'id' => $p->getId(),
                        'name' => $p->getName(),
                        'published_at' => $p->getPublishedAt()?->format('Y-m-d H:i:s'),
                    ], $recentPublished),
                ],
                'growth' => $growth,
                'timestamp' => date('Y-m-d H:i:s'),
            ];

        }, 300); // 5 minute cache for statistics
    }

    /**
     * Get popular products
     *
     * @param int $limit
     * @param string $period all|month|week|day
     * @param bool $adminMode
     * @return array
     */
    public function getPopular(int $limit = 10, string $period = 'all', bool $adminMode = false): array
    {
        $cacheKey = $this->getCacheKey('popular_' . $period . '_' . $limit . '_' . ($adminMode ? 'admin' : 'public'));

        return $this->cache->remember($cacheKey, function () use ($limit, $period, $adminMode) {

            $products = $this->productRepository->getPopular($limit, $period);

            $config = [
                'admin_mode' => $adminMode,
                'include_trashed' => $adminMode,
            ];

            return ProductResponse::collection($products, $config);

        }, 1800); // 30 minute cache for popular products
    }

    /**
     * Get trending products (based on recent view growth)
     *
     * @param int $limit
     * @param int $days
     * @return array
     */
    public function getTrending(int $limit = 10, int $days = 7): array
    {
        $cacheKey = $this->getCacheKey('trending_' . $days . '_' . $limit);

        return $this->cache->remember($cacheKey, function () use ($limit, $days) {
            // This would typically involve more complex logic
            // For now, we'll return recently published with high view counts

            $products = $this->productRepository->findAll(
                [
                    'status' => [ProductStatus::PUBLISHED->value],
                    'date_range' => [
                        'from' => date('Y-m-d', strtotime("-$days days")),
                        'to' => date('Y-m-d'),
                        'field' => 'published_at'
                    ]
                ],
                $limit,
                0,
                'view_count DESC'
            );

            return ProductResponse::collection($products);

        }, 900); // 15 minute cache for trending
    }

    /**
     * Get products needing updates
     *
     * @param string $type price|links|both
     * @param int $limit
     * @return array
     */
    public function getNeedsUpdate(string $type = 'both', int $limit = 50): array
    {
        $products = $this->productRepository->findNeedsUpdate($type, $limit);

        $config = [
            'admin_mode' => true,
            'include_trashed' => false,
        ];

        return ProductResponse::collection($products, $config);
    }

    // ==================== VALIDATION & UTILITY METHODS ====================

    /**
     * Validate product for publishing
     *
     * @param int $productId
     * @param bool $includeLinks
     * @return array [valid: bool, errors: array, warnings: array]
     */
    public function validateForPublish(int $productId, bool $includeLinks = true): array
    {
        $product = $this->productRepository->find($productId);
        if (!$product) {
            return [
                'valid' => false,
                'errors' => ['Product not found'],
                'warnings' => [],
            ];
        }

        $errors = [];
        $warnings = [];

        // Check status
        if (!$product->getStatus()->canBePublished()) {
            $errors[] = 'Product status does not allow publishing';
        }

        // Check required fields
        $requiredFields = [
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'category_id' => $product->getCategoryId(),
            'image' => $product->getImage(),
            'description' => $product->getDescription(),
            'market_price' => $product->getMarketPrice(),
        ];

        foreach ($requiredFields as $field => $value) {
            if (empty($value)) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }

        // Check market price is valid
        if ((float)$product->getMarketPrice() <= 0) {
            $errors[] = 'Valid market price is required';
        }

        // Check image source type compatibility
        if ($product->getImageSourceType()->value === 'url' && empty($product->getImage())) {
            $errors[] = 'External image URL is required for URL source type';
        }

        if ($product->getImageSourceType()->value === 'upload' && empty($product->getImagePath())) {
            $errors[] = 'Image path is required for uploaded images';
        }

        // Check links if requested
        if ($includeLinks) {
            $links = $this->linkModel->findActiveByProduct($productId);

            if (empty($links)) {
                $errors[] = 'At least one active product link is required';
            } else {
                $validLinks = array_filter(
                    $links,
                    fn ($link) =>
                    !empty($link->getUrl()) && $link->isActive()
                );

                if (empty($validLinks)) {
                    $errors[] = 'No valid active links found';
                }

                // Check for price variation warnings
                $prices = array_map(fn ($link) => (float)$link->getPrice(), $validLinks);
                if (count($prices) > 1) {
                    $priceRange = max($prices) - min($prices);
                    if ($priceRange > 100000) { // More than 100k difference
                        $warnings[] = 'Large price variation detected among links';
                    }
                }
            }
        }

        // Check if recently updated
        $updatedAt = $product->getUpdatedAt();
        if ($updatedAt && $updatedAt->diff(new \DateTimeImmutable())->days < 1) {
            $warnings[] = 'Product was updated less than 24 hours ago';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'product_id' => $productId,
            'product_name' => $product->getName(),
            'current_status' => $product->getStatus()->value,
        ];
    }

    /**
     * Check product links
     *
     * @param int $productId
     * @return array
     */
    public function checkLinks(int $productId): array
    {
        $links = $this->linkModel->findByProduct($productId, null);

        $results = [
            'total' => count($links),
            'active' => 0,
            'inactive' => 0,
            'needs_validation' => 0,
            'needs_price_update' => 0,
            'broken_links' => 0,
            'links' => [],
        ];

        foreach ($links as $link) {
            $linkData = [
                'id' => $link->getId(),
                'marketplace_id' => $link->getMarketplaceId(),
                'store_name' => $link->getStoreName(),
                'url' => $link->getUrl(),
                'active' => $link->isActive(),
                'needs_validation' => $link->needsValidation(),
                'needs_price_update' => $link->needsPriceUpdate(),
                'last_validation' => $link->getLastValidation()?->format('Y-m-d H:i:s'),
                'last_price_update' => $link->getLastPriceUpdate()?->format('Y-m-d H:i:s'),
            ];

            $results['links'][] = $linkData;

            if ($link->isActive()) {
                $results['active']++;
            } else {
                $results['inactive']++;
            }

            if ($link->needsValidation()) {
                $results['needs_validation']++;
            }

            if ($link->needsPriceUpdate()) {
                $results['needs_price_update']++;
            }

            // Check if URL is valid (basic check)
            if ($link->getUrl() && !filter_var($link->getUrl(), FILTER_VALIDATE_URL)) {
                $results['broken_links']++;
            }
        }

        return $results;
    }

    /**
     * Check product creation limits
     *
     * @param int $adminId
     * @return array [allowed: bool, remaining: int, limit: int]
     */
    public function checkProductLimit(int $adminId): array
    {
        // Get today's product count for this admin
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');

        $todayCount = $this->productRepository->countAll(false, [
            'date_range' => [
                'from' => $todayStart,
                'to' => $todayEnd,
                'field' => 'created_at'
            ],
            'created_by' => $adminId, // Assuming we track created_by
        ]);

        $remaining = max(0, self::MAX_PRODUCTS_PER_DAY - $todayCount);

        return [
            'allowed' => $remaining > 0,
            'remaining' => $remaining,
            'limit' => self::MAX_PRODUCTS_PER_DAY,
            'used' => $todayCount,
            'admin_id' => $adminId,
        ];
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Log admin action
     */
    private function logAdminAction(
        int $adminId,
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues,
        ?array $newValues,
        string $summary
    ): void {
        $this->auditLogModel->logCrudOperation(
            $adminId,
            $actionType,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            $summary
        );
    }

    /**
     * Calculate product statistics
     */
    private function calculateProductStatistics(int $productId, array $links): array
    {
        $activeLinks = array_filter($links, fn ($link) => $link->isActive());

        return [
            'total_links' => count($links),
            'active_links' => count($activeLinks),
            'total_clicks' => array_sum(array_map(fn ($link) => $link->getClicks(), $links)),
            'total_sold' => array_sum(array_map(fn ($link) => $link->getSoldCount(), $links)),
            'total_revenue' => array_sum(array_map(
                fn ($link) => (float)$link->getAffiliateRevenue(),
                $links
            )),
            'average_rating' => $this->calculateAverageRating($links),
            'price_range' => $this->calculatePriceRange($activeLinks),
        ];
    }

    /**
     * Calculate average rating from links
     */
    private function calculateAverageRating(array $links): float
    {
        $ratings = array_filter(array_map(
            fn ($link) => (float)$link->getRating(),
            $links
        ));

        if (empty($ratings)) {
            return 0.0;
        }

        return round(array_sum($ratings) / count($ratings), 2);
    }

    /**
     * Calculate price range from links
     */
    private function calculatePriceRange(array $links): ?array
    {
        if (empty($links)) {
            return null;
        }

        $prices = array_map(fn ($link) => (float)$link->getPrice(), $links);

        return [
            'lowest' => min($prices),
            'highest' => max($prices),
            'average' => round(array_sum($prices) / count($prices), 2),
        ];
    }

    /**
     * Calculate growth statistics
     */
    private function calculateGrowthStatistics(): array
    {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        $sixtyDaysAgo = date('Y-m-d', strtotime('-60 days'));

        // Get product counts for periods
        $currentCount = $this->productRepository->countAll(false, [
            'date_range' => [
                'from' => $thirtyDaysAgo,
                'to' => date('Y-m-d'),
                'field' => 'created_at'
            ]
        ]);

        $previousCount = $this->productRepository->countAll(false, [
            'date_range' => [
                'from' => $sixtyDaysAgo,
                'to' => $thirtyDaysAgo,
                'field' => 'created_at'
            ]
        ]);

        // Calculate growth percentage
        $growth = $previousCount > 0 ?
            (($currentCount - $previousCount) / $previousCount) * 100 :
            ($currentCount > 0 ? 100 : 0);

        return [
            'current_period' => [
                'start' => $thirtyDaysAgo,
                'end' => date('Y-m-d'),
                'count' => $currentCount,
            ],
            'previous_period' => [
                'start' => $sixtyDaysAgo,
                'end' => $thirtyDaysAgo,
                'count' => $previousCount,
            ],
            'growth_percentage' => round($growth, 2),
            'growth_absolute' => $currentCount - $previousCount,
        ];
    }

    /**
     * Clear product caches
     */
    private function clearProductCaches(int $productId): void
    {
        // Clear specific product caches
        $this->cache->deleteMultiple([
            $this->getCacheKey('find_' . $productId . '_*'),
            $this->getCacheKey('product_detail_' . $productId . '_*'),
        ]);

        // Clear repository caches
        $this->productRepository->setCacheTtl(0); // Force cache refresh
    }

    /**
     * Clear aggregate caches
     */
    private function clearAggregateCaches(): void
    {
        $this->cache->deleteMultiple([
            $this->getCacheKey('list_*'),
            $this->getCacheKey('stats_*'),
            $this->getCacheKey('popular_*'),
            $this->getCacheKey('trending_*'),
        ]);
    }

    /**
     * Get cache key
     */
    private function getCacheKey(string $suffix): string
    {
        return self::CACHE_PREFIX . $suffix;
    }
}
