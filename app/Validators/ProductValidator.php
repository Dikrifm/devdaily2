<?php

namespace App\Validators;

use App\DTOs\Requests\Product\PublishProductRequest;
use App\Entities\Product;
use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;
use App\Models\CategoryModel;
use App\Models\LinkModel;
use App\Models\ProductModel;
use App\Services\CacheService;
use CodeIgniter\Validation\Validation;
use DateTimeImmutable;

/**
 * Enterprise-grade Product Validator
 *
 * Comprehensive validation rules for product operations with caching,
 * business rule validation, and multi-context support.
 */
class ProductValidator
{
    private Validation $validation;
    private ProductModel $productModel;
    private CategoryModel $categoryModel;
    private LinkModel $linkModel;
    private CacheService $cacheService;

    // Validation contexts
    public const CONTEXT_CREATE = 'create';
    public const CONTEXT_UPDATE = 'update';
    public const CONTEXT_PUBLISH = 'publish';
    public const CONTEXT_DELETE = 'delete';
    public const CONTEXT_ARCHIVE = 'archive';
    public const CONTEXT_RESTORE = 'restore';
    public const CONTEXT_VERIFY = 'verify';
    public const CONTEXT_BULK = 'bulk';

    // Rule sets
    private array $baseRules = [
        'name' => [
            'label' => 'Product Name',
            'rules' => 'required|max_length[255]|min_length[3]',
            'errors' => [
                'required' => 'Product name is required',
                'max_length' => 'Product name cannot exceed 255 characters',
                'min_length' => 'Product name must be at least 3 characters'
            ]
        ],
        'slug' => [
            'label' => 'Product Slug',
            'rules' => 'required|alpha_dash|max_length[100]|min_length[3]',
            'errors' => [
                'required' => 'Product slug is required',
                'alpha_dash' => 'Slug can only contain letters, numbers, dashes, and underscores',
                'max_length' => 'Slug cannot exceed 100 characters',
                'min_length' => 'Slug must be at least 3 characters'
            ]
        ],
        'description' => [
            'label' => 'Description',
            'rules' => 'max_length[2000]',
            'errors' => [
                'max_length' => 'Description cannot exceed 2000 characters'
            ]
        ],
        'market_price' => [
            'label' => 'Market Price',
            'rules' => 'required|decimal|greater_than_equal_to[0]|max_decimal[2]',
            'errors' => [
                'required' => 'Market price is required',
                'decimal' => 'Market price must be a valid decimal number',
                'greater_than_equal_to' => 'Market price cannot be negative',
                'max_decimal' => 'Market price can have maximum 2 decimal places'
            ]
        ],
        'category_id' => [
            'label' => 'Category',
            'rules' => 'integer|greater_than[0]',
            'errors' => [
                'integer' => 'Category ID must be an integer',
                'greater_than' => 'Category ID must be greater than 0'
            ]
        ]
    ];

    private array $imageRules = [
        'image' => [
            'label' => 'Product Image',
            'rules' => 'max_length[500]',
            'errors' => [
                'max_length' => 'Image URL cannot exceed 500 characters'
            ]
        ],
        'image_source_type' => [
            'label' => 'Image Source Type',
            'rules' => 'in_list[' . ImageSourceType::valuesAsString() . ']',
            'errors' => [
                'in_list' => 'Invalid image source type'
            ]
        ]
    ];

    private array $statusRules = [
        'status' => [
            'label' => 'Product Status',
            'rules' => 'in_list[' . ProductStatus::valuesAsString() . ']',
            'errors' => [
                'in_list' => 'Invalid product status'
            ]
        ]
    ];

    // Business rule constraints
    private const MIN_PRICE = 100;
    private const MAX_PRICE = 1000000000;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 2000;
    private const MAX_SLUG_LENGTH = 100;
    private const MAX_IMAGE_URL_LENGTH = 500;

    // Cache for validation results
    private array $validationCache = [];

    public function __construct(
        Validation $validation,
        ProductModel $productModel,
        CategoryModel $categoryModel,
        LinkModel $linkModel,
        CacheService $cacheService
    ) {
        $this->validation = $validation;
        $this->productModel = $productModel;
        $this->categoryModel = $categoryModel;
        $this->linkModel = $linkModel;
        $this->cacheService = $cacheService;
    }

