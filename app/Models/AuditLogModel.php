<?php

namespace App\Models;

use App\Entities\AuditLog;
use CodeIgniter\I18n\Time;

/**
 * Audit Log Model
 *
 * Tracks all administrative actions for security auditing and accountability.
 * Immutable log - entries cannot be modified or deleted (read-only).
 *
 * @package App\Models
 */
class AuditLogModel extends BaseModel
{
    /**
     * Table name
     *
     * @var string
     */
    protected $table = 'admin_actions';

    /**
     * Primary key
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Return type
     *
     * @var string
     */
    protected $returnType = AuditLog::class;

    /**
     * Allowed fields for insertion only
     * This model is write-once, read-many
     *
     * @var array
     */
    protected $allowedFields = [
        'admin_id',
        'action_type',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'changes_summary',
        'ip_address',
        'user_agent'
    ];

    /**
     * Use timestamps? NO - uses performed_at instead
     *
     * @var bool
     */
    protected $useTimestamps = false;

    /**
     * Use soft deletes? NO - audit logs are immutable
     *
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * Default ordering for queries
     *
     * @var string
     */
    protected $orderBy = 'performed_at DESC';

    /**
     * Action types constants
     *
     * @var array
     */
    public const ACTION_TYPES = [
        'create'              => 'Create',
        'update'              => 'Update',
        'delete'              => 'Delete',
        'verify'              => 'Verify',
        'publish'             => 'Publish',
        'archive'             => 'Archive',
        'restore'             => 'Restore',
        'login'               => 'Login',
        'logout'              => 'Logout',
        'password_change'     => 'Password Change',
        'role_change'         => 'Role Change',
        'status_change'       => 'Status Change',
        'bulk_operation'      => 'Bulk Operation',
        'import'              => 'Import',
        'export'              => 'Export',
        'system'              => 'System Action'
    ];

    /**
     * Entity types from database
     *
     * @var array
     */
    public const ENTITY_TYPES = [
        'Product',
        'Category',
        'Marketplace',
        'Badge',
        'MarketplaceBadge',
        'Link',
        'Admin',
        'ProductBadge'
    ];

