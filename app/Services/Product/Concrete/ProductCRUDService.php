<?php

namespace App\Services\Product\Concrete;

use App\Services\BaseService;
use App\Contracts\ProductCRUDInterface;
use App\DTOs\Requests\Product\CreateProductRequest;
use App\DTOs\Requests\Product\UpdateProductRequest;
use App\DTOs\Requests\Product\ProductDeleteRequest;
use App\DTOs\Requests\Product\ProductQuickEditRequest;
use App\DTOs\Responses\ProductResponse;
use App\DTOs\Responses\ProductDetailResponse;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Validators\ProductValidator;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\CategoryNotFoundException;
use CodeIgniter\I18n\Time;

/**
 * ProductCRUDService - Concrete Implementation for Product CRUD Operations
 * Layer 5: Business Orchestrator (CRUD-specific)
 * Implements ONLY 10 methods from ProductCRUDInterface
 * 
 * @package App\Services\Product\Concrete
 */
class ProductCRUDService extends BaseService implements ProductCRUDInterface
{
    private ProductRepositoryInterface $productRepository;
    private CategoryRepositoryInterface $categoryRepository;
    private LinkRepositoryInterface $linkRepository;
    private AuditLogRepositoryInterface $auditLogRepository;
    private ProductValidator $productValidator;
    
    private array $serviceStats = [
        'crud_operations' => 0,
        'cache_operations' => 0
    ];
    
    public function __construct(
        \CodeIgniter\Database\ConnectionInterface $db,
        \App\Contracts\CacheInterface $cache,
        \App\Services\AuditService $auditService,
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        LinkRepositoryInterface $linkRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        ProductValidator $productValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->linkRepository = $linkRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->productValidator = $productValidator;
    }
    
    // ==================== REQUIRED BY BASE SERVICE ====================
    
    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'ProductCRUDService';
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(\App\DTOs\BaseDTO $dto, array $context = []): array
    {
        // Implement validation based on DTO type
        if ($dto instanceof CreateProductRequest) {
            return $this->validateCreateBusinessRules($dto, $context);
        }
        
        if ($dto instanceof UpdateProductRequest) {
            return $this->validateUpdateBusinessRules($dto, $context);
        }
        
        if ($dto instanceof ProductDeleteRequest) {
            return $this->validateDeleteBusinessRules($dto, $context);
        }
        
        if ($dto instanceof ProductQuickEditRequest) {
            return $this->validateQuickEditBusinessRules($dto, $context);
        }
        
        return [];
    }
    
    // ==================== INTERFACE IMPLEMENTATION (10 METHODS) ====================
    
