<?php

namespace App\Services\Product;

use App\Contracts\ProductServiceInterface;
use App\Contracts\ProductCRUDInterface;
use App\Contracts\ProductWorkflowInterface;
use App\Contracts\ProductQueryInterface;
use App\Contracts\ProductBulkInterface;
use App\Contracts\ProductMaintenanceInterface;
use App\DTOs\Requests\Product\CreateProductRequest;
use App\DTOs\Requests\Product\UpdateProductRequest;
use App\DTOs\Requests\Product\ProductDeleteRequest;
use App\DTOs\Requests\Product\PublishProductRequest;
use App\DTOs\Requests\Product\ProductQuickEditRequest;
use App\DTOs\Requests\Product\ProductBulkActionRequest;
use App\DTOs\Requests\Product\ProductToggleStatusRequest;
use App\DTOs\Responses\ProductResponse;
use App\DTOs\Responses\ProductDetailResponse;
use App\DTOs\Responses\BulkActionResult;
use App\DTOs\Queries\ProductQuery;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Enums\ProductBulkActionType;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use Closure;
use Throwable;

/**
 * ProductOrchestrator - Main Facade/Coordinator for Product Domain
 * 
 * Layer 5.5: Service Coordinator
 * - Delegates to specialized services based on operation type
 * - Provides single entry point for controllers
 * - Manages cross-service coordination
 * - Implements facade pattern for simplified consumption
 * 
 * @package App\Services\Product
 */
class ProductOrchestrator implements ProductInterface
{
    /**
     * @var ProductCRUDInterface
     */
    private ProductCRUDInterface $crudService;

    /**
     * @var ProductWorkflowInterface
     */
    private ProductWorkflowInterface $workflowService;

    /**
     * @var ProductQueryInterface
     */
    private ProductQueryInterface $queryService;

    /**
     * @var ProductBulkInterface
     */
    private ProductBulkInterface $bulkService;

    /**
     * @var ProductMaintenanceInterface
     */
    private ProductMaintenanceInterface $maintenanceService;

    /**
     * Constructor with Dependency Injection
     * 
     * @param ProductCRUDInterface $crudService
     * @param ProductWorkflowInterface $workflowService
     * @param ProductQueryInterface $queryService
     * @param ProductBulkInterface $bulkService
     * @param ProductMaintenanceInterface $maintenanceService
     */
    public function __construct(
        ProductCRUDInterface $crudService,
        ProductWorkflowInterface $workflowService,
        ProductQueryInterface $queryService,
        ProductBulkInterface $bulkService,
        ProductMaintenanceInterface $maintenanceService
    ) {
        $this->crudService = $crudService;
        $this->workflowService = $workflowService;
        $this->queryService = $queryService;
        $this->bulkService = $bulkService;
        $this->maintenanceService = $maintenanceService;
    }

    // ==================== BASE INTERFACE DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function transaction(Closure $operation, ?string $transactionName = null): mixed
    {
        // Use CRUD service for transaction management (all services should have same implementation)
        return $this->crudService->transaction($operation, $transactionName);
    }

    /**
     * {@inheritDoc}
     */
    public function transactionWithRetry(
        Closure $operation,
        int $maxRetries = 3,
        int $retryDelayMs = 100
    ): mixed {
        return $this->crudService->transactionWithRetry($operation, $maxRetries, $retryDelayMs);
    }

    /**
     * {@inheritDoc}
     */
    public function authorize(string $permission, $resource = null): void
    {
        $this->crudService->authorize($permission, $resource);
    }

