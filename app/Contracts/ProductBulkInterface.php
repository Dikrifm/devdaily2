<?php

namespace App\Contracts;

use App\DTOs\Requests\Product\ProductBulkActionRequest;
use App\DTOs\Responses\BulkActionResult;
use App\Enums\ProductStatus;
use App\Enums\ProductBulkActionType;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;

/**
 * ProductBulkInterface - Contract for Bulk Product Operations
 * 
 * Handles operations on multiple products simultaneously with:
 * - Transaction safety
 * - Progress tracking
 * - Batch processing optimization
 * - Error recovery & rollback handling
 * 
 * @package App\Contracts
 */
interface ProductBulkInterface extends BaseInterface
{
    // ==================== BULK ACTION EXECUTION ====================

    /**
     * Execute bulk action on products
     * 
     * @param ProductBulkActionRequest $request
     * @return BulkActionResult
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function bulkAction(ProductBulkActionRequest $request): BulkActionResult;

    /**
     * Execute bulk action with custom callback for each item
     * 
     * @param array<int> $productIds
     * @param callable $itemCallback Callback receiving (int $productId, array $context)
     * @param array $context Additional context for callback
     * @param bool $useTransaction Wrap in single transaction
     * @param int $batchSize Process in batches
     * @return array{
     *     total: int,
     *     success: int,
     *     failed: int,
     *     results: array<int, mixed>,
     *     errors: array<int, string>
     * }
     */
    public function executeBulkWithCallback(
        array $productIds,
        callable $itemCallback,
        array $context = [],
        bool $useTransaction = true,
        int $batchSize = 100
    ): array;

    // ==================== BULK STATUS OPERATIONS ====================

    /**
     * Bulk update product status
     * 
     * @param array<int> $productIds
     * @param ProductStatus $status
     * @param int $adminId
     * @param array $parameters Additional parameters
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkUpdateStatus(
        array $productIds, 
        ProductStatus $status, 
        int $adminId, 
        array $parameters = []
    ): array;

    /**
     * Bulk publish products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $force Force publish even if validation fails
     *     @var string|null $notes
     *     @var \DateTimeInterface|null $scheduled_at
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkPublish(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk verify products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var string|null $notes
     *     @var bool $skip_validation Skip business rule validation
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkVerify(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk archive products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param string|null $reason
     * @param array $parameters {
     *     @var bool $force Force archive even if validation fails
     *     @var bool $notify Send notification to product owners
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkArchive(array $productIds, int $adminId, ?string $reason = null, array $parameters = []): array;

    /**
     * Bulk restore products from archive
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $restore_links Also restore associated links
     *     @var string|null $target_status Status to restore to (default: DRAFT)
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkRestore(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk delete products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $hard_delete Permanent deletion
     *     @var bool $cascade Delete related records
     *     @var string $reason
     *     @var bool $force Force delete even if dependencies exist
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkDelete(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk request verification
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var string|null $notes
     *     @var int|null $assign_to Admin ID to assign for verification
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkRequestVerification(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk revert to draft
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param string|null $reason
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkRevertToDraft(array $productIds, int $adminId, ?string $reason = null): array;

    // ==================== BULK DATA OPERATIONS ====================

    /**
     * Bulk update product data
     * 
     * @param array<int> $productIds
     * @param array<string, mixed> $data Field-value pairs to update
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $validate Validate each update
     *     @var bool $skip_slug_regeneration Skip slug regeneration for name updates
     * }
     * @return array{success: int, failed: array<int, string>, updated_fields: array<string>}
     */
    public function bulkUpdateData(array $productIds, array $data, int $adminId, array $parameters = []): array;

    /**
     * Bulk update product prices
     * 
     * @param array<int, float> $priceUpdates [productId => newPrice]
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $mark_checked Mark price as checked after update
     *     @var float|null $percentage_increase Apply percentage increase instead of absolute value
     *     @var string $price_type 'market_price' or 'sale_price'
     * }
     * @return array{success: int, failed: array<int, string>, total_updated: int}
     */
    public function bulkUpdatePrices(array $priceUpdates, int $adminId, array $parameters = []): array;

