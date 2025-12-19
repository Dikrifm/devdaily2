<?php

namespace App\Services;

use App\Entities\Admin;
use App\Entities\Category;
use App\Entities\Link;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Models\AdminModel;
use App\Models\CategoryModel;
use App\Models\LinkModel;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use CodeIgniter\Validation\Validation;
use DateTimeImmutable;

/**
 * Enterprise-grade Business Rule Validation Service
 *
 * Centralized validation for complex business rules, cross-entity validations,
 * and system constraints. Separates business logic validation from input validation.
 */
class ValidationService
{
    private ProductRepositoryInterface $productRepository;
    private CategoryModel $categoryModel;
    private LinkModel $linkModel;
    private AdminModel $adminModel;
    private Validation $validation;

    private array $config;

    // Validation contexts
    public const CONTEXT_CREATE = 'CREATE';
    public const CONTEXT_UPDATE = 'UPDATE';
    public const CONTEXT_PUBLISH = 'PUBLISH';
    public const CONTEXT_DELETE = 'DELETE';
    public const CONTEXT_RESTORE = 'RESTORE';
    public const CONTEXT_ARCHIVE = 'ARCHIVE';
    public const CONTEXT_VERIFY = 'VERIFY';
    public const CONTEXT_BULK = 'BULK';

    // Business rule error codes
    private const ERROR_CODE = 'BUSINESS_RULE_VIOLATION';

    // System constraints
    private const MAX_PRODUCTS_PER_DAY = 100;
    private const MAX_CATEGORIES_PER_PRODUCT = 5;
    private const MAX_LINKS_PER_PRODUCT = 10;
    private const MIN_PRICE = 100; // Rp 100
    private const MAX_PRICE = 1000000000; // Rp 1M
    private const MIN_PASSWORD_LENGTH = 8;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 2000;

