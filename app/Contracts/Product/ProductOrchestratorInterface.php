<?php

namespace App\Contracts\Product;

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

interface ProductOrchestratorInterface
{
    // ========== CRUD OPERATIONS ==========
    public function createProduct(CreateProductRequest $request): ProductResponse;
    public function getProduct(int $productId, bool $adminMode = false): ProductResponse;
    public function getProductBySlug(string $slug, bool $incrementViewCount = true): ProductDetailResponse;
    public function updateProduct(UpdateProductRequest $request): ProductResponse;
    public function deleteProduct(ProductDeleteRequest $request): bool;
    public function restoreProduct(int $productId, int $adminId): ProductResponse;
    public function quickEditProduct(ProductQuickEditRequest $request): ProductResponse;
    
    // ========== WORKFLOW OPERATIONS ==========
    public function publishProduct(PublishProductRequest $request): ProductResponse;
    public function verifyProduct(int $productId, int $adminId, ?string $notes = null): ProductResponse;
    public function requestVerification(int $productId, int $adminId): ProductResponse;
    public function archiveProduct(int $productId, int $adminId, ?string $reason = null): ProductResponse;
    public function unarchiveProduct(int $productId, int $adminId): ProductResponse;
    public function toggleProductStatus(ProductToggleStatusRequest $request): ProductResponse;
    public function revertToDraft(int $productId, int $adminId, ?string $reason = null): ProductResponse;
    
    // ========== QUERY OPERATIONS ==========
    public function listProducts(ProductQuery $query, bool $adminMode = false): array;
    public function searchProducts(string $keyword, array $filters = [], int $limit = 20, int $offset = 0): array;
    public function getProductsByStatus(string $status, int $limit = 20, int $offset = 0): array;
    public function countProductsByStatus(string $status): int;
    
    // ========== BULK OPERATIONS ==========
    public function bulkAction(ProductBulkActionRequest $request): BulkActionResult;
    
    // ========== HEALTH/STATUS ==========
    public function getServiceHealth(): array;
}