    /**
     * Before insert callback
     * Sets performed_at timestamp and validates data
     */
    protected function beforeInsert(array $data): array
    {
        // Set performed_at to current time if not provided
        if (!isset($data['performed_at']) || empty($data['performed_at'])) {
            $data['performed_at'] = date('Y-m-d H:i:s');
        }

        // Validate JSON fields
        if (isset($data['old_values']) && is_array($data['old_values'])) {
            $data['old_values'] = json_encode($data['old_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['new_values']) && is_array($data['new_values'])) {
            $data['new_values'] = json_encode($data['new_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        // Truncate changes_summary if too long
        if (isset($data['changes_summary']) && strlen($data['changes_summary']) > 500) {
            $data['changes_summary'] = substr($data['changes_summary'], 0, 497) . '...';
        }

        return $data;
    }

    /**
     * Log an administrative action
     *
     * @return int|false Insert ID or false on failure
     */
    public function logAction(array $logData)
    {
        // Required fields validation
        $required = ['action_type', 'entity_type', 'entity_id'];
        foreach ($required as $field) {
            if (!isset($logData[$field]) || empty($logData[$field])) {
                log_message('error', "Audit log missing required field: {$field}");
                return false;
            }
        }

        // Default admin_id to current session if not provided
        if (!isset($logData['admin_id'])) {
            $logData['admin_id'] = session('admin_id') ?? null;
        }

        // Add IP address if not provided
        if (!isset($logData['ip_address'])) {
            $logData['ip_address'] = service('request')->getIPAddress();
        }

        // Add user agent if not provided
        if (!isset($logData['user_agent'])) {
            $logData['user_agent'] = service('request')->getUserAgent()->getAgentString();
        }

        try {
            $insertId = $this->insert($logData, true); // Return insert ID

            if ($insertId) {
                // Clear relevant caches
                $this->clearAuditCache($logData);

                // Log to file for additional backup
                $this->logToFile($insertId, $logData);
            }

            return $insertId;

        } catch (\Exception $e) {
            log_message('error', 'Failed to log audit action: ' . $e->getMessage());

            // Emergency fallback: log to file only
            $this->logToFile(0, $logData, 'FAILED_DB: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Log a CRUD operation with before/after states
     *
     * @param string $action      create|update|delete
     * @param string $entityType  Entity class name
     * @param int $entityId       Entity ID
     * @param mixed $oldEntity    Old entity state (for update/delete)
     * @param mixed $newEntity    New entity state (for create/update)
     * @param int|null $adminId   Admin ID (defaults to session)
     * @return int|false
     */
    public function logCrudOperation(
        string $action,
        string $entityType,
        int $entityId,
        $oldEntity = null,
        $newEntity = null,
        ?int $adminId = null
    ) {
        $logData = [
            'admin_id'    => $adminId ?? session('admin_id'),
            'action_type' => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ];

        // Prepare old and new values
        if ($oldEntity !== null) {
            $logData['old_values'] = $this->prepareEntityData($oldEntity);
        }

        if ($newEntity !== null) {
            $logData['new_values'] = $this->prepareEntityData($newEntity);
        }

        // Generate changes summary
        $logData['changes_summary'] = $this->generateChangesSummary(
            $action,
            $entityType,
            $entityId,
            $logData['old_values'] ?? null,
            $logData['new_values'] ?? null
        );

        return $this->logAction($logData);
    }

    /**
     * Log a state transition (e.g., draft → pending_verification)
     *
     * @return int|false
     */
    public function logStateTransition(
        string $entityType,
        int $entityId,
        string $fromState,
        string $toState,
        ?int $adminId = null
    ) {
        $logData = [
            'admin_id'    => $adminId ?? session('admin_id'),
            'action_type' => 'status_change',
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'old_values'  => ['status' => $fromState],
            'new_values'  => ['status' => $toState],
            'changes_summary' => "Status changed from '{$fromState}' to '{$toState}'"
        ];

        return $this->logAction($logData);
    }

    /**
     * Log admin login
     *
     * @param string|null $reason Failure reason if unsuccessful
     * @return int|false
     */
    public function logLogin(int $adminId, bool $success = true, ?string $reason = null)
    {
        $logData = [
            'admin_id'    => $adminId,
            'action_type' => 'login',
            'entity_type' => 'Admin',
            'entity_id'   => $adminId,
            'new_values'  => [
                'success' => $success,
                'timestamp' => date('Y-m-d H:i:s'),
                'ip_address' => service('request')->getIPAddress()
            ],
            'changes_summary' => $success
                ? 'Successful login'
                : 'Failed login attempt' . ($reason ? ': ' . $reason : '')
        ];

        return $this->logAction($logData);
    }

    /**
     * Log admin logout
     *
     * @return int|false
     */
    public function logLogout(int $adminId)
    {
        $logData = [
            'admin_id'    => $adminId,
            'action_type' => 'logout',
            'entity_type' => 'Admin',
            'entity_id'   => $adminId,
            'changes_summary' => 'Admin logged out'
        ];

        return $this->logAction($logData);
    }

    /**
     * Get logs for specific entity
     */
    public function getEntityLogs(string $entityType, int $entityId, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->cacheKey("entity_{$entityType}_{$entityId}_{$limit}_{$offset}");

        return $this->cached($cacheKey, function () use ($entityType, $entityId, $limit, $offset) {
            return $this->where('entity_type', $entityType)
                        ->where('entity_id', $entityId)
                        ->orderBy('performed_at', 'DESC')
                        ->limit($limit, $offset)
                        ->find(); // Changed from findAll() to find()
        });
    }

    /**
     * Get logs for specific admin
     */
    public function getAdminLogs(int $adminId, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->cacheKey("admin_{$adminId}_{$limit}_{$offset}");

        return $this->cached($cacheKey, function () use ($adminId, $limit, $offset) {
            return $this->where('admin_id', $adminId)
                        ->orderBy('performed_at', 'DESC')
                        ->limit($limit, $offset)
                        ->find(); // Changed from findAll() to find()
        });
    }

    /**
     * Search logs with multiple filters
     *
     * @return array [total, results]
     */
    public function searchLogs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $builder = $this->builder();

        // Apply filters
        if (!empty($filters['admin_id'])) {
            $builder->where('admin_id', (int) $filters['admin_id']);
        }

        if (!empty($filters['action_type'])) {
            $builder->where('action_type', $filters['action_type']);
        }

        if (!empty($filters['entity_type'])) {
            $builder->where('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['entity_id'])) {
            $builder->where('entity_id', (int) $filters['entity_id']);
        }

        if (!empty($filters['date_from'])) {
            $builder->where('performed_at >=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $builder->where('performed_at <=', $filters['date_to'] . ' 23:59:59');
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $builder->groupStart()
                    ->like('changes_summary', $search)
                    ->orLike('ip_address', $search)
                    ->groupEnd();
        }

        // Count total
        $total = $builder->countAllResults(false);

        // Get results - FIXED: Removed $this->returnType parameter
        $results = $builder->orderBy('performed_at', 'DESC')
                          ->limit($limit, $offset)
                          ->get()
                          ->getResult(); // Changed from getResult($this->returnType)

        return [
            'total' => $total,
            'results' => $results,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Get recent activity for dashboard
     */
    public function getRecentActivity(int $limit = 20): array
    {
        $cacheKey = $this->cacheKey("recent_{$limit}_" . date('YmdH'));

        return $this->cached($cacheKey, function () use ($limit) {
            return $this->orderBy('performed_at', 'DESC')
                        ->limit($limit)
                        ->find(); // Changed from findAll() to find()
        });
    }

    /**
     * Get statistics for reporting
     *
     * @param string $period day|week|month|year
     */
    public function getStatistics(string $period = 'month'): array
    {
        $cacheKey = $this->cacheKey("stats_{$period}_" . date('Ymd'));

        return $this->cached($cacheKey, function () use ($period) {
            $dateCondition = $this->getDateCondition($period);

            $stats = [
                'total_actions' => 0,
                'actions_by_type' => [],
                'actions_by_entity' => [],
                'top_admins' => [],
                'activity_trend' => []
            ];

            // Total actions
            $stats['total_actions'] = $this->where($dateCondition)->countAllResults();

            // Actions by type
            $builder = $this->builder();
            $query = $builder->select('action_type, COUNT(*) as count')
                            ->where($dateCondition)
                            ->groupBy('action_type')
                            ->orderBy('count', 'DESC')
                            ->get();
            $stats['actions_by_type'] = $query->getResultArray();

            // Actions by entity
            $builder = $this->builder();
            $query = $builder->select('entity_type, COUNT(*) as count')
                            ->where($dateCondition)
                            ->groupBy('entity_type')
                            ->orderBy('count', 'DESC')
                            ->get();
            $stats['actions_by_entity'] = $query->getResultArray();

            // Top admins
            $builder = $this->builder();
            $query = $builder->select('admin_id, COUNT(*) as count')
                            ->where($dateCondition)
                            ->where('admin_id IS NOT NULL')
                            ->groupBy('admin_id')
                            ->orderBy('count', 'DESC')
                            ->limit(10)
                            ->get();
            $stats['top_admins'] = $query->getResultArray();

            return $stats;
        }, 300); // 5 minutes cache
    }

    /**
     * Clean up old logs (archival function)
     *
     * @param int $daysOld Keep logs newer than X days
     * @return int Number of rows affected
     */
    public function cleanupOldLogs(int $daysOld = 365): int
    {
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        // For MVP: we don't delete, we archive to separate table
        // For now, just return count of old logs
        $count = $this->where('performed_at <', $dateLimit)->countAllResults();

        log_message('info', "Found {$count} audit logs older than {$daysOld} days");

        // In production, you might:
        // 1. Export to archive table
        // 2. Compress and store off-site
        // 3. Then delete from main table

        return $count;
    }

    /**
     * Export logs to CSV
     *
     * @return string CSV content
     */
    public function exportToCsv(array $filters = []): string
    {
        $logs = $this->searchLogs($filters, 10000, 0)['results'];

        $csv = "ID,Date,Time,Admin ID,Action Type,Entity Type,Entity ID,Changes Summary,IP Address\n";

        foreach ($logs as $log) {
            $date = $log->performed_at ? date('Y-m-d', strtotime((string) $log->performed_at)) : '';
            $time = $log->performed_at ? date('H:i:s', strtotime((string) $log->performed_at)) : '';

            $csv .= sprintf(
                '%d,%s,%s,%s,%s,%s,%d,"%s",%s' . "\n",
                $log->id,
                $date,
                $time,
                $log->admin_id ?? 'SYSTEM',
                $log->action_type,
                $log->entity_type,
                $log->entity_id,
                str_replace('"', '""', $log->changes_summary ?? ''),
                $log->ip_address
            );
        }

        return $csv;
    }

    /**
     * Prepare entity data for JSON storage
     *
     * @param mixed $entity
     */
    private function prepareEntityData($entity): array
    {
        if (is_object($entity) && method_exists($entity, 'toArray')) {
            return $entity->toArray();
        }

        if (is_array($entity)) {
            return $entity;
        }

        if (is_object($entity)) {
            return (array) $entity;
        }

        return ['raw' => $entity];
    }

    /**
     * Generate human-readable changes summary
     *
     * @param mixed $oldValues
     * @param mixed $newValues
     */
    private function generateChangesSummary(
        string $action,
        string $entityType,
        int $entityId,
        $oldValues = null,
        $newValues = null
    ): string {
        $oldArray = is_string($oldValues) ? json_decode($oldValues, true) : $oldValues;
        $newArray = is_string($newValues) ? json_decode($newValues, true) : $newValues;

        switch ($action) {
            case 'create':
                return "Created {$entityType} #{$entityId}";

            case 'update':
                if (is_array($oldArray) && is_array($newArray)) {
                    $changes = [];
                    foreach ($newArray as $key => $value) {
                        if (!isset($oldArray[$key]) || $oldArray[$key] != $value) {
                            $oldVal = $oldArray[$key] ?? '(empty)';
                            $newVal = $value;

                            // Truncate long values
                            if (is_string($oldVal) && strlen($oldVal) > 30) {
                                $oldVal = substr($oldVal, 0, 27) . '...';
                            }
                            if (is_string($newVal) && strlen($newVal) > 30) {
                                $newVal = substr($newVal, 0, 27) . '...';
                            }

                            $changes[] = "{$key}: {$oldVal} → {$newVal}";
                        }
                    }

                    if ($changes === []) {
                        return "Updated {$entityType} #{$entityId} (no visible changes)";
                    }

                    return "Updated {$entityType} #{$entityId}: " . implode(', ', array_slice($changes, 0, 3)) .
                           (count($changes) > 3 ? '...' : '');
                }
                return "Updated {$entityType} #{$entityId}";

            case 'delete':
                return "Deleted {$entityType} #{$entityId}";

            default:
                return ucfirst($action) . " {$entityType} #{$entityId}";
        }
    }

    /**
     * Get date condition for period
     */
    private function getDateCondition(string $period): string
    {
        $formats = [
            'day'   => 'Y-m-d',
            'week'  => 'Y-m-d',
            'month' => 'Y-m',
            'year'  => 'Y'
        ];

        if (!isset($formats[$period])) {
            $period = 'month';
        }

        $date = date($formats[$period]);

        switch ($period) {
            case 'day':
                return "DATE(performed_at) = '{$date}'";
            case 'week':
                $monday = date('Y-m-d', strtotime('monday this week'));
                $sunday = date('Y-m-d', strtotime('sunday this week'));
                return "DATE(performed_at) BETWEEN '{$monday}' AND '{$sunday}'";
            case 'month':
                return "DATE_FORMAT(performed_at, '%Y-%m') = '{$date}'";
            case 'year':
                return "YEAR(performed_at) = '{$date}'";
            default:
                return "1=1";
        }
    }

    /**
     * Log to file as backup
     */
    private function logToFile(int $logId, array $data, string $note = ''): void
    {
        $logMessage = sprintf(
            "[%s] Audit Log %s: %s | Admin: %s | Action: %s | Entity: %s #%s | IP: %s | %s\n",
            date('Y-m-d H:i:s'),
            $logId !== 0 ? '#' . $logId : 'FAILED',
            $data['changes_summary'] ?? 'No summary',
            $data['admin_id'] ?? 'SYSTEM',
            $data['action_type'] ?? 'unknown',
            $data['entity_type'] ?? 'unknown',
            $data['entity_id'] ?? '0',
            $data['ip_address'] ?? '0.0.0.0',
            $note
        );

        // Write to audit log file
        $logPath = WRITEPATH . 'logs/audit-' . date('Y-m-d') . '.log';
        file_put_contents($logPath, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clear relevant audit caches
     */
    private function clearAuditCache(array $logData): void
    {
        // Clear recent activity cache
        $this->clearCache($this->cacheKey('recent_*'));

        // Clear entity-specific caches
        if (isset($logData['entity_type']) && isset($logData['entity_id'])) {
            $this->clearCache($this->cacheKey("entity_{$logData['entity_type']}_{$logData['entity_id']}_*"));
        }

        // Clear admin-specific caches
        if (isset($logData['admin_id'])) {
            $this->clearCache($this->cacheKey("admin_{$logData['admin_id']}_*"));
        }

        // Clear statistics caches
        $this->clearCache($this->cacheKey('stats_*'));
    }

    /**
     * Override delete method - audit logs are immutable
     *
     * @param mixed $id
     * @throws \RuntimeException
     */
    public function delete($id = null, bool $purge = false): never
    {
        throw new \RuntimeException('Audit logs cannot be deleted. They are immutable for security reasons.');
    }

    /**
     * Override update method - audit logs are immutable
     *
     * @param mixed $id
     * @param array $data
     * @return never
     * @throws \RuntimeException
     */
    public function update($id = null, $data = null): bool
    {
        throw new \RuntimeException('Audit logs cannot be updated. They are immutable for security reasons.');
    }

    /**
     * Check if action type is valid
     */
    public function isValidActionType(string $actionType): bool
    {
        return array_key_exists($actionType, self::ACTION_TYPES);
    }

    /**
     * Check if entity type is valid
     */
    public function isValidEntityType(string $entityType): bool
    {
        return in_array($entityType, self::ENTITY_TYPES);
    }
}
