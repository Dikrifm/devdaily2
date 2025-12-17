<?php

namespace App\Services;

use App\Entities\AuditLog;
use App\Models\AuditLogModel;
use App\Entities\BaseEntity;
use App\Entities\Admin;
use CodeIgniter\HTTP\RequestInterface;
use DateTimeImmutable;

/**
 * Enterprise-grade Audit Service
 * 
 * Handles automatic audit logging for all critical operations
 * Supports context-aware logging, diff tracking, and audit trails
 */
class AuditService
{
    private AuditLogModel $auditLogModel;
    private ?RequestInterface $request;
    private bool $enabled;
    private array $config;
    
    private const ACTION_CREATE = 'CREATE';
    private const ACTION_UPDATE = 'UPDATE';
    private const ACTION_DELETE = 'DELETE';
    private const ACTION_SOFT_DELETE = 'SOFT_DELETE';
    private const ACTION_RESTORE = 'RESTORE';
    private const ACTION_PUBLISH = 'PUBLISH';
    private const ACTION_ARCHIVE = 'ARCHIVE';
    private const ACTION_VERIFY = 'VERIFY';
    private const ACTION_LOGIN = 'LOGIN';
    private const ACTION_LOGOUT = 'LOGOUT';
    private const ACTION_STATUS_CHANGE = 'STATUS_CHANGE';
    
    private const ENTITY_PRODUCT = 'PRODUCT';
    private const ENTITY_CATEGORY = 'CATEGORY';
    private const ENTITY_LINK = 'LINK';
    private const ENTITY_ADMIN = 'ADMIN';
    private const ENTITY_BADGE = 'BADGE';
    private const ENTITY_MARKETPLACE = 'MARKETPLACE';

    public function __construct(
        AuditLogModel $auditLogModel,
        ?RequestInterface $request = null,
        array $config = []
    ) {
        $this->auditLogModel = $auditLogModel;
        $this->request = $request;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->enabled = $this->config['enabled'] ?? true;
    }

    /**
     * Log CRUD operation with automatic diff tracking
     */
    public function logCrudOperation(
        string $actionType,
        string $entityType,
        int $entityId,
        ?Admin $admin = null,
        ?BaseEntity $oldEntity = null,
        ?BaseEntity $newEntity = null,
        ?string $notes = null,
        ?array $context = null
    ): ?AuditLog {
        if (!$this->enabled) {
            return null;
        }

        $changesSummary = $this->generateChangesSummary($oldEntity, $newEntity);
        $oldValues = $oldEntity ? $this->prepareEntityData($oldEntity) : null;
        $newValues = $newEntity ? $this->prepareEntityData($newEntity) : null;

        $logData = [
            'admin_id' => $admin?->getId(),
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? json_encode($oldValues, JSON_PRETTY_PRINT) : null,
            'new_values' => $newValues ? json_encode($newValues, JSON_PRETTY_PRINT) : null,
            'changes_summary' => $changesSummary,
            'notes' => $notes,
            'context' => $context ? json_encode($context) : null,
            'ip_address' => $this->request?->getIPAddress(),
            'user_agent' => $this->request?->getUserAgent(),
            'performed_at' => new DateTimeImmutable(),
            'admin_name' => $admin?->getName(),
            'admin_username' => $admin?->getUsername(),
        ];

        try {
            return $this->auditLogModel->logAction($logData);
        } catch (\Exception $e) {
            // Fallback to file logging if database fails
            $this->logToFile($logData, $e->getMessage());
            return null;
        }
    }

    /**
     * Log entity creation
     */
    public function logCreate(
        string $entityType,
        int $entityId,
        BaseEntity $newEntity,
        ?Admin $admin = null,
        ?string $notes = null
    ): ?AuditLog {
        return $this->logCrudOperation(
            self::ACTION_CREATE,
            $entityType,
            $entityId,
            $admin,
            null,
            $newEntity,
            $notes
        );
    }

    /**
     * Log entity update with diff tracking
     */
    public function logUpdate(
        string $entityType,
        int $entityId,
        BaseEntity $oldEntity,
        BaseEntity $newEntity,
        ?Admin $admin = null,
        ?string $notes = null
    ): ?AuditLog {
        return $this->logCrudOperation(
            self::ACTION_UPDATE,
            $entityType,
            $entityId,
            $admin,
            $oldEntity,
            $newEntity,
            $notes
        );
    }

