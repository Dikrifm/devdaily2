<?php

namespace App\Contracts;

use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Responses\AuditLogResponse;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Audit Log Service Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for audit logging and activity tracking.
 * Defines protocol for recording, retrieving, and analyzing audit trails.
 *
 * @package App\Contracts
 */
interface AuditLogInterface extends BaseInterface
{
    // ==================== AUDIT LOG RECORDING ====================

    /**
     * Record audit log entry for business operation
     *
     * @param string $actionType
     * @param string $entityType
     * @param int $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param int|null $adminId
     * @param array $additionalContext
     * @return string Audit log entry ID or reference
     * @throws ValidationException
     * @throws DomainException
     */
    public function log(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $adminId = null,
        array $additionalContext = []
    ): string;

    /**
     * Record system-generated audit log (no admin context)
     *
     * @param string $actionType
     * @param string $entityType
     * @param int $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param array $systemContext
     * @return string
     * @throws ValidationException
     * @throws DomainException
     */
    public function logSystemAction(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $systemContext = []
    ): string;

    /**
     * Record bulk audit logs for batch operations
     *
     * @param array<array{
     *     action_type: string,
     *     entity_type: string,
     *     entity_id: int,
     *     old_values?: array|null,
     *     new_values?: array|null,
     *     admin_id?: int|null,
     *     context?: array
     * }> $logEntries
     * @return int Number of successfully logged entries
     * @throws ValidationException
     * @throws DomainException
     */
    public function logBulk(array $logEntries): int;

    // ==================== AUDIT LOG RETRIEVAL ====================

    /**
     * Get audit log by ID
     *
     * @param int $logId
     * @return AuditLogResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getLog(int $logId): AuditLogResponse;

    /**
     * Get paginated audit logs with filters
     *
     * @param PaginationQuery $pagination
     * @param array<string, mixed> $filters
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function getLogs(PaginationQuery $pagination, array $filters = []): array;

    /**
     * Get audit logs for specific entity
     *
     * @param string $entityType
     * @param int $entityId
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function getEntityLogs(string $entityType, int $entityId, PaginationQuery $pagination): array;

    /**
     * Get audit logs for specific admin
     *
     * @param int $adminId
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getAdminLogs(int $adminId, PaginationQuery $pagination): array;

    /**
     * Get audit logs by action type
     *
     * @param string $actionType
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function getLogsByActionType(string $actionType, PaginationQuery $pagination): array;

    /**
     * Get system-generated audit logs
     *
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function getSystemLogs(PaginationQuery $pagination): array;

    // ==================== AUDIT LOG SEARCH ====================

    /**
     * Search audit logs by summary/content
     *
     * @param string $searchTerm
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function searchLogs(string $searchTerm, PaginationQuery $pagination): array;

    /**
     * Search audit logs by IP address
     *
     * @param string $ipAddress
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function searchLogsByIp(string $ipAddress, PaginationQuery $pagination): array;

    /**
     * Search audit logs within date range
     *
     * @param string $startDate ISO 8601 date
     * @param string $endDate ISO 8601 date
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function searchLogsByDateRange(string $startDate, string $endDate, PaginationQuery $pagination): array;

    /**
     * Get recent audit logs (last N hours)
     *
     * @param int $hours
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AuditLogResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     * @throws AuthorizationException
     */
    public function getRecentLogs(int $hours = 24, PaginationQuery $pagination): array;

    // ==================== AUDIT TRAIL & HISTORY ====================

    /**
     * Get complete audit trail for entity
     *
     * @param string $entityType
     * @param int $entityId
     * @param bool $includeSystemActions
     * @return array<AuditLogResponse>
     * @throws AuthorizationException
     */
    public function getEntityAuditTrail(string $entityType, int $entityId, bool $includeSystemActions = false): array;

