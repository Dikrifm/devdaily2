<?php

namespace App\Repositories\Interfaces;

use App\Repositories\BaseRepositoryInterface;
use App\Entities\AuditLog;
use App\DTOs\Queries\PaginationQuery;

/**
 * AuditLog Repository Interface
 * 
 * Layer 3: Data Orchestrator Contract for AuditLog
 * Specialized for audit trail and system logging operations
 * 
 * @extends App\Repositories\BaseRepositoryInterface<AuditLog>
 */
interface AuditLogRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find audit logs by admin ID
     * 
     * @param int $adminId
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findByAdminId(int $adminId, int $limit = 50, int $offset = 0): array;

    /**
     * Find audit logs by entity (entity type and ID)
     * 
     * @param string $entityType
     * @param int $entityId
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 50, int $offset = 0): array;

    /**
     * Find audit logs by action type
     * 
     * @param string $actionType
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findByActionType(string $actionType, int $limit = 50, int $offset = 0): array;

    /**
     * Find system actions (logs without admin_id)
     * 
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findSystemActions(int $limit = 50, int $offset = 0): array;

    /**
     * Find audit logs by date range
     * 
     * @param string $startDate Format: Y-m-d H:i:s
     * @param string $endDate Format: Y-m-d H:i:s
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findByDateRange(string $startDate, string $endDate, int $limit = 100, int $offset = 0): array;

    /**
     * Find recent audit logs
     * 
     * @param int $hours Number of hours to look back
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findRecent(int $hours = 24, int $limit = 100, int $offset = 0): array;

    /**
     * Search audit logs by summary text
     * 
     * @param string $searchTerm
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function searchBySummary(string $searchTerm, int $limit = 50, int $offset = 0): array;

    /**
     * Find audit logs by IP address
     * 
     * @param string $ipAddress
     * @param int $limit
     * @param int $offset
     * @return array<AuditLog>
     */
    public function findByIpAddress(string $ipAddress, int $limit = 50, int $offset = 0): array;

    /**
     * Paginate with advanced filters
     * 
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @param int $page
     * @return array{data: array<AuditLog>, pagination: array}
     */
    public function paginateWithFilters(array $filters = [], int $perPage = 25, int $page = 1): array;

    /**
     * Find audit logs with admin information (join)
     * 
     * @param int $limit
     * @param int $offset
     * @return array<array>
     */
    public function findWithAdminInfo(int $limit = 50, int $offset = 0): array;

    /**
     * Get complete entity history (audit trail)
     * 
     * @param string $entityType
     * @param int $entityId
     * @return array<AuditLog>
     */
    public function getEntityHistory(string $entityType, int $entityId): array;

    /**
     * Get statistics by date range
     * 
     * @param string $startDate
     * @param string $endDate
     * @return array{
     *     total_logs: int,
     *     actions_by_type: array<string, int>,
     *     entities_by_type: array<string, int>,
     *     top_admins: array<array{admin_id: int, action_count: int}>
     * }
     */
    public function getStatisticsByDateRange(string $startDate, string $endDate): array;

    /**
     * Get most active admins
     * 
     * @param int $limit
     * @param string|null $startDate
     * @param string|null $endDate
     * @return array<array{admin_id: int, admin_name: string, action_count: int}>
     */
    public function getMostActiveAdmins(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array;

    /**
     * Get activity timeline (grouped by day/hour)
     * 
     * @param int $days Number of days to include
     * @return array<array{date: string, hour: int, count: int}>
     */
    public function getActivityTimeline(int $days = 30): array;

    /**
     * Check if entity has history
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
     * @return string|null
     */
    public function getLastAdminActivity(int $adminId): ?string;

    /**
     * Bulk insert audit logs
     * 
     * @param array<AuditLog> $auditLogs
     * @return int Number of inserted logs
     */
    public function bulkInsert(array $auditLogs): int;

    /**
     * Clean old logs (archive or delete)
     * 
     * @param string $olderThan Date string (e.g., '2024-01-01')
     * @return int Number of cleaned logs
     */
    public function cleanOldLogs(string $olderThan): int;

    /**
     * Get database size statistics for audit logs
     * 
     * @return array{
     *     table_size: string,
     *     row_count: int,
     *     avg_row_size: string,
     *     index_size: string
     * }
     */
    public function getDatabaseSize(): array;

    /**
     * Create a single audit log entry
     * 
     * @param int|null $adminId
     * @param string $actionType
     * @param string $entityType
     * @param int $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return AuditLog
     */
    public function createLogEntry(
        ?int $adminId,
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): AuditLog;

    /**
     * Get action type distribution
     * 
     * @param int $days Number of days to analyze
     * @return array<string, int>
     */
    public function getActionTypeDistribution(int $days = 30): array;

    /**
     * Get entity type distribution
     * 
     * @param int $days Number of days to analyze
     * @return array<string, int>
     */
    public function getEntityTypeDistribution(int $days = 30): array;

    /**
     * Get daily activity count
     * 
     * @param int $days Number of days to include
     * @return array<array{date: string, count: int}>
     */
    public function getDailyActivity(int $days = 30): array;

    /**
     * Get entity audit trail with pagination
     * 
     * @param string $entityType
     * @param int $entityId
     * @param int $page
     * @param int $perPage
     * @return array{data: array<AuditLog>, pagination: array}
     */
    public function getEntityAuditTrail(string $entityType, int $entityId, int $page = 1, int $perPage = 20): array;

    /**
     * Cleanup old logs with archiving option
     * 
     * @param int $daysOlderThan
     * @param bool $archiveFirst
     * @return int Number of cleaned logs
     */
    public function cleanupOldLogs(int $daysOlderThan = 365, bool $archiveFirst = true): int;
}