    /**
     * {@inheritDoc}
     */
    public function createProduct(CreateProductRequest $request): ProductResponse
    {
        $this->serviceStats['crud_operations']++;
        $this->authorize('product.create');
        
        // Validate input
        $validationResult = $this->productValidator->validateCreate(
            $request->toArray(),
            ['admin_id' => $this->getCurrentAdminId()]
        );
        
        if (!$validationResult['valid']) {
            throw ValidationException::forField(
                'product_create',
                'Product creation validation failed',
                $validationResult['errors']
            );
        }
        
        return $this->transaction(function() use ($request) {
            // Check category exists if provided
            if ($request->getCategoryId() !== null) {
                $category = $this->categoryRepository->findById($request->getCategoryId());
                if ($category === null) {
                    throw new CategoryNotFoundException("Category not found");
                }
            }
            
            // Create product entity
            $product = new Product($request->getName(), $request->getSlug());
            $product->setDescription($request->getDescription());
            $product->setCategoryId($request->getCategoryId());
            $product->setMarketPrice($request->getMarketPrice());
            $product->setImage($request->getImage());
            $product->setImageSourceType($request->getImageSourceType());
            $product->setImagePath($request->getImagePath());
            
            if ($request->getStatus() !== ProductStatus::DRAFT) {
                $product->setStatus($request->getStatus());
            }
            
            // Save product
            $savedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($savedProduct) {
                $this->invalidateProductCache($savedProduct->getId());
            });
            
            // Audit log
            $this->audit(
                'CREATE',
                'PRODUCT',
                $savedProduct->getId(),
                null,
                $savedProduct->toArray(),
                [
                    'created_by' => $this->getCurrentAdminId(),
                    'request_source' => 'create_product'
                ]
            );
            
            return ProductResponse::fromEntity($savedProduct, ['admin_mode' => true]);
        }, 'create_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProduct(
        int $productId, 
        bool $includeRelations = false, 
        bool $adminMode = false
    ): ProductDetailResponse {
        $this->serviceStats['crud_operations']++;
        
        if ($adminMode) {
            $this->authorize('product.view');
        }
        
        $cacheKey = $this->getServiceCacheKey('get_product', [
            'id' => $productId,
            'relations' => $includeRelations,
            'admin_mode' => $adminMode
        ]);
        
        return $this->withCaching($cacheKey, function() use ($productId, $includeRelations, $adminMode) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Check if admin can view (for admin mode)
            if ($adminMode && !$this->canAdminViewProduct($product)) {
                throw new AuthorizationException(
                    'You are not authorized to view this product',
                    'PRODUCT_VIEW_UNAUTHORIZED',
                    403
                );
            }
            
            $relations = [];
            if ($includeRelations) {
                $relations = $this->loadProductRelations($product);
            }
            
            $config = [
                'admin_mode' => $adminMode,
                'include_trashed' => $adminMode,
                'relations' => $relations
            ];
            
            return ProductDetailResponse::fromEntityWithRelations($product, $relations, $config);
        }, $adminMode ? 300 : 1800);
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductBySlug(string $slug, bool $incrementViewCount = true): ProductDetailResponse
    {
        $this->serviceStats['crud_operations']++;
        
        $cacheKey = $this->getServiceCacheKey('get_product_by_slug', [
            'slug' => $slug,
            'increment' => $incrementViewCount
        ]);
        
        return $this->withCaching($cacheKey, function() use ($slug, $incrementViewCount) {
            $product = $this->productRepository->findBySlug($slug, true);
            
            if ($product === null || !$product->isPublished()) {
                throw ProductNotFoundException::forSlug($slug);
            }
            
            // Increment view count if requested
            if ($incrementViewCount) {
                $this->transaction(function() use ($product) {
                    $this->productRepository->incrementViewCount($product->getId());
                    
                    // Queue cache invalidation
                    $this->queueCacheOperation(function() use ($product) {
                        $this->invalidateProductCache($product->getId());
                    });
                }, 'increment_view_count');
            }
            
            // Load basic relations for public view
            $relations = $this->loadProductRelations($product, ['category', 'links']);
            
            return ProductDetailResponse::fromEntityWithRelations($product, $relations, [
                'admin_mode' => false,
                'include_trashed' => false
            ]);
        }, 1800);
    }
    
