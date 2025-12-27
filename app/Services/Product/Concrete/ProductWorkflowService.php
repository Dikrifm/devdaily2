<?php

namespace App\Services\Product\Concrete;

use App\Services\BaseService;
use App\Contracts\ProductWorkflowInterface;
use App\DTOs\Requests\Product\PublishProductRequest;
use App\DTOs\Requests\Product\ProductToggleStatusRequest;
use App\DTOs\Responses\ProductResponse;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Validators\ProductValidator;
use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use CodeIgniter\I18n\Time;

/**
 * ProductWorkflowService - Concrete Implementation for Product Status & Workflow Operations
 * Layer 5: Business Orchestrator (Workflow-specific)
 * Implements ONLY methods from ProductWorkflowInterface
 * 
 * @package App\Services\Product\Concrete
 */
class ProductWorkflowService extends BaseService implements ProductWorkflowInterface
{
    private ProductRepositoryInterface $productRepository;
    private AuditLogRepositoryInterface $auditLogRepository;
    private ProductValidator $productValidator;
    
    private array $workflowStats = [
        'status_transitions' => 0,
        'failed_transitions' => 0,
        'scheduled_operations' => 0
    ];
    
    public function __construct(
        \CodeIgniter\Database\ConnectionInterface $db,
        \App\Contracts\CacheInterface $cache,
        \App\Services\AuditService $auditService,
        ProductRepositoryInterface $productRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        ProductValidator $productValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->productRepository = $productRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->productValidator = $productValidator;
    }
    
    // ==================== REQUIRED BY BASE SERVICE ====================
    
    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'ProductWorkflowService';
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(\App\DTOs\BaseDTO $dto, array $context = []): array
    {
        if ($dto instanceof PublishProductRequest) {
            return $this->validatePublishBusinessRules($dto, $context);
        }
        
        if ($dto instanceof ProductToggleStatusRequest) {
            return $this->validateToggleStatusBusinessRules($dto, $context);
        }
        
        return [];
    }
    
    // ==================== STATUS TRANSITIONS ====================
    
    /**
     * {@inheritDoc}
     */
    public function publishProduct(PublishProductRequest $request): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($request->getAdminId());
        $this->authorize('product.publish');
        
        return $this->transaction(function() use ($request) {
            $product = $this->productRepository->findById($request->getProductId());
            
            if ($product === null) {
                throw ProductNotFoundException::forId($request->getProductId());
            }
            
            // Validate publish prerequisites
            $prerequisites = $this->validateForPublication($product->getId());
            if (!$prerequisites['valid'] && !$request->isForcePublish()) {
                throw ValidationException::forBusinessRule(
                    'publish_prerequisites',
                    'Product cannot be published: ' . implode(', ', $prerequisites['errors']),
                    $prerequisites
                );
            }
            
            $oldValues = $product->toArray();
            
            // Publish product
            if ($request->isScheduled()) {
                // Handle scheduled publish
                $product->setStatus(ProductStatus::PENDING_PUBLICATION);
                // Store scheduled time in metadata
                $scheduledAt = $request->getScheduledAt()->format('Y-m-d H:i:s');
            } else {
                // Immediate publish
                $product->publish();
                if ($request->getAdminId()) {
                    $product->setVerifiedBy($request->getAdminId());
                }
            }
            
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($product) {
                $this->invalidateProductCache($product->getId());
            });
            
            // Audit log
            $this->audit(
                'PUBLISH',
                'PRODUCT',
                $product->getId(),
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'published_by' => $request->getAdminId(),
                    'scheduled' => $request->isScheduled(),
                    'force_publish' => $request->isForcePublish(),
                    'notes' => $request->getNotes()
                ]
            );
            
