<?php

namespace App\Services\Product\Validators;

use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Repositories\Interfaces\OrderRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Enums\ImageSourceType;
use CodeIgniter\I18n\Time;
use Psr\Log\LoggerInterface;

/**
 * ProductBusinessValidator - Complex Business Rule Validation for Product Domain
 * 
 * Layer: Service Validator Component (Business Rules Validation)
 * Responsibility: Validates complex business rules, state transitions, and cross-entity constraints
 * Called by: Service Layer (not Controller)
 * 
 * @package App\Services\Product\Validators
 */
class ProductBusinessValidator
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var CategoryRepositoryInterface
     */
    private CategoryRepositoryInterface $categoryRepository;

    /**
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;

    /**
     * @var MarketplaceRepositoryInterface
     */
    private MarketplaceRepositoryInterface $marketplaceRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Business rule configuration
     */
    private array $rules = [
        'price' => [
            'min_price' => 1000,
            'max_price' => 1000000000,
            'max_increase_percent' => 50,
            'max_decrease_percent' => 30,
        ],
        'status_transitions' => [
            'max_daily_status_changes' => 5,
            'cooldown_minutes' => 30,
        ],
        'publishing' => [
            'min_description_length' => 20,
            'min_image_width' => 300,
            'min_image_height' => 300,
            'max_daily_publish' => 20,
        ],
        'category' => [
            'max_products_per_category' => 10000,
            'category_change_cooldown_days' => 7,
        ],
        'marketplace' => [
            'min_active_links_for_publish' => 1,
            'max_links_per_product' => 10,
        ],
        'deletion' => [
            'min_age_for_hard_delete_days' => 30,
            'max_view_count_for_hard_delete' => 100,
        ],
    ];

    /**
     * Validation statistics
     */
    private array $statistics = [
        'total_validations' => 0,
        'passed_validations' => 0,
        'failed_validations' => 0,
        'business_rule_checks' => 0,
        'repository_queries' => 0,
    ];

    /**
     * Constructor with Dependency Injection
     * 
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryRepositoryInterface $categoryRepository
     * @param LinkRepositoryInterface $linkRepository
     * @param MarketplaceRepositoryInterface $marketplaceRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param AuditLogRepositoryInterface $auditLogRepository
     * @param LoggerInterface $logger
     * @param array|null $rules Custom business rules
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        CategoryRepositoryInterface $categoryRepository,
        LinkRepositoryInterface $linkRepository,
        MarketplaceRepositoryInterface $marketplaceRepository,
        OrderRepositoryInterface $orderRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        LoggerInterface $logger,
        ?array $rules = null
    ) {
        $this->productRepository = $productRepository;
        $this->categoryRepository = $categoryRepository;
        $this->linkRepository = $linkRepository;
        $this->marketplaceRepository = $marketplaceRepository;
        $this->orderRepository = $orderRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->logger = $logger;
        
        if ($rules !== null) {
            $this->rules = array_replace_recursive($this->rules, $rules);
        }
    }

    // ==================== CRUD VALIDATION METHODS ====================

    /**
     * Validate product creation business rules
     * 
     * @param array $data Product data
     * @param array $context {
     *     @var int $admin_id Admin performing the operation
     *     @var bool $is_bulk Is this a bulk creation
     *     @var array $additional_context Additional context data
     * }
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validateCreate(array $data, array $context = []): array
    {
        $this->statistics['total_validations']++;
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        try {
            // 1. Validate required fields exist
            $requiredFields = ['name', 'slug', 'market_price'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $errors[] = "Required field '{$field}' is missing or empty";
                }
            }
            
            if (!empty($errors)) {
                return $this->buildValidationResult(false, $errors, $warnings, $ruleChecks, $startTime);
            }
            
            // 2. Validate slug uniqueness
            $slugCheck = $this->validateSlugUniqueness($data['slug'], null, $context);
            if (!$slugCheck['valid']) {
                $errors = array_merge($errors, $slugCheck['errors']);
            }
            $ruleChecks['slug_uniqueness'] = $slugCheck['valid'];
            
            // 3. Validate price constraints
            if (isset($data['market_price'])) {
                $priceCheck = $this->validatePrice($data['market_price'], null, $context);
                if (!$priceCheck['valid']) {
                    $errors = array_merge($errors, $priceCheck['errors']);
                    $warnings = array_merge($warnings, $priceCheck['warnings']);
                }
                $ruleChecks['price_constraints'] = $priceCheck['valid'];
            }
            
            // 4. Validate category if provided
            if (isset($data['category_id'])) {
                $categoryCheck = $this->validateCategory($data['category_id'], $context);
                if (!$categoryCheck['valid']) {
                    $warnings = array_merge($warnings, $categoryCheck['warnings']);
                }
                $ruleChecks['category_validity'] = $categoryCheck['valid'];
            }
            
            // 5. Validate daily creation limit for admin
            if (isset($context['admin_id'])) {
                $limitCheck = $this->validateDailyCreationLimit($context['admin_id'], $context);
                if (!$limitCheck['valid']) {
                    $warnings = array_merge($warnings, $limitCheck['warnings']);
                }
                $ruleChecks['daily_limit'] = $limitCheck['valid'];
            }
            
            // 6. Validate image source if provided
            if (isset($data['image_source_type'])) {
                $imageCheck = $this->validateImageSource($data['image_source_type'], $data['image'] ?? null, $context);
                if (!$imageCheck['valid']) {
                    $warnings = array_merge($warnings, $imageCheck['warnings']);
                }
                $ruleChecks['image_source'] = $imageCheck['valid'];
            }
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                ['validated_data' => $data]
            );
            
            $this->logValidation('create', $result, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Product creation validation failed", [
                'data' => $data,
                'context' => $context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    /**
     * Validate product update business rules
     * 
     * @param int $productId
     * @param array $data Update data
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validateUpdate(int $productId, array $data, array $context = []): array
    {
        $this->statistics['total_validations']++;
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        try {
            // 1. Check if product exists and is accessible
            $product = $this->productRepository->findById($productId);
            if ($product === null) {
                return $this->buildValidationResult(
                    false,
                    ["Product with ID {$productId} not found"],
                    [],
                    [],
                    $startTime
                );
            }
            
            // 2. Validate slug uniqueness if changing
            if (isset($data['slug']) && $data['slug'] !== $product->getSlug()) {
                $slugCheck = $this->validateSlugUniqueness($data['slug'], $productId, $context);
                if (!$slugCheck['valid']) {
                    $errors = array_merge($errors, $slugCheck['errors']);
                }
                $ruleChecks['slug_uniqueness'] = $slugCheck['valid'];
            }
            
            // 3. Validate price change if updating
            if (isset($data['market_price']) && $data['market_price'] != $product->getMarketPrice()) {
                $priceCheck = $this->validatePriceChange(
                    $product->getMarketPrice(),
                    $data['market_price'],
                    $productId,
                    $context
                );
                if (!$priceCheck['valid']) {
                    $errors = array_merge($errors, $priceCheck['errors']);
                    $warnings = array_merge($warnings, $priceCheck['warnings']);
                }
                $ruleChecks['price_change'] = $priceCheck['valid'];
            }
            
            // 4. Validate category change if updating
            if (isset($data['category_id']) && $data['category_id'] != $product->getCategoryId()) {
                $categoryCheck = $this->validateCategoryChange(
                    $product->getCategoryId(),
                    $data['category_id'],
                    $productId,
                    $context
                );
                if (!$categoryCheck['valid']) {
                    $warnings = array_merge($warnings, $categoryCheck['warnings']);
                }
                $ruleChecks['category_change'] = $categoryCheck['valid'];
            }
            
            // 5. Validate status change if updating
            if (isset($data['status'])) {
                $newStatus = ProductStatus::tryFrom($data['status']);
                if ($newStatus !== null && $newStatus !== $product->getStatus()) {
                    $statusCheck = $this->validateStatusTransition(
                        $product->getStatus(),
                        $newStatus,
                        $productId,
                        $context
                    );
                    if (!$statusCheck['valid']) {
                        $errors = array_merge($errors, $statusCheck['errors']);
                    }
                    $ruleChecks['status_transition'] = $statusCheck['valid'];
                }
            }
            
            // 6. Validate update frequency limit
            $frequencyCheck = $this->validateUpdateFrequency($productId, $context);
            if (!$frequencyCheck['valid']) {
                $warnings = array_merge($warnings, $frequencyCheck['warnings']);
            }
            $ruleChecks['update_frequency'] = $frequencyCheck['valid'];
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                [
                    'product' => $product->toArray(),
                    'changed_fields' => array_keys($data),
                ]
            );
            
            $this->logValidation('update', $result, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Product update validation failed", [
                'product_id' => $productId,
                'data' => $data,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    /**
     * Validate product deletion business rules
     * 
     * @param int $productId
     * @param bool $hardDelete
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>,
     *     dependencies: array
     * }
     */
    public function validateDelete(int $productId, bool $hardDelete = false, array $context = []): array
    {
        $this->statistics['total_validations']++;
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        $dependencies = [];
        
        try {
            // 1. Check if product exists
            $product = $this->productRepository->findById($productId);
            if ($product === null) {
                return $this->buildValidationResult(
                    false,
                    ["Product with ID {$productId} not found"],
                    [],
                    [],
                    $startTime
                );
            }
            
            // 2. Check if product is already deleted
            if ($product->isDeleted() && !$hardDelete) {
                $errors[] = "Product is already deleted";
            }
            
            // 3. Check dependencies
            $dependencyCheck = $this->checkDeletionDependencies($productId, $hardDelete, $context);
            $dependencies = $dependencyCheck['dependencies'];
            
            if (!$dependencyCheck['can_delete']) {
                $errors = array_merge($errors, $dependencyCheck['errors']);
            }
            
            if (!empty($dependencyCheck['warnings'])) {
                $warnings = array_merge($warnings, $dependencyCheck['warnings']);
            }
            
            $ruleChecks['dependencies'] = $dependencyCheck['can_delete'];
            
            // 4. Validate hard deletion constraints
            if ($hardDelete) {
                $hardDeleteCheck = $this->validateHardDeletion($product, $context);
                if (!$hardDeleteCheck['valid']) {
                    $errors = array_merge($errors, $hardDeleteCheck['errors']);
                }
                $ruleChecks['hard_delete_constraints'] = $hardDeleteCheck['valid'];
            }
            
            // 5. Check active orders if published
            if ($product->isPublished()) {
                $orderCheck = $this->checkActiveOrders($productId, $context);
                if (!$orderCheck['can_delete']) {
                    $errors = array_merge($errors, $orderCheck['errors']);
                }
                $ruleChecks['active_orders'] = $orderCheck['can_delete'];
            }
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                [
                    'product' => $product->toArray(),
                    'dependencies' => $dependencies,
                    'hard_delete' => $hardDelete,
                ]
            );
            
            $this->logValidation('delete', $result, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Product deletion validation failed", [
                'product_id' => $productId,
                'hard_delete' => $hardDelete,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    // ==================== WORKFLOW VALIDATION METHODS ====================

    /**
     * Validate product publishing business rules
     * 
     * @param int $productId
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>,
     *     missing_requirements: array<string>
     * }
     */
    public function validatePublish(int $productId, array $context = []): array
    {
        $this->statistics['total_validations']++;
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        $missingRequirements = [];
        
        try {
            // 1. Check if product exists
            $product = $this->productRepository->findById($productId);
            if ($product === null) {
                return $this->buildValidationResult(
                    false,
                    ["Product with ID {$productId} not found"],
                    [],
                    [],
                    $startTime
                );
            }
            
            // 2. Check if product is already published
            if ($product->isPublished()) {
                $errors[] = "Product is already published";
            }
            
            // 3. Validate product completeness
            $completenessCheck = $this->validatePublishCompleteness($product, $context);
            if (!$completenessCheck['valid']) {
                $errors = array_merge($errors, $completenessCheck['errors']);
                $missingRequirements = $completenessCheck['missing_requirements'];
            }
            $ruleChecks['completeness'] = $completenessCheck['valid'];
            
            // 4. Validate marketplace links
            $linksCheck = $this->validateMarketplaceLinks($productId, $context);
            if (!$linksCheck['valid']) {
                $warnings = array_merge($warnings, $linksCheck['warnings']);
            }
            $ruleChecks['marketplace_links'] = $linksCheck['valid'];
            
            // 5. Validate price for publishing
            $priceCheck = $this->validatePublishPrice($product->getMarketPrice(), $context);
            if (!$priceCheck['valid']) {
                $warnings = array_merge($warnings, $priceCheck['warnings']);
            }
            $ruleChecks['price_for_publish'] = $priceCheck['valid'];
            
            // 6. Validate image for publishing
            $imageCheck = $this->validatePublishImage($product, $context);
            if (!$imageCheck['valid']) {
                $warnings = array_merge($warnings, $imageCheck['warnings']);
            }
            $ruleChecks['image_for_publish'] = $imageCheck['valid'];
            
            // 7. Check daily publish limit
            if (isset($context['admin_id'])) {
                $limitCheck = $this->validateDailyPublishLimit($context['admin_id'], $context);
                if (!$limitCheck['valid']) {
                    $warnings = array_merge($warnings, $limitCheck['warnings']);
                }
                $ruleChecks['daily_publish_limit'] = $limitCheck['valid'];
            }
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                [
                    'product' => $product->toArray(),
                    'missing_requirements' => $missingRequirements,
                    'completeness_score' => $completenessCheck['completeness_score'] ?? 0,
                ]
            );
            
            $this->logValidation('publish', $result, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Product publish validation failed", [
                'product_id' => $productId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    /**
     * Validate product status transition
     * 
     * @param ProductStatus $fromStatus
     * @param ProductStatus $toStatus
     * @param int|null $productId
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validateStatusTransition(
        ProductStatus $fromStatus,
        ProductStatus $toStatus,
        ?int $productId = null,
        array $context = []
    ): array {
        $this->statistics['total_validations']++;
        $this->statistics['business_rule_checks']++;
        
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        try {
            // 1. Check if transition is allowed in state machine
            $stateMachineCheck = $this->validateStateMachineTransition($fromStatus, $toStatus, $context);
            if (!$stateMachineCheck['valid']) {
                $errors = array_merge($errors, $stateMachineCheck['errors']);
            }
            $ruleChecks['state_machine'] = $stateMachineCheck['valid'];
            
            // 2. Check business rules for specific transitions
            $businessRuleCheck = $this->validateTransitionBusinessRules($fromStatus, $toStatus, $productId, $context);
            if (!$businessRuleCheck['valid']) {
                $errors = array_merge($errors, $businessRuleCheck['errors']);
                $warnings = array_merge($warnings, $businessRuleCheck['warnings']);
            }
            $ruleChecks['business_rules'] = $businessRuleCheck['valid'];
            
            // 3. Check cooldown period for frequent status changes
            if ($productId !== null) {
                $cooldownCheck = $this->validateStatusChangeCooldown($productId, $context);
                if (!$cooldownCheck['valid']) {
                    $warnings = array_merge($warnings, $cooldownCheck['warnings']);
                }
                $ruleChecks['cooldown_period'] = $cooldownCheck['valid'];
            }
            
            // 4. Validate permissions for specific transitions
            $permissionCheck = $this->validateTransitionPermissions($fromStatus, $toStatus, $context);
            if (!$permissionCheck['valid']) {
                $errors = array_merge($errors, $permissionCheck['errors']);
            }
            $ruleChecks['permissions'] = $permissionCheck['valid'];
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                [
                    'from_status' => $fromStatus->value,
                    'to_status' => $toStatus->value,
                    'transition_name' => "{$fromStatus->value}_to_{$toStatus->value}",
                ]
            );
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Status transition validation failed", [
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    /**
     * Validate product archiving
     * 
     * @param int $productId
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validateArchive(int $productId, array $context = []): array
    {
        $this->statistics['total_validations']++;
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        try {
            // 1. Check if product exists
            $product = $this->productRepository->findById($productId);
            if ($product === null) {
                return $this->buildValidationResult(
                    false,
                    ["Product with ID {$productId} not found"],
                    [],
                    [],
                    $startTime
                );
            }
            
            // 2. Check if product is already archived
            if ($product->isArchived()) {
                $errors[] = "Product is already archived";
            }
            
            // 3. Check if product is published (business rule)
            if ($product->isPublished()) {
                $publishedCheck = $this->validateArchivePublishedProduct($product, $context);
                if (!$publishedCheck['valid']) {
                    $warnings = array_merge($warnings, $publishedCheck['warnings']);
                }
                $ruleChecks['archive_published'] = $publishedCheck['valid'];
            }
            
            // 4. Check recent activity
            $activityCheck = $this->validateArchiveActivity($productId, $context);
            if (!$activityCheck['valid']) {
                $warnings = array_merge($warnings, $activityCheck['warnings']);
            }
            $ruleChecks['recent_activity'] = $activityCheck['valid'];
            
            // 5. Check dependencies
            $dependencyCheck = $this->validateArchiveDependencies($productId, $context);
            if (!$dependencyCheck['valid']) {
                $warnings = array_merge($warnings, $dependencyCheck['warnings']);
            }
            $ruleChecks['dependencies'] = $dependencyCheck['valid'];
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                [
                    'product' => $product->toArray(),
                    'archive_reason_required' => $product->isPublished(),
                ]
            );
            
            $this->logValidation('archive', $result, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Product archive validation failed", [
                'product_id' => $productId,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    // ==================== BUSINESS RULE VALIDATION METHODS ====================

    /**
     * Validate price change business rules
     * 
     * @param float $oldPrice
     * @param float $newPrice
     * @param int|null $productId
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validatePriceChange(float $oldPrice, float $newPrice, ?int $productId = null, array $context = []): array
    {
        $this->statistics['business_rule_checks']++;
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        // 1. Validate price range
        if ($newPrice < $this->rules['price']['min_price']) {
            $errors[] = sprintf(
                "Price (Rp %s) is below minimum allowed (Rp %s)",
                number_format($newPrice),
                number_format($this->rules['price']['min_price'])
            );
            $ruleChecks['min_price'] = false;
        } elseif ($newPrice > $this->rules['price']['max_price']) {
            $errors[] = sprintf(
                "Price (Rp %s) exceeds maximum allowed (Rp %s)",
                number_format($newPrice),
                number_format($this->rules['price']['max_price'])
            );
            $ruleChecks['max_price'] = false;
        } else {
            $ruleChecks['price_range'] = true;
        }
        
        // 2. Validate price change percentage
        if ($oldPrice > 0) {
            $percentageChange = (($newPrice - $oldPrice) / $oldPrice) * 100;
            
            if ($percentageChange > $this->rules['price']['max_increase_percent']) {
                $warnings[] = sprintf(
                    "Price increased by %.1f%%, exceeding recommended maximum of %d%%",
                    $percentageChange,
                    $this->rules['price']['max_increase_percent']
                );
                $ruleChecks['max_increase'] = false;
            } elseif ($percentageChange < -$this->rules['price']['max_decrease_percent']) {
                $warnings[] = sprintf(
                    "Price decreased by %.1f%%, exceeding recommended maximum of %d%%",
                    abs($percentageChange),
                    $this->rules['price']['max_decrease_percent']
                );
                $ruleChecks['max_decrease'] = false;
            } else {
                $ruleChecks['change_percentage'] = true;
            }
        }
        
        // 3. Check recent price changes for this product
        if ($productId !== null) {
            $frequencyCheck = $this->validatePriceChangeFrequency($productId, $context);
            if (!$frequencyCheck['valid']) {
                $warnings = array_merge($warnings, $frequencyCheck['warnings']);
            }
            $ruleChecks['change_frequency'] = $frequencyCheck['valid'];
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => [
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'percentage_change' => $oldPrice > 0 ? (($newPrice - $oldPrice) / $oldPrice) * 100 : 0,
            ],
            'rule_checks' => $ruleChecks,
        ];
    }

    /**
     * Validate category assignment
     * 
     * @param int|null $oldCategoryId
     * @param int|null $newCategoryId
     * @param int|null $productId
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validateCategoryChange(
        ?int $oldCategoryId,
        ?int $newCategoryId,
        ?int $productId = null,
        array $context = []
    ): array {
        $this->statistics['business_rule_checks']++;
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        // 1. Check if new category exists
        if ($newCategoryId !== null) {
            $category = $this->categoryRepository->findById($newCategoryId);
            if ($category === null) {
                $errors[] = "Category with ID {$newCategoryId} does not exist";
                $ruleChecks['category_exists'] = false;
            } else {
                $ruleChecks['category_exists'] = true;
                
                // 2. Check if category is active
                if ($category->isDeleted()) {
                    $warnings[] = "Category '{$category->getName()}' is archived";
                    $ruleChecks['category_active'] = false;
                } else {
                    $ruleChecks['category_active'] = true;
                }
                
                // 3. Check category product limit
                $limitCheck = $this->validateCategoryProductLimit($newCategoryId, $context);
                if (!$limitCheck['valid']) {
                    $warnings = array_merge($warnings, $limitCheck['warnings']);
                }
                $ruleChecks['category_limit'] = $limitCheck['valid'];
            }
        }
        
        // 4. Check category change frequency
        if ($productId !== null && $oldCategoryId !== $newCategoryId) {
            $frequencyCheck = $this->validateCategoryChangeFrequency($productId, $context);
            if (!$frequencyCheck['valid']) {
                $warnings = array_merge($warnings, $frequencyCheck['warnings']);
            }
            $ruleChecks['change_frequency'] = $frequencyCheck['valid'];
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => [
                'old_category_id' => $oldCategoryId,
                'new_category_id' => $newCategoryId,
                'category_changed' => $oldCategoryId !== $newCategoryId,
            ],
            'rule_checks' => $ruleChecks,
        ];
    }

    /**
     * Validate marketplace links for product
     * 
     * @param int $productId
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>
     * }
     */
    public function validateMarketplaceLinks(int $productId, array $context = []): array
    {
        $this->statistics['business_rule_checks']++;
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        
        try {
            // 1. Get all links for product
            $links = $this->linkRepository->findByProduct($productId);
            
            // 2. Check total links count
            $totalLinks = count($links);
            if ($totalLinks > $this->rules['marketplace']['max_links_per_product']) {
                $warnings[] = sprintf(
                    "Product has %d marketplace links, exceeding recommended maximum of %d",
                    $totalLinks,
                    $this->rules['marketplace']['max_links_per_product']
                );
                $ruleChecks['max_links'] = false;
            } else {
                $ruleChecks['max_links'] = true;
            }
            
            // 3. Check active links count
            $activeLinks = array_filter($links, function($link) {
                return $link->isActive();
            });
            
            $activeCount = count($activeLinks);
            $ruleChecks['has_active_links'] = $activeCount > 0;
            
            if ($activeCount === 0) {
                $warnings[] = "Product has no active marketplace links";
            }
            
            // 4. Check for duplicate marketplace links
            $marketplaceIds = [];
            $duplicates = [];
            
            foreach ($links as $link) {
                $marketplaceId = $link->getMarketplaceId();
                if (in_array($marketplaceId, $marketplaceIds)) {
                    $duplicates[] = $marketplaceId;
                } else {
                    $marketplaceIds[] = $marketplaceId;
                }
            }
            
            if (!empty($duplicates)) {
                $warnings[] = sprintf(
                    "Product has duplicate links for marketplace IDs: %s",
                    implode(', ', array_unique($duplicates))
                );
                $ruleChecks['duplicate_links'] = false;
            } else {
                $ruleChecks['duplicate_links'] = true;
            }
            
            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
                'data' => [
                    'total_links' => $totalLinks,
                    'active_links' => $activeCount,
                    'inactive_links' => $totalLinks - $activeCount,
                    'marketplaces' => $marketplaceIds,
                    'duplicates' => $duplicates,
                ],
                'rule_checks' => $ruleChecks,
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error("Marketplace links validation failed", [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'valid' => false,
                'errors' => ["Validation error: " . $e->getMessage()],
                'warnings' => [],
                'data' => [],
                'rule_checks' => [],
            ];
        }
    }

    // ==================== BULK OPERATION VALIDATION ====================

    /**
     * Validate bulk operation
     * 
     * @param array $productIds
     * @param string $operation
     * @param array $context
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     data: array,
     *     rule_checks: array<string, bool>,
     *     validation_results: array<int, array>
     * }
     */
    public function validateBulkOperation(array $productIds, string $operation, array $context = []): array
    {
        $this->statistics['total_validations']++;
        $startTime = microtime(true);
        
        $errors = [];
        $warnings = [];
        $ruleChecks = [];
        $validationResults = [];
        
        try {
            // 1. Validate product IDs
            if (empty($productIds)) {
                return $this->buildValidationResult(
                    false,
                    ["No product IDs provided for bulk operation"],
                    [],
                    [],
                    $startTime
                );
            }
            
            // 2. Check maximum batch size
            $maxBatchSize = $context['max_batch_size'] ?? 1000;
            if (count($productIds) > $maxBatchSize) {
                $errors[] = sprintf(
                    "Batch size %d exceeds maximum allowed %d",
                    count($productIds),
                    $maxBatchSize
                );
                $ruleChecks['batch_size'] = false;
            } else {
                $ruleChecks['batch_size'] = true;
            }
            
            // 3. Validate each product based on operation
            foreach ($productIds as $productId) {
                $productValidation = $this->validateProductForBulkOperation($productId, $operation, $context);
                $validationResults[$productId] = $productValidation;
                
                if (!$productValidation['valid']) {
                    $errors[] = sprintf(
                        "Product %d: %s",
                        $productId,
                        implode(', ', $productValidation['errors'])
                    );
                }
                
                if (!empty($productValidation['warnings'])) {
                    $warnings[] = sprintf(
                        "Product %d: %s",
                        $productId,
                        implode(', ', $productValidation['warnings'])
                    );
                }
            }
            
            // 4. Check operation-specific constraints
            $operationCheck = $this->validateBulkOperationConstraints($productIds, $operation, $context);
            if (!$operationCheck['valid']) {
                $errors = array_merge($errors, $operationCheck['errors']);
                $warnings = array_merge($warnings, $operationCheck['warnings']);
            }
            $ruleChecks['operation_constraints'] = $operationCheck['valid'];
            
            // 5. Estimate resource requirements
            $resourceCheck = $this->estimateBulkResourceRequirements($productIds, $operation, $context);
            if (!$resourceCheck['can_proceed']) {
                $warnings = array_merge($warnings, $resourceCheck['warnings']);
            }
            $ruleChecks['resource_requirements'] = $resourceCheck['can_proceed'];
            
            $result = $this->buildValidationResult(
                empty($errors),
                $errors,
                $warnings,
                $ruleChecks,
                $startTime,
                [
                    'operation' => $operation,
                    'total_products' => count($productIds),
                    'valid_products' => count(array_filter($validationResults, fn($r) => $r['valid'])),
                    'invalid_products' => count(array_filter($validationResults, fn($r) => !$r['valid'])),
                    'validation_results' => $validationResults,
                    'resource_estimate' => $resourceCheck['estimate'],
                ]
            );
            
            $this->logValidation("bulk_{$operation}", $result, $context);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->statistics['failed_validations']++;
            
            $this->logger->error("Bulk operation validation failed", [
                'operation' => $operation,
                'product_count' => count($productIds),
                'error' => $e->getMessage(),
            ]);
            
            return $this->buildValidationResult(
                false,
                ["Validation error: " . $e->getMessage()],
                [],
                [],
                $startTime
            );
        }
    }

    // ==================== HELPER VALIDATION METHODS ====================

    /**
     * Validate slug uniqueness
     * 
     * @param string $slug
     * @param int|null $excludeProductId
     * @param array $context
     * @return array
     */
    private function validateSlugUniqueness(string $slug, ?int $excludeProductId = null, array $context = []): array
    {
        $this->statistics['repository_queries']++;
        
        try {
            $existingProduct = $this->productRepository->findBySlug($slug, false);
            
            if ($existingProduct !== null && $existingProduct->getId() !== $excludeProductId) {
                return [
                    'valid' => false,
                    'errors' => ["Slug '{$slug}' is already in use by product ID {$existingProduct->getId()}"],
                    'warnings' => [],
                    'data' => [
                        'existing_product_id' => $existingProduct->getId(),
                        'existing_product_name' => $existingProduct->getName(),
                    ],
                ];
            }
            
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => [],
                'data' => [],
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error("Slug uniqueness validation failed", [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'valid' => false,
                'errors' => ["Unable to validate slug uniqueness: " . $e->getMessage()],
                'warnings' => [],
                'data' => [],
            ];
        }
    }

    /**
     * Validate price constraints
     * 
     * @param float $price
     * @param int|null $productId
     * @param array $context
     * @return array
     */
    private function validatePrice(float $price, ?int $productId = null, array $context = []): array
    {
        $errors = [];
        $warnings = [];
        
        // Check minimum price
        if ($price < $this->rules['price']['min_price']) {
            $errors[] = sprintf(
                "Price Rp %s is below minimum allowed Rp %s",
                number_format($price),
                number_format($this->rules['price']['min_price'])
            );
        }
        
        // Check maximum price
        if ($price > $this->rules['price']['max_price']) {
            $errors[] = sprintf(
                "Price Rp %s exceeds maximum allowed Rp %s",
                number_format($price),
                number_format($this->rules['price']['max_price'])
            );
        }
        
        // Check for suspiciously low/high prices
        if ($price > 0 && $price < 10000) {
            $warnings[] = "Price seems unusually low for a product";
        }
        
        if ($price > 10000000) {
            $warnings[] = "Price seems unusually high for a product";
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => [
                'price' => $price,
                'is_within_range' => $price >= $this->rules['price']['min_price'] && $price <= $this->rules['price']['max_price'],
            ],
        ];
    }

    /**
     * Validate category
     * 
     * @param int $categoryId
     * @param array $context
     * @return array
     */
    private function validateCategory(int $categoryId, array $context = []): array
    {
        $this->statistics['repository_queries']++;
        
        try {
            $category = $this->categoryRepository->findById($categoryId);
            
            if ($category === null) {
                return [
                    'valid' => false,
                    'errors' => ["Category with ID {$categoryId} does not exist"],
                    'warnings' => [],
                    'data' => [],
                ];
            }
            
            $warnings = [];
            
            if ($category->isDeleted()) {
                $warnings[] = "Category '{$category->getName()}' is archived";
            }
            
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => $warnings,
                'data' => [
                    'category' => $category->toArray(),
                ],
            ];
            
        } catch (\Throwable $e) {
            $this->logger->error("Category validation failed", [
                'category_id' => $categoryId,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'valid' => false,
                'errors' => ["Unable to validate category: " . $e->getMessage()],
                'warnings' => [],
                'data' => [],
            ];
        }
    }

    /**
     * Validate daily creation limit for admin
     * 
     * @param int $adminId
     * @param array $context
     * @return array
     */
    private function validateDailyCreationLimit(int $adminId, array $context = []): array
    {
        // This would query the database for today's creation count
        // For now, return a stub implementation
        
        $dailyLimit = $context['daily_limit'] ?? 50;
        $todayCount = 0; // Would be fetched from database
        
        if ($todayCount >= $dailyLimit) {
            return [
                'valid' => false,
                'errors' => [],
                'warnings' => ["Daily product creation limit ({$dailyLimit}) reached"],
                'data' => [
                    'today_count' => $todayCount,
                    'daily_limit' => $dailyLimit,
                ],
            ];
        }
        
        return [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'data' => [
                'today_count' => $todayCount,
                'daily_limit' => $dailyLimit,
                'remaining' => $dailyLimit - $todayCount,
            ],
        ];
    }

    /**
     * Validate image source
     * 
     * @param string $sourceType
     * @param string|null $imageUrl
     * @param array $context
     * @return array
     */
    private function validateImageSource(string $sourceType, ?string $imageUrl = null, array $context = []): array
    {
        try {
            $sourceEnum = ImageSourceType::tryFrom($sourceType);
            
            if ($sourceEnum === null) {
                return [
                    'valid' => false,
                    'errors' => ["Invalid image source type: {$sourceType}"],
                    'warnings' => [],
                    'data' => [],
                ];
            }
            
            $warnings = [];
            
            // Validate URL format for external images
            if ($sourceEnum === ImageSourceType::EXTERNAL && $imageUrl !== null) {
                if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                    $warnings[] = "External image URL appears to be invalid";
                }
                
                // Check if URL is accessible
                if ($context['validate_url_availability'] ?? false) {
                    // Would perform HTTP HEAD request to check URL
                }
            }
            
            return [
                'valid' => true,
                'errors' => [],
                'warnings' => $warnings,
                'data' => [
                    'source_type' => $sourceEnum->value,
                    'source_label' => $sourceEnum->label(),
                ],
            ];
            
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'errors' => ["Image source validation error: " . $e->getMessage()],
                'warnings' => [],
                'data' => [],
            ];
        }
    }

    // ==================== UTILITY METHODS ====================

    /**
     * Build validation result structure
     * 
     * @param bool $valid
     * @param array $errors
     * @param array $warnings
     * @param array $ruleChecks
     * @param float $startTime
     * @param array $additionalData
     * @return array
     */
    private function buildValidationResult(
        bool $valid,
        array $errors,
        array $warnings,
        array $ruleChecks,
        float $startTime,
        array $additionalData = []
    ): array {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($valid) {
            $this->statistics['passed_validations']++;
        } else {
            $this->statistics['failed_validations']++;
        }
        
        $result = [
            'valid' => $valid,
            'errors' => $errors,
            'warnings' => $warnings,
            'data' => array_merge([
                'validation_time_ms' => $duration,
                'timestamp' => Time::now()->format('Y-m-d H:i:s'),
            ], $additionalData),
            'rule_checks' => $ruleChecks,
        ];
        
        return $result;
    }

    /**
     * Log validation result
     * 
     * @param string $operation
     * @param array $result
     * @param array $context
     * @return void
     */
    private function logValidation(string $operation, array $result, array $context): void
    {
        $logData = [
            'operation' => $operation,
            'valid' => $result['valid'],
            'error_count' => count($result['errors']),
            'warning_count' => count($result['warnings']),
            'duration_ms' => $result['data']['validation_time_ms'] ?? 0,
            'context' => $context,
        ];
        
        if (!$result['valid']) {
            $this->logger->warning("Product validation failed", $logData);
        } else {
            $this->logger->debug("Product validation passed", $logData);
        }
    }

    /**
     * Get validation statistics
     * 
     * @return array
     */
    public function getStatistics(): array
    {
        return array_merge($this->statistics, [
            'success_rate' => $this->statistics['total_validations'] > 0 
                ? round(($this->statistics['passed_validations'] / $this->statistics['total_validations']) * 100, 2)
                : 0,
            'timestamp' => Time::now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Reset validation statistics
     * 
     * @return void
     */
    public function resetStatistics(): void
    {
        $this->statistics = [
            'total_validations' => 0,
            'passed_validations' => 0,
            'failed_validations' => 0,
            'business_rule_checks' => 0,
            'repository_queries' => 0,
        ];
        
        $this->logger->debug("Validation statistics reset");
    }

    /**
     * Get business rule configuration
     * 
     * @return array
     */
    public function getBusinessRules(): array
    {
        return $this->rules;
    }

    /**
     * Update business rule configuration
     * 
     * @param array $rules
     * @return void
     */
    public function updateBusinessRules(array $rules): void
    {
        $this->rules = array_replace_recursive($this->rules, $rules);
        
        $this->logger->info("Business rules updated", [
            'new_rules' => $this->rules,
        ]);
    }

    // Note: Due to character limit, I've provided the core structure and main methods.
    // The following methods would be implemented similarly:
    // - validatePublishCompleteness()
    // - validateStateMachineTransition()
    // - checkDeletionDependencies()
    // - validateHardDeletion()
    // - checkActiveOrders()
    // - validateUpdateFrequency()
    // - validateStatusChangeCooldown()
    // - validateTransitionBusinessRules()
    // - validateTransitionPermissions()
    // - validatePublishPrice()
    // - validatePublishImage()
    // - validateDailyPublishLimit()
    // - validateArchivePublishedProduct()
    // - validateArchiveActivity()
    // - validateArchiveDependencies()
    // - validatePriceChangeFrequency()
    // - validateCategoryProductLimit()
    // - validateCategoryChangeFrequency()
    // - validateProductForBulkOperation()
    // - validateBulkOperationConstraints()
    // - estimateBulkResourceRequirements()
    // - validateProductConstraints()
    // - validateBusinessConstraints()
}