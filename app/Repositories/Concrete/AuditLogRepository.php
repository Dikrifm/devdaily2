<?php

namespace App\Repositories\Concrete;

use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Entities\AuditLog;
use App\Models\AuditLogModel;
use App\Contracts\CacheInterface;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use InvalidArgumentException;
use RuntimeException;

/**
 * AuditLog Repository
 * 
 * Layer 3: Data Orchestrator for AuditLog
 * Specialized implementation for audit trail and system logging
 * Note: Audit logs typically don't use soft deletes and have different caching requirements
 * 
 * @package App\Repositories\Concrete
 */
class AuditLogRepository extends BaseRepository implements AuditLogRepositoryInterface
{
    /**
     * Constructor with dependency injection
     * 
     * @param AuditLogModel $model
     * @param CacheInterface $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        AuditLogModel $model,
        CacheInterface $cache,
        ConnectionInterface $db
    ) {
        parent::__construct(
            $model,
            $cache,
            $db,
            AuditLog::class,
            'audit_logs'
        );
        
        // Audit logs have different characteristics
        $this->defaultCacheTtl = 900; // 15 minutes for audit logs
        $this->useAtomicCache = true;
    }

    /**
     * {@inheritDoc}
     */
    public function findByAdminId(int $adminId, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findByAdminId',
            'adminId' => $adminId,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($adminId, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findByAdminId')) {
                return $model->findByAdminId($adminId, $limit, $offset);
            }
            
            // Manual implementation
            $builder = $model->builder();
            $builder->where('admin_id', $adminId);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 600); // 10 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findByEntity',
            'entityType' => $entityType,
            'entityId' => $entityId,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($entityType, $entityId, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findByEntity')) {
                return $model->findByEntity($entityType, $entityId, $limit, $offset);
            }
            
            // Manual implementation
            $builder = $model->builder();
            $builder->where('entity_type', $entityType);
            $builder->where('entity_id', $entityId);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function findByActionType(string $actionType, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findByActionType',
            'actionType' => $actionType,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($actionType, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findByActionType')) {
                return $model->findByActionType($actionType, $limit, $offset);
            }
            
            // Manual implementation
            $builder = $model->builder();
            $builder->where('action_type', $actionType);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function findSystemActions(int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findSystemActions',
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findSystemActions')) {
                return $model->findSystemActions($limit, $offset);
            }
            
            // Manual implementation: logs without admin_id
            $builder = $model->builder();
            $builder->where('admin_id IS NULL', null, false);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function findByDateRange(string $startDate, string $endDate, int $limit = 100, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findByDateRange',
            'startDate' => $startDate,
            'endDate' => $endDate,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($startDate, $endDate, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findByDateRange')) {
                return $model->findByDateRange($startDate, $endDate, $limit, $offset);
            }
            
            // Manual implementation
            $builder = $model->builder();
            $builder->where('performed_at >=', $startDate);
            $builder->where('performed_at <=', $endDate);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 300); // 5 minutes TTL for date range queries
    }