    /**
     * Validate product creation data
     */
    public function validateCreate(array $data, array $options = []): array
    {
        $errors = [];

        // 1. Basic field validation
        $rules = $this->getRulesForContext(self::CONTEXT_CREATE);
        $validationResult = $this->runValidation($data, $rules);

        if (!$validationResult['is_valid']) {
            $errors = array_merge($errors, $validationResult['errors']);
        }

        // 2. Business rule validation
        $businessErrors = $this->validateBusinessRules($data, self::CONTEXT_CREATE, $options);
        $errors = array_merge($errors, $businessErrors);

        // 3. Return structured result
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'validated_data' => $validationResult['validated_data'] ?? [],
            'context' => self::CONTEXT_CREATE,
            'timestamp' => (new DateTimeImmutable())->format('c')
        ];
    }

    /**
     * Validate product update data
     */
    public function validateUpdate(int $productId, array $data, array $options = []): array
    {
        $errors = [];

        // 1. Check if product exists
        $product = $this->productModel->find($productId);
        if (!$product) {
            $errors[] = [
                'field' => 'product_id',
                'rule' => 'exists',
                'message' => 'Product not found',
                'value' => $productId
            ];

            return $this->buildValidationResult(false, $errors, self::CONTEXT_UPDATE);
        }

        // 2. Basic field validation (only validate fields that are present)
        $rules = $this->getRulesForContext(self::CONTEXT_UPDATE, $data);
        $validationResult = $this->runValidation($data, $rules);

        if (!$validationResult['is_valid']) {
            $errors = array_merge($errors, $validationResult['errors']);
        }

        // 3. Business rule validation
        $businessErrors = $this->validateBusinessRules(
            array_merge($data, ['product_id' => $productId]),
            self::CONTEXT_UPDATE,
            $options
        );
        $errors = array_merge($errors, $businessErrors);

        // 4. Check if product can be updated in its current state
        if (!$this->canProductBeUpdated($product, $data)) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'updatable',
                'message' => sprintf(
                    'Product cannot be updated in %s state',
                    $product->getStatus()->value
                ),
                'value' => $product->getStatus()->value
            ];
        }

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_UPDATE);
    }

    /**
     * Validate product publish data
     */
    public function validatePublish(PublishProductRequest $request, Product $product): array
    {
        $errors = [];
        $productId = $product->getId();

        // 1. Check if product can be published from current state
        if (!$product->getStatus()->canBePublished()) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'publishable',
                'message' => sprintf(
                    'Product cannot be published from %s state',
                    $product->getStatus()->value
                ),
                'value' => $product->getStatus()->value
            ];
        }

        // 2. If not force publish, validate all prerequisites
        if (!$request->isForcePublish()) {
            // Required fields validation
            $requiredFields = [
                'name' => $product->getName(),
                'slug' => $product->getSlug(),
                'market_price' => $product->getMarketPrice(),
                'category_id' => $product->getCategoryId(),
            ];

            foreach ($requiredFields as $field => $value) {
                if (empty($value)) {
                    $errors[] = [
                        'field' => $field,
                        'rule' => 'required_for_publish',
                        'message' => sprintf('%s is required for publishing', $this->getFieldLabel($field)),
                        'value' => $value
                    ];
                }
            }

            // Validate category exists and is active
            if ($product->getCategoryId()) {
                $category = $this->categoryModel->find($product->getCategoryId());
                if (!$category) {
                    $errors[] = [
                        'field' => 'category_id',
                        'rule' => 'category_exists',
                        'message' => 'Category does not exist',
                        'value' => $product->getCategoryId()
                    ];
                } elseif (!$category->isActive()) {
                    $errors[] = [
                        'field' => 'category_id',
                        'rule' => 'category_active',
                        'message' => 'Category is not active',
                        'value' => $product->getCategoryId()
                    ];
                }
            }

            // Validate image requirements
            if (!$product->getImage()) {
                $errors[] = [
                    'field' => 'image',
                    'rule' => 'required_for_publish',
                    'message' => 'Product image is required for publishing',
                    'value' => null
                ];
            }

            // Validate at least one active link
            $activeLinks = $this->linkModel->findActiveByProduct($productId);
            if (empty($activeLinks)) {
                $errors[] = [
                    'field' => 'links',
                    'rule' => 'active_links_required',
                    'message' => 'At least one active affiliate link is required for publishing',
                    'value' => 0
                ];
            }

            // Validate price is within reasonable range
            $price = (float) $product->getMarketPrice();
            if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                $errors[] = [
                    'field' => 'market_price',
                    'rule' => 'price_range',
                    'message' => sprintf(
                        'Price must be between %s and %s',
                        number_format(self::MIN_PRICE, 0),
                        number_format(self::MAX_PRICE, 0)
                    ),
                    'value' => $price,
                    'params' => [
                        'min' => self::MIN_PRICE,
                        'max' => self::MAX_PRICE
                    ]
                ];
            }

            // Validate slug uniqueness
            if (!$this->isSlugUnique($product->getSlug(), $productId)) {
                $errors[] = [
                    'field' => 'slug',
                    'rule' => 'unique',
                    'message' => 'Product slug must be unique',
                    'value' => $product->getSlug()
                ];
            }
        }

        // 3. Validate admin permissions (would be done by another service)

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_PUBLISH);
    }

    /**
     * Validate product deletion
     */
    public function validateDelete(int $productId, bool $force = false, array $options = []): array
    {
        $errors = [];

        // 1. Check if product exists
        $product = $this->productModel->find($productId, true); // Include trashed

        if (!$product) {
            $errors[] = [
                'field' => 'product_id',
                'rule' => 'exists',
                'message' => 'Product not found',
                'value' => $productId
            ];

            return $this->buildValidationResult(false, $errors, self::CONTEXT_DELETE);
        }

        // 2. Check if already deleted
        if ($product->isDeleted() && !$force) {
            $errors[] = [
                'field' => 'deleted_at',
                'rule' => 'not_deleted',
                'message' => 'Product is already deleted',
                'value' => $product->getDeletedAt()?->format('Y-m-d H:i:s')
            ];
        }

        // 3. Check if published product can be deleted
        if ($product->isPublished() && !$force) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'delete_published',
                'message' => 'Published products must be archived first',
                'value' => $product->getStatus()->value
            ];
        }

        // 4. Check if product has active dependencies
        if (!$force) {
            $activeLinks = $this->linkModel->findActiveByProduct($productId);
            if (!empty($activeLinks)) {
                $errors[] = [
                    'field' => 'links',
                    'rule' => 'has_active_dependencies',
                    'message' => 'Product has active affiliate links. Remove or deactivate them first.',
                    'value' => count($activeLinks)
                ];
            }
        }

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_DELETE);
    }

    /**
     * Validate product archive operation
     */
    public function validateArchive(int $productId): array
    {
        $errors = [];

        $product = $this->productModel->find($productId);

        if (!$product) {
            $errors[] = [
                'field' => 'product_id',
                'rule' => 'exists',
                'message' => 'Product not found',
                'value' => $productId
            ];

            return $this->buildValidationResult(false, $errors, self::CONTEXT_ARCHIVE);
        }

        // Check if already archived
        if ($product->isArchived()) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'not_archived',
                'message' => 'Product is already archived',
                'value' => $product->getStatus()->value
            ];
        }

        // Check if can be archived from current state
        if (!$product->getStatus()->canTransitionTo(ProductStatus::ARCHIVED)) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'can_archive',
                'message' => sprintf(
                    'Cannot archive product from %s state',
                    $product->getStatus()->value
                ),
                'value' => $product->getStatus()->value
            ];
        }

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_ARCHIVE);
    }

    /**
     * Validate product restore operation
     */
    public function validateRestore(int $productId): array
    {
        $errors = [];

        $product = $this->productModel->find($productId, true); // Include trashed

        if (!$product) {
            $errors[] = [
                'field' => 'product_id',
                'rule' => 'exists',
                'message' => 'Product not found',
                'value' => $productId
            ];

            return $this->buildValidationResult(false, $errors, self::CONTEXT_RESTORE);
        }

        // Check if product is deleted or archived
        if (!$product->isDeleted() && !$product->isArchived()) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'can_restore',
                'message' => 'Product is not deleted or archived',
                'value' => $product->getStatus()->value
            ];
        }

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_RESTORE);
    }

    /**
     * Validate product verification
     */
    public function validateVerify(int $productId): array
    {
        $errors = [];

        $product = $this->productModel->find($productId);

        if (!$product) {
            $errors[] = [
                'field' => 'product_id',
                'rule' => 'exists',
                'message' => 'Product not found',
                'value' => $productId
            ];

            return $this->buildValidationResult(false, $errors, self::CONTEXT_VERIFY);
        }

        // Check if product can be verified
        if (!$product->getStatus()->canBePublished()) {
            $errors[] = [
                'field' => 'status',
                'rule' => 'can_verify',
                'message' => sprintf(
                    'Product cannot be verified from %s state',
                    $product->getStatus()->value
                ),
                'value' => $product->getStatus()->value
            ];
        }

        // Validate all prerequisites for verification
        $requiredFields = [
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'market_price' => $product->getMarketPrice(),
            'category_id' => $product->getCategoryId(),
            'image' => $product->getImage(),
        ];

        foreach ($requiredFields as $field => $value) {
            if (empty($value)) {
                $errors[] = [
                    'field' => $field,
                    'rule' => 'required_for_verification',
                    'message' => sprintf('%s is required for verification', $this->getFieldLabel($field)),
                    'value' => $value
                ];
            }
        }

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_VERIFY);
    }

    /**
     * Validate bulk product operations
     */
    public function validateBulk(array $productIds, string $operation, array $options = []): array
    {
        $errors = [];

        // 1. Validate batch size
        $maxBatchSize = $options['max_batch_size'] ?? 100;
        if (count($productIds) > $maxBatchSize) {
            $errors[] = [
                'field' => 'product_ids',
                'rule' => 'batch_size',
                'message' => sprintf('Maximum %s items per batch allowed', $maxBatchSize),
                'value' => count($productIds),
                'params' => ['max' => $maxBatchSize]
            ];

            return $this->buildValidationResult(false, $errors, self::CONTEXT_BULK);
        }

        // 2. Check for duplicate IDs
        $uniqueIds = array_unique($productIds);
        if (count($uniqueIds) !== count($productIds)) {
            $errors[] = [
                'field' => 'product_ids',
                'rule' => 'unique_ids',
                'message' => 'Duplicate IDs found in batch',
                'value' => $productIds,
                'params' => [
                    'original_count' => count($productIds),
                    'unique_count' => count($uniqueIds)
                ]
            ];
        }

        // 3. Validate each product based on operation
        $individualErrors = [];

        foreach ($productIds as $index => $productId) {
            $productErrors = [];

            switch ($operation) {
                case 'publish':
                    $product = $this->productModel->find($productId);
                    if ($product) {
                        // Gunakan factory method dengan parameter minimal yang diperlukan
                        $adminId = $options['admin_id'] ?? 0; // Default atau ambil dari context
                        $forcePublish = $options['force'] ?? false;

                        if ($forcePublish) {
                            $request = PublishProductRequest::forForcePublish($productId, $adminId);
                        } else {
                            $request = PublishProductRequest::forImmediatePublish($productId, $adminId);
                        }

                        $publishResult = $this->validatePublish($request, $product);
                        if (!$publishResult['is_valid']) {
                            $productErrors = $publishResult['errors'];
                        }
                    }
                    break;

                case 'archive':
                    $archiveResult = $this->validateArchive($productId);
                    if (!$archiveResult['is_valid']) {
                        $productErrors = $archiveResult['errors'];
                    }
                    break;

                case 'delete':
                    $force = $options['force'] ?? false;
                    $deleteResult = $this->validateDelete($productId, $force, $options);
                    if (!$deleteResult['is_valid']) {
                        $productErrors = $deleteResult['errors'];
                    }
                    break;

                default:
                    $productErrors[] = [
                        'field' => 'operation',
                        'rule' => 'valid_operation',
                        'message' => 'Invalid bulk operation',
                        'value' => $operation
                    ];
            }

            if (!empty($productErrors)) {
                $individualErrors[] = [
                    'product_id' => $productId,
                    'index' => $index,
                    'errors' => $productErrors
                ];
            }
        }

        if (!empty($individualErrors)) {
            $errors[] = [
                'field' => 'product_ids',
                'rule' => 'individual_validation',
                'message' => 'Some products failed validation',
                'value' => $productIds,
                'params' => ['failed_products' => $individualErrors]
            ];
        }

        return $this->buildValidationResult(empty($errors), $errors, self::CONTEXT_BULK);
    }

    /**
     * Validate product price
     */
    public function validatePrice(string $price, array $options = []): array
    {
        $errors = [];
        $priceFloat = (float) $price;

        // 1. Basic price validation
        if (!is_numeric($price) || $priceFloat < 0) {
            $errors[] = [
                'field' => 'price',
                'rule' => 'valid_price',
                'message' => 'Price must be a valid positive number',
                'value' => $price
            ];
        }

        // 2. Decimal places
        if (strpos($price, '.') !== false) {
            $decimalPart = explode('.', $price)[1];
            if (strlen($decimalPart) > 2) {
                $errors[] = [
                    'field' => 'price',
                    'rule' => 'max_decimal_places',
                    'message' => 'Price can have maximum 2 decimal places',
                    'value' => $price
                ];
            }
        }

        // 3. Price range
        $minPrice = $options['min_price'] ?? self::MIN_PRICE;
        $maxPrice = $options['max_price'] ?? self::MAX_PRICE;

        if ($priceFloat < $minPrice || $priceFloat > $maxPrice) {
            $errors[] = [
                'field' => 'price',
                'rule' => 'price_range',
                'message' => sprintf(
                    'Price must be between %s and %s',
                    number_format($minPrice, 0),
                    number_format($maxPrice, 0)
                ),
                'value' => $priceFloat,
                'params' => [
                    'min' => $minPrice,
                    'max' => $maxPrice
                ]
            ];
        }

        // 4. Price change validation (if old price provided)
        if (isset($options['old_price'])) {
            $oldPrice = (float) $options['old_price'];
            if ($oldPrice > 0) {
                $changePercent = abs(($priceFloat - $oldPrice) / $oldPrice) * 100;
                $maxChange = $options['max_change_percent'] ?? 100;

                if ($changePercent > $maxChange) {
                    $errors[] = [
                        'field' => 'price',
                        'rule' => 'price_change',
                        'message' => sprintf(
                            'Price change exceeds maximum allowed percentage (%s%%)',
                            $maxChange
                        ),
                        'value' => $priceFloat,
                        'params' => [
                            'old_price' => $oldPrice,
                            'change_percent' => $changePercent,
                            'max_change_percent' => $maxChange
                        ]
                    ];
                }
            }
        }

        return $this->buildValidationResult(empty($errors), $errors, 'price_validation');
    }

    /**
     * Validate product image
     */
    public function validateImage(array $imageData, ImageSourceType $sourceType): array
    {
        $errors = [];

        // Validate based on source type
        switch ($sourceType) {
            case ImageSourceType::UPLOAD:
                $errors = array_merge($errors, $this->validateUploadedImage($imageData));
                break;

            case ImageSourceType::URL:
                $errors = array_merge($errors, $this->validateImageUrl($imageData['url'] ?? ''));
                break;

                // Hapus atau komentari case EXTERNAL_SERVICE jika tidak ada
                // case ImageSourceType::EXTERNAL_SERVICE:
                //     $errors = array_merge($errors, $this->validateExternalImage($imageData));
                //     break;

            default:
                $errors[] = [
                    'field' => 'image_source_type',
                    'rule' => 'valid_source_type',
                    'message' => sprintf(
                        'Invalid image source type: %s. Valid types are: %s',
                        $sourceType->value,
                        ImageSourceType::valuesAsString()
                    ),
                     'value' => $sourceType->value
                ];
        }

        return $this->buildValidationResult(empty($errors), $errors, 'image_validation');
    }

    /**
     * Validate product name
     */
    public function validateName(string $name, ?int $excludeProductId = null): array
    {
        $errors = [];

        // 1. Basic validation
        if (empty(trim($name))) {
            $errors[] = [
                'field' => 'name',
                'rule' => 'required',
                'message' => 'Product name is required',
                'value' => $name
            ];
        }

        // 2. Length validation
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = [
                'field' => 'name',
                'rule' => 'max_length',
                'message' => sprintf('Product name cannot exceed %s characters', self::MAX_NAME_LENGTH),
                'value' => $name,
                'params' => ['max' => self::MAX_NAME_LENGTH]
            ];
        }

        if (strlen($name) < 3) {
            $errors[] = [
                'field' => 'name',
                'rule' => 'min_length',
                'message' => 'Product name must be at least 3 characters',
                'value' => $name,
                'params' => ['min' => 3]
            ];
        }

        // 3. Check for duplicate names (case-insensitive)
        if (!$this->isProductNameUnique($name, $excludeProductId)) {
            $errors[] = [
                'field' => 'name',
                'rule' => 'unique',
                'message' => 'Product name must be unique',
                'value' => $name
            ];
        }

        return $this->buildValidationResult(empty($errors), $errors, 'name_validation');
    }

    /**
     * Validate product description
     */
    public function validateDescription(?string $description): array
    {
        $errors = [];

        if ($description === null) {
            return $this->buildValidationResult(true, $errors, 'description_validation');
        }

        // Length validation
        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $errors[] = [
                'field' => 'description',
                'rule' => 'max_length',
                'message' => sprintf(
                    'Description cannot exceed %s characters',
                    self::MAX_DESCRIPTION_LENGTH
                ),
                'value' => $description,
                'params' => ['max' => self::MAX_DESCRIPTION_LENGTH]
            ];
        }

        // HTML validation (basic)
        if ($this->containsDangerousHtml($description)) {
            $errors[] = [
                'field' => 'description',
                'rule' => 'safe_html',
                'message' => 'Description contains potentially dangerous HTML',
                'value' => $description
            ];
        }

        return $this->buildValidationResult(empty($errors), $errors, 'description_validation');
    }

    /**
     * Get validation rules for a specific context
     */
    public function getRulesForContext(string $context, array $data = []): array
    {
        $rules = [];

        switch ($context) {
            case self::CONTEXT_CREATE:
                $rules = array_merge(
                    $this->baseRules,
                    $this->imageRules,
                    $this->statusRules
                );
                break;

            case self::CONTEXT_UPDATE:
                // Only include rules for fields that are present in data
                $allRules = array_merge(
                    $this->baseRules,
                    $this->imageRules,
                    $this->statusRules
                );

                foreach ($allRules as $field => $ruleConfig) {
                    if (array_key_exists($field, $data)) {
                        $rules[$field] = $ruleConfig;
                    }
                }
                break;

            case self::CONTEXT_PUBLISH:
                $rules = [
                    'product_id' => [
                        'label' => 'Product ID',
                        'rules' => 'required|integer|greater_than[0]',
                        'errors' => [
                            'required' => 'Product ID is required',
                            'integer' => 'Product ID must be an integer',
                            'greater_than' => 'Product ID must be greater than 0'
                        ]
                    ]
                ];
                break;
        }

        return $rules;
    }

    /**
     * Run validation with CodeIgniter's validation library
     */
    private function runValidation(array $data, array $rules): array
    {
        if (empty($rules)) {
            return [
                'is_valid' => true,
                'errors' => [],
                'validated_data' => $data
            ];
        }

        $this->validation->setRules($rules);

        if ($this->validation->run($data)) {
            return [
                'is_valid' => true,
                'errors' => [],
                'validated_data' => $this->validation->getValidated()
            ];
        }

        $errors = [];
        foreach ($this->validation->getErrors() as $field => $message) {
            $errors[] = [
                'field' => $field,
                'rule' => $this->getRuleFromMessage($message),
                'message' => $message,
                'value' => $data[$field] ?? null
            ];
        }

        return [
            'is_valid' => false,
            'errors' => $errors,
            'validated_data' => []
        ];
    }

    /**
     * Validate business rules
     */
    private function validateBusinessRules(array $data, string $context, array $options): array
    {
        $errors = [];

        // 1. Slug uniqueness (for create and update with slug change)
        if (($context === self::CONTEXT_CREATE ||
            ($context === self::CONTEXT_UPDATE && isset($data['slug']))) &&
            isset($data['slug'])) {

            $excludeId = $context === self::CONTEXT_UPDATE ? ($data['product_id'] ?? null) : null;

            if (!$this->isSlugUnique($data['slug'], $excludeId)) {
                $errors[] = [
                    'field' => 'slug',
                    'rule' => 'unique',
                    'message' => 'Product slug must be unique',
                    'value' => $data['slug']
                ];
            }
        }

        // 2. Category validation
        if (isset($data['category_id'])) {
            $category = $this->categoryModel->find($data['category_id']);
            if (!$category) {
                $errors[] = [
                    'field' => 'category_id',
                    'rule' => 'exists',
                    'message' => 'Category does not exist',
                    'value' => $data['category_id']
                ];
            } elseif (!$category->isActive() && $context !== self::CONTEXT_UPDATE) {
                $errors[] = [
                    'field' => 'category_id',
                    'rule' => 'active',
                    'message' => 'Category is not active',
                    'value' => $data['category_id']
                ];
            }
        }

        // 3. Price range validation
        if (isset($data['market_price'])) {
            $price = (float) $data['market_price'];
            if ($price < self::MIN_PRICE || $price > self::MAX_PRICE) {
                $errors[] = [
                    'field' => 'market_price',
                    'rule' => 'price_range',
                    'message' => sprintf(
                        'Price must be between %s and %s',
                        number_format(self::MIN_PRICE, 0),
                        number_format(self::MAX_PRICE, 0)
                    ),
                    'value' => $price,
                    'params' => [
                        'min' => self::MIN_PRICE,
                        'max' => self::MAX_PRICE
                    ]
                ];
            }
        }

        // 4. Status transition validation
        if (isset($data['status']) && $context === self::CONTEXT_UPDATE && isset($data['product_id'])) {
            $product = $this->productModel->find($data['product_id']);
            if ($product) {
                try {
                    $newStatus = ProductStatus::from($data['status']);
                    if (!$product->getStatus()->canTransitionTo($newStatus)) {
                        $errors[] = [
                            'field' => 'status',
                            'rule' => 'valid_transition',
                            'message' => sprintf(
                                'Cannot transition from %s to %s',
                                $product->getStatus()->value,
                                $newStatus->value
                            ),
                            'value' => $data['status'],
                            'params' => [
                                'from' => $product->getStatus()->value,
                                'to' => $newStatus->value
                            ]
                        ];
                    }
                } catch (\ValueError $e) {
                    $errors[] = [
                        'field' => 'status',
                        'rule' => 'valid_status',
                        'message' => 'Invalid product status',
                        'value' => $data['status']
                    ];
                }
            }
        }

        // 5. Daily limit check (optional)
        if ($context === self::CONTEXT_CREATE && ($options['check_daily_limit'] ?? false)) {
            $adminId = $options['admin_id'] ?? null;
            if ($adminId && !$this->checkDailyProductLimit($adminId)) {
                $errors[] = [
                    'field' => 'daily_limit',
                    'rule' => 'max_per_day',
                    'message' => 'Daily product creation limit reached',
                    'value' => $adminId
                ];
            }
        }

        return $errors;
    }

    /**
     * Check if product can be updated
     */
    private function canProductBeUpdated(Product $product, array $updateData): bool
    {
        // Published products have restricted fields that can be updated
        if ($product->isPublished()) {
            $restrictedFields = ['slug', 'category_id', 'market_price'];

            foreach ($restrictedFields as $field) {
                if (isset($updateData[$field])) {
                    $getter = 'get' . str_replace('_', '', ucwords($field, '_'));
                    $oldValue = $product->$getter();

                    // Allow updates if value is the same (no actual change)
                    if ($updateData[$field] != $oldValue) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Check if slug is unique
     */
    private function isSlugUnique(string $slug, ?int $excludeId = null): bool
    {
        $cacheKey = 'slug_unique_' . md5($slug . '_' . ($excludeId ?? ''));

        return $this->cacheService->remember($cacheKey, function () use ($slug, $excludeId) {
            return $this->productModel->isSlugUnique($slug, $excludeId);
        }, 300); // 5 minute cache
    }

    /**
     * Check if product name is unique
     */
    private function isProductNameUnique(string $name, ?int $excludeId = null): bool
    {
        // Normalize name for comparison
        $normalizedName = strtolower(trim($name));

        $cacheKey = 'product_name_unique_' . md5($normalizedName . '_' . ($excludeId ?? ''));

        return $this->cacheService->remember($cacheKey, function () use ($normalizedName, $excludeId) {
            // Query database for case-insensitive name match
            $builder = $this->productModel->builder();
            $builder->where('LOWER(name)', $normalizedName);

            if ($excludeId) {
                $builder->where('id !=', $excludeId);
            }

            $builder->where('deleted_at IS NULL');

            return $builder->countAllResults() === 0;
        }, 300); // 5 minute cache
    }

    /**
     * Check daily product limit for admin
     */
    private function checkDailyProductLimit(int $adminId): bool
    {
        $today = date('Y-m-d');
        $cacheKey = 'daily_product_limit_' . $adminId . '_' . $today;

        $count = $this->cacheService->remember($cacheKey, function () use ($adminId, $today) {
            // Query database for today's product count by this admin
            $builder = $this->productModel->builder();
            $builder->where('created_by', $adminId);
            $builder->where('DATE(created_at)', $today);

            return $builder->countAllResults();
        }, 3600); // 1 hour cache

        $maxDaily = config('ProductValidation')->maxDailyProducts ?? 100;

        return $count < $maxDaily;
    }

    /**
     * Validate uploaded image
     */
    private function validateUploadedImage(array $imageData): array
    {
        $errors = [];

        if (empty($imageData['tmp_name'] ?? '')) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'required',
                'message' => 'Image file is required',
                'value' => null
            ];
            return $errors;
        }

        // File size validation
        $maxSize = config('ImageUpload')->maxSize ?? 5 * 1024 * 1024; // 5MB default
        if (($imageData['size'] ?? 0) > $maxSize) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'max_size',
                'message' => sprintf('Image file size cannot exceed %sMB', $maxSize / 1024 / 1024),
                'value' => $imageData['size'] ?? 0,
                'params' => ['max_size' => $maxSize]
            ];
        }

        // File type validation
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($imageData['type'] ?? '', $allowedTypes)) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'allowed_types',
                'message' => 'Only JPEG, PNG, GIF, and WebP images are allowed',
                'value' => $imageData['type'] ?? '',
                'params' => ['allowed_types' => $allowedTypes]
            ];
        }

        return $errors;
    }

    /**
     * Validate image URL
     */
    private function validateImageUrl(string $url): array
    {
        $errors = [];

        if (empty($url)) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'required',
                'message' => 'Image URL is required',
                'value' => $url
            ];
            return $errors;
        }

        // URL format validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'valid_url',
                'message' => 'Invalid image URL format',
                'value' => $url
            ];
        }

        // URL length validation
        if (strlen($url) > self::MAX_IMAGE_URL_LENGTH) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'max_length',
                'message' => sprintf(
                    'Image URL cannot exceed %s characters',
                    self::MAX_IMAGE_URL_LENGTH
                ),
                'value' => $url,
                'params' => ['max' => self::MAX_IMAGE_URL_LENGTH]
            ];
        }

        // Check if URL points to an image (by extension)
        $imageExtensions = ['.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg'];
        $hasImageExtension = false;
        foreach ($imageExtensions as $ext) {
            if (stripos($url, $ext) !== false) {
                $hasImageExtension = true;
                break;
            }
        }

        if (!$hasImageExtension) {
            $errors[] = [
                'field' => 'image',
                'rule' => 'image_url',
                'message' => 'URL does not appear to point to an image file',
                'value' => $url
            ];
        }

        return $errors;
    }

    /**
     * Validate external image service data
     */
    private function validateExternalImage(array $data): array
    {
        $errors = [];

        if (empty($data['service_id'] ?? '')) {
            $errors[] = [
                'field' => 'service_id',
                'rule' => 'required',
                'message' => 'External service ID is required',
                'value' => null
            ];
        }

        if (empty($data['service_type'] ?? '')) {
            $errors[] = [
                'field' => 'service_type',
                'rule' => 'required',
                'message' => 'External service type is required',
                'value' => null
            ];
        }

        return $errors;
    }

    /**
     * Check if text contains dangerous HTML
     */
    private function containsDangerousHtml(string $text): bool
    {
        $dangerousPatterns = [
            '/<script\b[^>]*>(.*?)<\/script>/is',
            '/<iframe\b[^>]*>(.*?)<\/iframe>/is',
            '/onerror\s*=/i',
            '/onload\s*=/i',
            '/onclick\s*=/i',
            '/javascript:/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get field label from field name
     */
    private function getFieldLabel(string $field): string
    {
        $labels = [
            'name' => 'Product name',
            'slug' => 'Product slug',
            'description' => 'Description',
            'market_price' => 'Market price',
            'category_id' => 'Category',
            'image' => 'Product image',
            'status' => 'Product status'
        ];

        return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Extract rule name from error message
     */
    private function getRuleFromMessage(string $message): string
    {
        // Simple mapping - in production, this would be more sophisticated
        $ruleMap = [
            'required' => 'required',
            'max_length' => 'max_length',
            'min_length' => 'min_length',
            'alpha_dash' => 'alpha_dash',
            'decimal' => 'decimal',
            'greater_than_equal_to' => 'greater_than_equal_to',
            'max_decimal' => 'max_decimal',
            'integer' => 'integer',
            'greater_than' => 'greater_than',
            'in_list' => 'in_list'
        ];

        foreach ($ruleMap as $rule => $pattern) {
            if (stripos($message, $rule) !== false) {
                return $rule;
            }
        }

        return 'unknown';
    }

    /**
     * Build standardized validation result
     */
    private function buildValidationResult(bool $isValid, array $errors, string $context): array
    {
        return [
            'is_valid' => $isValid,
            'errors' => $errors,
            'context' => $context,
            'timestamp' => (new DateTimeImmutable())->format('c'),
            'error_count' => count($errors),
            'summary' => $isValid ? 'Validation passed' : 'Validation failed'
        ];
    }

    /**
     * Create ProductValidator instance with default dependencies
     */
    public static function create(): self
    {
        $validation = service('validation');
        $productModel = model(ProductModel::class);
        $categoryModel = model(CategoryModel::class);
        $linkModel = model(LinkModel::class);
        $cacheService = new CacheService(service('cache'));

        return new self(
            $validation,
            $productModel,
            $categoryModel,
            $linkModel,
            $cacheService
        );
    }
}
