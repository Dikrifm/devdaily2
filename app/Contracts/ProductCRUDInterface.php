<?php

namespace App\Contracts;

use App\DTOs\Requests\Product\CreateProductRequest;
use App\DTOs\Requests\Product\UpdateProductRequest;
use App\DTOs\Requests\Product\ProductDeleteRequest;
use App\DTOs\Requests\Product\ProductQuickEditRequest;
use App\DTOs\Responses\ProductResponse;
use App\DTOs\Responses\ProductDetailResponse;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;

/**
 * ProductCRUDInterface - Contract for basic CRUD operations on Product
 * 
 * @package App\Contracts
 */
interface ProductCRUDInterface extends BaseInterface
{
    // ==================== CRUD OPERATIONS ====================

    /**
     * Create new product with business validation
     * 
     * @param CreateProductRequest $request
     * @return ProductResponse
     * @throws ValidationException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function createProduct(CreateProductRequest $request): ProductResponse;

    /**
     * Get product by ID with full business context
     * 
     * @param int $productId
     * @param bool $includeRelations Include category, links, etc.
     * @param bool $adminMode Return admin-only fields
     * @return ProductDetailResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     */
    public function getProduct(
        int $productId, 
        bool $includeRelations = false, 
        bool $adminMode = false
    ): ProductDetailResponse;

    /**
     * Get product by slug with public context
     * 
     * @param string $slug
     * @param bool $incrementViewCount
     * @return ProductDetailResponse
     * @throws ProductNotFoundException
     */
    public function getProductBySlug(string $slug, bool $incrementViewCount = true): ProductDetailResponse;

    /**
     * Update product with comprehensive business rules
     * 
     * @param UpdateProductRequest $request
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function updateProduct(UpdateProductRequest $request): ProductResponse;

    /**
     * Delete product with business validation
     * 
     * @param ProductDeleteRequest $request
     * @return bool
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function deleteProduct(ProductDeleteRequest $request): bool;

    /**
     * Restore soft-deleted product
     * 
     * @param int $productId
     * @param int $adminId
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function restoreProduct(int $productId, int $adminId): ProductResponse;

    /**
     * Quick edit product (limited fields)
     * 
     * @param ProductQuickEditRequest $request
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function quickEditProduct(ProductQuickEditRequest $request): ProductResponse;

    // ==================== VALIDATION & BUSINESS RULES ====================

    /**
     * Check if product can be deleted
     * 
     * @param int $productId
     * @param bool $hardDelete
     * @return array{can_delete: bool, reasons: array<string>, dependencies: array}
     */
    public function canDeleteProduct(int $productId, bool $hardDelete = false): array;

    /**
     * Convert Product entity to Response DTO
     * 
     * @param \App\Entities\Product $product
     * @param bool $adminMode
     * @param array $relations
     * @return ProductResponse|ProductDetailResponse
     */
    public function productToResponse(
        \App\Entities\Product $product, 
        bool $adminMode = false, 
        array $relations = []
    );

    /**
     * Convert array of Product entities to Response DTOs
     * 
     * @param array<\App\Entities\Product> $products
     * @param bool $adminMode
     * @param array $relations
     * @return array<ProductResponse>
     */
    public function productsToResponses(
        array $products, 
        bool $adminMode = false, 
        array $relations = []
    ): array;
}