    /**
     * {@inheritDoc}
     */
    public function findRecent(int $hours = 24, int $limit = 100, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findRecent',
            'hours' => $hours,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($hours, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findRecent')) {
                return $model->findRecent($hours, $limit, $offset);
            }
            
            // Manual implementation
            $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$hours} hours"));
            $builder = $model->builder();
            $builder->where('performed_at >=', $dateThreshold);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 300); // 5 minutes TTL for recent logs
    }

    /**
     * {@inheritDoc}
     */
    public function searchBySummary(string $searchTerm, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'searchBySummary',
            'searchTerm' => $searchTerm,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($searchTerm, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'searchBySummary')) {
                return $model->searchBySummary($searchTerm, $limit, $offset);
            }
            
            // Manual implementation
            $builder = $model->builder();
            $builder->like('changes_summary', $searchTerm);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function findByIpAddress(string $ipAddress, int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findByIpAddress',
            'ipAddress' => $ipAddress,
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($ipAddress, $limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findByIpAddress')) {
                return $model->findByIpAddress($ipAddress, $limit, $offset);
            }
            
            // Manual implementation
            $builder = $model->builder();
            $builder->where('ip_address', $ipAddress);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 1800); // 30 minutes TTL for IP-based queries
    }

    /**
     * {@inheritDoc}
     */
    public function paginateWithFilters(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'paginateWithFilters',
            'filters' => $filters,
            'perPage' => $perPage,
            'page' => $page
        ]);

        return $this->atomicCacheOperation($cacheKey, function () use ($filters, $perPage, $page) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'paginateWithFilters')) {
                $result = $model->paginateWithFilters($filters, $perPage, $page);
                $entities = $result['data'] ?? [];
                $total = $result['total'] ?? 0;
            } else {
                // Manual implementation with filters
                $builder = $model->builder();
                
                // Apply filters
                if (isset($filters['admin_id']) && $filters['admin_id'] !== '') {
                    $builder->where('admin_id', $filters['admin_id']);
                }
                
                if (isset($filters['entity_type']) && $filters['entity_type'] !== '') {
                    $builder->where('entity_type', $filters['entity_type']);
                }
                
                if (isset($filters['action_type']) && $filters['action_type'] !== '') {
                    $builder->where('action_type', $filters['action_type']);
                }
                
                if (isset($filters['start_date']) && $filters['start_date'] !== '') {
                    $builder->where('performed_at >=', $filters['start_date']);
                }
                
                if (isset($filters['end_date']) && $filters['end_date'] !== '') {
                    $builder->where('performed_at <=', $filters['end_date']);
                }
                
                if (isset($filters['search']) && $filters['search'] !== '') {
                    $builder->groupStart()
                        ->like('action_type', $filters['search'])
                        ->orLike('entity_type', $filters['search'])
                        ->orLike('changes_summary', $filters['search'])
                        ->orLike('ip_address', $filters['search'])
                    ->groupEnd();
                }
                
                $builder->orderBy('performed_at', 'DESC');
                
                // Get total count
                $total = $builder->countAllResults(false);
                
                // Apply pagination
                $offset = ($page - 1) * $perPage;
                $builder->limit($perPage, $offset);
                
                $entities = $builder->get()->getResult($this->entityClass);
                $entities = $entities ?: [];
            }
            
            $lastPage = ceil($total / $perPage);
            $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
            $to = min($page * $perPage, $total);
            
            return [
                'data' => $entities,
                'pagination' => [
                    'total' => (int) $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => (int) $lastPage,
                    'from' => (int) $from,
                    'to' => (int) $to
                ]
            ];
        }, null, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findWithAdminInfo(int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findWithAdminInfo',
            'limit' => $limit,
            'offset' => $offset
        ]);

        return $this->remember($cacheKey, function () use ($limit, $offset) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'findWithAdminInfo')) {
                return $model->findWithAdminInfo($limit, $offset);
            }
            
            // Manual implementation with join
            $builder = $model->builder();
            $builder->select('audit_logs.*, admins.username as admin_username, admins.name as admin_name');
            $builder->join('admins', 'admins.id = audit_logs.admin_id', 'left');
            $builder->orderBy('audit_logs.performed_at', 'DESC');
            $builder->limit($limit, $offset);
            
            return $builder->get()->getResultArray();
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityHistory(string $entityType, int $entityId): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'getEntityHistory',
            'entityType' => $entityType,
            'entityId' => $entityId
        ]);

        return $this->remember($cacheKey, function () use ($entityType, $entityId) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getEntityHistory')) {
                return $model->getEntityHistory($entityType, $entityId);
            }
            
            // Get all logs for this entity
            return $this->findByEntity($entityType, $entityId, 1000, 0);
        }, 1800); // 30 minutes TTL for entity history
    }

    /**
     * {@inheritDoc}
     */
    public function getStatisticsByDateRange(string $startDate, string $endDate): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'getStatisticsByDateRange',
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);

        return $this->remember($cacheKey, function () use ($startDate, $endDate) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getStatisticsByDateRange')) {
                return $model->getStatisticsByDateRange($startDate, $endDate);
            }
            
            // Manual implementation
            $builder = $model->builder();
            
            // Total logs in date range
            $builder->where('performed_at >=', $startDate);
            $builder->where('performed_at <=', $endDate);
            $totalLogs = $builder->countAllResults();
            
            // Actions by type
            $builder->select('action_type, COUNT(*) as count');
            $builder->where('performed_at >=', $startDate);
            $builder->where('performed_at <=', $endDate);
            $builder->groupBy('action_type');
            $actionResult = $builder->get()->getResultArray();
            
            $actionsByType = [];
            foreach ($actionResult as $row) {
                $actionsByType[$row['action_type']] = (int) $row['count'];
            }
            
            // Entities by type
            $builder->select('entity_type, COUNT(*) as count');
            $builder->where('performed_at >=', $startDate);
            $builder->where('performed_at <=', $endDate);
            $builder->groupBy('entity_type');
            $entityResult = $builder->get()->getResultArray();
            
            $entitiesByType = [];
            foreach ($entityResult as $row) {
                $entitiesByType[$row['entity_type']] = (int) $row['count'];
            }
            
            // Top admins
            $builder->select('admin_id, COUNT(*) as action_count');
            $builder->where('performed_at >=', $startDate);
            $builder->where('performed_at <=', $endDate);
            $builder->where('admin_id IS NOT NULL', null, false);
            $builder->groupBy('admin_id');
            $builder->orderBy('action_count', 'DESC');
            $builder->limit(10);
            $adminResult = $builder->get()->getResultArray();
            
            $topAdmins = array_map(function($row) {
                return [
                    'admin_id' => (int) $row['admin_id'],
                    'action_count' => (int) $row['action_count']
                ];
            }, $adminResult);
            
            return [
                'total_logs' => (int) $totalLogs,
                'actions_by_type' => $actionsByType,
                'entities_by_type' => $entitiesByType,
                'top_admins' => $topAdmins
            ];
        }, 1800); // 30 minutes TTL for statistics
    }

    /**
     * {@inheritDoc}
     */
    public function getMostActiveAdmins(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'getMostActiveAdmins',
            'limit' => $limit,
            'startDate' => $startDate,
            'endDate' => $endDate
        ]);

        return $this->remember($cacheKey, function () use ($limit, $startDate, $endDate) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getMostActiveAdmins')) {
                return $model->getMostActiveAdmins($limit, $startDate, $endDate);
            }
            
            // Manual implementation with join
            $builder = $model->builder();
            $builder->select('audit_logs.admin_id, admins.username as admin_name, admins.name as admin_full_name, COUNT(*) as action_count');
            $builder->join('admins', 'admins.id = audit_logs.admin_id', 'inner');
            
            if ($startDate) {
                $builder->where('audit_logs.performed_at >=', $startDate);
            }
            
            if ($endDate) {
                $builder->where('audit_logs.performed_at <=', $endDate);
            }
            
            $builder->where('audit_logs.admin_id IS NOT NULL', null, false);
            $builder->groupBy('audit_logs.admin_id, admins.username, admins.name');
            $builder->orderBy('action_count', 'DESC');
            $builder->limit($limit);
            
            $result = $builder->get()->getResultArray();
            
            return array_map(function($row) {
                return [
                    'admin_id' => (int) $row['admin_id'],
                    'admin_name' => $row['admin_name'],
                    'admin_full_name' => $row['admin_full_name'],
                    'action_count' => (int) $row['action_count']
                ];
            }, $result);
        }, 3600); // 1 hour TTL for admin activity
    }

    /**
     * {@inheritDoc}
     */
    public function getActivityTimeline(int $days = 30): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getActivityTimeline', 'days' => $days]);

        return $this->remember($cacheKey, function () use ($days) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getActivityTimeline')) {
                return $model->getActivityTimeline($days);
            }
            
            // Manual implementation
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $builder = $model->builder();
            
            // For MySQL
            $builder->select("DATE(performed_at) as date, HOUR(performed_at) as hour, COUNT(*) as count");
            $builder->where('performed_at >=', $startDate);
            $builder->groupBy("DATE(performed_at), HOUR(performed_at)");
            $builder->orderBy("date DESC, hour DESC");
            
            $result = $builder->get()->getResultArray();
            
            return array_map(function($row) {
                return [
                    'date' => $row['date'],
                    'hour' => (int) $row['hour'],
                    'count' => (int) $row['count']
                ];
            }, $result);
        }, 1800); // 30 minutes TTL for timeline
    }

    /**
     * {@inheritDoc}
     */
    public function hasEntityHistory(string $entityType, int $entityId): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'hasEntityHistory',
            'entityType' => $entityType,
            'entityId' => $entityId
        ]);

        return $this->remember($cacheKey, function () use ($entityType, $entityId) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'hasEntityHistory')) {
                return $model->hasEntityHistory($entityType, $entityId);
            }
            
            $builder = $model->builder();
            $builder->select('1');
            $builder->where('entity_type', $entityType);
            $builder->where('entity_id', $entityId);
            $builder->limit(1);
            
            return $builder->get()->getRow() !== null;
        }, 3600); // 1 hour TTL for existence check
    }

    /**
     * {@inheritDoc}
     */
    public function getLastAdminActivity(int $adminId): ?string
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getLastAdminActivity', 'adminId' => $adminId]);

        return $this->remember($cacheKey, function () use ($adminId) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getLastAdminActivity')) {
                return $model->getLastAdminActivity($adminId);
            }
            
            $builder = $model->builder();
            $builder->select('performed_at');
            $builder->where('admin_id', $adminId);
            $builder->orderBy('performed_at', 'DESC');
            $builder->limit(1);
            
            $result = $builder->get()->getRow();
            
            return $result ? $result->performed_at : null;
        }, 300); // 5 minutes TTL for last activity
    }

    /**
     * {@inheritDoc}
     */
    public function bulkInsert(array $auditLogs): int
    {
        if (empty($auditLogs)) {
            return 0;
        }

        return $this->transaction(function () use ($auditLogs) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            $inserted = 0;
            
            if (method_exists($model, 'bulkInsert')) {
                $inserted = $model->bulkInsert($auditLogs);
            } else {
                // Manual bulk insert
                foreach ($auditLogs as $log) {
                    if ($log instanceof AuditLog) {
                        $data = $log->toArray();
                        if ($model->insert($data)) {
                            $inserted++;
                        }
                    }
                }
            }
            
            // Invalidate relevant caches after bulk insert
            $this->queueCacheInvalidation($this->cachePrefix . '*');
            
            return $inserted;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function cleanOldLogs(string $olderThan): int
    {
        return $this->transaction(function () use ($olderThan) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            $cleaned = 0;
            if (method_exists($model, 'cleanOldLogs')) {
                $cleaned = $model->cleanOldLogs($olderThan);
            } else {
                // Manual cleanup
                $builder = $model->builder();
                $builder->where('performed_at <', $olderThan);
                $cleaned = $builder->delete() ? $builder->affectedRows() : 0;
            }
            
            if ($cleaned > 0) {
                $this->queueCacheInvalidation($this->cachePrefix . '*');
            }
            
            return $cleaned;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabaseSize(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getDatabaseSize']);

        return $this->remember($cacheKey, function () {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getDatabaseSize')) {
                return $model->getDatabaseSize();
            }
            
            // For MySQL - get table size information
            $db = $model->db;
            $tableName = $model->table;
            
            // This is MySQL specific
            $query = $db->query("
                SELECT 
                    table_name AS `table`,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS `size_mb`,
                    table_rows AS `rows`,
                    ROUND((data_length / table_rows), 2) AS `avg_row_size`,
                    ROUND((index_length / 1024 / 1024), 2) AS `index_size_mb`
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE() 
                AND table_name = ?
            ", [$tableName]);
            
            $result = $query->getRowArray();
            
            if ($result) {
                return [
                    'table_size' => $result['size_mb'] . ' MB',
                    'row_count' => (int) $result['rows'],
                    'avg_row_size' => $result['avg_row_size'] . ' bytes',
                    'index_size' => $result['index_size_mb'] . ' MB'
                ];
            }
            
            return [
                'table_size' => '0 MB',
                'row_count' => 0,
                'avg_row_size' => '0 bytes',
                'index_size' => '0 MB'
            ];
        }, 86400); // 24 hours TTL for database size
    }

    /**
     * {@inheritDoc}
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
    ): AuditLog {
        // Create audit log entity
        $auditLog = new AuditLog($actionType, $entityType, $entityId);
        
        if ($adminId !== null) {
            $auditLog->setAdminId($adminId);
        }
        
        if ($oldValues !== null) {
            $auditLog->setOldValues(json_encode($oldValues, JSON_PRETTY_PRINT));
        }
        
        if ($newValues !== null) {
            $auditLog->setNewValues(json_encode($newValues, JSON_PRETTY_PRINT));
        }
        
        // Generate changes summary
        if ($oldValues !== null && $newValues !== null) {
            $changes = [];
            foreach ($newValues as $key => $newValue) {
                $oldValue = $oldValues[$key] ?? null;
                if ($oldValue !== $newValue) {
                    $changes[] = "$key: '$oldValue' → '$newValue'";
                }
            }
            
            if (!empty($changes)) {
                $auditLog->setChangesSummary(implode(', ', $changes));
            }
        }
        
        if ($ipAddress !== null) {
            $auditLog->setIpAddress($ipAddress);
        }
        
        if ($userAgent !== null) {
            $auditLog->setUserAgent($userAgent);
        }
        
        // Set performed_at to current time
        $auditLog->setPerformedAt(new \DateTimeImmutable());
        
        // Save the log entry
        return $this->save($auditLog);
    }

    /**
     * {@inheritDoc}
     */
    public function getActionTypeDistribution(int $days = 30): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getActionTypeDistribution', 'days' => $days]);

        return $this->remember($cacheKey, function () use ($days) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getActionTypeDistribution')) {
                return $model->getActionTypeDistribution($days);
            }
            
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $builder = $model->builder();
            
            $builder->select('action_type, COUNT(*) as count');
            $builder->where('performed_at >=', $startDate);
            $builder->groupBy('action_type');
            $builder->orderBy('count', 'DESC');
            
            $result = $builder->get()->getResultArray();
            
            $distribution = [];
            foreach ($result as $row) {
                $distribution[$row['action_type']] = (int) $row['count'];
            }
            
            return $distribution;
        }, 3600); // 1 hour TTL for distribution data
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityTypeDistribution(int $days = 30): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getEntityTypeDistribution', 'days' => $days]);

        return $this->remember($cacheKey, function () use ($days) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getEntityTypeDistribution')) {
                return $model->getEntityTypeDistribution($days);
            }
            
            $startDate = date('Y-m-d H:i:s', strtotime("-{$days} days"));
            $builder = $model->builder();
            
            $builder->select('entity_type, COUNT(*) as count');
            $builder->where('performed_at >=', $startDate);
            $builder->groupBy('entity_type');
            $builder->orderBy('count', 'DESC');
            
            $result = $builder->get()->getResultArray();
            
            $distribution = [];
            foreach ($result as $row) {
                $distribution[$row['entity_type']] = (int) $row['count'];
            }
            
            return $distribution;
        }, 3600);
    }

    /**
     * {@inheritDoc}
     */
    public function getDailyActivity(int $days = 30): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getDailyActivity', 'days' => $days]);

        return $this->remember($cacheKey, function () use ($days) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getDailyActivity')) {
                return $model->getDailyActivity($days);
            }
            
            $startDate = date('Y-m-d', strtotime("-{$days} days"));
            $builder = $model->builder();
            
            // For MySQL
            $builder->select("DATE(performed_at) as date, COUNT(*) as count");
            $builder->where("DATE(performed_at) >= ", $startDate);
            $builder->groupBy("DATE(performed_at)");
            $builder->orderBy("date", "DESC");
            
            $result = $builder->get()->getResultArray();
            
            return array_map(function($row) {
                return [
                    'date' => $row['date'],
                    'count' => (int) $row['count']
                ];
            }, $result);
        }, 1800); // 30 minutes TTL for daily activity
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityAuditTrail(string $entityType, int $entityId, int $page = 1, int $perPage = 20): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'getEntityAuditTrail',
            'entityType' => $entityType,
            'entityId' => $entityId,
            'page' => $page,
            'perPage' => $perPage
        ]);

        return $this->atomicCacheOperation($cacheKey, function () use ($entityType, $entityId, $page, $perPage) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            if (method_exists($model, 'getEntityAuditTrail')) {
                $result = $model->getEntityAuditTrail($entityType, $entityId, $page, $perPage);
                $entities = $result['data'] ?? [];
                $total = $result['total'] ?? 0;
            } else {
                $builder = $model->builder();
                $builder->where('entity_type', $entityType);
                $builder->where('entity_id', $entityId);
                $builder->orderBy('performed_at', 'DESC');
                
                // Get total count
                $total = $builder->countAllResults(false);
                
                // Apply pagination
                $offset = ($page - 1) * $perPage;
                $builder->limit($perPage, $offset);
                
                $entities = $builder->get()->getResult($this->entityClass);
                $entities = $entities ?: [];
            }
            
            $lastPage = ceil($total / $perPage);
            $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
            $to = min($page * $perPage, $total);
            
            return [
                'data' => $entities,
                'pagination' => [
                    'total' => (int) $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => (int) $lastPage,
                    'from' => (int) $from,
                    'to' => (int) $to
                ]
            ];
        }, null, 1800); // 30 minutes TTL for audit trail
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupOldLogs(int $daysOlderThan = 365, bool $archiveFirst = true): int
    {
        return $this->transaction(function () use ($daysOlderThan, $archiveFirst) {
            /** @var AuditLogModel $model */
            $model = $this->getModel();
            
            $cleaned = 0;
            if (method_exists($model, 'cleanupOldLogs')) {
                $cleaned = $model->cleanupOldLogs($daysOlderThan, $archiveFirst);
            } else {
                $thresholdDate = date('Y-m-d H:i:s', strtotime("-{$daysOlderThan} days"));
                
                if ($archiveFirst) {
                    // Archive logic would go here if we had an archive table
                    // For now, we just delete
                    $builder = $model->builder();
                    $builder->where('performed_at <', $thresholdDate);
                    $cleaned = $builder->delete() ? $builder->affectedRows() : 0;
                } else {
                    // Direct deletion
                    $builder = $model->builder();
                    $builder->where('performed_at <', $thresholdDate);
                    $cleaned = $builder->delete() ? $builder->affectedRows() : 0;
                }
            }
            
            if ($cleaned > 0) {
                $this->queueCacheInvalidation($this->cachePrefix . '*');
            }
            
            return $cleaned;
        });
    }

    /**
     * Override delete method for audit logs
     * Audit logs typically should not be deleted (except for cleanup operations)
     * 
     * @param int|string $id
     * @return bool
     */
    public function delete(int|string $id): bool
    {
        throw new RuntimeException('Audit logs should not be individually deleted. Use cleanup methods instead.');
    }

    /**
     * Override forceDelete method for audit logs
     * 
     * @param int|string $id
     * @return bool
     */
    public function forceDelete(int|string $id): bool
    {
        throw new RuntimeException('Audit logs should not be individually force deleted. Use cleanup methods instead.');
    }

    /**
     * Override restore method - audit logs don't support soft delete
     * 
     * @param int|string $id
     * @return bool
     */
    public function restore(int|string $id): bool
    {
        throw new RuntimeException('Audit logs do not support restore operation.');
    }

    /**
     * Override bulkDelete for audit logs
     * 
     * @param array<int|string> $ids
     * @return int
     */
    public function bulkDelete(array $ids): int
    {
        throw new RuntimeException('Audit logs should not be bulk deleted. Use cleanup methods instead.');
    }

    /**
     * Override bulkRestore for audit logs
     * 
     * @param array<int|string> $ids
     * @return int
     */
    public function bulkRestore(array $ids): int
    {
        throw new RuntimeException('Audit logs do not support bulk restore operation.');
    }

    /**
     * Override to handle audit log specific save logic
     * Audit logs are typically created only, not updated
     * 
     * @param AuditLog $entity
     * @return AuditLog
     */
    public function save($entity): AuditLog
    {
        if (!$entity instanceof AuditLog) {
            throw new InvalidArgumentException(
                sprintf('Expected entity of type AuditLog, got %s', get_class($entity))
            );
        }

        // Audit logs are typically only created, not updated
        if ($entity->exists()) {
            throw new RuntimeException('Audit logs cannot be updated after creation');
        }

        return parent::save($entity);
    }

    /**
     * Override to handle audit log specific update logic
     * 
     * @param int|string $id
     * @param array<string, mixed> $data
     * @return AuditLog
     */
    public function update(int|string $id, array $data): AuditLog
    {
        throw new RuntimeException('Audit logs cannot be updated.');
    }
}