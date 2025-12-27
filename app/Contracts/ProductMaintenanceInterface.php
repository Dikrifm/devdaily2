<?php

namespace App\Contracts;

use App\DTOs\Responses\ProductResponse;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;

/**
 * ProductMaintenanceInterface - Contract for Product Maintenance, Cache, Statistics & Utility Operations
 * 
 * Handles system maintenance, cache management, statistics calculation, import/export,
 * and utility operations for the Product domain.
 * 
 * @package App\Contracts
 */
interface ProductMaintenanceInterface extends BaseInterface
{
    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all product caches (L1, L2, L3)
     * 
     * @return array{
     *     cleared: int,
     *     levels: array{
     *         entity: int,
     *         query: int,
     *         compute: int
     *     },
     *     total_size: string
     * }
     */
    public function clearAllProductCaches(): array;

    /**
     * Clear cache for specific product
     * 
     * @param int $productId
     * @param array $options {
     *     @var bool $clear_related Clear related caches (category, marketplace)
     *     @var array $levels Cache levels to clear ['entity', 'query', 'compute']
     * }
     * @return bool
     */
    public function clearProductCache(int $productId, array $options = []): bool;

    /**
     * Clear cache matching pattern
     * 
     * @param string $pattern Cache key pattern
     * @param array $options {
     *     @var bool $dry_run Simulate without actually clearing
     *     @var int $limit Maximum number of keys to clear
     * }
     * @return array{matched: int, cleared: int, sample_keys: array<string>}
     */
    public function clearCacheMatching(string $pattern, array $options = []): array;

    /**
     * Warm product caches for better performance
     * 
     * @param array<int> $productIds
     * @param array $options {
     *     @var array $levels Cache levels to warm ['entity', 'query', 'compute']
     *     @var bool $include_relations Warm relation caches
     *     @var int $priority Priority level (1-10)
     *     @var bool $background Perform in background
     * }
     * @return array{
     *     total: int,
     *     warmed: int,
     *     failed: int,
     *     estimated_improvement: string,
     *     cache_size: string
     * }
     */
    public function warmProductCaches(array $productIds, array $options = []): array;

    /**
     * Get cache statistics for product domain
     * 
     * @return array{
     *     total_keys: int,
     *     memory_usage: string,
     *     hit_rate: float,
     *     levels: array{
     *         entity: array{hits: int, misses: int, size: string},
     *         query: array{hits: int, misses: int, size: string},
     *         compute: array{hits: int, misses: int, size: string}
     *     },
     *     top_keys: array<array{key: string, hits: int, size: string, ttl: int}>,
     *     recommendations: array<string>
     * }
     */
    public function getCacheStatistics(): array;

    /**
     * Optimize cache configuration for product domain
     * 
     * @param array $constraints {
     *     @var int $max_memory_mb Maximum memory allocation
     *     @var float $target_hit_rate Target cache hit rate
     *     @var int $max_ttl Maximum TTL in seconds
     * }
     * @return array{
     *     current_config: array,
     *     optimized_config: array,
     *     expected_improvement: float,
     *     changes_required: array<string>
     * }
     */
    public function optimizeCacheConfiguration(array $constraints = []): array;

    /**
     * Preload cache for frequently accessed products
     * 
     * @param array $criteria {
     *     @var int $limit Number of products to preload
     *     @var string $strategy 'popular', 'recent', 'scheduled'
     *     @var bool $include_relations Preload relations
     * }
     * @return array{preloaded: int, estimated_impact: string}
     */
    public function preloadFrequentProductCache(array $criteria = []): array;

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * Get comprehensive product statistics
     * 
     * @param string $period 'day', 'week', 'month', 'year', 'all'
     * @param array $options {
     *     @var bool $include_graph_data Include graph/chart data
     *     @var bool $include_trends Include trend analysis
     *     @var bool $include_forecast Include simple forecast
     *     @var array $filters Additional filters
     * }
     * @return array{
     *     summary: array{
     *         total_products: int,
     *         published_products: int,
     *         draft_products: int,
     *         verified_products: int,
     *         archived_products: int,
     *         average_price: float,
     *         total_views: int
     *     },
     *     trends: array<string, mixed>,
     *     period_data: array<array>,
     *     generated_at: string,
     *     cache_key: string
     * }
     */
    public function getProductStatistics(string $period = 'month', array $options = []): array;

