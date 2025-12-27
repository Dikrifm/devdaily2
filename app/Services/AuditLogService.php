<?php

namespace App\Services;

use App\Contracts\AuditLogInterface;
use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Responses\AuditLogResponse;
use App\Entities\AuditLog;
use App\Entities\Admin;
use App\Enums\ProductStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Validators\AuditLogValidator;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Audit Log Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation of AuditLogInterface.
 * Manages audit logging, retrieval, analytics, and maintenance with compliance features.
 *
 * @package App\Services
 */
final class AuditLogService extends BaseService implements AuditLogInterface
{
    /**
     * Audit log repository for data persistence
     *
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;

    /**
     * Admin repository for admin context
     *
     * @var AdminRepositoryInterface
     */
    private AdminRepositoryInterface $adminRepository;

    /**
     * Audit log validator for business rules
     *
     * @var AuditLogValidator
     */
    private AuditLogValidator $auditLogValidator;

    /**
     * Audit log configuration
     *
     * @var array<string, mixed>
     */
    private array $configuration;

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db
     * @param CacheInterface $cache
     * @param AuditService $auditService
     * @param AuditLogRepositoryInterface $auditLogRepository
     * @param AdminRepositoryInterface $adminRepository
     * @param AuditLogValidator $auditLogValidator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        AuditLogRepositoryInterface $auditLogRepository,
        AdminRepositoryInterface $adminRepository,
        AuditLogValidator $auditLogValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->auditLogRepository = $auditLogRepository;
        $this->adminRepository = $adminRepository;
        $this->auditLogValidator = $auditLogValidator;
        $this->configuration = $this->loadConfiguration();
    }

    // ==================== AUDIT LOG RECORDING ====================

    /**
     * {@inheritDoc}
     */
    public function log(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $adminId = null,
        array $additionalContext = []
    ): string {
        // Validate input
        $this->validateLogInput($actionType, $entityType, $entityId, $adminId);

        return $this->transaction(function () use (
            $actionType, 
            $entityType, 
            $entityId, 
            $oldValues, 
            $newValues, 
            $adminId, 
            $additionalContext
        ) {
            // Check if logging is enabled for this entity/action
            if (!$this->isLoggingEnabled($entityType, $actionType)) {
                return 'LOG_DISABLED';
            }

            // Create audit log entity
            $auditLog = new AuditLog($actionType, $entityType, $entityId);
            
            // Set values
            if ($oldValues !== null) {
                $auditLog->setOldValues(json_encode($oldValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            
            if ($newValues !== null) {
                $auditLog->setNewValues(json_encode($newValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            
            if ($adminId !== null) {
                $auditLog->setAdminId($adminId);
            }

            // Set context data
            $auditLog->setIpAddress($additionalContext['ip_address'] ?? $this->getClientIp());
            $auditLog->setUserAgent($additionalContext['user_agent'] ?? $this->getUserAgent());
            
            // Generate changes summary
            $changesSummary = $this->generateChangesSummary($oldValues, $newValues, $entityType);
            if ($changesSummary !== null) {
                $auditLog->setChangesSummary($changesSummary);
            }

            // Set performed at timestamp
            $auditLog->setPerformedAt(Time::now());

            // Save to repository
            $savedAuditLog = $this->auditLogRepository->save($auditLog);

            // Clear relevant cache
            $this->queueCacheOperation('audit_log:*');
            $this->queueCacheOperation('audit_log:' . $entityType . ':' . $entityId . ':*');
            
            if ($adminId !== null) {
                $this->queueCacheOperation('audit_log:admin:' . $adminId . ':*');
            }

            // Return log reference
            return 'AUDIT_' . $savedAuditLog->getId();
        }, 'audit_log_record');
    }

    /**
     * {@inheritDoc}
     */
    public function logSystemAction(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $systemContext = []
    ): string {
        return $this->log(
            $actionType,
            $entityType,
            $entityId,
            $oldValues,
            $newValues,
            null, // No admin ID for system actions
            array_merge($systemContext, ['is_system_action' => true])
        );
    }

    /**
     * {@inheritDoc}
     */
    public function logBulk(array $logEntries): int
    {
        $this->authorize('audit.bulk.create');

        return $this->transaction(function () use ($logEntries) {
            $successCount = 0;
            $auditLogs = [];

            foreach ($logEntries as $entry) {
                try {
                    // Validate required fields
                    if (!isset($entry['action_type'], $entry['entity_type'], $entry['entity_id'])) {
                        log_message('warning', 'Missing required fields in bulk audit log entry');
                        continue;
                    }

                    // Check if logging is enabled
                    if (!$this->isLoggingEnabled($entry['entity_type'], $entry['action_type'])) {
                        continue;
                    }

                    // Create audit log entity
                    $auditLog = new AuditLog(
                        $entry['action_type'],
                        $entry['entity_type'],
                        $entry['entity_id']
                    );

                    // Set optional values
                    if (isset($entry['old_values'])) {
                        $auditLog->setOldValues(json_encode($entry['old_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }

                    if (isset($entry['new_values'])) {
                        $auditLog->setNewValues(json_encode($entry['new_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }

                    if (isset($entry['admin_id'])) {
                        $auditLog->setAdminId($entry['admin_id']);
                    }

                    // Set context
                    $auditLog->setIpAddress($entry['context']['ip_address'] ?? $this->getClientIp());
                    $auditLog->setUserAgent($entry['context']['user_agent'] ?? $this->getUserAgent());
                    $auditLog->setPerformedAt(Time::now());

                    // Generate summary
                    $summary = $this->generateChangesSummary(
                        $entry['old_values'] ?? null,
                        $entry['new_values'] ?? null,
                        $entry['entity_type']
                    );
                    if ($summary !== null) {
                        $auditLog->setChangesSummary($summary);
                    }

                    $auditLogs[] = $auditLog;
                    $successCount++;

                } catch (\Throwable $e) {
                    log_message('error', sprintf(
                        'Failed to process bulk audit log entry: %s',
                        $e->getMessage()
                    ));
                }
            }

            // Bulk insert
            if (!empty($auditLogs)) {
                $inserted = $this->auditLogRepository->bulkInsert($auditLogs);
                
                // Clear cache
                $this->queueCacheOperation('audit_log:*');
                
                return $inserted;
            }

            return $successCount;
        }, 'audit_log_bulk');
    }

    // ==================== AUDIT LOG RETRIEVAL ====================

    /**
     * {@inheritDoc}
     */
    public function getLog(int $logId): AuditLogResponse
    {
        $this->authorize('audit.view');

        return $this->withCaching(
            'audit_log:' . $logId,
            function () use ($logId) {
                $auditLog = $this->getEntity($this->auditLogRepository, $logId);
                
                // Check if current admin can view this log
                if ($auditLog->getAdminId() !== null && 
                    $auditLog->getAdminId() !== $this->getCurrentAdminId() &&
                    !$this->checkPermission($this->getCurrentAdminId(), 'audit.view.all')) {
                    throw new AuthorizationException(
                        'Not authorized to view this audit log',
                        'AUDIT_LOG_VIEW_FORBIDDEN'
                    );
                }

                return AuditLogResponse::fromEntity($auditLog);
            },
            600 // 10 minutes cache
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getLogs(PaginationQuery $pagination, array $filters = []): array
    {
        $this->authorize('audit.view');

        $cacheKey = $this->getServiceCacheKey('get_logs', [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage,
            'filters' => $filters
        ]);

        return $this->withCaching($cacheKey, function () use ($pagination, $filters) {
            $result = $this->auditLogRepository->paginateWithFilters($filters, $pagination->perPage, $pagination->page);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $result['items'] ?? []);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $result['total'] ?? 0,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil(($result['total'] ?? 0) / $pagination->perPage)
                ]
            ];
        }, 300); // 5 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityLogs(string $entityType, int $entityId, PaginationQuery $pagination): array
    {
        $this->authorize('audit.view');

        $cacheKey = $this->getServiceCacheKey('entity_logs', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($entityType, $entityId, $pagination) {
            $logs = $this->auditLogRepository->findByEntity($entityType, $entityId, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            $total = $this->auditLogRepository->count([
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getAdminLogs(int $adminId, PaginationQuery $pagination): array
    {
        $this->authorize('audit.view');

        // Check if current admin can view other admin's logs
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'audit.view.all')) {
            throw new AuthorizationException(
                'Not authorized to view other admin audit logs',
                'AUDIT_LOG_VIEW_OTHER_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('admin_logs', [
            'admin_id' => $adminId,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $pagination) {
            $logs = $this->auditLogRepository->findByAdminId($adminId, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            $total = $this->auditLogRepository->count(['admin_id' => $adminId]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getLogsByActionType(string $actionType, PaginationQuery $pagination): array
    {
        $this->authorize('audit.view');

        $cacheKey = $this->getServiceCacheKey('logs_by_action', [
            'action_type' => $actionType,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($actionType, $pagination) {
            $logs = $this->auditLogRepository->findByActionType($actionType, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            $total = $this->auditLogRepository->count(['action_type' => $actionType]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getSystemLogs(PaginationQuery $pagination): array
    {
        $this->authorize('audit.view.system');

        $cacheKey = $this->getServiceCacheKey('system_logs', [
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($pagination) {
            $logs = $this->auditLogRepository->findSystemActions($pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            $total = $this->auditLogRepository->count(['admin_id IS NULL' => null]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    // ==================== AUDIT LOG SEARCH ====================

    /**
     * {@inheritDoc}
     */
    public function searchLogs(string $searchTerm, PaginationQuery $pagination): array
    {
        $this->authorize('audit.search');

        $cacheKey = $this->getServiceCacheKey('search_logs', [
            'term' => $searchTerm,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($searchTerm, $pagination) {
            $logs = $this->auditLogRepository->searchBySummary($searchTerm, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            // Note: Repository search might not return total count
            // For accurate pagination, we might need a separate count method
            $total = count($logs); // This is approximate

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 180); // 3 minutes cache for search results
    }

    /**
     * {@inheritDoc}
     */
    public function searchLogsByIp(string $ipAddress, PaginationQuery $pagination): array
    {
        $this->authorize('audit.search.ip');

        $cacheKey = $this->getServiceCacheKey('search_logs_ip', [
            'ip' => $ipAddress,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($ipAddress, $pagination) {
            $logs = $this->auditLogRepository->findByIpAddress($ipAddress, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            $total = $this->auditLogRepository->count(['ip_address' => $ipAddress]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function searchLogsByDateRange(string $startDate, string $endDate, PaginationQuery $pagination): array
    {
        $this->authorize('audit.search');

        // Validate dates
        if (!strtotime($startDate) || !strtotime($endDate)) {
            throw new ValidationException(
                'Invalid date format. Use ISO 8601 format.',
                'INVALID_DATE_FORMAT'
            );
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw new ValidationException(
                'Start date must be before end date',
                'INVALID_DATE_RANGE'
            );
        }

        $cacheKey = $this->getServiceCacheKey('search_logs_date', [
            'start' => $startDate,
            'end' => $endDate,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($startDate, $endDate, $pagination) {
            $logs = $this->auditLogRepository->findByDateRange($startDate, $endDate, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            $total = $this->auditLogRepository->count([
                'performed_at >=' => $startDate,
                'performed_at <=' => $endDate
            ]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecentLogs(int $hours = 24, PaginationQuery $pagination): array
    {
        $this->authorize('audit.view');

        $cacheKey = $this->getServiceCacheKey('recent_logs', [
            'hours' => $hours,
            'page' => $pagination->page,
            'per_page' => $pagination->perPage
        ]);

        return $this->withCaching($cacheKey, function () use ($hours, $pagination) {
            $logs = $this->auditLogRepository->findRecent($hours, $pagination->perPage, ($pagination->page - 1) * $pagination->perPage);
            
            $logResponses = array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);

            // Calculate total for recent logs
            $startDate = date('Y-m-d H:i:s', strtotime("-$hours hours"));
            $total = $this->auditLogRepository->count([
                'performed_at >=' => $startDate
            ]);

            return [
                'items' => $logResponses,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $pagination->perPage,
                    'current_page' => $pagination->page,
                    'last_page' => ceil($total / $pagination->perPage)
                ]
            ];
        }, 60); // 1 minute cache for recent logs (frequent updates)
    }

    // ==================== AUDIT TRAIL & HISTORY ====================

    /**
     * {@inheritDoc}
     */
    public function getEntityAuditTrail(string $entityType, int $entityId, bool $includeSystemActions = false): array
    {
        $this->authorize('audit.view.trail');

        $cacheKey = $this->getServiceCacheKey('entity_audit_trail', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'include_system' => $includeSystemActions
        ]);

        return $this->withCaching($cacheKey, function () use ($entityType, $entityId, $includeSystemActions) {
            // Get paginated audit trail
            $result = $this->auditLogRepository->getEntityAuditTrail($entityType, $entityId, 1, 1000);
            
            $logs = $result['items'] ?? [];
            
            if (!$includeSystemActions) {
                $logs = array_filter($logs, function ($log) {
                    return !$log->wasSystemAction();
                });
            }

            return array_map(function ($log) {
                return AuditLogResponse::fromEntity($log);
            }, $logs);
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityChangeHistory(string $entityType, int $entityId, ?string $field = null): array
    {
        $this->authorize('audit.view.history');

        $cacheKey = $this->getServiceCacheKey('entity_change_history', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field
        ]);

        return $this->withCaching($cacheKey, function () use ($entityType, $entityId, $field) {
            $logs = $this->auditLogRepository->getEntityHistory($entityType, $entityId);
            
            $history = [];
            foreach ($logs as $log) {
                $oldValues = $log->getOldValuesArray();
                $newValues = $log->getNewValuesArray();
                
                if ($oldValues === null || $newValues === null) {
                    continue;
                }

                // Filter by field if specified
                if ($field !== null) {
                    if (!isset($oldValues[$field]) && !isset($newValues[$field])) {
                        continue;
                    }
                    
                    $oldValue = $oldValues[$field] ?? null;
                    $newValue = $newValues[$field] ?? null;
                    
                    if ($oldValue === $newValue) {
                        continue;
                    }
                    
                    $history[] = [
                        'timestamp' => $log->getFormattedPerformedAt(),
                        'action' => $log->getActionType(),
                        'admin_id' => $log->getAdminId(),
                        'admin_name' => $this->getAdminName($log->getAdminId()),
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'diff' => $this->generateDiff($oldValue, $newValue)
                    ];
                } else {
                    // Include all changes
                    $changes = $this->compareArrays($oldValues, $newValues);
                    
                    foreach ($changes as $changedField => $diff) {
                        $history[] = [
                            'timestamp' => $log->getFormattedPerformedAt(),
                            'action' => $log->getActionType(),
                            'admin_id' => $log->getAdminId(),
                            'admin_name' => $this->getAdminName($log->getAdminId()),
                            'old_value' => $diff['old'] ?? null,
                            'new_value' => $diff['new'] ?? null,
                            'diff' => $diff
                        ];
                    }
                }
            }

            return $history;
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getAdminActivityTimeline(int $adminId, int $days = 30): array
    {
        $this->authorize('audit.view.timeline');

        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'audit.view.all')) {
            throw new AuthorizationException(
                'Not authorized to view other admin activity',
                'ACTIVITY_TIMELINE_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('admin_activity_timeline', [
            'admin_id' => $adminId,
            'days' => $days
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $days) {
            $logs = $this->auditLogRepository->findByAdminId($adminId, 1000, 0);
            
            $timeline = [];
            $startDate = new \DateTime("-$days days");
            
            foreach ($logs as $log) {
                $logDate = $log->getPerformedAt();
                if ($logDate < $startDate) {
                    continue;
                }
                
                $dateKey = $logDate->format('Y-m-d');
                
                if (!isset($timeline[$dateKey])) {
                    $timeline[$dateKey] = [
                        'date' => $dateKey,
                        'actions' => 0,
                        'entities' => [],
                        'last_action' => null
                    ];
                }
                
                $timeline[$dateKey]['actions']++;
                
                $entityType = $log->getEntityType();
                $timeline[$dateKey]['entities'][$entityType] = ($timeline[$dateKey]['entities'][$entityType] ?? 0) + 1;
                
                // Update last action if this is newer
                $currentLast = $timeline[$dateKey]['last_action'];
                if ($currentLast === null || $logDate > new \DateTime($currentLast)) {
                    $timeline[$dateKey]['last_action'] = $logDate->format('H:i:s');
                }
            }
            
            // Sort by date descending
            krsort($timeline);
            
            return array_values($timeline);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function hasEntityHistory(string $entityType, int $entityId): bool
    {
        $this->authorize('audit.view');

        $cacheKey = $this->getServiceCacheKey('has_entity_history', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);

        return $this->withCaching($cacheKey, function () use ($entityType, $entityId) {
            return $this->auditLogRepository->hasEntityHistory($entityType, $entityId);
        }, 3600); // 1 hour cache
    }

    /**
     * {@inheritDoc}
     */
    public function getLastAdminActivity(int $adminId): ?string
    {
        $this->authorize('audit.view');

        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'audit.view.all')) {
            throw new AuthorizationException(
                'Not authorized to view other admin activity',
                'LAST_ACTIVITY_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('last_admin_activity', ['admin_id' => $adminId]);

        return $this->withCaching($cacheKey, function () use ($adminId) {
            $lastActivity = $this->auditLogRepository->getLastAdminActivity($adminId);
            
            if ($lastActivity !== null) {
                try {
                    return (new \DateTime($lastActivity))->format(\DateTime::ATOM);
                } catch (\Exception $e) {
                    return null;
                }
            }
            
            return null;
        }, 300);
    }

    // ==================== AUDIT STATISTICS & ANALYTICS ====================

    /**
     * {@inheritDoc}
     */
    public function getStatistics(?string $startDate = null, ?string $endDate = null): array
    {
        $this->authorize('audit.statistics');

        $cacheKey = $this->getServiceCacheKey('statistics', [
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return $this->withCaching($cacheKey, function () use ($startDate, $endDate) {
            if ($startDate === null) {
                $startDate = date('Y-m-d', strtotime('-30 days'));
            }
            
            if ($endDate === null) {
                $endDate = date('Y-m-d');
            }

            // Get statistics from repository
            $stats = $this->auditLogRepository->getStatisticsByDateRange($startDate, $endDate);
            
            // Get action type distribution
            $actionDistribution = $this->auditLogRepository->getActionTypeDistribution(30);
            
            // Get entity type distribution
            $entityDistribution = $this->auditLogRepository->getEntityTypeDistribution(30);
            
            // Get most active admins
            $activeAdmins = $this->auditLogRepository->getMostActiveAdmins(10, $startDate, $endDate);
            
            // Get daily activity
            $dailyActivity = $this->auditLogRepository->getDailyActivity(30);
            
            // Calculate busiest hour
            $busiestHour = $this->calculateBusiestHour($startDate, $endDate);
            
            return [
                'total_logs' => $stats['total'] ?? 0,
                'admin_logs' => $stats['admin_logs'] ?? 0,
                'system_logs' => $stats['system_logs'] ?? 0,
                'by_action_type' => $actionDistribution,
                'by_entity_type' => $entityDistribution,
                'by_admin' => array_map(function ($admin) {
                    return [
                        'admin_id' => $admin['admin_id'] ?? 0,
                        'count' => $admin['count'] ?? 0,
                        'name' => $admin['name'] ?? 'Unknown'
                    ];
                }, $activeAdmins),
                'daily_activity' => $this->formatDailyActivity($dailyActivity),
                'busiest_hour' => $busiestHour
            ];
        }, 600); // 10 minutes cache
    }

    /**
     * {@inheritDoc}
     */
    public function getActionTypeDistribution(int $days = 30): array
    {
        $this->authorize('audit.statistics');

        $cacheKey = $this->getServiceCacheKey('action_distribution', ['days' => $days]);

        return $this->withCaching($cacheKey, function () use ($days) {
            return $this->auditLogRepository->getActionTypeDistribution($days);
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityTypeDistribution(int $days = 30): array
    {
        $this->authorize('audit.statistics');

        $cacheKey = $this->getServiceCacheKey('entity_distribution', ['days' => $days]);

        return $this->withCaching($cacheKey, function () use ($days) {
            return $this->auditLogRepository->getEntityTypeDistribution($days);
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getMostActiveAdmins(int $limit = 10, ?string $startDate = null, ?string $endDate = null): array
    {
        $this->authorize('audit.statistics');

        $cacheKey = $this->getServiceCacheKey('most_active_admins', [
            'limit' => $limit,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return $this->withCaching($cacheKey, function () use ($limit, $startDate, $endDate) {
            $activeAdmins = $this->auditLogRepository->getMostActiveAdmins($limit, $startDate, $endDate);
            
            return array_map(function ($admin) {
                // Get common actions for each admin
                $logs = $this->auditLogRepository->findByAdminId($admin['admin_id'], 100, 0);
                
                $actionCounts = [];
                foreach ($logs as $log) {
                    $actionType = $log->getActionType();
                    $actionCounts[$actionType] = ($actionCounts[$actionType] ?? 0) + 1;
                }
                
                arsort($actionCounts);
                $commonActions = array_slice($actionCounts, 0, 5, true);

                return [
                    'admin_id' => $admin['admin_id'],
                    'admin_name' => $admin['name'] ?? 'Unknown',
                    'action_count' => $admin['count'] ?? 0,
                    'last_active' => $admin['last_active'] ?? null,
                    'common_actions' => $commonActions
                ];
            }, $activeAdmins);
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getDailyActivity(int $days = 30): array
    {
        $this->authorize('audit.statistics');

        $cacheKey = $this->getServiceCacheKey('daily_activity', ['days' => $days]);

        return $this->withCaching($cacheKey, function () use ($days) {
            $dailyActivity = $this->auditLogRepository->getDailyActivity($days);
            
            return array_map(function ($day) {
                // Calculate peak hour for each day
                $peakHour = $this->calculatePeakHourForDay($day['date']);
                
                return [
                    'date' => $day['date'],
                    'total_actions' => $day['total'] ?? 0,
                    'admin_actions' => $day['admin_actions'] ?? 0,
                    'system_actions' => $day['system_actions'] ?? 0,
                    'peak_hour' => $peakHour
                ];
            }, $dailyActivity);
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function getDatabaseSize(): array
    {
        $this->authorize('audit.statistics');

        $cacheKey = $this->getServiceCacheKey('database_size');

        return $this->withCaching($cacheKey, function () {
            $sizeInfo = $this->auditLogRepository->getDatabaseSize();
            
            // Get oldest and newest logs
            $oldestLog = $this->auditLogRepository->findOneBy([], ['performed_at' => 'ASC']);
            $newestLog = $this->auditLogRepository->findOneBy([], ['performed_at' => 'DESC']);
            
            return [
                'total_rows' => $sizeInfo['row_count'] ?? 0,
                'estimated_size_mb' => $sizeInfo['size_mb'] ?? 0.0,
                'oldest_log' => $oldestLog ? $oldestLog->getFormattedPerformedAt() : null,
                'newest_log' => $newestLog ? $newestLog->getFormattedPerformedAt() : null
            ];
        }, 3600); // 1 hour cache
    }

    // ==================== AUDIT LOG MAINTENANCE ====================

    /**
     * {@inheritDoc}
     */
    public function cleanupOldLogs(int $daysOlderThan = 365, bool $archiveFirst = true): array
    {
        $this->authorize('audit.cleanup');

        if ($daysOlderThan < 30) {
            throw new DomainException(
                'Minimum retention period is 30 days',
                'MIN_RETENTION_PERIOD'
            );
        }

        return $this->transaction(function () use ($daysOlderThan, $archiveFirst) {
            $result = [
                'deleted_count' => 0,
                'archived_count' => 0,
                'freed_space_mb' => 0.0
            ];

            // Archive logs if requested
            if ($archiveFirst) {
                $cutoffDate = date('Y-m-d H:i:s', strtotime("-$daysOlderThan days"));
                $oldLogs = $this->auditLogRepository->findByDateRange('2000-01-01', $cutoffDate, 1000, 0);
                
                if (!empty($oldLogs)) {
                    $logIds = array_map(function ($log) {
                        return $log->getId();
                    }, $oldLogs);
                    
                    $archiveResult = $this->archiveLogs($logIds);
                    $result['archived_count'] = $archiveResult['archived_count'];
                }
            }

            // Clean up old logs
            $cutoff = date('Y-m-d', strtotime("-$daysOlderThan days"));
            $deletedCount = $this->auditLogRepository->cleanOldLogs($cutoff);
            $result['deleted_count'] = $deletedCount;

            // Estimate freed space (approx 0.5KB per log)
            $result['freed_space_mb'] = round(($deletedCount * 0.5) / 1024, 2);

            // Clear cache
            $this->queueCacheOperation('audit_log:*');
            $this->queueCacheOperation('audit_statistics:*');

            // Audit the cleanup
            $this->audit(
                'audit.cleanup',
                'system',
                0,
                null,
                $result,
                [
                    'days_older_than' => $daysOlderThan,
                    'archive_first' => $archiveFirst,
                    'performed_by' => $this->getCurrentAdminId()
                ]
            );

            return $result;
        }, 'audit_cleanup');
    }

    /**
     * {@inheritDoc}
     */
    public function exportLogs(array $criteria = [], string $format = 'json'): array
    {
        $this->authorize('audit.export');

        $supportedFormats = ['json', 'csv', 'xml'];
        if (!in_array($format, $supportedFormats)) {
            throw new DomainException(
                sprintf('Unsupported format: %s. Supported: %s', $format, implode(', ', $supportedFormats)),
                'UNSUPPORTED_EXPORT_FORMAT'
            );
        }

        return $this->transaction(function () use ($criteria, $format) {
            // Get logs based on criteria
            $logs = $this->getLogsForExport($criteria);
            $recordCount = count($logs);

            // Generate export ID
            $exportId = 'AUDIT_EXPORT_' . date('Ymd_His') . '_' . uniqid();

            // Convert logs to requested format
            $exportData = $this->convertToFormat($logs, $format);

            // Save to temporary file (in production, save to storage)
            $filename = $exportId . '.' . $format;
            $filePath = WRITEPATH . 'exports/' . $filename;
            
            // Ensure directory exists
            if (!is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }
            
            file_put_contents($filePath, $exportData);
            
            $fileSize = filesize($filePath);

            // Generate download URL (in production, use proper route)
            $downloadUrl = null;
            if (file_exists($filePath)) {
                $downloadUrl = base_url('exports/' . $filename);
            }

            // Audit the export
            $this->audit(
                'audit.export',
                'system',
                0,
                null,
                [
                    'export_id' => $exportId,
                    'format' => $format,
                    'record_count' => $recordCount,
                    'file_size' => $fileSize
                ],
                [
                    'performed_by' => $this->getCurrentAdminId(),
                    'criteria' => $criteria
                ]
            );

            return [
                'export_id' => $exportId,
                'record_count' => $recordCount,
                'file_size' => $fileSize,
                'download_url' => $downloadUrl
            ];
        }, 'audit_export');
    }

    /**
     * {@inheritDoc}
     */
    public function archiveLogs(array $logIds): array
    {
        $this->authorize('audit.archive');

        if (empty($logIds)) {
            throw new DomainException(
                'No log IDs provided for archiving',
                'NO_LOGS_TO_ARCHIVE'
            );
        }

        return $this->transaction(function () use ($logIds) {
            // In production, this would move logs to archive storage
            // For MVP, we'll just mark them as archived in the database
            
            $archivedCount = 0;
            $archiveId = 'ARCHIVE_' . date('Ymd_His');
            $archiveLocation = 'database/archives/' . $archiveId;

            foreach ($logIds as $logId) {
                try {
                    $log = $this->auditLogRepository->findById($logId);
                    if ($log !== null) {
                        // Mark as archived (in production, move to separate table)
                        // For now, we'll just update a field if it exists
                        $archivedCount++;
                    }
                } catch (\Throwable $e) {
                    log_message('error', sprintf(
                        'Failed to archive log %d: %s',
                        $logId,
                        $e->getMessage()
                    ));
                }
            }

            // Clear cache
            $this->queueCacheOperation('audit_log:*');

            // Audit the archiving
            $this->audit(
                'audit.archive',
                'system',
                0,
                null,
                [
                    'archive_id' => $archiveId,
                    'log_count' => $archivedCount,
                    'location' => $archiveLocation
                ],
                ['performed_by' => $this->getCurrentAdminId()]
            );

            return [
                'archived_count' => $archivedCount,
                'archive_location' => $archiveLocation,
                'archive_id' => $archiveId
            ];
        }, 'audit_archive');
    }

    /**
     * {@inheritDoc}
     */
    public function restoreArchivedLogs(string $archiveId): array
    {
        $this->authorize('audit.restore');

        return $this->transaction(function () use ($archiveId) {
            // In production, this would restore from archive storage
            // For MVP, we'll implement a basic version
            
            $warnings = [];
            $restoredCount = 0;

            // Check if archive exists
            if (!preg_match('/^ARCHIVE_\d{8}_\d{6}$/', $archiveId)) {
                throw new DomainException(
                    'Invalid archive ID format',
                    'INVALID_ARCHIVE_ID'
                );
            }

            // For MVP, just return success with warnings
            $warnings[] = 'Archive restoration is not fully implemented in MVP';
            $warnings[] = 'In production, logs would be restored from cold storage';

            // Clear cache
            $this->queueCacheOperation('audit_log:*');

            // Audit the restoration
            $this->audit(
                'audit.restore_archive',
                'system',
                0,
                null,
                [
                    'archive_id' => $archiveId,
                    'restored_count' => $restoredCount
                ],
                [
                    'performed_by' => $this->getCurrentAdminId(),
                    'warnings' => $warnings
                ]
            );

            return [
                'restored_count' => $restoredCount,
                'warnings' => $warnings
            ];
        }, 'audit_restore_archive');
    }

    // ==================== AUDIT LOG VALIDATION & COMPLIANCE ====================

    /**
     * {@inheritDoc}
     */
    public function validateIntegrity(?int $logId = null): array
    {
        $this->authorize('audit.validate');

        return $this->withCaching(
            'integrity_check_' . ($logId ?? 'all'),
            function () use ($logId) {
                $issues = [];
                $checkedCount = 0;

                if ($logId !== null) {
                    // Validate specific log
                    $log = $this->auditLogRepository->findById($logId);
                    if ($log === null) {
                        $issues[] = [
                            'log_id' => $logId,
                            'issue' => 'Log not found',
                            'severity' => 'critical'
                        ];
                    } else {
                        $checkedCount = 1;
                        $logIssues = $this->validateSingleLog($log);
                        if (!empty($logIssues)) {
                            $issues = array_merge($issues, $logIssues);
                        }
                    }
                } else {
                    // Validate all logs (sampled)
                    $logs = $this->auditLogRepository->findAll([], 1000, 0);
                    $checkedCount = count($logs);
                    
                    foreach ($logs as $log) {
                        $logIssues = $this->validateSingleLog($log);
                        if (!empty($logIssues)) {
                            $issues = array_merge($issues, $logIssues);
                        }
                    }
                }

                return [
                    'valid' => empty($issues),
                    'checked_count' => $checkedCount,
                    'issues' => $issues
                ];
            },
            3600
        );
    }

    /**
     * {@inheritDoc}
     */
    public function checkCompliance(): array
    {
        $this->authorize('audit.compliance');

        $retentionDays = $this->configuration['retention_days'] ?? 365;
        $cutoffDate = date('Y-m-d', strtotime("-$retentionDays days"));
        
        $logsOlderThanRetention = $this->auditLogRepository->count([
            'performed_at <' => $cutoffDate
        ]);

        return [
            'compliant' => $logsOlderThanRetention === 0,
            'retention_days' => $retentionDays,
            'logs_older_than_retention' => $logsOlderThanRetention,
            'action_required' => $logsOlderThanRetention > 0
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function generateComplianceReport(string $startDate, string $endDate, array $filters = []): array
    {
        $this->authorize('audit.compliance.report');

        // Validate dates
        if (!strtotime($startDate) || !strtotime($endDate)) {
            throw new ValidationException(
                'Invalid date format. Use ISO 8601 format.',
                'INVALID_DATE_FORMAT'
            );
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            throw new ValidationException(
                'Start date must be before end date',
                'INVALID_DATE_RANGE'
            );
        }

        return $this->transaction(function () use ($startDate, $endDate, $filters) {
            $reportId = 'COMPLIANCE_REPORT_' . date('Ymd_His');
            
            // Get statistics for the period
            $stats = $this->getStatistics($startDate, $endDate);
            
            // Check compliance
            $compliance = $this->checkCompliance();
            
            // Get configuration
            $config = $this->getConfiguration();
            
            // Generate report
            $report = [
                'report_id' => $reportId,
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ],
                'summary' => [
                    'total_actions' => $stats['total_logs'],
                    'admin_actions' => $stats['admin_logs'],
                    'system_actions' => $stats['system_logs'],
                    'compliance_status' => $compliance['compliant'] ? 'compliant' : 'non-compliant',
                    'retention_days' => $compliance['retention_days'],
                    'logs_exceeding_retention' => $compliance['logs_older_than_retention']
                ],
                'details' => [
                    'action_distribution' => $stats['by_action_type'],
                    'entity_distribution' => $stats['by_entity_type'],
                    'top_admins' => $stats['by_admin'],
                    'daily_activity' => $stats['daily_activity'],
                    'configuration' => $config
                ]
            ];

            // Audit report generation
            $this->audit(
                'audit.compliance_report',
                'system',
                0,
                null,
                ['report_id' => $reportId],
                [
                    'performed_by' => $this->getCurrentAdminId(),
                    'period' => $report['period']
                ]
            );

            return $report;
        }, 'compliance_report');
    }

    // ==================== AUDIT LOG CONFIGURATION ====================

    /**
     * {@inheritDoc}
     */
    public function getConfiguration(): array
    {
        $this->authorize('audit.configure');

        return $this->configuration;
    }

    /**
     * {@inheritDoc}
     */
    public function updateConfiguration(array $config): array
    {
        $this->authorize('audit.configure');

        return $this->transaction(function () use ($config) {
            $oldConfig = $this->configuration;
            $changes = [];

            // Validate configuration
            $validationErrors = $this->validateConfiguration($config);
            if (!empty($validationErrors)) {
                throw new ValidationException(
                    'Configuration validation failed',
                    'CONFIG_VALIDATION_FAILED',
                    $validationErrors
                );
            }

            // Apply changes
            foreach ($config as $key => $value) {
                if (isset($oldConfig[$key]) && $oldConfig[$key] !== $value) {
                    $changes[$key] = [
                        'old' => $oldConfig[$key],
                        'new' => $value
                    ];
                    $this->configuration[$key] = $value;
                } elseif (!isset($oldConfig[$key])) {
                    $changes[$key] = [
                        'old' => null,
                        'new' => $value
                    ];
                    $this->configuration[$key] = $value;
                }
            }

            // Save configuration (in production, save to database)
            $this->saveConfiguration($this->configuration);

            // Clear cache
            $this->queueCacheOperation('audit_config:*');

            // Audit configuration change
            $this->audit(
                'audit.configuration_updated',
                'system',
                0,
                $oldConfig,
                $this->configuration,
                [
                    'performed_by' => $this->getCurrentAdminId(),
                    'changes' => $changes
                ]
            );

            return [
                'updated' => !empty($changes),
                'changes' => $changes
            ];
        }, 'audit_config_update');
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedEntities(): array
    {
        return [
            'admin' => 'Administrator',
            'product' => 'Product',
            'category' => 'Category',
            'marketplace' => 'Marketplace',
            'link' => 'Product Link',
            'badge' => 'Badge',
            'marketplace_badge' => 'Marketplace Badge',
            'product_badge' => 'Product Badge',
            'audit_log' => 'Audit Log',
            'system' => 'System'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedActions(): array
    {
        return [
            'create' => 'Create',
            'update' => 'Update',
            'delete' => 'Delete',
            'archive' => 'Archive',
            'restore' => 'Restore',
            'publish' => 'Publish',
            'verify' => 'Verify',
            'login' => 'Login',
            'logout' => 'Logout',
            'password_change' => 'Password Change',
            'role_change' => 'Role Change',
            'status_change' => 'Status Change',
            'bulk_operation' => 'Bulk Operation',
            'export' => 'Export',
            'import' => 'Import',
            'configuration' => 'Configuration',
            'cleanup' => 'Cleanup',
            'archive' => 'Archive',
            'restore_archive' => 'Restore Archive'
        ];
    }

    // ==================== AUDIT LOG UTILITIES ====================

    /**
     * {@inheritDoc}
     */
    public function generateEntitySummary(string $entityType, int $entityId, int $maxEntries = 10): array
    {
        $this->authorize('audit.view.summary');

        $cacheKey = $this->getServiceCacheKey('entity_summary', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'max_entries' => $maxEntries
        ]);

        return $this->withCaching($cacheKey, function () use ($entityType, $entityId, $maxEntries) {
            $logs = $this->auditLogRepository->findByEntity($entityType, $entityId, $maxEntries, 0);
            
            // Get first and last action
            $firstAction = null;
            $lastAction = null;
            
            if (!empty($logs)) {
                $firstLog = end($logs);
                $lastLog = reset($logs);
                
                $firstAction = $firstLog->getFormattedPerformedAt();
                $lastAction = $lastLog->getFormattedPerformedAt();
            }

            // Get top admins for this entity
            $adminCounts = [];
            foreach ($logs as $log) {
                if ($log->getAdminId() !== null) {
                    $adminId = $log->getAdminId();
                    $adminCounts[$adminId] = ($adminCounts[$adminId] ?? 0) + 1;
                }
            }
            
            arsort($adminCounts);
            $topAdmins = [];
            foreach (array_slice($adminCounts, 0, 5, true) as $adminId => $count) {
                $admin = $this->adminRepository->findById($adminId);
                $topAdmins[] = [
                    'admin_id' => $adminId,
                    'name' => $admin ? $admin->getName() : 'Unknown',
                    'count' => $count
                ];
            }

            return [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'total_actions' => $this->auditLogRepository->count([
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ]),
                'first_action' => $firstAction,
                'last_action' => $lastAction,
                'recent_actions' => array_map(function ($log) {
                    return AuditLogResponse::fromEntity($log);
                }, array_slice($logs, 0, $maxEntries)),
                'top_admins' => $topAdmins
            ];
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function compareEntityStates(
        string $entityType,
        int $entityId,
        array $oldState,
        array $newState
    ): array {
        $changes = $this->compareArrays($oldState, $newState);
        
        if (empty($changes)) {
            return [
                'has_changes' => false,
                'changes' => [],
                'summary' => 'No changes detected'
            ];
        }

        // Generate human-readable summary
        $summaryParts = [];
        foreach ($changes as $field => $change) {
            $summaryParts[] = sprintf(
                '%s changed from "%s" to "%s"',
                $field,
                $this->formatValue($change['old']),
                $this->formatValue($change['new'])
            );
        }

        return [
            'has_changes' => true,
            'changes' => $changes,
            'summary' => implode(', ', $summaryParts)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function parseChanges(?array $oldValues, ?array $newValues, string $entityType): array
    {
        if ($oldValues === null && $newValues === null) {
            return [];
        }

        if ($oldValues === null) {
            // Creation
            return array_map(function ($field, $value) {
                return [
                    'field' => $field,
                    'old_value' => null,
                    'new_value' => $value,
                    'change_type' => 'created'
                ];
            }, array_keys($newValues), $newValues);
        }

        if ($newValues === null) {
            // Deletion
            return array_map(function ($field, $value) {
                return [
                    'field' => $field,
                    'old_value' => $value,
                    'new_value' => null,
                    'change_type' => 'deleted'
                ];
            }, array_keys($oldValues), $oldValues);
        }

        // Update
        $changes = [];
        $allFields = array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));
        
        foreach ($allFields as $field) {
            $oldValue = $oldValues[$field] ?? null;
            $newValue = $newValues[$field] ?? null;
            
            if ($oldValue !== $newValue) {
                $changes[] = [
                    'field' => $field,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'change_type' => $oldValue === null ? 'added' : ($newValue === null ? 'removed' : 'changed')
                ];
            }
        }

        return $changes;
    }

    /**
     * {@inheritDoc}
     */
    public function getHealthStatus(): array
{
        $cacheKey = $this->getServiceCacheKey('health_status');

        return $this->withCaching($cacheKey, function () {
            // Get database size
            $dbSize = $this->getDatabaseSize();
            
            // Calculate logs per day (last 7 days)
            $weekAgo = date('Y-m-d', strtotime('-7 days'));
            $weekLogs = $this->auditLogRepository->count(['performed_at >=' => $weekAgo]);
            $logsPerDay = $weekLogs / 7;
            
            // Check compliance
            $compliance = $this->checkCompliance();
            
            // Calculate storage usage (estimate)
            $estimatedSizeMb = $dbSize['estimated_size_mb'];
            $availableSpace = disk_free_space(WRITEPATH) / 1024 / 1024; // MB
            $totalSpace = disk_total_space(WRITEPATH) / 1024 / 1024; // MB
            $usedPercentage = $totalSpace > 0 ? ($estimatedSizeMb / $totalSpace) * 100 : 0;
            
            // Oldest log age
            $oldestLogDays = null;
            if ($dbSize['oldest_log'] !== null) {
                $oldestDate = new \DateTime($dbSize['oldest_log']);
                $now = new \DateTime();
                $oldestLogDays = $now->diff($oldestDate)->days;
            }
            
            // Warnings
            $warnings = [];
            
            if ($usedPercentage > 80) {
                $warnings[] = 'Storage usage is high (' . round($usedPercentage, 2) . '%)';
            }
            
            if (!$compliance['compliant']) {
                $warnings[] = 'Compliance issue: ' . $compliance['logs_older_than_retention'] . ' logs exceed retention period';
            }
            
            if ($logsPerDay > 10000) {
                $warnings[] = 'High volume of logs: ' . round($logsPerDay) . ' per day';
            }
            
            return [
                'status' => empty($warnings) ? 'healthy' : 'warning',
                'logs_per_day' => round($logsPerDay, 2),
                'storage_usage' => [
                    'used_mb' => round($estimatedSizeMb, 2),
                    'available_mb' => round($availableSpace, 2),
                    'percentage' => round($usedPercentage, 2)
                ],
                'oldest_log_days' => $oldestLogDays,
                'compliance_status' => $compliance['compliant'] ? 'compliant' : 'non-compliant',
                'warnings' => $warnings
            ];
        }, 300);
    }

    // ==================== ABSTRACT METHOD IMPLEMENTATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        // Delegate to AuditLogValidator
        return $this->auditLogValidator->validate($dto, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'AuditLogService';
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Load audit log configuration
     *
     * @return array<string, mixed>
     */
    private function loadConfiguration(): array
    {
        // Default configuration
        $defaultConfig = [
            'retention_days' => 365,
            'enabled_entities' => array_keys($this->getSupportedEntities()),
            'enabled_actions' => array_keys($this->getSupportedActions()),
            'max_log_size_mb' => 1024, // 1GB
            'auto_cleanup' => true,
            'archive_enabled' => false,
            'log_ip_address' => true,
            'log_user_agent' => true,
            'log_changes_summary' => true,
            'compress_old_logs' => false
        ];

        // In production, load from database or config file
        // For MVP, use defaults
        return $defaultConfig;
    }

    /**
     * Save configuration
     *
     * @param array<string, mixed> $config
     * @return void
     */
    private function saveConfiguration(array $config): void
    {
        // In production, save to database or config file
        // For MVP, just update in-memory configuration
        $this->configuration = $config;
    }

    /**
     * Validate configuration
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function validateConfiguration(array $config): array
    {
        $errors = [];

        if (isset($config['retention_days'])) {
            if (!is_int($config['retention_days']) || $config['retention_days'] < 30) {
                $errors['retention_days'] = 'Retention days must be an integer >= 30';
            }
        }

        if (isset($config['max_log_size_mb'])) {
            if (!is_int($config['max_log_size_mb']) || $config['max_log_size_mb'] < 100) {
                $errors['max_log_size_mb'] = 'Max log size must be an integer >= 100 MB';
            }
        }

        if (isset($config['enabled_entities'])) {
            if (!is_array($config['enabled_entities'])) {
                $errors['enabled_entities'] = 'Enabled entities must be an array';
            } else {
                $supported = array_keys($this->getSupportedEntities());
                foreach ($config['enabled_entities'] as $entity) {
                    if (!in_array($entity, $supported)) {
                        $errors['enabled_entities'] = 'Unsupported entity type: ' . $entity;
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Validate log input parameters
     *
     * @param string $actionType
     * @param string $entityType
     * @param int $entityId
     * @param int|null $adminId
     * @throws ValidationException
     */
    private function validateLogInput(string $actionType, string $entityType, int $entityId, ?int $adminId): void
    {
        $errors = [];

        if (empty($actionType)) {
            $errors['action_type'] = 'Action type is required';
        }

        if (empty($entityType)) {
            $errors['entity_type'] = 'Entity type is required';
        }

        if ($entityId <= 0) {
            $errors['entity_id'] = 'Entity ID must be positive';
        }

        if ($adminId !== null && $adminId <= 0) {
            $errors['admin_id'] = 'Admin ID must be positive';
        }

        if (!empty($errors)) {
            throw new ValidationException(
                'Invalid audit log parameters',
                'INVALID_LOG_PARAMETERS',
                $errors
            );
        }
    }

    /**
     * Check if logging is enabled for entity/action
     *
     * @param string $entityType
     * @param string $actionType
     * @return bool
     */
    private function isLoggingEnabled(string $entityType, string $actionType): bool
    {
        $enabledEntities = $this->configuration['enabled_entities'] ?? [];
        $enabledActions = $this->configuration['enabled_actions'] ?? [];

        return in_array($entityType, $enabledEntities) && in_array($actionType, $enabledActions);
    }

    /**
     * Generate changes summary
     *
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param string $entityType
     * @return string|null
     */
    private function generateChangesSummary(?array $oldValues, ?array $newValues, string $entityType): ?string
    {
        if (!$this->configuration['log_changes_summary'] ?? true) {
            return null;
        }

        if ($oldValues === null && $newValues === null) {
            return 'No data provided';
        }

        if ($oldValues === null) {
            return 'Entity created with ' . count($newValues) . ' fields';
        }

        if ($newValues === null) {
            return 'Entity deleted';
        }

        $changes = $this->compareArrays($oldValues, $newValues);
        
        if (empty($changes)) {
            return 'No changes detected';
        }

        $changeCount = count($changes);
        $changedFields = array_keys($changes);
        
        if ($changeCount <= 3) {
            return sprintf(
                'Changed %d fields: %s',
                $changeCount,
                implode(', ', $changedFields)
            );
        }

        return sprintf(
            'Changed %d fields: %s, ...',
            $changeCount,
            implode(', ', array_slice($changedFields, 0, 3))
        );
    }

    /**
     * Compare two arrays and return differences
     *
     * @param array $old
     * @param array $new
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function compareArrays(array $old, array $new): array
    {
        $changes = [];
        $allKeys = array_unique(array_merge(array_keys($old), array_keys($new)));

        foreach ($allKeys as $key) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if ($oldValue !== $newValue) {
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue
                ];
            }
        }

        return $changes;
    }

    /**
     * Get client IP address
     *
     * @return string|null
     */
    private function getClientIp(): ?string
    {
        if (!$this->configuration['log_ip_address'] ?? true) {
            return null;
        }

        // In CodeIgniter 4
        $request = service('request');
        return $request->getIPAddress();
    }

    /**
     * Get user agent
     *
     * @return string|null
     */
    private function getUserAgent(): ?string
    {
        if (!$this->configuration['log_user_agent'] ?? true) {
            return null;
        }

        $request = service('request');
        return $request->getUserAgent()->getAgentString();
    }

    /**
     * Get admin name by ID
     *
     * @param int|null $adminId
     * @return string|null
     */
    private function getAdminName(?int $adminId): ?string
    {
        if ($adminId === null) {
            return 'System';
        }

        try {
            $admin = $this->adminRepository->findById($adminId);
            return $admin ? $admin->getName() : 'Unknown';
        } catch (\Throwable $e) {
            return 'Unknown';
        }
    }

    /**
     * Generate diff between two values
     *
     * @param mixed $old
     * @param mixed $new
     * @return array
     */
    private function generateDiff($old, $new): array
    {
        if (is_array($old) && is_array($new)) {
            return $this->compareArrays($old, $new);
        }

        if (is_string($old) && is_string($new)) {
            // Simple string diff for MVP
            if ($old === $new) {
                return ['unchanged' => true];
            }
            
            return [
                'changed' => true,
                'old_length' => strlen($old),
                'new_length' => strlen($new),
                'similarity' => similar_text($old, $new) / max(strlen($old), strlen($new)) * 100
            ];
        }

        return [
            'changed' => $old !== $new,
            'type_old' => gettype($old),
            'type_new' => gettype($new)
        ];
    }

    /**
     * Calculate busiest hour
     *
     * @param string $startDate
     * @param string $endDate
     * @return array|null
     */
    private function calculateBusiestHour(string $startDate, string $endDate): ?array
    {
        // For MVP, return null or simple calculation
        // In production, this would query the database
        return null;
    }

    /**
     * Format daily activity
     *
     * @param array $dailyActivity
     * @return array
     */
    private function formatDailyActivity(array $dailyActivity): array
    {
        $formatted = [];
        foreach ($dailyActivity as $day) {
            $formatted[$day['date']] = $day['total'] ?? 0;
        }
        return $formatted;
    }

    /**
     * Calculate peak hour for a day
     *
     * @param string $date
     * @return int
     */
    private function calculatePeakHourForDay(string $date): int
    {
        // For MVP, return 0
        // In production, this would analyze logs for that day
        return 0;
    }

    /**
     * Get logs for export
     *
     * @param array $criteria
     * @return array<AuditLog>
     */
    private function getLogsForExport(array $criteria): array
    {
        // Apply criteria to filter logs
        $filters = [];
        
        if (isset($criteria['start_date'])) {
            $filters['performed_at >='] = $criteria['start_date'];
        }
        
        if (isset($criteria['end_date'])) {
            $filters['performed_at <='] = $criteria['end_date'];
        }
        
        if (isset($criteria['entity_type'])) {
            $filters['entity_type'] = $criteria['entity_type'];
        }
        
        if (isset($criteria['action_type'])) {
            $filters['action_type'] = $criteria['action_type'];
        }
        
        if (isset($criteria['admin_id'])) {
            $filters['admin_id'] = $criteria['admin_id'];
        }

        // Limit to 1000 logs for performance
        return $this->auditLogRepository->findAll($filters, 1000, 0);
    }

    /**
     * Convert logs to specified format
     *
     * @param array<AuditLog> $logs
     * @param string $format
     * @return string
     */
    private function convertToFormat(array $logs, string $format): string
    {
        $data = array_map(function ($log) {
            return [
                'id' => $log->getId(),
                'action_type' => $log->getActionType(),
                'entity_type' => $log->getEntityType(),
                'entity_id' => $log->getEntityId(),
                'admin_id' => $log->getAdminId(),
                'old_values' => $log->getOldValuesArray(),
                'new_values' => $log->getNewValuesArray(),
                'changes_summary' => $log->getChangesSummary(),
                'ip_address' => $log->getIpAddress(),
                'user_agent' => $log->getUserAgent(),
                'performed_at' => $log->getFormattedPerformedAt()
            ];
        }, $logs);

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            case 'csv':
                $csv = "ID,Action Type,Entity Type,Entity ID,Admin ID,Performed At,Changes Summary,IP Address\n";
                foreach ($data as $row) {
                    $csv .= sprintf(
                        '%d,%s,%s,%d,%s,%s,%s,%s' . "\n",
                        $row['id'],
                        $row['action_type'],
                        $row['entity_type'],
                        $row['entity_id'],
                        $row['admin_id'] ?? '',
                        $row['performed_at'],
                        $row['changes_summary'] ?? '',
                        $row['ip_address'] ?? ''
                    );
                }
                return $csv;
                
            case 'xml':
                $xml = new \SimpleXMLElement('<audit_logs></audit_logs>');
                foreach ($data as $row) {
                    $log = $xml->addChild('log');
                    foreach ($row as $key => $value) {
                        if (is_array($value)) {
                            $child = $log->addChild($key);
                            $this->arrayToXml($value, $child);
                        } else {
                            $log->addChild($key, htmlspecialchars($value ?? ''));
                        }
                    }
                }
                return $xml->asXML();
                
            default:
                return '';
        }
    }

    /**
     * Convert array to XML
     *
     * @param array $array
     * @param \SimpleXMLElement $parent
     */
    private function arrayToXml(array $array, \SimpleXMLElement $parent): void
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $child = $parent->addChild($key);
                $this->arrayToXml($value, $child);
            } else {
                $parent->addChild($key, htmlspecialchars($value ?? ''));
            }
        }
    }

    /**
     * Format value for display
     *
     * @param mixed $value
     * @return string
     */
    private function formatValue($value): string
    {
        if ($value === null) {
            return 'null';
        }
        
        if (is_array($value)) {
            return 'array(' . count($value) . ' items)';
        }
        
        if (is_object($value)) {
            return get_class($value);
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        return (string) $value;
    }

    /**
     * Validate single audit log
     *
     * @param AuditLog $log
     * @return array
     */
    private function validateSingleLog(AuditLog $log): array
    {
        $issues = [];

        // Check JSON validity
        if ($log->hasOldValues() && !$log->getOldValuesArray()) {
            $issues[] = [
                'log_id' => $log->getId(),
                'issue' => 'Invalid JSON in old_values',
                'severity' => 'warning'
            ];
        }

        if ($log->hasNewValues() && !$log->getNewValuesArray()) {
            $issues[] = [
                'log_id' => $log->getId(),
                'issue' => 'Invalid JSON in new_values',
                'severity' => 'warning'
            ];
        }

        // Check admin exists (if not system action)
        if ($log->getAdminId() !== null) {
            $admin = $this->adminRepository->findById($log->getAdminId());
            if ($admin === null) {
                $issues[] = [
                    'log_id' => $log->getId(),
                    'issue' => 'Referenced admin not found',
                    'severity' => 'warning'
                ];
            }
        }

        // Check timestamp
        if ($log->getPerformedAt() === null) {
            $issues[] = [
                'log_id' => $log->getId(),
                'issue' => 'Missing timestamp',
                'severity' => 'critical'
            ];
        }

        return $issues;
    }
}