    /**
     * {@inheritDoc}
     */
    public function updateProduct(UpdateProductRequest $request): ProductResponse
    {
        $this->serviceStats['crud_operations']++;
        $this->authorize('product.update');
        
        $validationResult = $this->productValidator->validateUpdate(
            $request->getProductId(),
            $request->toArray(),
            ['admin_id' => $this->getCurrentAdminId()]
        );
        
        if (!$validationResult['valid']) {
            throw ValidationException::forField(
                'product_update',
                'Product update validation failed',
                $validationResult['errors']
            );
        }
        
        return $this->transaction(function() use ($request) {
            $product = $this->productRepository->findById($request->getProductId());
            
            if ($product === null) {
                throw ProductNotFoundException::forId($request->getProductId());
            }
            
            $oldValues = $product->toArray();
            
            // Update fields if provided
            $this->applyProductUpdates($product, $request);
            
            // Save updated product
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($product) {
                $this->invalidateProductCache($product->getId());
            });
            
            // Audit log
            $this->audit(
                'UPDATE',
                'PRODUCT',
                $product->getId(),
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'updated_by' => $this->getCurrentAdminId(),
                    'changed_fields' => $request->getChangedFields()
                ]
            );
            
            return ProductResponse::fromEntity($updatedProduct, ['admin_mode' => true]);
        }, 'update_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function deleteProduct(ProductDeleteRequest $request): bool
    {
        $this->serviceStats['crud_operations']++;
        $this->authorize('product.delete');
        
        $validationResult = $this->productValidator->validateDelete(
            $request->getProductId(),
            $request->isHardDelete(),
            ['admin_id' => $this->getCurrentAdminId()]
        );
        
        if (!$validationResult['valid']) {
            throw ValidationException::forField(
                'product_delete',
                'Product deletion validation failed',
                $validationResult['errors']
            );
        }
        
        return $this->transaction(function() use ($request) {
            $product = $this->productRepository->findById($request->getProductId());
            
            if ($product === null) {
                throw ProductNotFoundException::forId($request->getProductId());
            }
            
            $oldValues = $product->toArray();
            
            // Perform deletion
            $success = $request->isHardDelete() 
                ? $this->productRepository->forceDelete($product->getId())
                : $this->productRepository->delete($product->getId());
            
            if ($success) {
                // Queue cache invalidation
                $this->queueCacheOperation(function() use ($product) {
                    $this->invalidateProductCache($product->getId());
                });
                
                // Audit log
                $this->audit(
                    $request->isHardDelete() ? 'HARD_DELETE' : 'SOFT_DELETE',
                    'PRODUCT',
                    $product->getId(),
                    $oldValues,
                    null,
                    [
                        'deleted_by' => $this->getCurrentAdminId(),
                        'reason' => $request->getReason(),
                        'cascade' => $request->isCascade(),
                        'hard_delete' => $request->isHardDelete()
                    ]
                );
            }
            
            return $success;
        }, 'delete_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function restoreProduct(int $productId, int $adminId): ProductResponse
    {
        $this->serviceStats['crud_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.restore');
        
        $validationResult = $this->productValidator->validateRestore($productId);
        
        if (!$validationResult['valid']) {
            throw ValidationException::forField(
                'product_restore',
                'Product restoration validation failed',
                $validationResult['errors']
            );
        }
        
        return $this->transaction(function() use ($productId, $adminId) {
            $product = $this->productRepository->findById($productId, false);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            if (!$product->isDeleted()) {
                throw new DomainException(
                    'Product is not deleted',
                    'PRODUCT_NOT_DELETED',
                    400
                );
            }
            
            // Restore product
            $success = $this->productRepository->restore($productId);
            
            if (!$success) {
                throw new DomainException(
                    'Failed to restore product',
                    'RESTORE_FAILED',
                    500
                );
            }
            
            // Refresh product data
            $restoredProduct = $this->productRepository->findById($productId);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'RESTORE',
                'PRODUCT',
                $productId,
                null,
                $restoredProduct->toArray(),
                [
                    'restored_by' => $adminId,
                    'previous_status' => $product->getStatus()->value
                ]
            );
            
            return ProductResponse::fromEntity($restoredProduct, ['admin_mode' => true]);
        }, 'restore_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function quickEditProduct(ProductQuickEditRequest $request): ProductResponse
    {
        $this->serviceStats['crud_operations']++;
        $this->setAdminContext($request->getUserId());
        $this->authorize('product.update.quick');
        
        return $this->transaction(function() use ($request) {
            $product = $this->productRepository->findById($request->getProductId());
            
            if ($product === null) {
                throw ProductNotFoundException::forId($request->getProductId());
            }
            
            $oldValues = $product->toArray();
            $changedFields = $this->applyQuickEdits($product, $request);
            
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($product) {
                $this->invalidateProductCache($product->getId());
            });
            
            // Audit log
            $this->audit(
                'QUICK_EDIT',
                'PRODUCT',
                $product->getId(),
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'edited_by' => $request->getUserId(),
                    'changed_fields' => $changedFields,
                    'quick_edit' => true
                ]
            );
            
            return ProductResponse::fromEntity($updatedProduct, ['admin_mode' => true]);
        }, 'quick_edit_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function canDeleteProduct(int $productId, bool $hardDelete = false): array
    {
        $this->serviceStats['crud_operations']++;
        
        $product = $this->productRepository->findById($productId);
        
        if ($product === null) {
            return [
                'can_delete' => false,
                'reasons' => ['Product not found'],
                'dependencies' => []
            ];
        }
        
        $reasons = [];
        $dependencies = [];
        
        // Check if product has active dependencies
        $links = $this->linkRepository->findByProduct($productId);
        if (!empty($links)) {
            $dependencies['links'] = count($links);
            
            if ($hardDelete) {
                $reasons[] = 'Product has associated marketplace links';
            }
        }
        
        // Check if product is published
        if ($product->isPublished() && $hardDelete) {
            $reasons[] = 'Published products cannot be hard deleted';
        }
        
        // Business rule: Products with high views should not be hard deleted
        if ($product->getViewCount() > 100 && $hardDelete) {
            $reasons[] = 'Popular products cannot be hard deleted';
        }
        
        return [
            'can_delete' => empty($reasons),
            'reasons' => $reasons,
            'dependencies' => $dependencies,
            'product_status' => $product->getStatus()->value,
            'view_count' => $product->getViewCount()
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function productToResponse(
        Product $product, 
        bool $adminMode = false, 
        array $relations = []
    ) {
        $config = [
            'admin_mode' => $adminMode,
            'include_trashed' => $adminMode
        ];
        
        if (!empty($relations)) {
            return ProductDetailResponse::fromEntityWithRelations($product, $relations, $config);
        }
        
        return ProductResponse::fromEntity($product, $config);
    }
    
    /**
     * {@inheritDoc}
     */
    public function productsToResponses(
        array $products, 
        bool $adminMode = false, 
        array $relations = []
    ): array {
        return array_map(
            fn($product) => $this->productToResponse($product, $adminMode, $relations),
            $products
        );
    }
    
    // ==================== PRIVATE HELPER METHODS ====================
    
    private function validateCreateBusinessRules(CreateProductRequest $dto, array $context): array
    {
        $errors = [];
        
        // Check daily product creation limit
        $adminId = $context['admin_id'] ?? $this->getCurrentAdminId();
        if ($adminId !== null) {
            $dailyLimit = 50;
            $todayCount = $this->productRepository->count([
                'created_by' => $adminId,
                'created_at >=' => (new Time('today'))->toDateTimeString()
            ]);
            
            if ($todayCount >= $dailyLimit) {
                $errors['daily_limit'] = "Daily product creation limit ({$dailyLimit}) exceeded";
            }
        }
        
        // Check unique slug
        $existing = $this->productRepository->findBySlug($dto->getSlug(), false);
        if ($existing !== null) {
            $errors['slug'] = "Slug '{$dto->getSlug()}' already exists";
        }
        
        return $errors;
    }
    
    private function validateUpdateBusinessRules(UpdateProductRequest $dto, array $context): array
    {
        $errors = [];
        
        $product = $this->productRepository->findById($dto->getProductId());
        if ($product === null) {
            $errors['product'] = "Product not found";
            return $errors;
        }
        
        // Check unique slug if changing
        if ($dto->getSlug() !== null && $dto->getSlug() !== $product->getSlug()) {
            $existing = $this->productRepository->findBySlug($dto->getSlug(), false);
            if ($existing !== null && $existing->getId() !== $product->getId()) {
                $errors['slug'] = "Slug '{$dto->getSlug()}' already exists";
            }
        }
        
        // Business rule: Cannot change status from ARCHIVED without permission
        if ($dto->getStatus() !== null 
            && $product->getStatus() === ProductStatus::ARCHIVED
            && $dto->getStatus() !== ProductStatus::ARCHIVED) {
            
            $adminId = $context['admin_id'] ?? $this->getCurrentAdminId();
            try {
                $this->setAdminContext($adminId);
                $this->authorize('product.restore');
            } catch (AuthorizationException $e) {
                $errors['status'] = "Cannot change status from ARCHIVED without restore permission";
            }
        }
        
        return $errors;
    }
    
    private function validateDeleteBusinessRules(ProductDeleteRequest $dto, array $context): array
    {
        $errors = [];
        
        $product = $this->productRepository->findById($dto->getProductId());
        if ($product === null) {
            $errors['product'] = "Product not found";
        }
        
        return $errors;
    }
    
    private function validateQuickEditBusinessRules(ProductQuickEditRequest $dto, array $context): array
    {
        $errors = [];
        
        $product = $this->productRepository->findById($dto->getProductId());
        if ($product === null) {
            $errors['product'] = "Product not found";
        }
        
        return $errors;
    }
    
    private function applyProductUpdates(Product $product, UpdateProductRequest $request): void
    {
        if ($request->getName() !== null) {
            $product->setName($request->getName());
        }
        
        if ($request->getSlug() !== null) {
            $product->setSlug($request->getSlug());
        }
        
        if ($request->getDescription() !== null) {
            $product->setDescription($request->getDescription());
        }
        
        if ($request->getCategoryId() !== null) {
            $product->setCategoryId($request->getCategoryId());
        }
        
        if ($request->getMarketPrice() !== null) {
            $product->setMarketPrice($request->getMarketPrice());
        }
        
        if ($request->getImage() !== null) {
            $product->setImage($request->getImage());
        }
        
        if ($request->getImageSourceType() !== null) {
            $product->setImageSourceType($request->getImageSourceType());
        }
        
        if ($request->getStatus() !== null) {
            $product->setStatus($request->getStatus());
        }
    }
    
    private function applyQuickEdits(Product $product, ProductQuickEditRequest $request): array
    {
        $changedFields = [];
        
        if ($request->getName() !== null) {
            $product->setName($request->getName());
            $changedFields[] = 'name';
        }
        
        if ($request->getSlug() !== null) {
            $product->setSlug($request->getSlug());
            $changedFields[] = 'slug';
        }
        
        if ($request->getDescription() !== null) {
            $product->setDescription($request->getDescription());
            $changedFields[] = 'description';
        }
        
        if ($request->getPrice() !== null) {
            $product->setMarketPrice($request->getPrice());
            $changedFields[] = 'market_price';
        }
        
        if ($request->getStatus() !== null) {
            $product->setStatus($request->getStatus());
            $changedFields[] = 'status';
        }
        
        if ($request->getCategoryId() !== null) {
            $product->setCategoryId($request->getCategoryId());
            $changedFields[] = 'category_id';
        }
        
        return $changedFields;
    }
    
    private function loadProductRelations(Product $product, array $relationTypes = []): array
    {
        $relations = [];
        
        if (empty($relationTypes) || in_array('category', $relationTypes)) {
            if ($product->getCategoryId() !== null) {
                $category = $this->categoryRepository->findById($product->getCategoryId());
                if ($category !== null) {
                    $relations['category'] = $category;
                }
            }
        }
        
        if (in_array('links', $relationTypes)) {
            $links = $this->linkRepository->findByProduct($product->getId());
            $relations['links'] = $links;
        }
        
        return $relations;
    }
    
    private function canAdminViewProduct(Product $product): bool
    {
        try {
            $this->authorize('product.view');
            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }
    
    private function invalidateProductCache(int $productId): void
    {
        $this->serviceStats['cache_operations']++;
        
        try {
            $this->productRepository->clearEntityCache($productId);
            
            // Clear related caches
            $patterns = [
                $this->getServiceCacheKey('get_product:*' . $productId . '*'),
                "product_service:*{$productId}*",
            ];
            
            foreach ($patterns as $pattern) {
                $this->cache->deleteMatching($pattern);
            }
        } catch (\Throwable $e) {
            log_message('error', "Failed to clear cache for product {$productId}: " . $e->getMessage());
        }
    }
}