    /**
     * Log entity deletion (soft or hard)
     */
    public function logDelete(
        string $entityType,
        int $entityId,
        BaseEntity $entity,
        ?Admin $admin = null,
        bool $softDelete = true,
        ?string $notes = null
    ): ?AuditLog {
        $actionType = $softDelete ? self::ACTION_SOFT_DELETE : self::ACTION_DELETE;
        
        return $this->logCrudOperation(
            $actionType,
            $entityType,
            $entityId,
            $admin,
            $entity,
            null,
            $notes
        );
    }

    /**
     * Log entity restoration
     */
    public function logRestore(
        string $entityType,
        int $entityId,
        BaseEntity $entity,
        ?Admin $admin = null,
        ?string $notes = null
    ): ?AuditLog {
        return $this->logCrudOperation(
            self::ACTION_RESTORE,
            $entityType,
            $entityId,
            $admin,
            null,
            $entity,
            $notes
        );
    }

    /**
     * Log state transition (publish, archive, verify, etc.)
     */
    public function logStateTransition(
        string $entityType,
        int $entityId,
        string $fromState,
        string $toState,
        ?Admin $admin = null,
        ?array $metadata = null,
        ?string $notes = null
    ): ?AuditLog {
        $actionType = $this->mapStateTransitionToAction($fromState, $toState);
        
        $context = [
            'from_state' => $fromState,
            'to_state' => $toState,
            'metadata' => $metadata,
        ];

        $logData = [
            'admin_id' => $admin?->getId(),
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes_summary' => sprintf(
                'State changed from %s to %s',
                $fromState,
                $toState
            ),
            'notes' => $notes,
            'context' => json_encode($context),
            'ip_address' => $this->request?->getIPAddress(),
            'user_agent' => $this->request?->getUserAgent(),
            'performed_at' => new DateTimeImmutable(),
            'admin_name' => $admin?->getName(),
            'admin_username' => $admin?->getUsername(),
        ];