    /**
     * Get entity change history with diffs
     *
     * @param string $entityType
     * @param int $entityId
     * @param string $field Filter by specific field (optional)
     * @return array<array{
     *     timestamp: string,
     *     action: string,
     *     admin_id: int|null,
     *     admin_name: string|null,
     *     old_value: mixed,
     *     new_value: mixed,
     *     diff: array
     * }>
     * @throws AuthorizationException
     */
    public function getEntityChangeHistory(string $entityType, int $entityId, ?string $field = null): array;

    /**
     * Get admin activity timeline
     *
     * @param int $adminId
     * @param int $days
     * @return array<array{
     *     date: string,
     *     actions: int,
     *     entities: array<string, int>,
     *     last_action: string|null
     * }>
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getAdminActivityTimeline(int $adminId, int $days = 30): array;

    /**
     * Check if entity has audit history
     *
     * @param string $entityType
     * @param int $entityId
     * @return bool
     */
    public function hasEntityHistory(string $entityType, int $entityId): bool;

    /**
     * Get last activity timestamp for admin
     *
     * @param int $adminId
     * @return string|null ISO 8601 timestamp or null if no activity
     * @throws NotFoundException
     */
    public function getLastAdminActivity(int $adminId): ?string;

    // ==================== AUDIT STATISTICS & ANALYTICS ====================

    /**
     * Get audit log statistics
     *
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array{
     *     total_logs: int,
     *     admin_logs: int,
     *     system_logs: int,
     *     by_action_type: array<string, int>,
     *     by_entity_type: array<string, int>,
     *     by_admin: array<int, array{admin_id: int, count: int, name: string}>,
     *     daily_activity: array<string, int>,
     *     busiest_hour: array{hour: int, count: int}|null
     * }
     * @throws AuthorizationException
     */
    public function getStatistics(?string $startDate = null, ?string $endDate = null): array;

    /**
     * Get action type distribution
     *
     * @param int $days
     * @return array<string, int>
     * @throws AuthorizationException
     */
    public function getActionTypeDistribution(int $days = 30): array;

    /**
     * Get entity type distribution
     *
     * @param int $days
     * @return array<string, int>
     * @throws AuthorizationException
     */
    public function getEntityTypeDistribution(int $days = 30): array;