    /**
     * Get dashboard statistics for admin
     * 
     * @param array $options {
     *     @var bool $realtime Include real-time data
     *     @var bool $include_charts Include chart configurations
     *     @var string $timezone Timezone for dates
     * }
     * @return array{
     *     overview: array<string, mixed>,
     *     recent_activity: array<array>,
     *     alerts: array<array>,
     *     performance: array<string, mixed>,
     *     charts: array<string, mixed>
     * }
     */
    public function getDashboardStatistics(array $options = []): array;

    /**
     * Get performance metrics for product operations
     * 
     * @param string $period
     * @return array{
     *     response_times: array<string, float>,
     *     error_rates: array<string, float>,
     *     cache_efficiency: array<string, float>,
     *     database_performance: array<string, mixed>,
     *     recommendations: array<string>
     * }
     */
    public function getPerformanceMetrics(string $period = 'week'): array;

    /**
     * Get business intelligence data for products
     * 
     * @param array $dimensions ['category', 'status', 'price_range', 'time']
     * @param array $metrics ['count', 'views', 'price_average', 'conversion']
     * @param array $filters
     * @return array{
     *     data: array<array>,
     *     dimensions: array<string>,
     *     metrics: array<string>,
     *     summary: array<string, mixed>
     * }
     */
    public function getBusinessIntelligenceData(array $dimensions = [], array $metrics = [], array $filters = []): array;

    /**
     * Calculate product health score
     * 
     * @param int $productId
     * @return array{
     *     score: int,
     *     components: array<string, array{score: int, weight: int, details: array}>,
     *     recommendations: array<string>,
     *     overall_health: string
     * }
     */
    public function calculateProductHealthScore(int $productId): array;

    /**
     * Get system health status for product domain
     * 
     * @return array{
     *     status: 'healthy'|'warning'|'critical',
     *     checks: array<string, array{status: string, message: string, timestamp: string}>,
     *     metrics: array<string, mixed>,
     *     alerts: array<array>
     * }
     */
    public function getSystemHealthStatus(): array;

    // ==================== MAINTENANCE OPERATIONS ====================

    /**
     * Run product database maintenance
     * 
     * @param array $tasks {
     *     @var bool $optimize_tables Optimize database tables
     *     @var bool $rebuild_indexes Rebuild indexes
     *     @var bool $cleanup_orphaned Cleanup orphaned records
     *     @var bool $update_statistics Update database statistics
     * }
     * @return array{
     *     executed_tasks: array<string>,
     *     results: array<string, mixed>,
     *     duration: float,
     *     improvements: array<string>
     * }
     */
    public function runDatabaseMaintenance(array $tasks = []): array;

    /**
     * Validate product data integrity
     * 
     * @param array $options {
     *     @var bool $fix_issues Automatically fix issues where possible
     *     @var bool $deep_validation Perform deep validation
     *     @var array $product_ids Specific products to validate
     * }
     * @return array{
     *     total_checked: int,
     *     issues_found: int,
     *     issues: array<array>,
     *     fixed: int,
     *     recommendations: array<string>
     * }
     */
    public function validateDataIntegrity(array $options = []): array;

    /**
     * Rebuild product relationships and denormalized data
     * 
     * @param array $options {
     *     @var bool $rebuild_counts Rebuild product counts
     *     @var bool $rebuild_slugs Regenerate slugs
     *     @var bool $rebuild_search_index Rebuild search index
     *     @var bool $background Run in background
     * }
     * @return array{
     *     total_rebuilt: int,
     *     duration: float,
     *     memory_used: string,
     *     status: string
     * }
     */
    public function rebuildProductData(array $options = []): array;

    /**
     * Archive old products based on criteria
     * 
     * @param array $criteria {
     *     @var int $older_than_days Archive products older than X days
     *     @var string $status Archive products with specific status
     *     @var int $view_threshold Archive products with views below threshold
     *     @var bool $dry_run Simulate without actual archiving
     * }
     * @return array{
     *     matched: int,
     *     archived: int,
     *     skipped: int,
     *     details: array<array>,
     *     summary: string
     * }
     */
    public function archiveOldProducts(array $criteria = []): array;

    /**
     * Cleanup temporary and orphaned product data
     * 
     * @param array $options {
     *     @var bool $cleanup_images Cleanup unused product images
     *     @var bool $cleanup_temp_files Cleanup temporary files
     *     @var bool $cleanup_old_logs Cleanup old log entries
     *     @var int $older_than_days Only cleanup data older than X days
     * }
     * @return array{
     *     cleaned_items: int,
     *     freed_space: string,
     *     details: array<string, int>,
     *     duration: float
     * }
     */
    public function cleanupProductData(array $options = []): array;