        try {
            return $this->auditLogModel->logAction($logData);
        } catch (\Exception $e) {
            $this->logToFile($logData, $e->getMessage());
            return null;
        }
    }

    /**
     * Log user authentication events
     */
    public function logAuthentication(
        string $actionType,
        ?Admin $admin = null,
        bool $success = true,
        ?string $failureReason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): ?AuditLog {
        $context = [
            'success' => $success,
            'failure_reason' => $failureReason,
        ];

        $logData = [
            'admin_id' => $admin?->getId(),
            'action_type' => $actionType,
            'entity_type' => self::ENTITY_ADMIN,
            'entity_id' => $admin?->getId() ?? 0,
            'changes_summary' => sprintf(
                'Authentication %s: %s',
                $success ? 'successful' : 'failed',
                $failureReason ?? 'No reason provided'
            ),
            'context' => json_encode($context),
            'ip_address' => $ipAddress ?? $this->request?->getIPAddress(),
            'user_agent' => $userAgent ?? $this->request?->getUserAgent(),
            'performed_at' => new DateTimeImmutable(),
            'admin_name' => $admin?->getName(),
            'admin_username' => $admin?->getUsername(),
        ];

        try {
            return $this->auditLogModel->logAction($logData);
        } catch (\Exception $e) {
            $this->logToFile($logData, $e->getMessage());
            return null;
        }
    }

    /**
     * Get audit trail for an entity
     */
    public function getEntityAuditTrail(
        string $entityType,
        int $entityId,
        ?array $filters = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $defaultFilters = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ];

        $filters = array_merge($defaultFilters, $filters ?? []);

        return $this->auditLogModel->searchLogs($filters, $limit, $offset);
    }

    /**
     * Get admin activity log
     */
    public function getAdminActivity(
        int $adminId,
        ?array $filters = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $defaultFilters = [
            'admin_id' => $adminId,
        ];

        $filters = array_merge($defaultFilters, $filters ?? []);

        return $this->auditLogModel->searchLogs($filters, $limit, $offset);
    }

    /**
     * Get recent system activity
     */
    public function getRecentActivity(
        int $limit = 20,
        ?string $entityType = null,
        ?string $actionType = null
    ): array {
        $filters = [];
        
        if ($entityType) {
            $filters['entity_type'] = $entityType;
        }
        
        if ($actionType) {
            $filters['action_type'] = $actionType;
        }

        return $this->auditLogModel->getRecentActivity($limit, $filters);
    }

    /**
     * Generate audit statistics
     */
    public function getStatistics(string $period = 'month'): array
    {
        return $this->auditLogModel->getStatistics($period);
    }

    /**
     * Cleanup old audit logs
     */
    public function cleanupOldLogs(int $daysOld = 365): int
    {
        return $this->auditLogModel->cleanupOldLogs($daysOld);
    }

    /**
     * Export audit logs to CSV
     */
    public function exportToCsv(array $filters = []): string
    {
        return $this->auditLogModel->exportToCsv($filters);
    }

    /**
     * Enable/disable audit logging
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * Check if audit logging is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get service configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Generate human-readable changes summary
     */
    private function generateChangesSummary(
        ?BaseEntity $oldEntity,
        ?BaseEntity $newEntity
    ): string {
        if (!$oldEntity && $newEntity) {
            return 'Entity created';
        }

        if ($oldEntity && !$newEntity) {
            return 'Entity deleted';
        }

        if ($oldEntity && $newEntity) {
            $changes = $oldEntity->getChanges();
            
            if (empty($changes)) {
                return 'No significant changes detected';
            }

            $changeDescriptions = [];
            foreach ($changes as $property => $change) {
                $changeDescriptions[] = sprintf(
                    '%s: %s → %s',
                    $this->formatPropertyName($property),
                    $this->formatChangeValue($change['old']),
                    $this->formatChangeValue($change['new'])
                );
            }

            return 'Changed: ' . implode('; ', $changeDescriptions);
        }

        return 'Unknown change';
    }

    /**
     * Prepare entity data for JSON serialization
     */
    private function prepareEntityData(BaseEntity $entity): array
    {
        $data = $entity->toArray();
        
        // Remove sensitive information
        $sensitiveFields = ['password', 'password_hash', 'token', 'api_key'];
        foreach ($sensitiveFields as $field) {
            if (isset($data[$field])) {
                $data[$field] = '***REDACTED***';
            }
        }

        return $data;
    }

    /**
     * Map state transition to audit action
     */
    private function mapStateTransitionToAction(string $fromState, string $toState): string
    {
        $transitionMap = [
            'DRAFT->PENDING_VERIFICATION' => self::ACTION_STATUS_CHANGE,
            'PENDING_VERIFICATION->PUBLISHED' => self::ACTION_VERIFY,
            'DRAFT->PUBLISHED' => self::ACTION_PUBLISH,
            'PUBLISHED->ARCHIVED' => self::ACTION_ARCHIVE,
            'ARCHIVED->PUBLISHED' => self::ACTION_RESTORE,
            'PUBLISHED->DRAFT' => self::ACTION_STATUS_CHANGE,
        ];

        $key = "{$fromState}->{$toState}";
        
        return $transitionMap[$key] ?? self::ACTION_STATUS_CHANGE;
    }

    /**
     * Format property name for human reading
     */
    private function formatPropertyName(string $property): string
    {
        return ucfirst(str_replace('_', ' ', $property));
    }

    /**
     * Format change value for display
     */
    private function formatChangeValue($value): string
    {
        if (is_null($value)) {
            return '(empty)';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return '[Array]';
        }

        if (is_object($value)) {
            return '[Object]';
        }

        $stringValue = (string) $value;
        
        // Truncate long values
        if (strlen($stringValue) > 50) {
            return substr($stringValue, 0, 47) . '...';
        }

        return $stringValue;
    }

    /**
     * Fallback file logging
     */
    private function logToFile(array $logData, string $errorMessage): void
    {
        $logDir = WRITEPATH . 'logs/audit/';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logEntry = sprintf(
            "[%s] AUDIT LOG FAILED: %s\nLog Data: %s\n\n",
            date('Y-m-d H:i:s'),
            $errorMessage,
            json_encode($logData, JSON_PRETTY_PRINT)
        );

        $logFile = $logDir . 'fallback_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'log_ip_address' => true,
            'log_user_agent' => true,
            'redact_sensitive_data' => true,
            'max_log_age_days' => 365,
            'fallback_to_file' => true,
            'log_state_transitions' => true,
            'log_authentication' => true,
            'log_crud_operations' => true,
        ];
    }

    /**
     * Create AuditService instance with default dependencies
     */
    public static function create(): self
    {
        $auditLogModel = model(AuditLogModel::class);
        $request = service('request');
        
        return new self($auditLogModel, $request);
    }
}