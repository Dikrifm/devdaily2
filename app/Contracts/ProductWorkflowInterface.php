<?php

namespace App\Contracts;

use App\DTOs\Requests\Product\PublishProductRequest;
use App\DTOs\Requests\Product\ProductToggleStatusRequest;
use App\DTOs\Responses\ProductResponse;
use App\Enums\ProductStatus;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;

/**
 * ProductWorkflowInterface - Contract for Product Status & Workflow Operations
 * 
 * Handles product lifecycle: draft → pending_verification → verified → published → archived
 * Enforces state machine transitions and business rules for status changes.
 * 
 * @package App\Contracts
 */
interface ProductWorkflowInterface extends BaseInterface
{
    // ==================== STATUS TRANSITIONS ====================

    /**
     * Publish product with business validation
     * Transition: [DRAFT|VERIFIED] → PUBLISHED
     * 
     * @param PublishProductRequest $request
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function publishProduct(PublishProductRequest $request): ProductResponse;

    /**
     * Verify product (admin approval)
     * Transition: PENDING_VERIFICATION → VERIFIED
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $notes
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function verifyProduct(int $productId, int $adminId, ?string $notes = null): ProductResponse;

    /**
     * Request verification for product
     * Transition: DRAFT → PENDING_VERIFICATION
     * 
     * @param int $productId
     * @param int $adminId
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function requestVerification(int $productId, int $adminId): ProductResponse;

    /**
     * Archive product (soft delete with business rules)
     * Transition: [ANY] → ARCHIVED
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $reason
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function archiveProduct(int $productId, int $adminId, ?string $reason = null): ProductResponse;

    /**
     * Unarchive/Restore product from archive
     * Transition: ARCHIVED → DRAFT (or previous state)
     * 
     * @param int $productId
     * @param int $adminId
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function unarchiveProduct(int $productId, int $adminId): ProductResponse;

    /**
     * Toggle product status with state machine validation
     * 
     * @param ProductToggleStatusRequest $request
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function toggleProductStatus(ProductToggleStatusRequest $request): ProductResponse;

    /**
     * Update product status directly (admin override)
     * 
     * @param int $productId
     * @param ProductStatus $status
     * @param int $adminId
     * @param string|null $notes
     * @param bool $force Force transition even if not allowed
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function updateProductStatus(
        int $productId,
        ProductStatus $status,
        int $adminId,
        ?string $notes = null,
        bool $force = false
    ): ProductResponse;

    /**
     * Revert product to draft status
     * Transition: [PENDING_VERIFICATION|VERIFIED] → DRAFT
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $reason
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function revertToDraft(int $productId, int $adminId, ?string $reason = null): ProductResponse;

    // ==================== STATUS VALIDATION ====================

    /**
     * Check if product can transition to target status
     * 
     * @param int $productId
     * @param ProductStatus $targetStatus
     * @param bool $includeBusinessRules Include business validation (not just state machine)
     * @return array{
     *     allowed: bool,
     *     current_status: ProductStatus,
     *     target_status: ProductStatus,
     *     state_machine_allowed: bool,
     *     business_rules_allowed: bool,
     *     reasons: array<string>,
     *     requirements: array<string>
     * }
     */
    public function canTransitionTo(
        int $productId,
        ProductStatus $targetStatus,
        bool $includeBusinessRules = true
    ): array;

    /**
     * Validate product for publication
     * 
     * @param int $productId
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>,
     *     has_warnings: bool,
     *     requirements_met: bool,
     *     missing_requirements: array<string>
     * }
     */
    public function validateForPublication(int $productId): array;

    /**
     * Validate product for verification
     * 
     * @param int $productId
     * @return array{
     *     valid: bool,
     *     errors: array<string>,
     *     warnings: array<string>
     * }
     */
    public function validateForVerification(int $productId): array;

    /**
     * Check if product can be published
     * 
     * @param int $productId
     * @return array{
     *     can_publish: bool,
     *     reasons: array<string>,
     *     requirements: array<string>,
     *     has_warnings: bool,
     *     missing_requirements: array<string>
     * }
     */
    public function canPublishProduct(int $productId): array;

    /**
     * Check if product can be archived
     * 
     * @param int $productId
     * @return array{
     *     can_archive: bool,
     *     reasons: array<string>,
     *     warnings: array<string>
     * }
     */
    public function canArchiveProduct(int $productId): array;

    /**
     * Get allowed transitions for current product status
     * 
     * @param int $productId
     * @return array<ProductStatus>
     */
    public function getAllowedTransitions(int $productId): array;

    // ==================== WORKFLOW ACTIONS ====================