    // ==================== IMPORT/EXPORT OPERATIONS ====================

    /**
     * Import products from external source
     * 
     * @param mixed $source File path, URL, or data array
     * @param array $options {
     *     @var string $format 'csv', 'json', 'xml', 'excel'
     *     @var string $strategy 'create_only', 'update_only', 'upsert'
     *     @var array $mapping Field mapping configuration
     *     @var bool $validate Validate each record
     *     @var bool $transaction Use transaction
     *     @var int $batch_size Batch size for import
     *     @var callable $progress_callback Progress callback
     * }
     * @return array{
     *     total: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     errors: array<array>,
     *     warnings: array<string>,
     *     duration: float,
     *     summary: string
     * }
     */
    public function importProducts($source, array $options = []): array;

    /**
     * Export products to specified format
     * 
     * @param array $criteria Export criteria
     * @param array $options {
     *     @var string $format 'csv', 'json', 'xml', 'excel', 'pdf'
     *     @var array $fields Specific fields to export
     *     @var bool $include_relations Include related data
     *     @var string $filename Output filename
     *     @var bool $compress Compress output
     *     @var bool $stream Stream output directly
     * }
     * @return array{
     *     content: mixed,
     *     format: string,
     *     filename: string,
     *     size: int|string,
     *     checksum: string,
     *     download_url: string|null
     * }
     */
    public function exportProducts(array $criteria = [], array $options = []): array;

    /**
     * Generate product data template for import
     * 
     * @param string $format
     * @param array $options {
     *     @var array $include_fields Fields to include in template
     *     @var bool $include_examples Include example data
     *     @var bool $include_instructions Include import instructions
     * }
     * @return array{
     *     template: mixed,
     *     format: string,
     *     fields: array<array>,
     *     instructions: array<string>
     * }
     */
    public function generateImportTemplate(string $format = 'csv', array $options = []): array;

    /**
     * Validate import data before processing
     * 
     * @param mixed $data
     * @param string $format
     * @param array $options
     * @return array{
     *     valid: bool,
     *     total_records: int,
     *     valid_records: int,
     *     invalid_records: int,
     *     errors: array<array>,
     *     warnings: array<array>,
     *     suggestions: array<string>
     * }
     */
    public function validateImportData($data, string $format = 'csv', array $options = []): array;

    /**
     * Schedule automated import/export job
     * 
     * @param array $config Job configuration
     * @param array $options {
     *     @var string $frequency 'daily', 'weekly', 'monthly'
     *     @var string $time Specific time to run
     *     @var bool $enabled Enable/disable schedule
     *     @var array $notifications Notification settings
     * }
     * @return array{
     *     job_id: string,
     *     schedule: array,
     *     next_run: string,
     *     status: string
     * }
     */
    public function scheduleImportExportJob(array $config, array $options = []): array;

    // ==================== BACKUP & RECOVERY ====================

    /**
     * Create product data backup
     * 
     * @param array $options {
     *     @var string $type 'full', 'incremental', 'differential'
     *     @var bool $include_media Include product images/media
     *     @var bool $compress Compress backup
     *     @var string $storage Location to store backup
     *     @var string $description Backup description
     * }
     * @return array{
     *     backup_id: string,
     *     filename: string,
     *     size: string,
     *     checksum: string,
     *     created_at: string,
     *     included_tables: array<string>,
     *     download_url: string|null
     * }
     */
    public function createBackup(array $options = []): array;

    /**
     * Restore product data from backup
     * 
     * @param mixed $backup Backup ID, file path, or backup data
     * @param array $options {
     *     @var bool $validate Validate backup before restore
     *     @var bool $dry_run Simulate restore
     *     @var array $tables Specific tables to restore
     *     @var bool $preserve_current Preserve current data (merge)
     *     @var string $strategy 'replace', 'merge', 'update'
     * }
     * @return array{
     *     restored: int,
     *     skipped: int,
     *     failed: int,
     *     duration: float,
     *     warnings: array<string>,
     *     next_steps: array<string>
     * }
     */
    public function restoreBackup($backup, array $options = []): array;