    /**
     * Get most active admins
     *
     * @param int $limit
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array<array{
     *     admin_id: int,
     *     admin_name: string,
     *     action_count: int,
     *     last_active: string,
     *     common_actions: array<string, int>
     * }>
     * @throws AuthorizationException
     */
    public function getMostActiveAdmins(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array;

    /**
     * Get daily activity counts
     *
     * @param int $days
     * @return array<array{
     *     date: string,
     *     total_actions: int,
     *     admin_actions: int,
     *     system_actions: int,
     *     peak_hour: int
     * }>
     * @throws AuthorizationException
     */
    public function getDailyActivity(int $days = 30): array;

    /**
     * Get audit database size statistics
     *
     * @return array{
     *     total_rows: int,
     *     estimated_size_mb: float,
     *     oldest_log: string|null,
     *     newest_log: string|null
     * }
     * @throws AuthorizationException
     */
    public function getDatabaseSize(): array;

    // ==================== AUDIT LOG MAINTENANCE ====================

    /**
     * Clean up old audit logs
     *
     * @param int $daysOlderThan
     * @param bool $archiveFirst Archive logs before deletion
     * @return array{
     *     deleted_count: int,
     *     archived_count: int,
     *     freed_space_mb: float
     * }
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function cleanupOldLogs(int $daysOlderThan = 365, bool $archiveFirst = true): array;

    /**
     * Export audit logs
     *
     * @param array $criteria
     * @param string $format json|csv|xml
     * @return array{
     *     export_id: string,
     *     record_count: int,
     *     file_size: int,
     *     download_url: string|null
     * }
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function exportLogs(array $criteria = [], string $format = 'json'): array;

    /**
     * Archive audit logs to external storage
     *
     * @param array $logIds
     * @return array{
     *     archived_count: int,
     *     archive_location: string,
     *     archive_id: string
     * }
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function archiveLogs(array $logIds): array;

    /**
     * Restore archived logs
     *
     * @param string $archiveId
     * @return array{
     *     restored_count: int,
     *     warnings: array<string>
     * }
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function restoreArchivedLogs(string $archiveId): array;

    // ==================== AUDIT LOG VALIDATION & COMPLIANCE ====================

    /**
     * Validate audit log integrity
     *
     * @param int|null $logId Check specific log or all logs
     * @return array{
     *     valid: bool,
     *     checked_count: int,
     *     issues: array<array{
     *         log_id: int,
     *         issue: string,
     *         severity: string
     *     }>
     * }
     * @throws AuthorizationException
     */
    public function validateIntegrity(?int $logId = null): array;

    /**
     * Check audit log compliance with retention policy
     *
     * @return array{
     *     compliant: bool,
     *     retention_days: int,
     *     logs_older_than_retention: int,
     *     action_required: bool
     * }
     * @throws AuthorizationException
     */
    public function checkCompliance(): array;

    /**
     * Generate compliance report
     *
     * @param string $startDate
     * @param string $endDate
     * @param array $filters
     * @return array{
     *     report_id: string,
     *     period: array{start: string, end: string},
     *     summary: array<string, mixed>,
     *     details: array
     * }
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function generateComplianceReport(string $startDate, string $endDate, array $filters = []): array;

    // ==================== AUDIT LOG CONFIGURATION ====================

    /**
     * Get audit log configuration
     *
     * @return array{
     *     retention_days: int,
     *     enabled_entities: array<string>,
     *     enabled_actions: array<string>,
     *     max_log_size_mb: int,
     *     auto_cleanup: bool,
     *     archive_enabled: bool
     * }
     * @throws AuthorizationException
     */
    public function getConfiguration(): array;

    /**
     * Update audit log configuration
     *
     * @param array<string, mixed> $config
     * @return array{
     *     updated: bool,
     *     changes: array<string, array{old: mixed, new: mixed}>
     * }
     * @throws ValidationException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function updateConfiguration(array $config): array;

    /**
     * Get supported entity types for auditing
     *
     * @return array<string, string> [entity_type => display_name]
     */
    public function getSupportedEntities(): array;

    /**
     * Get supported action types for auditing
     *
     * @return array<string, string> [action_type => display_name]
     */
    public function getSupportedActions(): array;

    // ==================== AUDIT LOG UTILITIES ====================

    /**
     * Generate audit log summary for entity
     *
     * @param string $entityType
     * @param int $entityId
     * @param int $maxEntries
     * @return array{
     *     entity_type: string,
     *     entity_id: int,
     *     total_actions: int,
     *     first_action: string|null,
     *     last_action: string|null,
     *     recent_actions: array<AuditLogResponse>,
     *     top_admins: array<array{admin_id: int, name: string, count: int}>
     * }
     * @throws AuthorizationException
     */
    public function generateEntitySummary(string $entityType, int $entityId, int $maxEntries = 10): array;

    /**
     * Compare two entity states and generate audit data
     *
     * @param string $entityType
     * @param int $entityId
     * @param array $oldState
     * @param array $newState
     * @return array{
     *     has_changes: bool,
     *     changes: array<string, array{old: mixed, new: mixed}>,
     *     summary: string
     * }
     */
    public function compareEntityStates(
        string $entityType,
        int $entityId,
        array $oldState,
        array $newState
    ): array;

    /**
     * Parse audit log changes into human-readable format
     *
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param string $entityType
     * @return array<array{
     *     field: string,
     *     old_value: mixed,
     *     new_value: mixed,
     *     change_type: string
     * }>
     */
    public function parseChanges(?array $oldValues, ?array $newValues, string $entityType): array;

    /**
     * Get audit log health status
     *
     * @return array{
     *     status: string,
     *     logs_per_day: float,
     *     storage_usage: array{used_mb: float, available_mb: float, percentage: float},
     *     oldest_log_days: int|null,
     *     compliance_status: string,
     *     warnings: array<string>
     * }
     * @throws AuthorizationException
     */
    public function getHealthStatus(): array;
}