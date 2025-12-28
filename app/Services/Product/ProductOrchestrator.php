<?php

namespace App\Services\Product;

use App\Contracts\Product\ProductOrchestratorInterface;
use App\Contracts\ProductCRUDInterface;
use App\Contracts\ProductWorkflowInterface;
use App\Contracts\ProductQueryInterface;
use App\Contracts\ProductBulkInterface;
use App\DTOs\Requests\Product\CreateProductRequest;
use App\DTOs\Requests\Product\UpdateProductRequest;
use App\DTOs\Requests\Product\ProductDeleteRequest;
use App\DTOs\Requests\Product\PublishProductRequest;
use App\DTOs\Requests\Product\ProductQuickEditRequest;
use App\DTOs\Requests\Product\ProductBulkActionRequest;
use App\DTOs\Requests\Product\ProductToggleStatusRequest;
use App\DTOs\Queries\ProductQuery;
use App\DTOs\Responses\ProductResponse;
use App\DTOs\Responses\ProductDetailResponse;
use App\DTOs\Responses\BulkActionResult;

class ProductOrchestrator implements ProductOrchestratorInterface
{
    public function __construct(
        private readonly ProductCRUDInterface $crudService,
        private readonly ProductWorkflowInterface $workflowService,
        private readonly ProductQueryInterface $queryService,
        private readonly ProductBulkInterface $bulkService
    ) {
        // Constructor injection only, no logic
    }
    
    // ========== CRUD OPERATIONS ==========
    public function createProduct(CreateProductRequest $request): ProductResponse
    {
        return $this->crudService->createProduct($request);
    }
    
    public function getProduct(int $productId, bool $adminMode = false): ProductResponse
    {
        return $this->crudService->getProduct($productId, $adminMode);
    }
    
    public function getProductBySlug(string $slug, bool $incrementViewCount = true): ProductDetailResponse
    {
        return $this->crudService->getProductBySlug($slug, $incrementViewCount);
    }
    
    public function updateProduct(UpdateProductRequest $request): ProductResponse
    {
        return $this->crudService->updateProduct($request);
    }
    
    public function deleteProduct(ProductDeleteRequest $request): bool
    {
        return $this->crudService->deleteProduct($request);
    }
    
    public function restoreProduct(int $productId, int $adminId): ProductResponse
    {
        return $this->crudService->restoreProduct($productId, $adminId);
    }
    
    public function quickEditProduct(ProductQuickEditRequest $request): ProductResponse
    {
        return $this->crudService->quickEditProduct($request);
    }
    
    // ========== WORKFLOW OPERATIONS ==========
    public function publishProduct(PublishProductRequest $request): ProductResponse
    {
        return $this->workflowService->publishProduct($request);
    }
    
    public function verifyProduct(int $productId, int $adminId, ?string $notes = null): ProductResponse
    {
        return $this->workflowService->verifyProduct($productId, $adminId, $notes);
    }
    
    public function requestVerification(int $productId, int $adminId): ProductResponse
    {
        return $this->workflowService->requestVerification($productId, $adminId);
    }
    
    public function archiveProduct(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        return $this->workflowService->archiveProduct($productId, $adminId, $reason);
    }
    
    public function unarchiveProduct(int $productId, int $adminId): ProductResponse
    {
        return $this->workflowService->unarchiveProduct($productId, $adminId);
    }
    
    public function toggleProductStatus(ProductToggleStatusRequest $request): ProductResponse
    {
        return $this->workflowService->toggleProductStatus($request);
    }
    
    public function revertToDraft(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        return $this->workflowService->revertToDraft($productId, $adminId, $reason);
    }
    
    // ========== QUERY OPERATIONS ==========
    public function listProducts(ProductQuery $query, bool $adminMode = false): array
    {
        return $this->queryService->listProducts($query, $adminMode);
    }
    
    public function searchProducts(string $keyword, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->queryService->searchProducts($keyword, $filters, $limit, $offset);
    }
    
    public function getProductsByStatus(string $status, int $limit = 20, int $offset = 0): array
    {
        return $this->queryService->getProductsByStatus($status, $limit, $offset);
    }
    
    public function countProductsByStatus(string $status): int
    {
        return $this->queryService->countProductsByStatus($status);
    }
    
    // ========== BULK OPERATIONS ==========
    public function bulkAction(ProductBulkActionRequest $request): BulkActionResult
    {
        return $this->bulkService->bulkAction($request);
    }
    
    // ========== HEALTH/STATUS ==========
    public function getServiceHealth(): array
    {
        return [
            'crud' => $this->crudService->getServiceName(),
            'workflow' => $this->workflowService->getServiceName(),
            'query' => $this->queryService->getServiceName(),
            'bulk' => $this->bulkService->getServiceName(),
            'status' => 'operational'
        ];
    }
    
    // HAPUS SEMUA METHOD LAIN YANG TIDAK ADA DI INTERFACE!
    // NO TRANSACTION METHODS, NO CACHE METHODS, NO VALIDATION METHODS
}