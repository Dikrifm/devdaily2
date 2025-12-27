<?php

namespace App\Models;

use App\Entities\AuditLog;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\Query;
use RuntimeException;
use InvalidArgumentException;

/**
 * Audit Log Model - Immutable SQL Encapsulator Layer for Audit Trail Data
 * 
 * Responsibilities:
 * 1. Handle INSERT operations for audit logs (immutable - no updates/deletes)
 * 2. Provide optimized query methods for audit trail retrieval
 * 3. Return fully hydrated AuditLog Entities
 * 4. Handle time-series and entity-based queries efficiently
 * 
 * Special Characteristics:
 * - IMMUTABLE: Audit logs cannot be updated or deleted
 * - HIGH-VOLUME: Optimized for large datasets with proper indexing
 * - TIME-SERIES: Natural time-series data structure
 * 
 * @package App\Models
 */
final class AuditLogModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'audit_logs';

    /**
     * Primary Key
     * 
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Return Type (must point to AuditLog Entity)
     * 
     * @var string
     */
    protected $returnType = AuditLog::class;

    /**
     * Audit logs are IMMUTABLE - disable soft deletes
     * 
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * Use timestamps but with custom field (performed_at)
     * Disable default created_at/updated_at
     * 
     * @var bool
     */
    protected $useTimestamps = false;

    /**
     * Allowed Fields for audit log insertion
     * 
     * @var array<string>
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
        'user_agent',
        'performed_at'
    ];

    /**
     * Validation Rules for audit log data
     * 
     * @var array<string, string>
     */
    protected $validationRules = [
        'action_type' => 'required|string|max_length[50]',
        'entity_type' => 'required|string|max_length[50]',
        'entity_id' => 'required|integer|greater_than[0]',
        'admin_id' => 'permit_empty|integer|greater_than[0]',
        'old_values' => 'permit_empty|valid_json',
        'new_values' => 'permit_empty|valid_json',
        'changes_summary' => 'permit_empty|string|max_length[500]',
        'ip_address' => 'permit_empty|valid_ip',
        'user_agent' => 'permit_empty|string|max_length[500]',
        'performed_at' => 'permit_empty|valid_date'
    ];

    /**
     * Validation Messages
     * 
     * @var array<string, array<string, string>>
     */
    protected $validationMessages = [
        'action_type' => [
            'required' => 'Action type is required for audit logging.'
        ],
        'entity_type' => [
            'required' => 'Entity type is required for audit logging.'
        ],
        'entity_id' => [
            'required' => 'Entity ID is required for audit logging.'
        ],
        'old_values' => [
            'valid_json' => 'Old values must be valid JSON format.'
        ],
        'new_values' => [
            'valid_json' => 'New values must be valid JSON format.'
        ]
    ];

    /**
     * Whether to skip validation
     * 
     * @var bool
     */
    protected $skipValidation = false;

    /**
     * Default pagination limit
     * 
     * @var int
     */
    private const DEFAULT_PAGINATION_LIMIT = 50;

    /**
     * Maximum records to return in single query
     * 
     * @var int
     */
    private const MAX_QUERY_LIMIT = 1000;

    // ============================================
    // IMMUTABLE MODEL OVERRIDES
    // ============================================

    /**
     * Override update method - Audit logs are IMMUTABLE
     * 
     * @param int|string|array|null $id
     * @param array|null $data
     * @return bool
     * @throws RuntimeException Always throws exception for audit logs
     */
    public function update($id = null, $data = null): bool
    {
        throw new RuntimeException('Audit logs are immutable and cannot be updated.');
    }

    /**
     * Override delete method - Audit logs are IMMUTABLE
     * 
     * @param int|string|array|null $id
     * @param bool $purge
     * @return bool
     * @throws RuntimeException Always throws exception for audit logs
     */
    public function delete($id = null, bool $purge = false): bool
    {
        throw new RuntimeException('Audit logs are immutable and cannot be deleted.');
    }

    /**
     * Override save method - Only allow inserts, no updates
     * 
     * @param array|object $data
     * @return bool
     * @throws RuntimeException If trying to update existing record
     */
    public function save($data): bool
    {
        // If data has an ID, it's an update attempt - reject
        if (is_array($data) && isset($data['id']) && $data['id']) {
            throw new RuntimeException('Cannot update existing audit logs. Audit logs are immutable.');
        }
        
        if (is_object($data) && property_exists($data, 'id') && $data->id) {
            throw new RuntimeException('Cannot update existing audit logs. Audit logs are immutable.');
        }

        return parent::save($data);
    }

    /**
     * Insert audit log with automatic timestamp
     * 
     * @param AuditLog $auditLog
     * @return bool
     */
    public function insertAuditLog(AuditLog $auditLog): bool
    {
        // Ensure performed_at is set
        if ($auditLog->getPerformedAt() === null) {
            $auditLog->setPerformedAt(new \DateTimeImmutable());
        }

        $data = $auditLog->toArray();
        
        // Remove ID to ensure fresh insert
        unset($data['id']);
        
        return $this->insert($data) !== false;
    }

    // ============================================
    // SCOPED QUERY METHODS (Layer 2 Responsibility)
    // ============================================

    /**
     * Scope: Filter by admin ID
     * 
     * @param BaseBuilder $builder
     * @param int|null $adminId Admin ID (null for system actions)
     * @return BaseBuilder
     */
    protected function scopeByAdmin(BaseBuilder $builder, ?int $adminId): BaseBuilder
    {
        if ($adminId === null) {
            return $builder->where('admin_id IS NULL');
        }
        
        return $builder->where('admin_id', $adminId);
    }

    /**
     * Scope: Filter by action type
     * 
     * @param BaseBuilder $builder
     * @param string $actionType Action type (create, update, delete, etc.)
     * @return BaseBuilder
     */
    protected function scopeByActionType(BaseBuilder $builder, string $actionType): BaseBuilder
    {
        return $builder->where('action_type', $actionType);
    }

    /**
     * Scope: Filter by entity type
     * 
     * @param BaseBuilder $builder
     * @param string $entityType Entity type (Product, Category, etc.)
     * @return BaseBuilder
     */
    protected function scopeByEntityType(BaseBuilder $builder, string $entityType): BaseBuilder
    {
        return $builder->where('entity_type', $entityType);
    }

    /**
     * Scope: Filter by entity ID
     * 
     * @param BaseBuilder $builder
     * @param int $entityId Entity ID
     * @return BaseBuilder
     */
    protected function scopeByEntityId(BaseBuilder $builder, int $entityId): BaseBuilder
    {
        return $builder->where('entity_id', $entityId);
    }

    /**
     * Scope: Filter by date range
     * 
     * @param BaseBuilder $builder
     * @param string $startDate Start date (Y-m-d H:i:s)
     * @param string $endDate End date (Y-m-d H:i:s)
     * @return BaseBuilder
     */
    protected function scopeByDateRange(BaseBuilder $builder, string $startDate, string $endDate): BaseBuilder
    {
        return $builder->where('performed_at >=', $startDate)
                      ->where('performed_at <=', $endDate);
    }

    /**
     * Scope: Filter by recent activity (last N days)
     * 
     * @param BaseBuilder $builder
     * @param int $days Number of days
     * @return BaseBuilder
     */
    protected function scopeRecentDays(BaseBuilder $builder, int $days): BaseBuilder
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return $builder->where('performed_at >=', $threshold);
    }

    /**
     * Scope: Order by performed_at (most recent first)
     * 
     * @param BaseBuilder $builder
     * @param string $direction 'DESC' (newest first) or 'ASC' (oldest first)
     * @return BaseBuilder
     */
    protected function scopeOrderByPerformedAt(BaseBuilder $builder, string $direction = 'DESC'): BaseBuilder
    {
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        return $builder->orderBy('performed_at', $direction);
    }

    /**
     * Scope: Join with admins table to get admin info
     * 
     * @param BaseBuilder $builder
     * @return BaseBuilder
     */
    protected function scopeWithAdminInfo(BaseBuilder $builder): BaseBuilder
    {
        return $builder->select('audit_logs.*, admins.username as admin_username, admins.name as admin_name')
                      ->join('admins', 'admins.id = audit_logs.admin_id', 'left')
                      ->where('admins.deleted_at IS NULL');
    }

    /**
     * Scope: Filter by IP address
     * 
     * @param BaseBuilder $builder
     * @param string $ipAddress IP address
     * @return BaseBuilder
     */
    protected function scopeByIpAddress(BaseBuilder $builder, string $ipAddress): BaseBuilder
    {
        return $builder->where('ip_address', $ipAddress);
    }

    /**
     * Scope: Filter by changes summary containing text
     * 
     * @param BaseBuilder $builder
     * @param string $searchText Text to search in changes_summary
     * @return BaseBuilder
     */
    protected function scopeSearchInSummary(BaseBuilder $builder, string $searchText): BaseBuilder
    {
        return $builder->like('changes_summary', $searchText);
    }

    // ============================================
    // PUBLIC QUERY METHODS (For Repository use)
    // ============================================

    /**
     * Find audit logs for specific entity
     * 
     * @param string $entityType
     * @param int $entityId
     * @param int $limit
     * @return array<AuditLog>
     */
    public function findForEntity(string $entityType, int $entityId, int $limit = 100): array
    {
        $result = $this->withScopes([
                'byEntityType' => $entityType,
                'byEntityId' => $entityId,
                'orderByPerformedAt' => 'DESC'
            ])
            ->limit(min($limit, self::MAX_QUERY_LIMIT))
            ->findAll();

        return $result ?? [];
    }

    /**
     * Find audit logs by admin
     * 
     * @param int $adminId
     * @param int $limit
     * @return array<AuditLog>
     */
    public function findByAdmin(int $adminId, int $limit = 100): array
    {
        $result = $this->withScopes([
                'byAdmin' => $adminId,
                'orderByPerformedAt' => 'DESC'
            ])
            ->limit(min($limit, self::MAX_QUERY_LIMIT))
            ->findAll();

        return $result ?? [];
    }

    /**
     * Find audit logs by action type
     * 
     * @param string $actionType
     * @param int $limit
     * @return array<AuditLog>
     */
    public function findByActionType(string $actionType, int $limit = 100): array
    {
        $result = $this->withScopes([
                'byActionType' => $actionType,
                'orderByPerformedAt' => 'DESC'
            ])
            ->limit(min($limit, self::MAX_QUERY_LIMIT))
            ->findAll();

        return $result ?? [];
    }

    /**
     * Find recent audit logs (last N days)
     * 
     * @param int $days Number of days
     * @param int $limit
     * @return array<AuditLog>
     */
    public function findRecent(int $days = 7, int $limit = 200): array
    {
        $result = $this->withScopes([
                'recentDays' => $days,
                'orderByPerformedAt' => 'DESC'
            ])
            ->withAdminInfo()
            ->limit(min($limit, self::MAX_QUERY_LIMIT))
            ->findAll();

        return $result ?? [];
    }

    /**
     * Search audit logs with multiple criteria
     * 
     * @param array<string, mixed> $criteria Search criteria
     * @param int $limit
     * @return array<AuditLog>
     */
    public function search(array $criteria, int $limit = 100): array
    {
        $builder = $this->builder();
        
        // Apply criteria
        if (isset($criteria['admin_id'])) {
            $builder->where('admin_id', $criteria['admin_id']);
        }
        
        if (isset($criteria['action_type'])) {
            $builder->where('action_type', $criteria['action_type']);
        }
        
        if (isset($criteria['entity_type'])) {
            $builder->where('entity_type', $criteria['entity_type']);
        }
        
        if (isset($criteria['entity_id'])) {
            $builder->where('entity_id', $criteria['entity_id']);
        }
        
        if (isset($criteria['start_date']) && isset($criteria['end_date'])) {
            $builder->where('performed_at >=', $criteria['start_date'])
                   ->where('performed_at <=', $criteria['end_date']);
        }
        
        if (isset($criteria['search_text'])) {
            $builder->groupStart()
                   ->like('changes_summary', $criteria['search_text'])
                   ->orLike('entity_type', $criteria['search_text'])
                   ->orLike('action_type', $criteria['search_text'])
                   ->groupEnd();
        }
        
        if (isset($criteria['ip_address'])) {
            $builder->where('ip_address', $criteria['ip_address']);
        }
        
        $result = $builder->orderBy('performed_at', 'DESC')
                         ->limit(min($limit, self::MAX_QUERY_LIMIT))
                         ->get()
                         ->getResult($this->returnType);

        return $result ?? [];
    }

    /**
     * Get audit log statistics
     * 
     * @param int $days Number of days for statistics
     * @return array<string, mixed>
     */
    public function getStatistics(int $days = 30): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $builder = $this->builder();
        
        $stats = $builder->select([
                'COUNT(*) as total_logs',
                'COUNT(DISTINCT admin_id) as unique_admins',
                'COUNT(CASE WHEN admin_id IS NULL THEN 1 END) as system_actions',
                'COUNT(DISTINCT entity_type) as unique_entity_types',
                'COUNT(DISTINCT action_type) as unique_action_types',
                'COUNT(DISTINCT ip_address) as unique_ips',
                'MIN(performed_at) as earliest_log',
                'MAX(performed_at) as latest_log'
            ])
            ->where('performed_at >=', $threshold)
            ->get()
            ->getRowArray();

        // Get action type distribution
        $actionStats = $this->getActionTypeDistribution($days);
        
        // Get entity type distribution
        $entityStats = $this->getEntityTypeDistribution($days);
        
        // Get daily activity
        $dailyActivity = $this->getDailyActivity($days);

        return array_merge($stats ?: [], [
            'action_distribution' => $actionStats,
            'entity_distribution' => $entityStats,
            'daily_activity' => $dailyActivity,
            'period_days' => $days
        ]);
    }

    /**
     * Get action type distribution
     * 
     * @param int $days Number of days
     * @return array<string, int>
     */
    public function getActionTypeDistribution(int $days = 30): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $builder = $this->builder();
        
        $result = $builder->select([
                'action_type',
                'COUNT(*) as count'
            ])
            ->where('performed_at >=', $threshold)
            ->groupBy('action_type')
            ->orderBy('count', 'DESC')
            ->get()
            ->getResultArray();

        $distribution = [];
        foreach ($result as $row) {
            $distribution[$row['action_type']] = (int) $row['count'];
        }

        return $distribution;
    }

    /**
     * Get entity type distribution
     * 
     * @param int $days Number of days
     * @return array<string, int>
     */
    public function getEntityTypeDistribution(int $days = 30): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $builder = $this->builder();
        
        $result = $builder->select([
                'entity_type',
                'COUNT(*) as count'
            ])
            ->where('performed_at >=', $threshold)
            ->groupBy('entity_type')
            ->orderBy('count', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        $distribution = [];
        foreach ($result as $row) {
            $distribution[$row['entity_type']] = (int) $row['count'];
        }

        return $distribution;
    }

    /**
     * Get daily activity for charting
     * 
     * @param int $days Number of days
     * @return array<array{date: string, count: int}>
     */
    public function getDailyActivity(int $days = 30): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $builder = $this->builder();
        
        $result = $builder->select([
                'DATE(performed_at) as date',
                'COUNT(*) as count'
            ])
            ->where('performed_at >=', $threshold)
            ->groupBy('DATE(performed_at)')
            ->orderBy('date', 'ASC')
            ->get()
            ->getResultArray();

        $activity = [];
        foreach ($result as $row) {
            $activity[] = [
                'date' => $row['date'],
                'count' => (int) $row['count']
            ];
        }

        return $activity;
    }

    /**
     * Get admin activity statistics
     * 
     * @param int $days Number of days
     * @param int $limit Top N admins
     * @return array<array{admin_id: int, username: string, name: string, action_count: int}>
     */
    public function getAdminActivityStats(int $days = 30, int $limit = 10): array
    {
        $threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $builder = $this->builder();
        
        $result = $builder->select([
                'audit_logs.admin_id',
                'admins.username',
                'admins.name',
                'COUNT(audit_logs.id) as action_count'
            ])
            ->join('admins', 'admins.id = audit_logs.admin_id', 'left')
            ->where('audit_logs.performed_at >=', $threshold)
            ->where('admins.deleted_at IS NULL')
            ->where('audit_logs.admin_id IS NOT NULL')
            ->groupBy('audit_logs.admin_id')
            ->orderBy('action_count', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $stats = [];
        foreach ($result as $row) {
            $stats[] = [
                'admin_id' => (int) $row['admin_id'],
                'username' => $row['username'],
                'name' => $row['name'],
                'action_count' => (int) $row['action_count']
            ];
        }

        return $stats;
    }

    /**
     * Get entity audit trail with pagination
     * 
     * @param string $entityType
     * @param int $entityId
     * @param int $page
     * @param int $perPage
     * @return array{logs: array<AuditLog>, total: int, pages: int}
     */
    public function getEntityAuditTrail(string $entityType, int $entityId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min($perPage, 100);
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $total = $this->where('entity_type', $entityType)
                     ->where('entity_id', $entityId)
                     ->countAllResults();
        
        // Get paginated logs
        $logs = $this->withScopes([
                'byEntityType' => $entityType,
                'byEntityId' => $entityId,
                'orderByPerformedAt' => 'DESC'
            ])
            ->withAdminInfo()
            ->limit($perPage, $offset)
            ->findAll();

        return [
            'logs' => $logs ?? [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
            'current_page' => $page
        ];
    }

    /**
     * Cleanup old audit logs (archive/delete logs older than N days)
     * WARNING: This should only be run by system administrators
     * 
     * @param int $daysOlderThan Delete logs older than N days
     * @param bool $archiveFirst Whether to archive before deletion
     * @return int Number of records deleted
     */
    public function cleanupOldLogs(int $daysOlderThan = 365, bool $archiveFirst = true): int
    {
        // Safety check - cannot delete logs newer than 90 days
        if ($daysOlderThan < 90) {
            throw new InvalidArgumentException('Cannot delete audit logs newer than 90 days for compliance.');
        }

        $threshold = date('Y-m-d H:i:s', strtotime("-{$daysOlderThan} days"));
        
        // In a real system, we would archive to cold storage first
        if ($archiveFirst) {
            // Archive implementation would go here
            // This could export to CSV, move to archive table, etc.
        }
        
        // Delete old logs
        $builder = $this->builder();
        $builder->where('performed_at <', $threshold);
        
        $result = $builder->delete();
        
        return $result ? $builder->db()->affectedRows() : 0;
    }

    /**
     * Get query signature for caching (L2 Cache Strategy)
     * Note: Audit logs are rarely cached due to real-time nature
     * 
     * @param Query|BaseBuilder $query
     * @return string
     */
    public function getQuerySignature(Query|BaseBuilder $query): string
    {
        $sql = $query->getCompiledSelect();
        $params = $query->getBinds();
        
        return 'audit_log_query:' . md5($sql . serialize($params));
    }

    /**
     * Validate audit log data before insert
     * 
     * @param array<string, mixed> $data
     * @return array{valid: bool, errors: array<string>}
     */
    public function validateAuditLogData(array $data): array
    {
        // Ensure performed_at is set if not provided
        if (!isset($data['performed_at']) || empty($data['performed_at'])) {
            $data['performed_at'] = date('Y-m-d H:i:s');
        }

        // Ensure old_values and new_values are JSON strings if provided
        if (isset($data['old_values']) && is_array($data['old_values'])) {
            $data['old_values'] = json_encode($data['old_values'], JSON_PRETTY_PRINT);
        }
        
        if (isset($data['new_values']) && is_array($data['new_values'])) {
            $data['new_values'] = json_encode($data['new_values'], JSON_PRETTY_PRINT);
        }

        return $this->validateData($data);
    }
}