            return ProductResponse::fromEntity($updatedProduct, ['admin_mode' => true]);
        }, 'publish_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function verifyProduct(int $productId, int $adminId, ?string $notes = null): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.verify');
        
        $validationResult = $this->productValidator->validateVerify($productId);
        
        if (!$validationResult['valid']) {
            throw ValidationException::forField(
                'product_verify',
                'Product verification validation failed',
                $validationResult['errors']
            );
        }
        
        return $this->transaction(function() use ($productId, $adminId, $notes) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            $oldValues = $product->toArray();
            
            // Verify product
            $success = $this->productRepository->verify($productId, $adminId);
            
            if (!$success) {
                throw new DomainException(
                    'Failed to verify product',
                    'VERIFICATION_FAILED',
                    500
                );
            }
            
            // Refresh product data
            $verifiedProduct = $this->productRepository->findById($productId);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'VERIFY',
                'PRODUCT',
                $productId,
                $oldValues,
                $verifiedProduct->toArray(),
                [
                    'verified_by' => $adminId,
                    'notes' => $notes
                ]
            );
            
            return ProductResponse::fromEntity($verifiedProduct, ['admin_mode' => true]);
        }, 'verify_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function requestVerification(int $productId, int $adminId): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.request_verification');
        
        return $this->transaction(function() use ($productId, $adminId) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Check if product can request verification
            if (!$product->getStatus()->canRequestVerification()) {
                throw new DomainException(
                    'Product cannot request verification from current status',
                    'INVALID_STATUS_TRANSITION',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            
            // Update to pending verification
            $product->setStatus(ProductStatus::PENDING_VERIFICATION);
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'REQUEST_VERIFICATION',
                'PRODUCT',
                $productId,
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'requested_by' => $adminId
                ]
            );
            
            return ProductResponse::fromEntity($updatedProduct, ['admin_mode' => true]);
        }, 'request_verification');
    }
    
    /**
     * {@inheritDoc}
     */
    public function archiveProduct(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.archive');
        
        $validationResult = $this->productValidator->validateArchive($productId);
        
        if (!$validationResult['valid']) {
            throw ValidationException::forField(
                'product_archive',
                'Product archive validation failed',
                $validationResult['errors']
            );
        }
        
        return $this->transaction(function() use ($productId, $adminId, $reason) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            $oldValues = $product->toArray();
            
            // Archive product
            $success = $this->productRepository->archive($productId, $adminId);
            
            if (!$success) {
                throw new DomainException(
                    'Failed to archive product',
                    'ARCHIVE_FAILED',
                    500
                );
            }
            
            // Refresh product data
            $archivedProduct = $this->productRepository->findById($productId, false);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'ARCHIVE',
                'PRODUCT',
                $productId,
                $oldValues,
                $archivedProduct->toArray(),
                [
                    'archived_by' => $adminId,
                    'reason' => $reason
                ]
            );
            
            return ProductResponse::fromEntity($archivedProduct, ['admin_mode' => true]);
        }, 'archive_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function unarchiveProduct(int $productId, int $adminId): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.unarchive');
        
        return $this->transaction(function() use ($productId, $adminId) {
            $product = $this->productRepository->findById($productId, false);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            if (!$product->isDeleted()) {
                throw new DomainException(
                    'Product is not archived',
                    'PRODUCT_NOT_ARCHIVED',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            
            // Unarchive product (restore to DRAFT status)
            $product->setStatus(ProductStatus::DRAFT);
            $product->setDeletedAt(null);
            $unarchivedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'UNARCHIVE',
                'PRODUCT',
                $productId,
                $oldValues,
                $unarchivedProduct->toArray(),
                [
                    'unarchived_by' => $adminId,
                    'previous_status' => $oldValues['status']
                ]
            );
            
            return ProductResponse::fromEntity($unarchivedProduct, ['admin_mode' => true]);
        }, 'unarchive_product');
    }
    
    /**
     * {@inheritDoc}
     */
    public function toggleProductStatus(ProductToggleStatusRequest $request): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($request->getAdminId());
        $this->authorize('product.update.status');
        
        return $this->transaction(function() use ($request) {
            $product = $this->productRepository->findById($request->getProductId());
            
            if ($product === null) {
                throw ProductNotFoundException::forId($request->getProductId());
            }
            
            $oldValues = $product->toArray();
            
            // Check if transition is allowed
            if (!$product->getStatus()->canTransitionTo($request->getTargetStatus()) 
                && !$request->isForceStatusChange()) {
                throw new DomainException(
                    sprintf(
                        'Cannot transition from %s to %s',
                        $product->getStatus()->label(),
                        $request->getTargetStatus()->label()
                    ),
                    'INVALID_STATUS_TRANSITION',
                    400
                );
            }
            
            // Update status
            $product->setStatus($request->getTargetStatus());
            
            // Special handling for certain statuses
            if ($request->getTargetStatus() === ProductStatus::VERIFIED) {
                $product->setVerifiedAt(new \DateTimeImmutable());
                $product->setVerifiedBy($request->getAdminId());
            }
            
            if ($request->getTargetStatus() === ProductStatus::PUBLISHED) {
                $product->setPublishedAt(new \DateTimeImmutable());
            }
            
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($product) {
                $this->invalidateProductCache($product->getId());
            });
            
            // Audit log
            $this->audit(
                'STATUS_CHANGE',
                'PRODUCT',
                $product->getId(),
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'changed_by' => $request->getAdminId(),
                    'old_status' => $oldValues['status'],
                    'new_status' => $request->getTargetStatus()->value,
                    'force_change' => $request->isForceStatusChange(),
                    'notes' => $request->getNotes()
                ]
            );
            
            return ProductResponse::fromEntity($updatedProduct, ['admin_mode' => true]);
        }, 'toggle_product_status');
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
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.update.status');
        
        return $this->transaction(function() use ($productId, $status, $adminId, $notes, $force) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            $oldValues = $product->toArray();
            
            // Check if transition is allowed
            if (!$product->getStatus()->canTransitionTo($status) && !$force) {
                throw new DomainException(
                    sprintf(
                        'Cannot transition from %s to %s without force flag',
                        $product->getStatus()->label(),
                        $status->label()
                    ),
                    'INVALID_STATUS_TRANSITION',
                    400
                );
            }
            
            // Update status
            $product->setStatus($status);
            
            // Update timestamps for specific statuses
            if ($status === ProductStatus::VERIFIED) {
                $product->setVerifiedAt(new \DateTimeImmutable());
                $product->setVerifiedBy($adminId);
            }
            
            if ($status === ProductStatus::PUBLISHED) {
                $product->setPublishedAt(new \DateTimeImmutable());
            }
            
            if ($status === ProductStatus::ARCHIVED) {
                $product->setDeletedAt(new \DateTimeImmutable());
            }
            
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'STATUS_UPDATE',
                'PRODUCT',
                $productId,
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'updated_by' => $adminId,
                    'old_status' => $oldValues['status'],
                    'new_status' => $status->value,
                    'force_update' => $force,
                    'notes' => $notes
                ]
            );
            
            return ProductResponse::fromEntity($updatedProduct, ['admin_mode' => true]);
        }, 'update_product_status');
    }
    
    /**
     * {@inheritDoc}
     */
    public function revertToDraft(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.revert');
        
        return $this->transaction(function() use ($productId, $adminId, $reason) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Check if can revert to draft
            if (!$product->getStatus()->canRevertToDraft()) {
                throw new DomainException(
                    'Cannot revert product to draft from current status',
                    'INVALID_STATUS_TRANSITION',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            
            // Revert to draft
            $product->setStatus(ProductStatus::DRAFT);
            $product->setVerifiedAt(null);
            $product->setVerifiedBy(null);
            $product->setPublishedAt(null);
            
            $revertedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'REVERT_TO_DRAFT',
                'PRODUCT',
                $productId,
                $oldValues,
                $revertedProduct->toArray(),
                [
                    'reverted_by' => $adminId,
                    'reason' => $reason,
                    'previous_status' => $oldValues['status']
                ]
            );
            
            return ProductResponse::fromEntity($revertedProduct, ['admin_mode' => true]);
        }, 'revert_to_draft');
    }
    
    // ==================== STATUS VALIDATION ====================
    
    /**
     * {@inheritDoc}
     */
    public function canTransitionTo(
        int $productId,
        ProductStatus $targetStatus,
        bool $includeBusinessRules = true
    ): array {
        $product = $this->productRepository->findById($productId);
        
        if ($product === null) {
            return [
                'allowed' => false,
                'current_status' => null,
                'target_status' => $targetStatus,
                'state_machine_allowed' => false,
                'business_rules_allowed' => false,
                'reasons' => ['Product not found'],
                'requirements' => []
            ];
        }
        
        $currentStatus = $product->getStatus();
        $stateMachineAllowed = $currentStatus->canTransitionTo($targetStatus);
        
        $businessRulesAllowed = true;
        $reasons = [];
        $requirements = [];
        
        if ($includeBusinessRules) {
            // Check business rules based on target status
            switch ($targetStatus) {
                case ProductStatus::PUBLISHED:
                    $validation = $this->validateForPublication($productId);
                    $businessRulesAllowed = $validation['valid'];
                    $reasons = $validation['errors'];
                    $requirements = $validation['missing_requirements'] ?? [];
                    break;
                    
                case ProductStatus::VERIFIED:
                    $validation = $this->validateForVerification($productId);
                    $businessRulesAllowed = $validation['valid'];
                    $reasons = $validation['errors'];
                    break;
                    
                case ProductStatus::ARCHIVED:
                    $validation = $this->canArchiveProduct($productId);
                    $businessRulesAllowed = $validation['can_archive'];
                    $reasons = $validation['reasons'];
                    break;
            }
        }
        
        return [
            'allowed' => $stateMachineAllowed && $businessRulesAllowed,
            'current_status' => $currentStatus,
            'target_status' => $targetStatus,
            'state_machine_allowed' => $stateMachineAllowed,
            'business_rules_allowed' => $businessRulesAllowed,
            'reasons' => $reasons,
            'requirements' => $requirements
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateForPublication(int $productId): array
    {
        $product = $this->productRepository->findById($productId);
        
        if ($product === null) {
            return [
                'valid' => false,
                'errors' => ['Product not found'],
                'warnings' => [],
                'has_warnings' => false,
                'requirements_met' => false,
                'missing_requirements' => ['Product not found']
            ];
        }
        
        $errors = [];
        $warnings = [];
        $missingRequirements = [];
        
        // Required fields
        if (empty($product->getName())) {
            $errors[] = 'Product name is required';
            $missingRequirements[] = 'name';
        }
        
        if (empty($product->getMarketPrice()) || $product->getMarketPrice() === '0.00') {
            $errors[] = 'Product price is required';
            $missingRequirements[] = 'market_price';
        }
        
        if ($product->getCategoryId() === null) {
            $errors[] = 'Product category is required';
            $missingRequirements[] = 'category_id';
        }
        
        if (!$product->getImage() && !$product->getImagePath()) {
            $errors[] = 'Product image is required';
            $missingRequirements[] = 'image';
        }
        
        // Business rules
        if (!$product->isVerified() && !$product->isPublished()) {
            $warnings[] = 'Product is not verified (can be force published)';
        }
        
        // Check if product has at least one active link
        // Note: This would require LinkRepository dependency
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
            'has_warnings' => !empty($warnings),
            'requirements_met' => empty($errors),
            'missing_requirements' => $missingRequirements,
            'product_status' => $product->getStatus()->value
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateForVerification(int $productId): array
    {
        $product = $this->productRepository->findById($productId);
        
        if ($product === null) {
            return [
                'valid' => false,
                'errors' => ['Product not found'],
                'warnings' => []
            ];
        }
        
        $errors = [];
        $warnings = [];
        
        // Basic requirements for verification
        if (empty($product->getName())) {
            $errors[] = 'Product name is required';
        }
        
        if (empty($product->getDescription())) {
            $warnings[] = 'Product description is empty';
        }
        
        if (empty($product->getMarketPrice()) || $product->getMarketPrice() === '0.00') {
            $errors[] = 'Product price is required';
        }
        
        if ($product->getCategoryId() === null) {
            $errors[] = 'Product category is required';
        }
        
        if (!$product->getImage() && !$product->getImagePath()) {
            $errors[] = 'Product image is required';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function canPublishProduct(int $productId): array
    {
        $validation = $this->validateForPublication($productId);
        
        $requirements = [];
        if ($validation['requirements_met']) {
            $requirements[] = '✓ All required fields are filled';
            $requirements[] = '✓ Product has valid data';
        }
        
        if (!empty($validation['warnings'])) {
            foreach ($validation['warnings'] as $warning) {
                $requirements[] = '⚠ ' . $warning;
            }
        }
        
        return [
            'can_publish' => $validation['valid'],
            'reasons' => $validation['errors'],
            'requirements' => $requirements,
            'has_warnings' => $validation['has_warnings'],
            'product_status' => $validation['product_status']
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function canArchiveProduct(int $productId): array
    {
        $product = $this->productRepository->findById($productId);
        
        if ($product === null) {
            return [
                'can_archive' => false,
                'reasons' => ['Product not found'],
                'warnings' => []
            ];
        }
        
        $reasons = [];
        $warnings = [];
        
        // Business rules for archiving
        if ($product->isPublished()) {
            $warnings[] = 'Product is currently published';
        }
        
        if ($product->getViewCount() > 1000) {
            $warnings[] = 'Product has high view count (' . $product->getViewCount() . ')';
        }
        
        // Check if product has active orders or dependencies
        // This would require additional repository checks
        
        return [
            'can_archive' => empty($reasons),
            'reasons' => $reasons,
            'warnings' => $warnings
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function getAllowedTransitions(int $productId): array
    {
        $product = $this->productRepository->findById($productId);
        
        if ($product === null) {
            return [];
        }
        
        $currentStatus = $product->getStatus();
        $allStatuses = ProductStatus::cases();
        $allowedTransitions = [];
        
        foreach ($allStatuses as $status) {
            if ($currentStatus->canTransitionTo($status)) {
                $allowedTransitions[] = $status;
            }
        }
        
        return $allowedTransitions;
    }
    
    // ==================== WORKFLOW ACTIONS ====================
    
    /**
     * {@inheritDoc}
     */
    public function sendForReview(int $productId, int $adminId, ?string $notes = null): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.review');
        
        return $this->transaction(function() use ($productId, $adminId, $notes) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            $oldValues = $product->toArray();
            
            // Mark as under review
            $product->setStatus(ProductStatus::PENDING_VERIFICATION);
            $product->setLastReviewedBy($adminId);
            $product->setLastReviewedAt(new \DateTimeImmutable());
            
            $reviewedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'SEND_FOR_REVIEW',
                'PRODUCT',
                $productId,
                $oldValues,
                $reviewedProduct->toArray(),
                [
                    'sent_by' => $adminId,
                    'notes' => $notes
                ]
            );
            
            return ProductResponse::fromEntity($reviewedProduct, ['admin_mode' => true]);
        }, 'send_for_review');
    }
    
    /**
     * {@inheritDoc}
     */
    public function rejectProduct(int $productId, int $adminId, string $reason): ProductResponse
    {
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.reject');
        
        return $this->transaction(function() use ($productId, $adminId, $reason) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Check if product can be rejected (must be in verification)
            if ($product->getStatus() !== ProductStatus::PENDING_VERIFICATION) {
                throw new DomainException(
                    'Only products pending verification can be rejected',
                    'INVALID_STATUS_FOR_REJECTION',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            
            // Reject and revert to draft
            $product->setStatus(ProductStatus::DRAFT);
            $product->setRejectionReason($reason);
            $product->setRejectedBy($adminId);
            $product->setRejectedAt(new \DateTimeImmutable());
            
            $rejectedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'REJECT',
                'PRODUCT',
                $productId,
                $oldValues,
                $rejectedProduct->toArray(),
                [
                    'rejected_by' => $adminId,
                    'reason' => $reason
                ]
            );
            
            return ProductResponse::fromEntity($rejectedProduct, ['admin_mode' => true]);
        }, 'reject_product');
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
        $this->workflowStats['scheduled_operations']++;
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.schedule');
        
        return $this->transaction(function() use ($productId, $publishAt, $adminId, $notes) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Validate product can be scheduled
            if (!$product->getStatus()->canBeScheduled()) {
                throw new DomainException(
                    'Product cannot be scheduled from current status',
                    'INVALID_STATUS_FOR_SCHEDULING',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            
            // Mark as scheduled
            $product->setStatus(ProductStatus::PENDING_PUBLICATION);
            $product->setScheduledAt($publishAt);
            $product->setScheduledBy($adminId);
            
            $scheduledProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'SCHEDULE_PUBLICATION',
                'PRODUCT',
                $productId,
                $oldValues,
                $scheduledProduct->toArray(),
                [
                    'scheduled_by' => $adminId,
                    'publish_at' => $publishAt->format('Y-m-d H:i:s'),
                    'notes' => $notes
                ]
            );
            
            return ProductResponse::fromEntity($scheduledProduct, ['admin_mode' => true]);
        }, 'schedule_publication');
    }
    
    /**
     * {@inheritDoc}
     */
    public function cancelScheduledPublication(int $productId, int $adminId, ?string $reason = null): ProductResponse
    {
        $this->workflowStats['scheduled_operations']++;
        $this->workflowStats['status_transitions']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.cancel_schedule');
        
        return $this->transaction(function() use ($productId, $adminId, $reason) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Check if product is scheduled
            if ($product->getStatus() !== ProductStatus::PENDING_PUBLICATION || $product->getScheduledAt() === null) {
                throw new DomainException(
                    'Product is not scheduled for publication',
                    'PRODUCT_NOT_SCHEDULED',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            $scheduledAt = $product->getScheduledAt();
            
            // Cancel schedule and revert to previous status (DRAFT or VERIFIED)
            $previousStatus = $product->wasVerified() ? ProductStatus::VERIFIED : ProductStatus::DRAFT;
            $product->setStatus($previousStatus);
            $product->setScheduledAt(null);
            $product->setScheduledBy(null);
            
            $cancelledProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'CANCEL_SCHEDULED_PUBLICATION',
                'PRODUCT',
                $productId,
                $oldValues,
                $cancelledProduct->toArray(),
                [
                    'cancelled_by' => $adminId,
                    'scheduled_at' => $scheduledAt ? $scheduledAt->format('Y-m-d H:i:s') : null,
                    'reason' => $reason,
                    'new_status' => $previousStatus->value
                ]
            );
            
            return ProductResponse::fromEntity($cancelledProduct, ['admin_mode' => true]);
        }, 'cancel_scheduled_publication');
    }
    
    /**
     * {@inheritDoc}
     */
    public function getScheduledPublications(?int $limit = null, int $offset = 0): array
    {
        $this->authorize('product.view_scheduled');
        
        $products = $this->productRepository->findByStatus(
            ProductStatus::PENDING_PUBLICATION,
            $limit,
            $offset,
            ['scheduled_at' => 'ASC']
        );
        
        return array_map(
            fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
            $products
        );
    }
    
    /**
     * {@inheritDoc}
     */
    public function processScheduledPublications(int $batchSize = 50): array
    {
        $this->authorize('product.process_scheduled');
        
        $now = new \DateTimeImmutable();
        $processed = 0;
        $succeeded = [];
        $failed = [];
        
        // Get products scheduled for publication
        $scheduledProducts = $this->productRepository->findScheduledForPublication($now, $batchSize);
        
        foreach ($scheduledProducts as $product) {
            try {
                $this->transaction(function() use ($product, $now) {
                    // Publish the product
                    $product->setStatus(ProductStatus::PUBLISHED);
                    $product->setPublishedAt($now);
                    $product->setScheduledAt(null);
                    
                    $this->productRepository->save($product);
                    
                    // Queue cache invalidation
                    $this->queueCacheOperation(function() use ($product) {
                        $this->invalidateProductCache($product->getId());
                    });
                    
                    // Audit log
                    $this->audit(
                        'PROCESS_SCHEDULED_PUBLICATION',
                        'PRODUCT',
                        $product->getId(),
                        null,
                        $product->toArray(),
                        [
                            'processed_at' => $now->format('Y-m-d H:i:s'),
                            'was_scheduled' => true
                        ]
                    );
                });
                
                $succeeded[] = $product->getId();
                $processed++;
            } catch (\Throwable $e) {
                $failed[$product->getId()] = $e->getMessage();
                log_message('error', "Failed to process scheduled publication for product {$product->getId()}: " . $e->getMessage());
            }
        }
        
        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'failed' => $failed
        ];
    }
    
    // ==================== STATUS QUERIES ====================
    
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
        if ($adminMode) {
            $this->authorize('product.list');
        }
        
        $products = $this->productRepository->findByStatus($status, $limit, $offset);
        
        return array_map(
            fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => $adminMode]),
            $products
        );
    }
    
    /**
     * {@inheritDoc}
     */
    public function countProductsByStatus(?ProductStatus $status = null, bool $includeArchived = false)
    {
        if ($status === null) {
            // Return array of all status counts
            $counts = [];
            foreach (ProductStatus::cases() as $productStatus) {
                $counts[$productStatus->value] = $this->productRepository->countByStatus($productStatus, $includeArchived);
            }
            return $counts;
        }
        
        return $this->productRepository->countByStatus($status, $includeArchived);
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsNeedingVerification(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->authorize('product.view_pending');
        
        $products = $this->productRepository->findByStatus(
            ProductStatus::PENDING_VERIFICATION,
            $limit,
            $offset,
            ['created_at' => 'ASC']
        );
        
        return array_map(
            fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
            $products
        );
    }
    
    /**
     * {@inheritDoc}
     */
    public function getProductsPendingPublication(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array {
        $this->authorize('product.view_scheduled');
        
        $products = $this->productRepository->findByStatus(
            ProductStatus::PENDING_PUBLICATION,
            $limit,
            $offset,
            ['scheduled_at' => 'ASC']
        );
        
        return array_map(
            fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
            $products
        );
    }
    
    // ==================== WORKFLOW ANALYTICS ====================
    
    /**
     * {@inheritDoc}
     */
    public function getWorkflowStatistics(string $period = 'month'): array
    {
        $this->authorize('product.view_statistics');
        
        // Calculate date range based on period
        $endDate = new \DateTimeImmutable();
        $startDate = match($period) {
            'day' => $endDate->modify('-1 day'),
            'week' => $endDate->modify('-1 week'),
            'month' => $endDate->modify('-1 month'),
            'year' => $endDate->modify('-1 year'),
            default => $endDate->modify('-1 month'),
        };
        
        // Get transition counts from audit log
        $transitions = $this->auditLogRepository->getTransitionCounts(
            'PRODUCT',
            $startDate,
            $endDate
        );
        
        // Calculate average times
        $averageTimes = $this->calculateAverageStatusTimes($startDate, $endDate);
        
        // Identify bottlenecks (statuses with longest average times)
        $bottlenecks = $this->identifyWorkflowBottlenecks($averageTimes);
        
        return [
            'period' => $period,
            'date_range' => [
                'start' => $startDate->format('Y-m-d H:i:s'),
                'end' => $endDate->format('Y-m-d H:i:s')
            ],
            'transitions' => $transitions,
            'average_times' => $averageTimes,
            'bottlenecks' => $bottlenecks,
            'total_products' => $this->productRepository->count(),
            'generated_at' => $endDate->format('Y-m-d H:i:s')
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function getStatusHistory(int $productId, ?int $limit = null, int $offset = 0): array
    {
        $this->authorize('product.view_history');
        
        return $this->auditLogRepository->getStatusHistory(
            'PRODUCT',
            $productId,
            $limit,
            $offset
        );
    }
    
    /**
     * {@inheritDoc}
     */
    public function getAverageStatusTimes(string $period = 'month'): array
    {
        $this->authorize('product.view_statistics');
        
        $endDate = new \DateTimeImmutable();
        $startDate = match($period) {
            'day' => $endDate->modify('-1 day'),
            'week' => $endDate->modify('-1 week'),
            'month' => $endDate->modify('-1 month'),
            'year' => $endDate->modify('-1 year'),
            default => $endDate->modify('-1 month'),
        };
        
        return $this->calculateAverageStatusTimes($startDate, $endDate);
    }
    
    /**
     * {@inheritDoc}
     */
    public function getWorkflowBottlenecks(int $thresholdHours = 72, int $limit = 50): array
    {
        $this->authorize('product.view_statistics');
        
        // Get products that have been in a status for too long
        $bottleneckProducts = $this->productRepository->findStatusBottlenecks($thresholdHours, $limit);
        
        return array_map(
            fn($product) => ProductResponse::fromEntity($product, ['admin_mode' => true]),
            $bottleneckProducts
        );
    }
    
    // ==================== BULK WORKFLOW OPERATIONS ====================
    
    /**
     * {@inheritDoc}
     */
    public function bulkPublish(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.publish');
        
        $success = [];
        $failed = [];
        
        foreach ($productIds as $productId) {
            try {
                $request = PublishProductRequest::forImmediatePublish(
                    $productId,
                    $adminId,
                    $parameters['notes'] ?? null,
                    $parameters['force'] ?? false
                );
                
                $result = $this->publishProduct($request);
                $success[] = $productId;
            } catch (\Throwable $e) {
                $failed[$productId] = $e->getMessage();
            }
        }
        
        return [
            'success' => count($success),
            'failed' => $failed
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function bulkVerify(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.verify');
        
        $success = [];
        $failed = [];
        
        foreach ($productIds as $productId) {
            try {
                $result = $this->verifyProduct(
                    $productId,
                    $adminId,
                    $parameters['notes'] ?? null
                );
                $success[] = $productId;
            } catch (\Throwable $e) {
                $failed[$productId] = $e->getMessage();
            }
        }
        
        return [
            'success' => count($success),
            'failed' => $failed
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $productIds, int $adminId, ?string $reason = null): array
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.archive');
        
        $success = [];
        $failed = [];
        
        foreach ($productIds as $productId) {
            try {
                $result = $this->archiveProduct($productId, $adminId, $reason);
                $success[] = $productId;
            } catch (\Throwable $e) {
                $failed[$productId] = $e->getMessage();
            }
        }
        
        return [
            'success' => count($success),
            'failed' => $failed
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function bulkRequestVerification(array $productIds, int $adminId): array
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.request_verification');
        
        $success = [];
        $failed = [];
        
        foreach ($productIds as $productId) {
            try {
                $result = $this->requestVerification($productId, $adminId);
                $success[] = $productId;
            } catch (\Throwable $e) {
                $failed[$productId] = $e->getMessage();
            }
        }
        
        return [
            'success' => count($success),
            'failed' => $failed
        ];
    }
    
    // ==================== STATE MACHINE MANAGEMENT ====================
    
    /**
     * {@inheritDoc}
     */
    public function getStateMachineConfig(): array
    {
        $states = array_map(fn($status) => $status->value, ProductStatus::cases());
        
        $transitions = [];
        foreach (ProductStatus::cases() as $from) {
            foreach (ProductStatus::cases() as $to) {
                if ($from->canTransitionTo($to)) {
                    $transitions[] = [
                        'from' => $from->value,
                        'to' => $to->value,
                        'label' => $from->label() . ' → ' . $to->label()
                    ];
                }
            }
        }
        
        return [
            'states' => $states,
            'initial_state' => ProductStatus::DRAFT->value,
            'transitions' => $transitions,
            'metadata' => [
                'version' => '1.0',
                'last_updated' => date('Y-m-d H:i:s'),
                'total_states' => count($states),
                'total_transitions' => count($transitions)
            ]
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function validateTransition(ProductStatus $from, ProductStatus $to, array $context = []): array
    {
        $errors = [];
        $warnings = [];
        
        // Check state machine rules
        if (!$from->canTransitionTo($to)) {
            $errors[] = sprintf(
                'Invalid transition from %s to %s according to state machine',
                $from->label(),
                $to->label()
            );
        }
        
        // Additional business rule validations
        if ($to === ProductStatus::PUBLISHED) {
            if (!($context['force_publish'] ?? false)) {
                // Check if product would need verification first
                if ($from !== ProductStatus::VERIFIED) {
                    $warnings[] = 'Product should be verified before publishing';
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
    
    /**
     * {@inheritDoc}
     */
    public function addTransitionGuard(string $transitionName, callable $guardCallback): void
    {
        // Implementation would depend on how state machine is configured
        // For now, log that this was called
        log_message('debug', "Transition guard added for: {$transitionName}");
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
        $this->setAdminContext($adminId);
        $this->authorize('product.override_transition');
        
        return $this->transaction(function() use ($productId, $from, $to, $adminId, $reason) {
            $product = $this->productRepository->findById($productId);
            
            if ($product === null) {
                throw ProductNotFoundException::forId($productId);
            }
            
            // Verify current status matches expected 'from' status
            if ($product->getStatus() !== $from) {
                throw new DomainException(
                    sprintf(
                        'Product is in %s status, expected %s',
                        $product->getStatus()->label(),
                        $from->label()
                    ),
                    'STATUS_MISMATCH',
                    400
                );
            }
            
            $oldValues = $product->toArray();
            
            // Force the transition
            $product->setStatus($to);
            
            // Update timestamps if needed
            if ($to === ProductStatus::PUBLISHED) {
                $product->setPublishedAt(new \DateTimeImmutable());
            }
            
            if ($to === ProductStatus::VERIFIED) {
                $product->setVerifiedAt(new \DateTimeImmutable());
                $product->setVerifiedBy($adminId);
            }
            
            $updatedProduct = $this->productRepository->save($product);
            
            // Queue cache invalidation
            $this->queueCacheOperation(function() use ($productId) {
                $this->invalidateProductCache($productId);
            });
            
            // Audit log
            $this->audit(
                'OVERRIDE_TRANSITION',
                'PRODUCT',
                $productId,
                $oldValues,
                $updatedProduct->toArray(),
                [
                    'overridden_by' => $adminId,
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'reason' => $reason,
                    'override' => true
                ]
            );
            
            return true;
        }, 'override_transition');
    }
    
    // ==================== PRIVATE HELPER METHODS ====================
    
    private function validatePublishBusinessRules(PublishProductRequest $dto, array $context): array
    {
        $errors = [];
        
        $product = $this->productRepository->findById($dto->getProductId());
        if ($product === null) {
            $errors[] = 'Product not found';
            return $errors;
        }
        
        // Check if already published
        if ($product->isPublished() && !$dto->isForcePublish()) {
            $errors[] = 'Product is already published';
        }
        
        // Check scheduled date is in future
        if ($dto->isScheduled() && $dto->getScheduledAt() <= new \DateTimeImmutable()) {
            $errors[] = 'Scheduled publication date must be in the future';
        }
        
        return $errors;
    }
    
    private function validateToggleStatusBusinessRules(ProductToggleStatusRequest $dto, array $context): array
    {
        $errors = [];
        
        $product = $this->productRepository->findById($dto->getProductId());
        if ($product === null) {
            $errors[] = 'Product not found';
            return $errors;
        }
        
        // Validate transition
        $transitionValidation = $this->validateTransition(
            $product->getStatus(),
            $dto->getTargetStatus(),
            ['force_status_change' => $dto->isForceStatusChange()]
        );
        
        if (!$transitionValidation['valid']) {
            $errors = array_merge($errors, $transitionValidation['errors']);
        }
        
        return $errors;
    }
    
    private function calculateAverageStatusTimes(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        $averageTimes = [];
        
        // This would query audit logs to calculate average time in each status
        // For now, return mock data
        $statuses = ProductStatus::cases();
        foreach ($statuses as $status) {
            $averageTimes[$status->value] = [
                'average_hours' => rand(1, 168), // 1 hour to 1 week
                'median_hours' => rand(1, 168),
                'min_hours' => rand(1, 24),
                'max_hours' => rand(48, 720), // 2 days to 1 month
                'sample_size' => rand(10, 1000)
            ];
        }
        
        return $averageTimes;
    }
    
    private function identifyWorkflowBottlenecks(array $averageTimes): array
    {
        $bottlenecks = [];
        $threshold = 24; // 24 hours threshold
        
        foreach ($averageTimes as $status => $times) {
            if ($times['average_hours'] > $threshold) {
                $bottlenecks[$status] = [
                    'status' => $status,
                    'average_hours' => $times['average_hours'],
                    'threshold_exceeded_by' => $times['average_hours'] - $threshold,
                    'severity' => $times['average_hours'] > 72 ? 'high' : ($times['average_hours'] > 48 ? 'medium' : 'low')
                ];
            }
        }
        
        // Sort by severity
        usort($bottlenecks, fn($a, $b) => $b['average_hours'] <=> $a['average_hours']);
        
        return $bottlenecks;
    }
    
    private function invalidateProductCache(int $productId): void
    {
        try {
            $this->productRepository->clearEntityCache($productId);
            
            // Clear workflow-related caches
            $patterns = [
                $this->getServiceCacheKey('*' . $productId . '*'),
                "product_service:*{$productId}*",
                "workflow:*{$productId}*"
            ];
            
            foreach ($patterns as $pattern) {
                $this->cache->deleteMatching($pattern);
            }
        } catch (\Throwable $e) {
            log_message('error', "Failed to clear cache for product {$productId}: " . $e->getMessage());
        }
    }
    
    /**
     * Get workflow service statistics
     */
    public function getWorkflowStats(): array
    {
        return array_merge($this->workflowStats, [
            'service_name' => $this->getServiceName(),
            'initialized_at' => $this->getInitializedAt()
        ]);
    }
}