    /**
     * List available backups
     * 
     * @param array $options {
     *     @var string $storage Storage location to list
     *     @var string $type Filter by backup type
     *     @var \DateTimeInterface $from Filter by date
     *     @var \DateTimeInterface $to Filter by date
     * }
     * @return array<array{
     *     id: string,
     *     filename: string,
     *     size: string,
     *     created_at: string,
     *     type: string,
     *     description: string|null,
     *     checksum: string
     * }>
     */
    public function listBackups(array $options = []): array;

    /**
     * Verify backup integrity
     * 
     * @param mixed $backup
     * @return array{
     *     valid: bool,
     *     checksum_match: bool,
     *     structure_valid: bool,
     *     data_valid: bool,
     *     issues: array<string>,
     *     recovery_options: array<string>
     * }
     */
    public function verifyBackup($backup): array;

    /**
     * Create disaster recovery plan
     * 
     * @return array{
     *     plan_id: string,
     *     steps: array<array>,
     *     estimated_recovery_time: string,
     *     required_resources: array<string>,
     *     risks: array<string>,
     *     testing_instructions: array<string>
     * }
     */
    public function createDisasterRecoveryPlan(): array;

    // ==================== SYSTEM UTILITIES ====================

    /**
     * Reindex product search
     * 
     * @param array $options {
     *     @var array $product_ids Specific products to reindex
     *     @var bool $background Run in background
     *     @var bool $full_reindex Full reindex
     *     @var string $search_engine Search engine to use
     * }
     * @return array{
     *     indexed: int,
     *     failed: int,
     *     duration: float,
     *     search_engine: string,
     *     index_size: string
     * }
     */
    public function reindexProductSearch(array $options = []): array;

    /**
     * Recalculate product statistics and aggregates
     * 
     * @param array $options {
     *     @var array $calculations Calculations to perform
     *     @var bool $background Run in background
     *     @var bool $force Force recalculation
     * }
     * @return array{
     *     recalculated: int,
     *     duration: float,
     *     memory_used: string,
     *     updated_metrics: array<string>
     * }
     */
    public function recalculateAggregates(array $options = []): array;

    /**
     * Synchronize product data with external systems
     * 
     * @param array $systems Systems to sync with
     * @param array $options {
     *     @var bool $bidirectional Bidirectional sync
     *     @var bool $dry_run Simulate sync
     *     @var array $filters Sync filters
     *     @var callable $progress_callback Progress callback
     * }
     * @return array{
     *     synced: int,
     *     created: int,
     *     updated: int,
     *     conflicts: int,
     *     resolved: int,
     *     duration: float,
     *     log: array<array>
     * }
     */
    public function synchronizeWithExternalSystems(array $systems = [], array $options = []): array;

    /**
     * Generate product data report
     * 
     * @param string $report_type Type of report
     * @param array $parameters Report parameters
     * @param array $options {
     *     @var string $format Output format
     *     @var bool $schedule Schedule recurring report
     *     @var array $delivery Delivery options
     *     @var bool $include_charts Include charts
     * }
     * @return array{
     *     report_id: string,
     *     content: mixed,
     *     format: string,
     *     generated_at: string,
     *     parameters: array,
     *     metadata: array<string, mixed>
     * }
     */
    public function generateReport(string $report_type, array $parameters = [], array $options = []): array;

    /**
     * Migrate product data to new schema/structure
     * 
     * @param array $migration_config Migration configuration
     * @param array $options {
     *     @var bool $validate Validate before migration
     *     @var bool $backup Create backup before migration
     *     @var bool $dry_run Simulate migration
     *     @var bool $rollback_enabled Enable rollback capability
     *     @var callable $progress_callback Progress callback
     * }
     * @return array{
     *     migrated: int,
     *     failed: int,
     *     duration: float,
     *     backup_id: string|null,
     *     rollback_instructions: array<string>,
     *     verification_results: array<string, mixed>
     * }
     */
    public function migrateProductData(array $migration_config, array $options = []): array;

    // ==================== MONITORING & ALERTS ====================

    /**
     * Set up product monitoring
     * 
     * @param array $monitors Monitoring configurations
     * @param array $options {
     *     @var array $alerts Alert configurations
     *     @var array $notifications Notification channels
     *     @var array $thresholds Threshold values
     * }
     * @return array{
     *     monitors_setup: array<string>,
     *     alerts_configured: array<string>,
     *     status: string,
     *     dashboard_url: string|null
     * }
     */
    public function setupMonitoring(array $monitors = [], array $options = []): array;