    /**
     * Send product for review (internal workflow)
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $notes
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function sendForReview(int $productId, int $adminId, ?string $notes = null): ProductResponse;

    /**
     * Reject product (failed verification)
     * Transition: PENDING_VERIFICATION → DRAFT
     * 
     * @param int $productId
     * @param int $adminId
     * @param string $reason
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function rejectProduct(int $productId, int $adminId, string $reason): ProductResponse;

    /**
     * Schedule product for future publication
     * 
     * @param int $productId
     * @param \DateTimeInterface $publishAt
     * @param int $adminId
     * @param string|null $notes
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function schedulePublication(
        int $productId,
        \DateTimeInterface $publishAt,
        int $adminId,
        ?string $notes = null
    ): ProductResponse;

    /**
     * Cancel scheduled publication
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $reason
     * @return ProductResponse
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function cancelScheduledPublication(int $productId, int $adminId, ?string $reason = null): ProductResponse;

    /**
     * Get scheduled publications
     * 
     * @param int|null $limit
     * @param int $offset
     * @return array<ProductResponse>
     */
    public function getScheduledPublications(?int $limit = null, int $offset = 0): array;

    /**
     * Process due scheduled publications
     * 
     * @param int $batchSize
     * @return array{processed: int, succeeded: array<int>, failed: array<int, string>}
     */
    public function processScheduledPublications(int $batchSize = 50): array;

    // ==================== STATUS QUERIES ====================

    /**
     * Get products by status
     * 
     * @param ProductStatus $status
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @param bool $adminMode
     * @return array<ProductResponse>
     */
    public function getProductsByStatus(
        ProductStatus $status,
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false,
        bool $adminMode = false
    ): array;

    /**
     * Count products by status
     * 
     * @param ProductStatus|null $status If null, returns array of all status counts
     * @param bool $includeArchived
     * @return int|array<ProductStatus, int>
     */
    public function countProductsByStatus(?ProductStatus $status = null, bool $includeArchived = false);

    /**
     * Get products needing verification
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     */
    public function getProductsNeedingVerification(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    /**
     * Get products pending publication
     * 
     * @param int|null $limit
     * @param int $offset
     * @param bool $includeRelations
     * @return array<ProductResponse>
     */
    public function getProductsPendingPublication(
        ?int $limit = null,
        int $offset = 0,
        bool $includeRelations = false
    ): array;

    // ==================== WORKFLOW ANALYTICS ====================

    /**
     * Get workflow statistics
     * 
     * @param string $period 'day', 'week', 'month', 'year'
     * @return array{
     *     transitions: array<string, int>,
     *     average_times: array<string, float>,
     *     bottlenecks: array<string, int>
     * }
     */
    public function getWorkflowStatistics(string $period = 'month'): array;

    /**
     * Get product status history
     * 
     * @param int $productId
     * @param int|null $limit
     * @param int $offset
     * @return array<array{
     *     from_status: string,
     *     to_status: string,
     *     changed_by: int|null,
     *     changed_at: string,
     *     notes: string|null
     * }>
     */
    public function getStatusHistory(int $productId, ?int $limit = null, int $offset = 0): array;

    /**
     * Get average time in each status
     * 
     * @param string $period
     * @return array<string, float>
     */
    public function getAverageStatusTimes(string $period = 'month'): array;

    /**
     * Get workflow bottlenecks (statuses where products stay too long)
     * 
     * @param int $thresholdHours
     * @param int $limit
     * @return array<ProductResponse>
     */
    public function getWorkflowBottlenecks(int $thresholdHours = 72, int $limit = 50): array;

    // ==================== BULK WORKFLOW OPERATIONS ====================

    /**
     * Bulk publish products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters Additional parameters
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkPublish(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk verify products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkVerify(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk archive products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param string|null $reason
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkArchive(array $productIds, int $adminId, ?string $reason = null): array;

    /**
     * Bulk request verification
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkRequestVerification(array $productIds, int $adminId): array;

    // ==================== STATE MACHINE MANAGEMENT ====================

    /**
     * Get product state machine configuration
     * 
     * @return array{
     *     states: array<string>,
     *     initial_state: string,
     *     transitions: array<array{from: string, to: string, guard?: string}>,
     *     metadata: array<string, mixed>
     * }
     */
    public function getStateMachineConfig(): array;

    /**
     * Validate state machine transition
     * 
     * @param ProductStatus $from
     * @param ProductStatus $to
     * @param array $context
     * @return array{valid: bool, errors: array<string>, warnings: array<string>}
     */
    public function validateTransition(ProductStatus $from, ProductStatus $to, array $context = []): array;

    /**
     * Add custom transition guard
     * 
     * @param string $transitionName
     * @param callable $guardCallback
     * @return void
     */
    public function addTransitionGuard(string $transitionName, callable $guardCallback): void;

    /**
     * Override transition (admin only)
     * 
     * @param int $productId
     * @param ProductStatus $from
     * @param ProductStatus $to
     * @param int $adminId
     * @param string $reason
     * @return bool
     * @throws ProductNotFoundException
     * @throws AuthorizationException
     */
    public function overrideTransition(
        int $productId,
        ProductStatus $from,
        ProductStatus $to,
        int $adminId,
        string $reason
    ): bool;
}