    /**
     * {@inheritDoc}
     */
    public function validateDTO(\App\DTOs\BaseDTO $dto, array $context = []): array
    {
        return $this->crudService->validateDTO($dto, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function validateDTOOrFail(\App\DTOs\BaseDTO $dto, array $context = []): void
    {
        $this->crudService->validateDTOOrFail($dto, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(\App\DTOs\BaseDTO $dto, array $context = []): array
    {
        return $this->crudService->validateBusinessRules($dto, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function coordinateRepositories(array $repositories, Closure $operation): mixed
    {
        return $this->crudService->coordinateRepositories($repositories, $operation);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntity(
        \App\Repositories\BaseRepositoryInterface $repository,
        $id,
        bool $throwIfNotFound = true
    ) {
        return $this->crudService->getEntity($repository, $id, $throwIfNotFound);
    }

    /**
     * {@inheritDoc}
     */
    public function audit(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $additionalContext = []
    ): void {
        $this->crudService->audit($actionType, $entityType, $entityId, $oldValues, $newValues, $additionalContext);
    }

    /**
     * {@inheritDoc}
     */
    public function clearCacheForEntity(
        \App\Repositories\BaseRepositoryInterface $repository,
        $entityId = null,
        ?string $pattern = null
    ): bool {
        return $this->crudService->clearCacheForEntity($repository, $entityId, $pattern);
    }

    /**
     * {@inheritDoc}
     */
    public function clearServiceCache(): bool
    {
        return $this->crudService->clearServiceCache();
    }

    /**
     * {@inheritDoc}
     */
    public function withCaching(string $cacheKey, Closure $callback, ?int $ttl = null): mixed
    {
        return $this->crudService->withCaching($cacheKey, $callback, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function batchOperation(
        array $items,
        Closure $itemOperation,
        int $batchSize = 100,
        ?callable $progressCallback = null
    ): array {
        return $this->crudService->batchOperation($items, $itemOperation, $batchSize, $progressCallback);
    }

    /**
     * {@inheritDoc}
     */
    public function setAdminContext(?int $adminId): self
    {
        $this->crudService->setAdminContext($adminId);
        $this->workflowService->setAdminContext($adminId);
        $this->bulkService->setAdminContext($adminId);
        $this->maintenanceService->setAdminContext($adminId);
        
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentAdminId(): ?int
    {
        return $this->crudService->getCurrentAdminId();
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'ProductOrchestrator';
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceCacheKey(string $operation, array $parameters = []): string
    {
        return $this->crudService->getServiceCacheKey($operation, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function getInitializedAt(): string
    {
        return $this->crudService->getInitializedAt();
    }

    /**
     * {@inheritDoc}
     */
    public function isReady(): bool
    {
        return $this->crudService->isReady() 
            && $this->workflowService->isReady()
            && $this->queryService->isReady()
            && $this->bulkService->isReady()
            && $this->maintenanceService->isReady();
    }

    /**
     * {@inheritDoc}
     */
    public function getHealthStatus(): array
    {
        $health = $this->crudService->getHealthStatus();
        
        $health['dependencies']['workflow_service'] = $this->workflowService->isReady();
        $health['dependencies']['query_service'] = $this->queryService->isReady();
        $health['dependencies']['bulk_service'] = $this->bulkService->isReady();
        $health['dependencies']['maintenance_service'] = $this->maintenanceService->isReady();
        
        $health['status'] = $this->isReady() ? 'healthy' : 'unhealthy';
        
        return $health;
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceMetrics(): array
    {
        $crudMetrics = $this->crudService->getPerformanceMetrics();
        $workflowMetrics = $this->workflowService->getPerformanceMetrics();
        $queryMetrics = $this->queryService->getPerformanceMetrics();
        $bulkMetrics = $this->bulkService->getPerformanceMetrics();
        $maintenanceMetrics = $this->maintenanceService->getPerformanceMetrics();
        
        return [
            'total_transactions' => 
                $crudMetrics['total_transactions'] +
                $workflowMetrics['total_transactions'] +
                $bulkMetrics['total_transactions'],
            'successful_transactions' => 
                $crudMetrics['successful_transactions'] +
                $workflowMetrics['successful_transactions'] +
                $bulkMetrics['successful_transactions'],
            'failed_transactions' => 
                $crudMetrics['failed_transactions'] +
                $workflowMetrics['failed_transactions'] +
                $bulkMetrics['failed_transactions'],
            'cache_hit_rate' => ($crudMetrics['cache_hit_rate'] + $queryMetrics['cache_hit_rate']) / 2,
            'orchestrator_calls' => $this->getOrchestratorCallCount(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function resetMetrics(): void
    {
        $this->crudService->resetMetrics();
        $this->workflowService->resetMetrics();
        $this->queryService->resetMetrics();
        $this->bulkService->resetMetrics();
        $this->maintenanceService->resetMetrics();
    }

    /**
     * {@inheritDoc}
     */
    public function validateConfiguration(): array
    {
        $crudConfig = $this->crudService->validateConfiguration();
        $workflowConfig = $this->workflowService->validateConfiguration();
        $queryConfig = $this->queryService->validateConfiguration();
        $bulkConfig = $this->bulkService->validateConfiguration();
        $maintenanceConfig = $this->maintenanceService->validateConfiguration();
        
        return array_merge(
            ['crud_service' => $crudConfig],
            ['workflow_service' => $workflowConfig],
            ['query_service' => $queryConfig],
            ['bulk_service' => $bulkConfig],
            ['maintenance_service' => $maintenanceConfig],
            ['orchestrator' => ['status' => 'ok', 'message' => 'All services configured']]
        );
    }

    // ==================== CRUD OPERATIONS DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function createProduct(CreateProductRequest $request): ProductResponse
    {
        return $this->crudService->createProduct($request);
    }

    /**
     * {@inheritDoc}
     */
    public function getProduct(
        int $productId, 
        bool $includeRelations = false, 
        bool $adminMode = false
    ): ProductDetailResponse {
        return $this->crudService->getProduct($productId, $includeRelations, $adminMode);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductBySlug(string $slug, bool $incrementViewCount = true): ProductDetailResponse
    {
        return $this->crudService->getProductBySlug($slug, $incrementViewCount);
    }

    /**
     * {@inheritDoc}
     */
    public function updateProduct(UpdateProductRequest $request): ProductResponse
    {
        return $this->crudService->updateProduct($request);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProduct(ProductDeleteRequest $request): bool
    {
        return $this->crudService->deleteProduct($request);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreProduct(int $productId, int $adminId): ProductResponse
    {
        return $this->crudService->restoreProduct($productId, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function quickEditProduct(ProductQuickEditRequest $request): ProductResponse
    {
        return $this->crudService->quickEditProduct($request);
    }

    /**
     * {@inheritDoc}
     */
    public function canDeleteProduct(int $productId, bool $hardDelete = false): array
    {
        return $this->crudService->canDeleteProduct($productId, $hardDelete);
    }

    /**
     * {@inheritDoc}
     */
    public function productToResponse(
        Product $product, 
        bool $adminMode = false, 
        array $relations = []
    ) {
        return $this->crudService->productToResponse($product, $adminMode, $relations);
    }

    /**
     * {@inheritDoc}
     */
    public function productsToResponses(
        array $products, 
        bool $adminMode = false, 
        array $relations = []
    ): array {
        return $this->crudService->productsToResponses($products, $adminMode, $relations);
    }

    // ==================== WORKFLOW OPERATIONS DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function publishProduct(PublishProductRequest $request): ProductResponse
    {
        return $this->workflowService->publishProduct($request);
    }

    /**
     * {@inheritDoc}
     */
    public function verifyProduct(int $productId, int $adminId, ?string $notes = null): ProductResponse
    {
        return $this->workflowService->verifyProduct($productId, $adminId, $notes);
    }

    /**
     * {@inheritDoc}
     */
    public function requestVerification(int $productId, int $adminId): ProductResponse
    {
        return $this->workflowService->requestVerification($productId, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function archiveProduct(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        return $this->workflowService->archiveProduct($productId, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function unarchiveProduct(int $productId, int $adminId): ProductResponse
    {
        return $this->workflowService->unarchiveProduct($productId, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function toggleProductStatus(ProductToggleStatusRequest $request): ProductResponse
    {
        return $this->workflowService->toggleProductStatus($request);
    }

    /**
     * {@inheritDoc}
     */
    public function updateProductStatus(
        int $productId,
        ProductStatus $status,
        int $adminId,
        ?string $notes = null,
        bool $force = false
    ): ProductResponse {
        return $this->workflowService->updateProductStatus($productId, $status, $adminId, $notes, $force);
    }

    /**
     * {@inheritDoc}
     */
    public function revertToDraft(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        return $this->workflowService->revertToDraft($productId, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function canTransitionTo(
        int $productId,
        ProductStatus $targetStatus,
        bool $includeBusinessRules = true
    ): array {
        return $this->workflowService->canTransitionTo($productId, $targetStatus, $includeBusinessRules);
    }

    /**
     * {@inheritDoc}
     */
    public function validateForPublication(int $productId): array
    {
        return $this->workflowService->validateForPublication($productId);
    }

    /**
     * {@inheritDoc}
     */
    public function validateForVerification(int $productId): array
    {
        return $this->workflowService->validateForVerification($productId);
    }

    /**
     * {@inheritDoc}
     */
    public function canPublishProduct(int $productId): array
    {
        return $this->workflowService->canPublishProduct($productId);
    }

    /**
     * {@inheritDoc}
     */
    public function canArchiveProduct(int $productId): array
    {
        return $this->workflowService->canArchiveProduct($productId);
    }

    /**
     * {@inheritDoc}
     */
    public function getAllowedTransitions(int $productId): array
    {
        return $this->workflowService->getAllowedTransitions($productId);
    }

    /**
     * {@inheritDoc}
     */
    public function sendForReview(int $productId, int $adminId, ?string $notes = null): ProductResponse
    {
        return $this->workflowService->sendForReview($productId, $adminId, $notes);
    }

    /**
     * {@inheritDoc}
     */
    public function rejectProduct(int $productId, int $adminId, string $reason): ProductResponse
    {
        return $this->workflowService->rejectProduct($productId, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function schedulePublication(
        int $productId,
        \DateTimeInterface $publishAt,
        int $adminId,
        ?string $notes = null
    ): ProductResponse {
        return $this->workflowService->schedulePublication($productId, $publishAt, $adminId, $notes);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelScheduledPublication(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        return $this->workflowService->cancelScheduledPublication($productId, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function getScheduledPublications(?int $limit = null, int $offset = 0): array
    {
        return $this->workflowService->getScheduledPublications($limit, $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function processScheduledPublications(int $batchSize = 50): array
    {
        return $this->workflowService->processScheduledPublications($batchSize);
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
        return $this->workflowService->getProductsByStatus($status, $limit, $offset, $includeRelations, $adminMode);
    }

    /**
     * {@inheritDoc}
     */
    public function countProductsByStatus(?ProductStatus $status = null, bool $includeArchived = false)
    {
        return $this->workflowService->countProductsByStatus($status, $includeArchived);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsNeedingVerification(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        return $this->workflowService->getProductsNeedingVerification($limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsPendingPublication(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        return $this->workflowService->getProductsPendingPublication($limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getWorkflowStatistics(string $period = 'month'): array
    {
        return $this->workflowService->getWorkflowStatistics($period);
    }

    /**
     * {@inheritDoc}
     */
    public function getStatusHistory(int $productId, ?int $limit = null, int $offset = 0): array
    {
        return $this->workflowService->getStatusHistory($productId, $limit, $offset);
    }

    /**
     * {@inheritDoc}
     */
    public function getAverageStatusTimes(string $period = 'month'): array
    {
        return $this->workflowService->getAverageStatusTimes($period);
    }

    /**
     * {@inheritDoc}
     */
    public function getWorkflowBottlenecks(int $thresholdHours = 72, int $limit = 50): array
    {
        return $this->workflowService->getWorkflowBottlenecks($thresholdHours, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkPublish(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->workflowService->bulkPublish($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkVerify(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->workflowService->bulkVerify($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $productIds, int $adminId, ?string $reason = null): array
    {
        return $this->workflowService->bulkArchive($productIds, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRequestVerification(array $productIds, int $adminId): array
    {
        return $this->workflowService->bulkRequestVerification($productIds, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function getStateMachineConfig(): array
    {
        return $this->workflowService->getStateMachineConfig();
    }

    /**
     * {@inheritDoc}
     */
    public function validateTransition(ProductStatus $from, ProductStatus $to, array $context = []): array
    {
        return $this->workflowService->validateTransition($from, $to, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function addTransitionGuard(string $transitionName, callable $guardCallback): void
    {
        $this->workflowService->addTransitionGuard($transitionName, $guardCallback);
    }

    /**
     * {@inheritDoc}
     */
    public function overrideTransition(
        int $productId,
        ProductStatus $from,
        ProductStatus $to,
        int $adminId,
        string $reason
    ): bool {
        return $this->workflowService->overrideTransition($productId, $from, $to, $adminId, $reason);
    }

    // ==================== QUERY OPERATIONS DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function listProducts(ProductQuery $query, bool $adminMode = false): array
    {
        return $this->queryService->listProducts($query, $adminMode);
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
        return $this->queryService->searchProducts($keyword, $filters, $limit, $offset, $adminMode);
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
        return $this->queryService->advancedSearch($criteria, $orderBy, $limit, $offset, $adminMode);
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
        return $this->queryService->fullTextSearch($searchTerm, $fields, $limit, $offset, $useWildcards);
    }

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
        return $this->queryService->getProductsByCategory(
            $categoryId, $includeSubcategories, $limit, $offset, $publishedOnly
        );
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
        return $this->queryService->getProductsByCategories(
            $categoryIds, $operator, $limit, $offset, $publishedOnly
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getUncategorizedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getUncategorizedProducts($limit, $offset, $publishedOnly);
    }

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
        return $this->queryService->getProductsByMarketplace(
            $marketplaceId, $activeLinksOnly, $limit, $offset, $publishedOnly
        );
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
        return $this->queryService->getProductsByMarketplaces(
            $marketplaceIds, $operator, $activeLinksOnly, $limit, $offset, $publishedOnly
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsWithoutLinks(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getProductsWithoutLinks($limit, $offset, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getPublishedProducts(
        ?int $limit = null, 
        int $offset = 0, 
        array $orderBy = ['published_at' => 'DESC']
    ): array {
        return $this->queryService->getPublishedProducts($limit, $offset, $orderBy);
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
        return $this->queryService->getProductsByStatus($status, $limit, $offset, $includeRelations, $adminMode);
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
        return $this->queryService->getProductsByStatuses($statuses, $operator, $limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getDraftProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        return $this->queryService->getDraftProducts($limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getPendingVerificationProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        return $this->queryService->getPendingVerificationProducts($limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getVerifiedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        return $this->queryService->getVerifiedProducts($limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getArchivedProducts(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        return $this->queryService->getArchivedProducts($limit, $offset, $includeRelations);
    }

    /**
     * {@inheritDoc}
     */
    public function getPopularProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        return $this->queryService->getPopularProducts($limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getTrendingProducts(int $limit = 10, string $period = 'week'): array
    {
        return $this->queryService->getTrendingProducts($limit, $period);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecentlyAddedProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        return $this->queryService->getRecentlyAddedProducts($limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecentlyUpdatedProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        return $this->queryService->getRecentlyUpdatedProducts($limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getFeaturedProducts(int $limit = 5): array
    {
        return $this->queryService->getFeaturedProducts($limit);
    }

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
        return $this->queryService->getProductsByPriceRange($minPrice, $maxPrice, $limit, $offset, $publishedOnly);
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
        return $this->queryService->getProductsBelowPrice($price, $limit, $offset, $publishedOnly);
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
        return $this->queryService->getProductsAbovePrice($price, $limit, $offset, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getCheapestProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        return $this->queryService->getCheapestProducts($limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getMostExpensiveProducts(int $limit = 10, bool $publishedOnly = true): array
    {
        return $this->queryService->getMostExpensiveProducts($limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsNeedingPriceUpdate(
        int $daysThreshold = 7,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getProductsNeedingPriceUpdate($daysThreshold, $limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsNeedingLinkValidation(
        int $daysThreshold = 14,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getProductsNeedingLinkValidation($daysThreshold, $limit, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsWithMissingImages(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getProductsWithMissingImages($limit, $offset, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsWithMissingDescriptions(
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getProductsWithMissingDescriptions($limit, $offset, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductsWithOutdatedInfo(
        int $daysThreshold = 30,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        return $this->queryService->getProductsWithOutdatedInfo($daysThreshold, $limit, $publishedOnly);
    }

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
        return $this->queryService->getProductsWithBadges($badgeIds, $operator, $limit, $offset, $publishedOnly);
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
        return $this->queryService->getProductsWithMarketplaceBadges(
            $marketplaceBadgeIds, $operator, $limit, $offset, $publishedOnly
        );
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
        return $this->queryService->getProductsVerifiedBy($adminId, $limit, $offset, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductRecommendations(
        int $currentProductId,
        int $limit = 4,
        array $criteria = ['category', 'popular']
    ): array {
        return $this->queryService->getProductRecommendations($currentProductId, $limit, $criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function getSimilarProducts(
        int $productId,
        int $limit = 4,
        array $similarityFactors = ['category', 'price_range']
    ): array {
        return $this->queryService->getSimilarProducts($productId, $limit, $similarityFactors);
    }

    /**
     * {@inheritDoc}
     */
    public function getFrequentlyBoughtTogether(int $productId, int $limit = 3): array
    {
        return $this->queryService->getFrequentlyBoughtTogether($productId, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function getCrossSellProducts(int $productId, int $limit = 4): array
    {
        return $this->queryService->getCrossSellProducts($productId, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function getUpsellProducts(int $productId, int $limit = 4): array
    {
        return $this->queryService->getUpsellProducts($productId, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function countProducts(array $criteria = [], bool $includeArchived = false): int
    {
        return $this->queryService->countProducts($criteria, $includeArchived);
    }

    /**
     * {@inheritDoc}
     */
    public function countProductsByStatus(?ProductStatus $status = null, bool $includeArchived = false)
    {
        return $this->queryService->countProductsByStatus($status, $includeArchived);
    }

    /**
     * {@inheritDoc}
     */
    public function countProductsByCategory(?int $categoryId = null, bool $publishedOnly = false)
    {
        return $this->queryService->countProductsByCategory($categoryId, $publishedOnly);
    }

    /**
     * {@inheritDoc}
     */
    public function getPriceStatistics(array $criteria = []): array
    {
        return $this->queryService->getPriceStatistics($criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function getViewStatistics(array $criteria = []): array
    {
        return $this->queryService->getViewStatistics($criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function clearQueryCaches(array $patterns = []): int
    {
        return $this->queryService->clearQueryCaches($patterns);
    }

    /**
     * {@inheritDoc}
     */
    public function warmQueryCaches(array $queryConfigs = []): array
    {
        return $this->queryService->warmQueryCaches($queryConfigs);
    }

    /**
     * {@inheritDoc}
     */
    public function getQueryCacheStatistics(): array
    {
        return $this->queryService->getQueryCacheStatistics();
    }

    /**
     * {@inheritDoc}
     */
    public function generateQueryCacheKey(string $operation, array $parameters = []): string
    {
        return $this->queryService->generateQueryCacheKey($operation, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function validateQueryParameters(array $parameters, string $context = 'search'): array
    {
        return $this->queryService->validateQueryParameters($parameters, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function buildFilterSummary(ProductQuery $query): array
    {
        return $this->queryService->buildFilterSummary($query);
    }

    /**
     * {@inheritDoc}
     */
    public function getQueryExecutionPlan(ProductQuery $query, bool $explain = false): array
    {
        return $this->queryService->getQueryExecutionPlan($query, $explain);
    }

    /**
     * {@inheritDoc}
     */
    public function batchQuery(array $queries, bool $parallel = false): array
    {
        return $this->queryService->batchQuery($queries, $parallel);
    }

    /**
     * {@inheritDoc}
     */
    public function streamQueryResults(ProductQuery $query, callable $callback, int $batchSize = 100): int
    {
        return $this->queryService->streamQueryResults($query, $callback, $batchSize);
    }

    /**
     * {@inheritDoc}
     */
    public function getQueryPerformanceMetrics(ProductQuery $query): array
    {
        return $this->queryService->getQueryPerformanceMetrics($query);
    }

    // ==================== BULK OPERATIONS DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function bulkAction(ProductBulkActionRequest $request): BulkActionResult
    {
        return $this->bulkService->bulkAction($request);
    }

    /**
     * {@inheritDoc}
     */
    public function executeBulkWithCallback(
        array $productIds,
        callable $itemCallback,
        array $context = [],
        bool $useTransaction = true,
        int $batchSize = 100
    ): array {
        return $this->bulkService->executeBulkWithCallback(
            $productIds, $itemCallback, $context, $useTransaction, $batchSize
        );
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(
        array $productIds, 
        ProductStatus $status, 
        int $adminId, 
        array $parameters = []
    ): array {
        return $this->bulkService->bulkUpdateStatus($productIds, $status, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkPublish(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkPublish($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkVerify(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkVerify($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $productIds, int $adminId, ?string $reason = null, array $parameters = []): array
    {
        return $this->bulkService->bulkArchive($productIds, $adminId, $reason, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkRestore($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkDelete($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRequestVerification(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkRequestVerification($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRevertToDraft(array $productIds, int $adminId, ?string $reason = null): array
    {
        return $this->bulkService->bulkRevertToDraft($productIds, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateData(array $productIds, array $data, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkUpdateData($productIds, $data, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdatePrices(array $priceUpdates, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkUpdatePrices($priceUpdates, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateCategories(array $productIds, int $categoryId, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkUpdateCategories($productIds, $categoryId, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkAssignBadges(array $productIds, array $badgeIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkAssignBadges($productIds, $badgeIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRemoveBadges(array $productIds, array $badgeIds, int $adminId): array
    {
        return $this->bulkService->bulkRemoveBadges($productIds, $badgeIds, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateImages(
        array $productIds, 
        string $imageUrl, 
        string $sourceType, 
        int $adminId
    ): array {
        return $this->bulkService->bulkUpdateImages($productIds, $imageUrl, $sourceType, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkImport(array $productsData, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkImport($productsData, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkExport(array $productIds, array $parameters = []): array
    {
        return $this->bulkService->bulkExport($productIds, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkClone(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkClone($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkMerge(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkMerge($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkMarkPricesChecked(array $productIds, int $adminId): array
    {
        return $this->bulkService->bulkMarkPricesChecked($productIds, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkMarkLinksChecked(array $productIds, int $adminId): array
    {
        return $this->bulkService->bulkMarkLinksChecked($productIds, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRegenerateSlugs(array $productIds, int $adminId, array $parameters = []): array
    {
        return $this->bulkService->bulkRegenerateSlugs($productIds, $adminId, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateMetadata(
        array $productIds, 
        array $metadata, 
        int $adminId, 
        string $merge_strategy = 'merge'
    ): array {
        return $this->bulkService->bulkUpdateMetadata($productIds, $metadata, $adminId, $merge_strategy);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkCleanup(array $parameters = []): array
    {
        return $this->bulkService->bulkCleanup($parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function validateBulkAction(
        array $productIds, 
        ProductBulkActionType $action, 
        array $parameters = []
    ): array {
        return $this->bulkService->validateBulkAction($productIds, $action, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function preflightCheck(array $productIds, string $operation, array $parameters = []): array
    {
        return $this->bulkService->preflightCheck($productIds, $operation, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function estimateResourceRequirements(
        array $productIds, 
        string $operation, 
        array $parameters = []
    ): array {
        return $this->bulkService->estimateResourceRequirements($productIds, $operation, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function checkBulkDeletionDependencies(array $productIds, bool $hardDelete = false): array
    {
        return $this->bulkService->checkBulkDeletionDependencies($productIds, $hardDelete);
    }

    /**
     * {@inheritDoc}
     */
    public function startBackgroundBulkJob(ProductBulkActionRequest $request): array
    {
        return $this->bulkService->startBackgroundBulkJob($request);
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkJobStatus(string $jobId): array
    {
        return $this->bulkService->getBulkJobStatus($jobId);
    }

    /**
     * {@inheritDoc}
     */
    public function cancelBulkJob(string $jobId, int $adminId, ?string $reason = null): bool
    {
        return $this->bulkService->cancelBulkJob($jobId, $adminId, $reason);
    }

    /**
     * {@inheritDoc}
     */
    public function listBulkJobs(int $limit = 20, int $offset = 0, string $status = 'active'): array
    {
        return $this->bulkService->listBulkJobs($limit, $offset, $status);
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupBulkJobs(int $olderThanDays = 7): int
    {
        return $this->bulkService->cleanupBulkJobs($olderThanDays);
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkStatistics(string $period = 'month'): array
    {
        return $this->bulkService->getBulkStatistics($period);
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkPerformanceMetrics(int $limit = 10): array
    {
        return $this->bulkService->getBulkPerformanceMetrics($limit);
    }

    /**
     * {@inheritDoc}
     */
    public function generateBulkReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        string $format = 'json'
    ): array {
        return $this->bulkService->generateBulkReport($startDate, $endDate, $format);
    }

    /**
     * {@inheritDoc}
     */
    public function retryFailedBulkItems(
        string $jobId, 
        array $itemIds, 
        int $adminId, 
        array $retryParameters = []
    ): array {
        return $this->bulkService->retryFailedBulkItems($jobId, $itemIds, $adminId, $retryParameters);
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkErrorDetails(string $jobId, array $itemIds = []): array
    {
        return $this->bulkService->getBulkErrorDetails($jobId, $itemIds);
    }

    /**
     * {@inheritDoc}
     */
    public function createRecoveryPlan(string $jobId): array
    {
        return $this->bulkService->createRecoveryPlan($jobId);
    }

    /**
     * {@inheritDoc}
     */
    public function executeRecoveryPlan(string $jobId, array $recoverySteps, int $adminId): array
    {
        return $this->bulkService->executeRecoveryPlan($jobId, $recoverySteps, $adminId);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkClearCaches(array $productIds, array $parameters = []): array
    {
        return $this->bulkService->bulkClearCaches($productIds, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function bulkWarmCaches(array $productIds, array $parameters = []): array
    {
        return $this->bulkService->bulkWarmCaches($productIds, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function calculateOptimalBatchSize(
        string $operation, 
        int $totalItems, 
        array $constraints = []
    ): array {
        return $this->bulkService->calculateOptimalBatchSize($operation, $totalItems, $constraints);
    }

    /**
     * {@inheritDoc}
     */
    public function createOptimizedBatches(array $productIds, string $operation, array $parameters = []): array
    {
        return $this->bulkService->createOptimizedBatches($productIds, $operation, $parameters);
    }

    /**
     * {@inheritDoc}
     */
    public function executeWithOptimizedBatching(
        array $productIds,
        callable $batchCallback,
        array $optimizationParameters = []
    ): array {
        return $this->bulkService->executeWithOptimizedBatching($productIds, $batchCallback, $optimizationParameters);
    }

    // ==================== MAINTENANCE OPERATIONS DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function clearAllProductCaches(): array
    {
        return $this->maintenanceService->clearAllProductCaches();
    }

    /**
     * {@inheritDoc}
     */
    public function clearProductCache(int $productId, array $options = []): bool
    {
        return $this->maintenanceService->clearProductCache($productId, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function clearCacheMatching(string $pattern, array $options = []): array
    {
        return $this->maintenanceService->clearCacheMatching($pattern, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function warmProductCaches(array $productIds, array $options = []): array
    {
        return $this->maintenanceService->warmProductCaches($productIds, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheStatistics(): array
    {
        return $this->maintenanceService->getCacheStatistics();
    }

    /**
     * {@inheritDoc}
     */
    public function optimizeCacheConfiguration(array $constraints = []): array
    {
        return $this->maintenanceService->optimizeCacheConfiguration($constraints);
    }

    /**
     * {@inheritDoc}
     */
    public function preloadFrequentProductCache(array $criteria = []): array
    {
        return $this->maintenanceService->preloadFrequentProductCache($criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductStatistics(string $period = 'month', array $options = []): array
    {
        return $this->maintenanceService->getProductStatistics($period, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getDashboardStatistics(array $options = []): array
    {
        return $this->maintenanceService->getDashboardStatistics($options);
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceMetrics(string $period = 'week'): array
    {
        return $this->maintenanceService->getPerformanceMetrics($period);
    }

    /**
     * {@inheritDoc}
     */
    public function getBusinessIntelligenceData(
        array $dimensions = [], 
        array $metrics = [], 
        array $filters = []
    ): array {
        return $this->maintenanceService->getBusinessIntelligenceData($dimensions, $metrics, $filters);
    }

    /**
     * {@inheritDoc}
     */
    public function calculateProductHealthScore(int $productId): array
    {
        return $this->maintenanceService->calculateProductHealthScore($productId);
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemHealthStatus(): array
    {
        return $this->maintenanceService->getSystemHealthStatus();
    }

    /**
     * {@inheritDoc}
     */
    public function runDatabaseMaintenance(array $tasks = []): array
    {
        return $this->maintenanceService->runDatabaseMaintenance($tasks);
    }

    /**
     * {@inheritDoc}
     */
    public function validateDataIntegrity(array $options = []): array
    {
        return $this->maintenanceService->validateDataIntegrity($options);
    }

    /**
     * {@inheritDoc}
     */
    public function rebuildProductData(array $options = []): array
    {
        return $this->maintenanceService->rebuildProductData($options);
    }

    /**
     * {@inheritDoc}
     */
    public function archiveOldProducts(array $criteria = []): array
    {
        return $this->maintenanceService->archiveOldProducts($criteria);
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupProductData(array $options = []): array
    {
        return $this->maintenanceService->cleanupProductData($options);
    }

    /**
     * {@inheritDoc}
     */
    public function importProducts($source, array $options = []): array
    {
        return $this->maintenanceService->importProducts($source, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function exportProducts(array $criteria = [], array $options = []): array
    {
        return $this->maintenanceService->exportProducts($criteria, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function generateImportTemplate(string $format = 'csv', array $options = []): array
    {
        return $this->maintenanceService->generateImportTemplate($format, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function validateImportData($data, string $format = 'csv', array $options = []): array
    {
        return $this->maintenanceService->validateImportData($data, $format, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function scheduleImportExportJob(array $config, array $options = []): array
    {
        return $this->maintenanceService->scheduleImportExportJob($config, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function createBackup(array $options = []): array
    {
        return $this->maintenanceService->createBackup($options);
    }

    /**
     * {@inheritDoc}
     */
    public function restoreBackup($backup, array $options = []): array
    {
        return $this->maintenanceService->restoreBackup($backup, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function listBackups(array $options = []): array
    {
        return $this->maintenanceService->listBackups($options);
    }

    /**
     * {@inheritDoc}
     */
    public function verifyBackup($backup): array
    {
        return $this->maintenanceService->verifyBackup($backup);
    }

    /**
     * {@inheritDoc}
     */
    public function createDisasterRecoveryPlan(): array
    {
        return $this->maintenanceService->createDisasterRecoveryPlan();
    }

    /**
     * {@inheritDoc}
     */
    public function reindexProductSearch(array $options = []): array
    {
        return $this->maintenanceService->reindexProductSearch($options);
    }

    /**
     * {@inheritDoc}
     */
    public function recalculateAggregates(array $options = []): array
    {
        return $this->maintenanceService->recalculateAggregates($options);
    }

    /**
     * {@inheritDoc}
     */
    public function synchronizeWithExternalSystems(array $systems = [], array $options = []): array
    {
        return $this->maintenanceService->synchronizeWithExternalSystems($systems, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function generateReport(string $report_type, array $parameters = [], array $options = []): array
    {
        return $this->maintenanceService->generateReport($report_type, $parameters, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function migrateProductData(array $migration_config, array $options = []): array
    {
        return $this->maintenanceService->migrateProductData($migration_config, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function setupMonitoring(array $monitors = [], array $options = []): array
    {
        return $this->maintenanceService->setupMonitoring($monitors, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemAlerts(array $options = []): array
    {
        return $this->maintenanceService->getSystemAlerts($options);
    }

    /**
     * {@inheritDoc}
     */
    public function acknowledgeAlert(string $alert_id, array $options = []): bool
    {
        return $this->maintenanceService->acknowledgeAlert($alert_id, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getMonitoringDashboard(): array
    {
        return $this->maintenanceService->getMonitoringDashboard();
    }

    /**
     * {@inheritDoc}
     */
    public function runDiagnostics(array $tests = [], array $options = []): array
    {
        return $this->maintenanceService->runDiagnostics($tests, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function debugProduct(int $productId, array $options = []): array
    {
        return $this->maintenanceService->debugProduct($productId, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductLogs(array $filters = [], array $options = [])
    {
        return $this->maintenanceService->getProductLogs($filters, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function generateHealthReport(array $options = []): array
    {
        return $this->maintenanceService->generateHealthReport($options);
    }

    /**
     * {@inheritDoc}
     */
    public function updateSystemConfiguration(array $config, array $options = []): array
    {
        return $this->maintenanceService->updateSystemConfiguration($config, $options);
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemConfiguration(array $options = []): array
    {
        return $this->maintenanceService->getSystemConfiguration($options);
    }

    /**
     * {@inheritDoc}
     */
    public function resetConfiguration(array $options = []): array
    {
        return $this->maintenanceService->resetConfiguration($options);
    }

    // ==================== ORCHESTRATOR-SPECIFIC METHODS ====================

    /**
     * Get orchestrator call count (for metrics)
     * 
     * @return int
     */
    private function getOrchestratorCallCount(): int
    {
        // This would track calls through orchestrator
        // Implementation would use a counter or cache
        return 0;
    }

    /**
     * Get service composition status
     * 
     * @return array{
     *     crud_service: string,
     *     workflow_service: string,
     *     query_service: string,
     *     bulk_service: string,
     *     maintenance_service: string,
     *     all_services_ready: bool
     * }
     */
    public function getServiceComposition(): array
    {
        return [
            'crud_service' => get_class($this->crudService),
            'workflow_service' => get_class($this->workflowService),
            'query_service' => get_class($this->queryService),
            'bulk_service' => get_class($this->bulkService),
            'maintenance_service' => get_class($this->maintenanceService),
            'all_services_ready' => $this->isReady(),
        ];
    }

    /**
     * Route method to appropriate service based on operation type
     * 
     * @param string $method
     * @param array $parameters
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function routeToService(string $method, array $parameters = [])
    {
        $methodMap = $this->getMethodServiceMap();
        
        if (!isset($methodMap[$method])) {
            throw new \BadMethodCallException("Method {$method} not found in any service");
        }
        
        $serviceType = $methodMap[$method];
        $service = $this->getServiceByType($serviceType);
        
        return call_user_func_array([$service, $method], $parameters);
    }

    /**
     * Get mapping of methods to services
     * 
     * @return array<string, string>
     */
    private function getMethodServiceMap(): array
    {
        // This would be generated based on interface analysis
        // For simplicity, we'll return a basic map
        return [
            // CRUD methods
            'createProduct' => 'crud',
            'getProduct' => 'crud',
            'getProductBySlug' => 'crud',
            'updateProduct' => 'crud',
            'deleteProduct' => 'crud',
            'restoreProduct' => 'crud',
            'quickEditProduct' => 'crud',
            
            // Workflow methods
            'publishProduct' => 'workflow',
            'verifyProduct' => 'workflow',
            'archiveProduct' => 'workflow',
            'toggleProductStatus' => 'workflow',
            
            // Query methods
            'listProducts' => 'query',
            'searchProducts' => 'query',
            'getPublishedProducts' => 'query',
            'getPopularProducts' => 'query',
            
            // Bulk methods
            'bulkAction' => 'bulk',
            'bulkUpdateStatus' => 'bulk',
            'bulkPublish' => 'bulk',
            
            // Maintenance methods
            'clearAllProductCaches' => 'maintenance',
            'getProductStatistics' => 'maintenance',
            'importProducts' => 'maintenance',
        ];
    }

    /**
     * Get service by type
     * 
     * @param string $type
     * @return object
     * @throws \InvalidArgumentException
     */
    private function getServiceByType(string $type): object
    {
        return match($type) {
            'crud' => $this->crudService,
            'workflow' => $this->workflowService,
            'query' => $this->queryService,
            'bulk' => $this->bulkService,
            'maintenance' => $this->maintenanceService,
            default => throw new \InvalidArgumentException("Unknown service type: {$type}"),
        };
    }
}