    /**
     * Get current product system alerts
     * 
     * @param array $options {
     *     @var string $severity Filter by severity
     *     @var string $status Filter by status
     *     @var \DateTimeInterface $since Filter by time
     * }
     * @return array<array{
     *     id: string,
     *     type: string,
     *     severity: 'info'|'warning'|'critical',
     *     message: string,
     *     created_at: string,
     *     acknowledged: bool,
     *     actions: array<string>
     * }>
     */
    public function getSystemAlerts(array $options = []): array;

    /**
     * Acknowledge system alert
     * 
     * @param string $alert_id
     * @param array $options {
     *     @var string $notes Acknowledgment notes
     *     @var int $admin_id Admin who acknowledged
     *     @var array $actions_taken Actions taken
     * }
     * @return bool
     */
    public function acknowledgeAlert(string $alert_id, array $options = []): bool;

    /**
     * Get performance monitoring dashboard
     * 
     * @return array{
     *     metrics: array<string, mixed>,
     *     charts: array<array>,
     *     alerts: array<array>,
     *     recommendations: array<string>,
     *     last_updated: string
     * }
     */
    public function getMonitoringDashboard(): array;

    // ==================== DIAGNOSTICS & DEBUGGING ====================

    /**
     * Run product system diagnostics
     * 
     * @param array $tests Specific tests to run
     * @param array $options {
     *     @var bool $fix_issues Attempt to fix issues
     *     @var bool $detailed Detailed diagnostics
     *     @var bool $export_results Export results
     * }
     * @return array{
     *     overall_status: string,
     *     tests_run: int,
     *     tests_passed: int,
     *     tests_failed: int,
     *     results: array<array>,
     *     recommendations: array<string>,
     *     execution_time: float
     * }
     */
    public function runDiagnostics(array $tests = [], array $options = []): array;

    /**
     * Debug product data issues
     * 
     * @param int $productId
     * @param array $options {
     *     @var bool $deep_inspection Deep inspection
     *     @var array $include_aspects Aspects to inspect
     *     @var bool $generate_report Generate debug report
     * }
     * @return array{
     *     issues_found: array<array>,
     *     data_snapshot: array,
     *     recommendations: array<string>,
     *     next_steps: array<string>
     * }
     */
    public function debugProduct(int $productId, array $options = []): array;

    /**
     * Get system logs related to products
     * 
     * @param array $filters Log filters
     * @param array $options {
     *     @var int $limit Number of logs to return
     *     @var string $level Log level filter
     *     @var \DateTimeInterface $since Time filter
     *     @var string $format Output format
     * }
     * @return array<array>|string
     */
    public function getProductLogs(array $filters = [], array $options = []);

    /**
     * Generate system health report
     * 
     * @param array $options {
     *     @var string $format Report format
     *     @var array $sections Sections to include
     *     @var bool $include_suggestions Include suggestions
     *     @var bool $schedule_followup Schedule follow-up
     * }
     * @return array{
     *     report: mixed,
     *     format: string,
     *     generated_at: string,
     *     summary: array<string, mixed>
     * }
     */
    public function generateHealthReport(array $options = []): array;

    // ==================== CONFIGURATION MANAGEMENT ====================

    /**
     * Update product system configuration
     * 
     * @param array $config New configuration
     * @param array $options {
     *     @var bool $validate Validate configuration
     *     @var bool $backup Backup current config
     *     @var bool $reload_services Reload affected services
     * }
     * @return array{
     *     updated: array<string>,
     *     skipped: array<string>,
     *     validation_errors: array<string>,
     *     backup_id: string|null,
     *     requires_restart: bool
     * }
     */
    public function updateSystemConfiguration(array $config, array $options = []): array;

    /**
     * Get current product system configuration
     * 
     * @param array $options {
     *     @var bool $include_defaults Include default values
     *     @var array $sections Specific sections
     *     @var bool $sensitive Include sensitive data
     * }
     * @return array<string, mixed>
     */
    public function getSystemConfiguration(array $options = []): array;

    /**
     * Reset configuration to defaults
     * 
     * @param array $options {
     *     @var array $sections Specific sections to reset
     *     @var bool $backup_current Backup current config
     *     @var bool $confirm Confirmation required
     * }
     * @return array{
     *     reset: array<string>,
     *     backup_id: string|null,
     *     requires_restart: bool
     * }
     */
    public function resetConfiguration(array $options = []): array;
}