    // Cache for validation results
    private array $validationCache = [];

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CategoryModel $categoryModel,
        LinkModel $linkModel,
        AdminModel $adminModel,
        Validation $validation,
        array $config = []
    ) {
        $this->productRepository = $productRepository;
        $this->categoryModel = $categoryModel;
        $this->linkModel = $linkModel;
        $this->adminModel = $adminModel;
        $this->validation = $validation;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Validate product creation with business rules
     */
    public function validateProductCreate(array $data, int $adminId, string $context = self::CONTEXT_CREATE): array
    {
        $errors = [];

        // 1. Basic field validation
        $errors = array_merge($errors, $this->validateProductFields($data, $context));

        // 2. Business rule validation
        $errors = array_merge($errors, $this->validateProductBusinessRules($data, $adminId, $context));

        // 3. System constraint validation
        $errors = array_merge($errors, $this->validateSystemConstraints($data, $adminId, $context));

        // 4. Cross-entity validation
        $errors = array_merge($errors, $this->validateProductRelations($data, $context));

        return $errors;
    }

    /**
     * Validate product update with business rules
     */
    public function validateProductUpdate(int $productId, array $data, int $adminId): array
    {
        $errors = [];

        // 1. Check if product exists and is editable
        $product = $this->productRepository->find($productId);

        if (!$product) {
            $errors[] = $this->createError(
                'product_not_found',
                'Product not found',
                ['product_id' => $productId]
            );
            return $errors;
        }

        // 2. Check if product can be edited in its current state
        if (!$product->getStatus()->canBePublished() && $product->getStatus() !== ProductStatus::DRAFT) {
            $errors[] = $this->createError(
                'invalid_product_state',
                sprintf('Product cannot be edited in %s state', $product->getStatus()->value),
                ['current_status' => $product->getStatus()->value]
            );
        }

        // 3. Validate fields based on what's being updated
        $errors = array_merge($errors, $this->validateProductFields($data, self::CONTEXT_UPDATE));

        // 4. Validate business rules for updates
        $errors = array_merge($errors, $this->validateUpdateBusinessRules($product, $data, $adminId));

        return $errors;
    }

    /**
     * Validate product publishing prerequisites
     */
    public function validateProductPublish(int $productId, int $adminId, bool $forcePublish = false): array
    {
        $errors = [];

        $product = $this->productRepository->find($productId);

        if (!$product) {
            $errors[] = $this->createError(
                'product_not_found',
                'Product not found',
                ['product_id' => $productId]
            );
            return $errors;
        }

        // Check current status
        if (!$product->getStatus()->canBePublished()) {
            $errors[] = $this->createError(
                'invalid_publish_state',
                sprintf('Product cannot be published from %s state', $product->getStatus()->value),
                ['current_status' => $product->getStatus()->value]
            );
        }

        // If not force publishing, validate all prerequisites
        if (!$forcePublish) {
            // 1. Validate required fields
            $requiredFields = ['name', 'slug', 'market_price', 'category_id'];
            foreach ($requiredFields as $field) {
                $getter = 'get' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($product, $getter)) {
                    $value = $product->$getter();
                    if (empty($value)) {
                        $errors[] = $this->createError(
                            'missing_required_field',
                            sprintf('Field %s is required for publishing', $field),
                            ['field' => $field]
                        );
                    }
                }
            }

            // 2. Validate category exists and is active
            if ($product->getCategoryId()) {
                $category = $this->categoryModel->find($product->getCategoryId());
                if (!$category || !$category->isActive()) {
                    $errors[] = $this->createError(
                        'invalid_category',
                        'Category is invalid or inactive',
                        ['category_id' => $product->getCategoryId()]
                    );
                }
            }

            // 3. Validate image requirements
            if (!$product->getImage() && $this->config['require_image_for_publish']) {
                $errors[] = $this->createError(
                    'missing_image',
                    'Product image is required for publishing',
                    []
                );
            }

            // 4. Validate at least one active link
            $activeLinks = $this->linkModel->findActiveByProduct($productId);
            if (empty($activeLinks) && $this->config['require_active_links_for_publish']) {
                $errors[] = $this->createError(
                    'no_active_links',
                    'At least one active affiliate link is required for publishing',
                    []
                );
            }

            // 5. Validate price is within reasonable range
            $price = (float) $product->getMarketPrice();
            if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                $errors[] = $this->createError(
                    'invalid_price_range',
                    sprintf(
                        'Price must be between %s and %s',
                        number_format(self::MIN_PRICE, 0),
                        number_format(self::MAX_PRICE, 0)
                    ),
                    [
                        'min_price' => self::MIN_PRICE,
                        'max_price' => self::MAX_PRICE,
                        'current_price' => $price
                    ]
                );
            }

            // 6. Validate slug uniqueness
            if (!$this->productRepository->isSlugUnique($product->getSlug(), $productId)) {
                $errors[] = $this->createError(
                    'duplicate_slug',
                    'Product slug must be unique',
                    ['slug' => $product->getSlug()]
                );
            }
        }

        // 7. Validate admin permissions
        $admin = $this->adminModel->find($adminId);
        if (!$admin || !$admin->isActive()) {
            $errors[] = $this->createError(
                'invalid_admin',
                'Admin is invalid or inactive',
                ['admin_id' => $adminId]
            );
        }

        return $errors;
    }

    /**
     * Validate product deletion
     */
    public function validateProductDelete(int $productId, int $adminId, bool $forceDelete = false): array
    {
        $errors = [];

        $product = $this->productRepository->find($productId, true); // Include trashed

        if (!$product) {
            $errors[] = $this->createError(
                'product_not_found',
                'Product not found',
                ['product_id' => $productId]
            );
            return $errors;
        }

        // Check if already deleted
        if ($product->isDeleted() && !$forceDelete) {
            $errors[] = $this->createError(
                'already_deleted',
                'Product is already deleted',
                ['product_id' => $productId]
            );
        }

        // Check if published product can be deleted
        if ($product->isPublished() && !$forceDelete && !$this->config['allow_delete_published']) {
            $errors[] = $this->createError(
                'cannot_delete_published',
                'Published products must be archived first',
                ['product_id' => $productId, 'status' => $product->getStatus()->value]
            );
        }

        // Check admin permissions
        $admin = $this->adminModel->find($adminId);
        if (!$admin || !$admin->isActive()) {
            $errors[] = $this->createError(
                'invalid_admin',
                'Admin is invalid or inactive',
                ['admin_id' => $adminId]
            );
        }

        // Check if product has active dependencies
        if (!$forceDelete) {
            $activeLinks = $this->linkModel->findActiveByProduct($productId);
            if (!empty($activeLinks) && $this->config['check_dependencies_before_delete']) {
                $errors[] = $this->createError(
                    'has_active_dependencies',
                    'Product has active affiliate links. Remove or deactivate them first.',
                    ['active_links_count' => count($activeLinks)]
                );
            }
        }

        return $errors;
    }

    /**
     * Validate category operations
     */
    public function validateCategoryOperation(int $categoryId, string $operation, ?array $data = null): array
    {
        $errors = [];

        $category = $this->categoryModel->find($categoryId);

        if (!$category && $operation !== self::CONTEXT_CREATE) {
            $errors[] = $this->createError(
                'category_not_found',
                'Category not found',
                ['category_id' => $categoryId]
            );
            return $errors;
        }

        switch ($operation) {
            case self::CONTEXT_CREATE:
                $errors = array_merge($errors, $this->validateCategoryFields($data ?? []));
                $errors = array_merge($errors, $this->validateCategoryUniqueness($data ?? []));
                break;

            case self::CONTEXT_UPDATE:
                $errors = array_merge($errors, $this->validateCategoryFields($data ?? []));
                $errors = array_merge($errors, $this->validateCategoryUniqueness($data ?? [], $categoryId));
                break;

            case self::CONTEXT_DELETE:
                if ($category->isInUse()) {
                    $errors[] = $this->createError(
                        'category_in_use',
                        'Category is in use by products',
                        ['category_id' => $categoryId]
                    );
                }
                break;

            case self::CONTEXT_ARCHIVE:
                if (!$category->isActive()) {
                    $errors[] = $this->createError(
                        'already_inactive',
                        'Category is already inactive',
                        ['category_id' => $categoryId]
                    );
                }
                break;
        }

        return $errors;
    }

    /**
     * Validate link operations
     */
    public function validateLinkOperation(array $linkData, string $operation, ?int $linkId = null): array
    {
        $errors = [];

        // Basic field validation
        $rules = [
            'product_id' => 'required|integer|greater_than[0]',
            'marketplace_id' => 'required|integer|greater_than[0]',
            'store_name' => 'required|max_length[100]',
            'price' => 'required|decimal|greater_than_equal_to[0]',
            'url' => 'valid_url|max_length[500]',
            'rating' => 'decimal|greater_than_equal_to[0]|less_than_equal_to[5]',
        ];

        $validation = $this->validation->setRules($rules);

        if (!$validation->run($linkData)) {
            foreach ($validation->getErrors() as $field => $error) {
                $errors[] = $this->createError(
                    'invalid_field',
                    $error,
                    ['field' => $field, 'value' => $linkData[$field] ?? null]
                );
            }
        }

        // Business rules
        if ($operation === self::CONTEXT_CREATE || $operation === self::CONTEXT_UPDATE) {
            // Check if product exists
            $product = $this->productRepository->find($linkData['product_id']);
            if (!$product) {
                $errors[] = $this->createError(
                    'invalid_product',
                    'Product does not exist',
                    ['product_id' => $linkData['product_id']]
                );
            }

            // Check maximum links per product
            $productLinks = $this->linkModel->findByProduct($linkData['product_id']);
            if (count($productLinks) >= self::MAX_LINKS_PER_PRODUCT && $operation === self::CONTEXT_CREATE) {
                $errors[] = $this->createError(
                    'max_links_reached',
                    sprintf('Maximum %s links per product reached', self::MAX_LINKS_PER_PRODUCT),
                    [
                        'current_links' => count($productLinks),
                        'max_links' => self::MAX_LINKS_PER_PRODUCT
                    ]
                );
            }

            // Validate price is reasonable compared to product market price
            if (isset($linkData['price']) && $product) {
                $linkPrice = (float) $linkData['price'];
                $marketPrice = (float) $product->getMarketPrice();

                // Price should not be more than 3x market price or less than 10% of market price
                if ($linkPrice > ($marketPrice * 3) || ($marketPrice > 0 && $linkPrice < ($marketPrice * 0.1))) {
                    $errors[] = $this->createError(
                        'unrealistic_price',
                        'Link price is unrealistic compared to product market price',
                        [
                            'link_price' => $linkPrice,
                            'market_price' => $marketPrice,
                            'min_allowed' => $marketPrice * 0.1,
                            'max_allowed' => $marketPrice * 3
                        ]
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Validate bulk operations
     */
    public function validateBulkOperation(array $entityIds, string $entityType, string $operation, int $adminId): array
    {
        $errors = [];

        // Check maximum batch size
        $maxBatchSize = $this->config['max_batch_size'] ?? 100;
        if (count($entityIds) > $maxBatchSize) {
            $errors[] = $this->createError(
                'batch_too_large',
                sprintf('Maximum %s items per batch allowed', $maxBatchSize),
                [
                    'batch_size' => count($entityIds),
                    'max_batch_size' => $maxBatchSize
                ]
            );
            return $errors;
        }

        // Check for duplicate IDs
        $uniqueIds = array_unique($entityIds);
        if (count($uniqueIds) !== count($entityIds)) {
            $errors[] = $this->createError(
                'duplicate_ids',
                'Duplicate IDs found in batch',
                [
                    'original_count' => count($entityIds),
                    'unique_count' => count($uniqueIds)
                ]
            );
        }

        // Validate each entity based on type and operation
        foreach ($entityIds as $entityId) {
            switch ($entityType) {
                case 'product':
                    $entityErrors = $this->validateProductForBulkOperation($entityId, $operation, $adminId);
                    break;
                case 'category':
                    $entityErrors = $this->validateCategoryForBulkOperation($entityId, $operation);
                    break;
                default:
                    $entityErrors = [$this->createError(
                        'invalid_entity_type',
                        'Invalid entity type for bulk operation',
                        ['entity_type' => $entityType]
                    )];
            }

            if (!empty($entityErrors)) {
                $errors[] = $this->createError(
                    'bulk_entity_error',
                    sprintf('Entity %s validation failed', $entityId),
                    [
                        'entity_id' => $entityId,
                        'entity_type' => $entityType,
                        'operation' => $operation,
                        'details' => $entityErrors
                    ]
                );
            }
        }

        return $errors;
    }

    /**
     * Check if admin has reached daily product creation limit
     */
    public function checkDailyProductLimit(int $adminId): array
    {
        $errors = [];

        if (!$this->config['enforce_daily_limits']) {
            return $errors;
        }

        $today = date('Y-m-d');
        $cacheKey = 'daily_limit_' . $adminId . '_' . $today;

        if (!isset($this->validationCache[$cacheKey])) {
            // In production, this would query the database
            $count = 0; // Placeholder - would be actual count from DB

            $this->validationCache[$cacheKey] = $count;
        }

        $count = $this->validationCache[$cacheKey];

        if ($count >= self::MAX_PRODUCTS_PER_DAY) {
            $errors[] = $this->createError(
                'daily_limit_reached',
                sprintf('Daily product creation limit of %s reached', self::MAX_PRODUCTS_PER_DAY),
                [
                    'admin_id' => $adminId,
                    'date' => $today,
                    'current_count' => $count,
                    'limit' => self::MAX_PRODUCTS_PER_DAY
                ]
            );
        }

        return $errors;
    }

    /**
     * Validate admin permissions for operation
     */
    public function validateAdminPermission(int $adminId, string $operation, ?string $entityType = null): array
    {
        $errors = [];

        $admin = $this->adminModel->find($adminId);

        if (!$admin) {
            $errors[] = $this->createError(
                'admin_not_found',
                'Admin not found',
                ['admin_id' => $adminId]
            );
            return $errors;
        }

        if (!$admin->isActive()) {
            $errors[] = $this->createError(
                'admin_inactive',
                'Admin account is inactive',
                ['admin_id' => $adminId]
            );
        }

        // Check role-based permissions
        switch ($operation) {
            case self::CONTEXT_PUBLISH:
            case self::CONTEXT_VERIFY:
                if (!$admin->isSuperAdmin() && !$this->config['allow_regular_admin_publish']) {
                    $errors[] = $this->createError(
                        'insufficient_permissions',
                        'Only super admins can publish/verify products',
                        [
                            'admin_role' => $admin->getRole(),
                            'required_role' => 'super_admin'
                        ]
                    );
                }
                break;

            case self::CONTEXT_DELETE:
                if ($entityType === 'admin' && !$admin->isSuperAdmin()) {
                    $errors[] = $this->createError(
                        'insufficient_permissions',
                        'Only super admins can delete admin accounts',
                        [
                            'admin_role' => $admin->getRole(),
                            'required_role' => 'super_admin'
                        ]
                    );
                }
                break;
        }

        return $errors;
    }

    /**
     * Create structured error response
     */
    private function createError(string $code, string $message, array $context = []): array
    {
        return [
            'code' => self::ERROR_CODE . '_' . strtoupper($code),
            'message' => $message,
            'context' => $context,
            'timestamp' => (new DateTimeImmutable())->format('c'),
        ];
    }

    /**
     * Validate product fields
     */
    private function validateProductFields(array $data, string $context): array
    {
        $errors = [];

        $rules = [];

        // Different rules for different contexts
        if ($context === self::CONTEXT_CREATE) {
            $rules = [
                'name' => 'required|max_length[' . self::MAX_NAME_LENGTH . ']',
                'slug' => 'required|alpha_dash|max_length[100]',
                'description' => 'max_length[' . self::MAX_DESCRIPTION_LENGTH . ']',
                'market_price' => 'decimal|greater_than_equal_to[0]',
                'category_id' => 'integer|greater_than[0]',
            ];
        } elseif ($context === self::CONTEXT_UPDATE) {
            // For update, only validate fields that are present
            if (isset($data['name'])) {
                $rules['name'] = 'max_length[' . self::MAX_NAME_LENGTH . ']';
            }
            if (isset($data['slug'])) {
                $rules['slug'] = 'alpha_dash|max_length[100]';
            }
            if (isset($data['description'])) {
                $rules['description'] = 'max_length[' . self::MAX_DESCRIPTION_LENGTH . ']';
            }
            if (isset($data['market_price'])) {
                $rules['market_price'] = 'decimal|greater_than_equal_to[0]';
            }
            if (isset($data['category_id'])) {
                $rules['category_id'] = 'integer|greater_than[0]';
            }
        }

        if (!empty($rules)) {
            $validation = $this->validation->setRules($rules);

            if (!$validation->run($data)) {
                foreach ($validation->getErrors() as $field => $error) {
                    $errors[] = $this->createError(
                        'invalid_field',
                        $error,
                        ['field' => $field, 'value' => $data[$field] ?? null]
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * Validate product business rules
     */
    private function validateProductBusinessRules(array $data, int $adminId, string $context): array
    {
        $errors = [];

        // Validate slug uniqueness for create
        if ($context === self::CONTEXT_CREATE && isset($data['slug'])) {
            if (!$this->productRepository->isSlugUnique($data['slug'])) {
                $errors[] = $this->createError(
                    'duplicate_slug',
                    'Product slug must be unique',
                    ['slug' => $data['slug']]
                );
            }
        }

        // Validate category exists and is active
        if (isset($data['category_id'])) {
            $category = $this->categoryModel->find($data['category_id']);
            if (!$category) {
                $errors[] = $this->createError(
                    'category_not_found',
                    'Category not found',
                    ['category_id' => $data['category_id']]
                );
            } elseif (!$category->isActive()) {
                $errors[] = $this->createError(
                    'category_inactive',
                    'Category is inactive',
                    ['category_id' => $data['category_id']]
                );
            }
        }

        // Validate price range
        if (isset($data['market_price'])) {
            $price = (float) $data['market_price'];
            if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                $errors[] = $this->createError(
                    'invalid_price_range',
                    sprintf(
                        'Price must be between %s and %s',
                        number_format(self::MIN_PRICE, 0),
                        number_format(self::MAX_PRICE, 0)
                    ),
                    [
                        'min_price' => self::MIN_PRICE,
                        'max_price' => self::MAX_PRICE,
                        'current_price' => $price
                    ]
                );
            }
        }

        return $errors;
    }

    /**
     * Validate system constraints
     */
    private function validateSystemConstraints(array $data, int $adminId, string $context): array
    {
        $errors = [];

        // Check daily limit for product creation
        if ($context === self::CONTEXT_CREATE) {
            $errors = array_merge($errors, $this->checkDailyProductLimit($adminId));
        }

        // Check total system limits if configured
        if ($this->config['enforce_system_limits']) {
            $totalProducts = $this->productRepository->countAll();
            $maxProducts = $this->config['max_total_products'] ?? 10000;

            if ($totalProducts >= $maxProducts && $context === self::CONTEXT_CREATE) {
                $errors[] = $this->createError(
                    'system_limit_reached',
                    sprintf('System limit of %s products reached', $maxProducts),
                    [
                        'current_count' => $totalProducts,
                        'limit' => $maxProducts
                    ]
                );
            }
        }

        return $errors;
    }

    /**
     * Validate product relations
     */
    private function validateProductRelations(array $data, string $context): array
    {
        $errors = [];

        // If category is specified, check product count in category
        if (isset($data['category_id']) && $this->config['enforce_category_limits']) {
            $categoryProductsCount = $this->productRepository->countPublished(); // Would need method for category count
            $maxPerCategory = $this->config['max_products_per_category'] ?? 1000;

            if ($categoryProductsCount >= $maxPerCategory && $context === self::CONTEXT_CREATE) {
                $errors[] = $this->createError(
                    'category_limit_reached',
                    sprintf('Category limit of %s products reached', $maxPerCategory),
                    [
                        'category_id' => $data['category_id'],
                        'current_count' => $categoryProductsCount,
                        'limit' => $maxPerCategory
                    ]
                );
            }
        }

        return $errors;
    }

    /**
     * Validate update business rules
     */
    private function validateUpdateBusinessRules(Product $product, array $data, int $adminId): array
    {
        $errors = [];

        // Validate slug uniqueness if slug is being changed
        if (isset($data['slug']) && $data['slug'] !== $product->getSlug()) {
            if (!$this->productRepository->isSlugUnique($data['slug'], $product->getId())) {
                $errors[] = $this->createError(
                    'duplicate_slug',
                    'Product slug must be unique',
                    ['slug' => $data['slug']]
                );
            }
        }

        // Prevent certain changes for published products
        if ($product->isPublished() && $this->config['restrict_published_updates']) {
            $restrictedFields = ['slug', 'category_id', 'market_price'];

            foreach ($restrictedFields as $field) {
                if (isset($data[$field])) {
                    $getter = 'get' . str_replace('_', '', ucwords($field, '_'));
                    $oldValue = $product->$getter();

                    if ($data[$field] != $oldValue) {
                        $errors[] = $this->createError(
                            'published_field_restricted',
                            sprintf('Field %s cannot be changed for published products', $field),
                            [
                                'field' => $field,
                                'old_value' => $oldValue,
                                'new_value' => $data[$field]
                            ]
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate category fields
     */
    private function validateCategoryFields(array $data): array
    {
        $errors = [];

        $rules = [
            'name' => 'required|max_length[100]',
            'slug' => 'required|alpha_dash|max_length[50]',
            'icon' => 'max_length[50]',
            'sort_order' => 'integer',
        ];

        $validation = $this->validation->setRules($rules);

        if (!$validation->run($data)) {
            foreach ($validation->getErrors() as $field => $error) {
                $errors[] = $this->createError(
                    'invalid_field',
                    $error,
                    ['field' => $field, 'value' => $data[$field] ?? null]
                );
            }
        }

        return $errors;
    }

    /**
     * Validate category uniqueness
     */
    private function validateCategoryUniqueness(array $data, ?int $excludeId = null): array
    {
        $errors = [];

        if (isset($data['slug'])) {
            $existing = $this->categoryModel->findBySlug($data['slug']);
            if ($existing && $existing->getId() !== $excludeId) {
                $errors[] = $this->createError(
                    'duplicate_category_slug',
                    'Category slug must be unique',
                    ['slug' => $data['slug']]
                );
            }
        }

        return $errors;
    }

    /**
     * Validate product for bulk operation
     */
    private function validateProductForBulkOperation(int $productId, string $operation, int $adminId): array
    {
        $errors = [];

        $product = $this->productRepository->find($productId);

        if (!$product) {
            $errors[] = $this->createError(
                'product_not_found',
                'Product not found',
                ['product_id' => $productId]
            );
            return $errors;
        }

        switch ($operation) {
            case 'publish':
                $publishErrors = $this->validateProductPublish($productId, $adminId);
                $errors = array_merge($errors, $publishErrors);
                break;

            case 'archive':
                if ($product->isArchived()) {
                    $errors[] = $this->createError(
                        'already_archived',
                        'Product is already archived',
                        ['product_id' => $productId]
                    );
                }
                break;

            case 'delete':
                $deleteErrors = $this->validateProductDelete($productId, $adminId);
                $errors = array_merge($errors, $deleteErrors);
                break;
        }

        return $errors;
    }

    /**
     * Validate category for bulk operation
     */
    private function validateCategoryForBulkOperation(int $categoryId, string $operation): array
    {
        $errors = [];

        $category = $this->categoryModel->find($categoryId);

        if (!$category) {
            $errors[] = $this->createError(
                'category_not_found',
                'Category not found',
                ['category_id' => $categoryId]
            );
            return $errors;
        }

        switch ($operation) {
            case 'archive':
                if (!$category->isActive()) {
                    $errors[] = $this->createError(
                        'already_inactive',
                        'Category is already inactive',
                        ['category_id' => $categoryId]
                    );
                }
                break;

            case 'delete':
                if ($category->isInUse()) {
                    $errors[] = $this->createError(
                        'category_in_use',
                        'Category is in use by products',
                        ['category_id' => $categoryId]
                    );
                }
                break;
        }

        return $errors;
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            // Product validation settings
            'require_image_for_publish' => true,
            'require_active_links_for_publish' => true,
            'allow_delete_published' => false,
            'check_dependencies_before_delete' => true,
            'restrict_published_updates' => true,

            // System limits
            'enforce_daily_limits' => true,
            'enforce_system_limits' => false,
            'max_total_products' => 10000,
            'enforce_category_limits' => true,
            'max_products_per_category' => 1000,

            // Bulk operations
            'max_batch_size' => 100,

            // Admin permissions
            'allow_regular_admin_publish' => false,
        ];
    }

    /**
     * Clear validation cache
     */
    public function clearCache(): void
    {
        $this->validationCache = [];
    }

    /**
     * Create ValidationService instance with default dependencies
     */
    public static function create(): self
    {
        $repositoryService = new \App\Services\RepositoryService();
        $productRepository = $repositoryService->product();

        $categoryModel = model(CategoryModel::class);
        $linkModel = model(LinkModel::class);
        $adminModel = model(AdminModel::class);
        $validation = service('validation');

        return new self(
            $productRepository,
            $categoryModel,
            $linkModel,
            $adminModel,
            $validation
        );
    }
}