    /**
     * Bulk update product categories
     * 
     * @param array<int> $productIds
     * @param int $categoryId
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $move_subcategories Move products in subcategories as well
     *     @var bool $clear_existing Clear existing category assignments
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkUpdateCategories(array $productIds, int $categoryId, int $adminId, array $parameters = []): array;

    /**
     * Bulk assign badges to products
     * 
     * @param array<int> $productIds
     * @param array<int> $badgeIds
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $replace Replace existing badges
     *     @var \DateTimeInterface|null $expires_at Badge expiration date
     * }
     * @return array{success: int, failed: array<int, string>, assignments_made: int}
     */
    public function bulkAssignBadges(array $productIds, array $badgeIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk remove badges from products
     * 
     * @param array<int> $productIds
     * @param array<int> $badgeIds If empty, remove all badges
     * @param int $adminId
     * @return array{success: int, failed: array<int, string>, removals_made: int}
     */
    public function bulkRemoveBadges(array $productIds, array $badgeIds, int $adminId): array;

    /**
     * Bulk update image sources
     * 
     * @param array<int> $productIds
     * @param string $imageUrl
     * @param string $sourceType
     * @param int $adminId
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkUpdateImages(
        array $productIds, 
        string $imageUrl, 
        string $sourceType, 
        int $adminId
    ): array;

    // ==================== BULK IMPORT/EXPORT ====================

    /**
     * Import products from array data
     * 
     * @param array<array<string, mixed>> $productsData
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $skip_duplicates Skip products with existing slugs
     *     @var bool $validate Validate each product before import
     *     @var bool $transaction Use single transaction for entire import
     *     @var int $batch_size Process in batches
     *     @var string $duplicate_strategy 'skip', 'overwrite', or 'rename'
     *     @var bool $create_categories Create missing categories
     *     @var bool $create_marketplaces Create missing marketplaces
     * }
     * @return array{
     *     total: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     errors: array<int, array{index: int, error: string, data: array}>
     * }
     */
    public function bulkImport(array $productsData, int $adminId, array $parameters = []): array;

    /**
     * Export products to specified format
     * 
     * @param array<int> $productIds
     * @param array $parameters {
     *     @var string $format 'json', 'csv', 'xml', 'excel'
     *     @var bool $include_relations Include category, links, badges
     *     @var array $fields Specific fields to export
     *     @var string $filename Output filename
     *     @var bool $compress Compress output as ZIP
     * }
     * @return array{content: string, format: string, filename: string, size: int}
     */
    public function bulkExport(array $productIds, array $parameters = []): array;

    /**
     * Clone/Mass duplicate products
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $clone_relations Clone badges, links, etc.
     *     @var string $name_suffix Suffix for cloned product names
     *     @var bool $generate_new_slugs Generate new unique slugs
     *     @var string $target_status Status for cloned products
     * }
     * @return array{success: int, failed: array<int, string>, cloned_ids: array<int>}
     */
    public function bulkClone(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Merge multiple products into one
     * 
     * @param array<int> $productIds Products to merge (first will be target)
     * @param int $adminId
     * @param array $parameters {
     *     @var string $merge_strategy How to handle conflicts: 'keep_target', 'keep_source', 'merge'
     *     @var bool $delete_source Delete source products after merge
     *     @var array $field_priority Priority for each field during merge
     * }
     * @return array{target_product_id: int, merged_count: int, deleted_source_ids: array<int>}
     */
    public function bulkMerge(array $productIds, int $adminId, array $parameters = []): array;

    // ==================== BULK MAINTENANCE OPERATIONS ====================

    /**
     * Bulk mark prices as checked
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkMarkPricesChecked(array $productIds, int $adminId): array;

    /**
     * Bulk mark links as checked
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkMarkLinksChecked(array $productIds, int $adminId): array;

    /**
     * Bulk regenerate slugs
     * 
     * @param array<int> $productIds
     * @param int $adminId
     * @param array $parameters {
     *     @var bool $force Regenerate even if slug already exists
     *     @var string $strategy 'increment', 'random', 'timestamp'
     * }
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkRegenerateSlugs(array $productIds, int $adminId, array $parameters = []): array;

    /**
     * Bulk update metadata
     * 
     * @param array<int> $productIds
     * @param array<string, mixed> $metadata
     * @param int $adminId
     * @param string $merge_strategy 'replace' or 'merge'
     * @return array{success: int, failed: array<int, string>}
     */
    public function bulkUpdateMetadata(
        array $productIds, 
        array $metadata, 
        int $adminId, 
        string $merge_strategy = 'merge'
    ): array;

    /**
     * Bulk cleanup orphaned data
     * 
     * @param array $parameters {
     *     @var bool $cleanup_images Remove unused product images
     *     @var bool $cleanup_links Remove broken marketplace links
     *     @var bool $cleanup_badges Remove expired badge assignments
     *     @var int $older_than_days Only clean data older than X days
     * }
     * @return array{cleaned_items: int, freed_space: string, details: array<string, int>}
     */
    public function bulkCleanup(array $parameters = []): array;

    // ==================== BULK VALIDATION & PREFLIGHT ====================

    /**
     * Validate products for bulk action
     * 
     * @param array<int> $productIds
     * @param ProductBulkActionType $action
     * @param array $parameters Action-specific parameters
     * @return array{
     *     valid: bool,
     *     valid_ids: array<int>,
     *     invalid_ids: array<int>,
     *     errors: array<int, string>,
     *     warnings: array<int, string>,
     *     estimated_time: int, // seconds
     *     memory_required: string, // estimated memory
     *     can_proceed: bool
     * }
     */
    public function validateBulkAction(
        array $productIds, 
        ProductBulkActionType $action, 
        array $parameters = []
    ): array;

    /**
     * Get preflight check for bulk operation
     * 
     * @param array<int> $productIds
     * @param string $operation
     * @param array $parameters
     * @return array{
     *     can_proceed: bool,
     *     checks: array<string, bool>,
     *     warnings: array<string>,
     *     errors: array<string>,
     *     recommendations: array<string>,
     *     estimated_impact: array<string, mixed>
     * }
     */
    public function preflightCheck(array $productIds, string $operation, array $parameters = []): array;

    /**
     * Estimate resource requirements for bulk operation
     * 
     * @param array<int> $productIds
     * @param string $operation
     * @param array $parameters
     * @return array{
     *     estimated_time_seconds: int,
     *     estimated_memory_mb: float,
     *     recommended_batch_size: int,
     *     database_impact: array<string, mixed>,
     *     cache_impact: array<string, mixed>
     * }
     */
    public function estimateResourceRequirements(
        array $productIds, 
        string $operation, 
        array $parameters = []
    ): array;

    /**
     * Check dependencies for bulk deletion
     * 
     * @param array<int> $productIds
     * @param bool $hardDelete
     * @return array{
     *     has_dependencies: bool,
     *     dependency_count: int,
     *     dependencies: array<string, int>,
     *     can_delete: bool,
     *     alternative_suggestions: array<string>
     * }
     */
    public function checkBulkDeletionDependencies(array $productIds, bool $hardDelete = false): array;

    // ==================== BULK PROCESSING MANAGEMENT ====================

    /**
     * Start background bulk processing job
     * 
     * @param ProductBulkActionRequest $request
     * @return array{
     *     job_id: string,
     *     status: string,
     *     estimated_completion: string,
     *     progress_url: string,
     *     cancel_url: string
     * }
     */
    public function startBackgroundBulkJob(ProductBulkActionRequest $request): array;

    /**
     * Get bulk job status
     * 
     * @param string $jobId
     * @return array{
     *     job_id: string,
     *     status: 'pending'|'processing'|'completed'|'failed'|'cancelled',
     *     progress: int, // percentage
     *     total_items: int,
     *     processed_items: int,
     *     successful_items: int,
     *     failed_items: int,
     *     start_time: string|null,
     *     end_time: string|null,
     *     estimated_completion: string|null,
     *     errors: array<int, string>
     * }
     */
    public function getBulkJobStatus(string $jobId): array;

    /**
     * Cancel bulk job
     * 
     * @param string $jobId
     * @param int $adminId
     * @param string|null $reason
     * @return bool
     */
    public function cancelBulkJob(string $jobId, int $adminId, ?string $reason = null): bool;

    /**
     * List active bulk jobs
     * 
     * @param int $limit
     * @param int $offset
     * @param string $status Filter by status
     * @return array<array>
     */
    public function listBulkJobs(int $limit = 20, int $offset = 0, string $status = 'active'): array;

    /**
     * Cleanup completed bulk jobs
     * 
     * @param int $olderThanDays
     * @return int Number of jobs cleaned up
     */
    public function cleanupBulkJobs(int $olderThanDays = 7): int;

    // ==================== BULK STATISTICS & ANALYTICS ====================

    /**
     * Get bulk operation statistics
     * 
     * @param string $period 'day', 'week', 'month', 'year'
     * @return array{
     *     total_operations: int,
     *     successful_operations: int,
     *     failed_operations: int,
     *     average_items_per_operation: float,
     *     most_common_operations: array<string, int>,
     *     performance_metrics: array<string, float>
     * }
     */
    public function getBulkStatistics(string $period = 'month'): array;

    /**
     * Get bulk operation performance metrics
     * 
     * @param int $limit
     * @return array<array{
     *     operation: string,
     *     avg_time_per_item_ms: float,
     *     success_rate: float,
     *     avg_batch_size: int,
     *     recommendation: string
     * }>
     */
    public function getBulkPerformanceMetrics(int $limit = 10): array;

    /**
     * Generate bulk operation report
     * 
     * @param \DateTimeInterface $startDate
     * @param \DateTimeInterface $endDate
     * @param string $format 'json', 'csv', 'pdf'
     * @return array{report: string, format: string, generated_at: string}
     */
    public function generateBulkReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        string $format = 'json'
    ): array;

    // ==================== BULK ERROR HANDLING & RECOVERY ====================

    /**
     * Retry failed bulk items
     * 
     * @param string $jobId
     * @param array<int> $itemIds
     * @param int $adminId
     * @param array $retryParameters
     * @return array{success: int, failed: int, new_job_id: string|null}
     */
    public function retryFailedBulkItems(
        string $jobId, 
        array $itemIds, 
        int $adminId, 
        array $retryParameters = []
    ): array;

    /**
     * Get error details for failed bulk items
     * 
     * @param string $jobId
     * @param array<int> $itemIds
     * @return array<int, array{error: string, details: array, suggested_fix: string|null}>
     */
    public function getBulkErrorDetails(string $jobId, array $itemIds = []): array;

    /**
     * Create recovery plan for failed bulk operation
     * 
     * @param string $jobId
     * @return array{
     *     can_recover: bool,
     *     recovery_steps: array<string>,
     *     estimated_time: string,
     *     risks: array<string>,
     *     recommendations: array<string>
     * }
     */
    public function createRecoveryPlan(string $jobId): array;

    /**
     * Execute recovery plan
     * 
     * @param string $jobId
     * @param array $recoverySteps
     * @param int $adminId
     * @return array{success: bool, recovered_items: int, new_status: string}
     */
    public function executeRecoveryPlan(string $jobId, array $recoverySteps, int $adminId): array;

    // ==================== BULK CACHE MANAGEMENT ====================

    /**
     * Bulk clear product caches
     * 
     * @param array<int> $productIds
     * @param array $parameters {
     *     @var bool $clear_related Clear related caches (category, marketplace)
     *     @var bool $async Perform asynchronously
     *     @var string $cache_level 'all', 'entity', 'query', 'compute'
     * }
     * @return array{cleared: int, failed: int, total_cache_entries: int}
     */
    public function bulkClearCaches(array $productIds, array $parameters = []): array;

    /**
     * Bulk warm product caches
     * 
     * @param array<int> $productIds
     * @param array $parameters {
     *     @var array $cache_levels Levels to warm: ['entity', 'query', 'compute']
     *     @var bool $include_relations Warm relation caches
     *     @var int $priority Priority for warming (1-10)
     * }
     * @return array{warmed: int, failed: int, total_size: string}
     */
    public function bulkWarmCaches(array $productIds, array $parameters = []): array;

    // ==================== BATCH PROCESSING OPTIMIZATION ====================

    /**
     * Calculate optimal batch size for operation
     * 
     * @param string $operation
     * @param int $totalItems
     * @param array $constraints {
     *     @var int $max_memory_mb Maximum memory available
     *     @var int $timeout_seconds Operation timeout
     *     @var int $max_connections Database connections
     * }
     * @return array{
     *     recommended_batch_size: int,
     *     estimated_batches: int,
     *     estimated_total_time: int,
     *     memory_per_batch_mb: float,
     *     warnings: array<string>
     * }
     */
    public function calculateOptimalBatchSize(
        string $operation, 
        int $totalItems, 
        array $constraints = []
    ): array;

    /**
     * Split bulk operation into optimized batches
     * 
     * @param array<int> $productIds
     * @param string $operation
     * @param array $parameters
     * @return array<array<int>> Array of batches
     */
    public function createOptimizedBatches(array $productIds, string $operation, array $parameters = []): array;

    /**
     * Execute bulk operation with optimized batching
     * 
     * @param array<int> $productIds
     * @param callable $batchCallback
     * @param array $optimizationParameters
     * @return array{
     *     total_batches: int,
     *     completed_batches: int,
     *     total_items: int,
     *     successful_items: int,
     *     performance_metrics: array<string, float>
     * }
     */
    public function executeWithOptimizedBatching(
        array $productIds,
        callable $batchCallback,
        array $optimizationParameters = []
    ): array;
}