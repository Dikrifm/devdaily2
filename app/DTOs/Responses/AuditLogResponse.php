<?php

namespace App\DTOs\Responses;

use App\Entities\AuditLog;
use DateTimeImmutable;
use InvalidArgumentException;

class AuditLogResponse
{
    private ?int $id = null;
    private ?int $adminId = null;
    private string $actionType;
    private string $entityType;
    private int $entityId;
    private ?string $oldValues = null;
    private ?string $newValues = null;
    private ?string $changesSummary = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    private string $performedAt;
    
    // Related admin info
    private ?string $adminName = null;
    private ?string $adminUsername = null;
    private ?string $adminInitials = null;
    private ?string $adminDisplayName = null;
    
    // Calculated fields
    private ?string $actionTypeLabel = null;
    private ?string $actionIcon = null;
    private ?string $actionColorClass = null;
    private ?string $entityReference = null;
    private ?string $timeAgo = null;
    private ?string $truncatedSummary = null;
    
    // Optional parsed values
    private ?array $oldValuesArray = null;
    private ?array $newValuesArray = null;
    private ?array $parsedChanges = null;
    
    // Configuration
    private bool $includeValues = false;
    private bool $includeDetails = false;
    private bool $includeRelated = true;
    private int $summaryLength = 100;
    private string $dateFormat = 'Y-m-d H:i:s';
    
    private function __construct()
    {
        // Private constructor to enforce use of factory methods
    }
    
    public static function fromEntity(AuditLog $auditLog, array $options = []): self
    {
        $response = new self();
        $response->applyConfiguration($options);
        $response->populateFromEntity($auditLog);
        return $response;
    }
    
    public static function fromArray(array $data, array $options = []): self
    {
        $response = new self();
        $response->applyConfiguration($options);
        $response->populateFromArray($data);
        return $response;
    }
    
    public static function collection(array $auditLogs, array $options = []): array
    {
        $collection = [];
        foreach ($auditLogs as $log) {
            if ($log instanceof AuditLog) {
                $collection[] = self::fromEntity($log, $options);
            } elseif (is_array($log)) {
                $collection[] = self::fromArray($log, $options);
            } else {
                throw new InvalidArgumentException(
                    'Each item must be an instance of AuditLog or an array'
                );
            }
        }
        return $collection;
    }
    
    private function applyConfiguration(array $config): void
    {
        $this->includeValues = $config['include_values'] ?? false;
        $this->includeDetails = $config['include_details'] ?? false;
        $this->includeRelated = $config['include_related'] ?? true;
        $this->summaryLength = $config['summary_length'] ?? 100;
        $this->dateFormat = $config['date_format'] ?? 'Y-m-d H:i:s';
        
        // If including details, automatically include some optional fields
        if ($this->includeDetails) {
            $this->includeValues = true;
        }
    }
    
    private function populateFromEntity(AuditLog $auditLog): void
    {
        $this->id = $auditLog->getId();
        $this->adminId = $auditLog->getAdminId();
        $this->actionType = $auditLog->getActionType();
        $this->entityType = $auditLog->getEntityType();
        $this->entityId = $auditLog->getEntityId();
        $this->oldValues = $auditLog->getOldValues();
        $this->newValues = $auditLog->getNewValues();
        $this->changesSummary = $auditLog->getChangesSummary();
        $this->ipAddress = $auditLog->getIpAddress();
        $this->userAgent = $auditLog->getUserAgent();
        $this->performedAt = $auditLog->getFormattedPerformedAt($this->dateFormat);
        
        // Admin info
        if ($this->includeRelated) {
            $this->adminName = $auditLog->getAdminName();
            $this->adminUsername = $auditLog->getAdminUsername();
            $this->adminDisplayName = $auditLog->getAdminDisplayName();
            $this->adminInitials = $this->generateInitials($this->adminName);
        }
        
        // Calculated fields from entity methods
        $this->actionTypeLabel = $auditLog->getActionTypeLabel();
        $this->actionIcon = $auditLog->getActionIcon();
        $this->actionColorClass = $auditLog->getActionColorClass();
        $this->entityReference = $auditLog->getEntityReference();
        $this->timeAgo = $auditLog->getTimeAgo();
        $this->truncatedSummary = $auditLog->getTruncatedSummary($this->summaryLength);
        
        // Parse JSON values if needed
        if ($this->includeValues) {
            $this->oldValuesArray = $auditLog->getOldValuesArray();
            $this->newValuesArray = $auditLog->getNewValuesArray();
            
            // Try to parse changes if summary is JSON
            if ($this->changesSummary) {
                $this->parsedChanges = $this->parseChangesSummary($this->changesSummary);
            }
        }
    }
    
    private function populateFromArray(array $data): void
    {
        $this->id = isset($data['id']) ? (int) $data['id'] : null;
        $this->adminId = isset($data['admin_id']) ? (int) $data['admin_id'] : null;
        $this->actionType = (string) ($data['action_type'] ?? '');
        $this->entityType = (string) ($data['entity_type'] ?? '');
        $this->entityId = (int) ($data['entity_id'] ?? 0);
        $this->oldValues = isset($data['old_values']) ? (string) $data['old_values'] : null;
        $this->newValues = isset($data['new_values']) ? (string) $data['new_values'] : null;
        $this->changesSummary = isset($data['changes_summary']) ? 
            (string) $data['changes_summary'] : null;
        $this->ipAddress = isset($data['ip_address']) ? (string) $data['ip_address'] : null;
        $this->userAgent = isset($data['user_agent']) ? (string) $data['user_agent'] : null;
        
        // Handle performed_at from string or array
        if (isset($data['performed_at'])) {
            if ($data['performed_at'] instanceof DateTimeImmutable) {
                $this->performedAt = $data['performed_at']->format($this->dateFormat);
            } else {
                $this->performedAt = $this->formatTimestampFromString((string) $data['performed_at']);
            }
        } else {
            $this->performedAt = date($this->dateFormat);
        }
        
        // Admin info
        if ($this->includeRelated) {
            $this->adminName = isset($data['admin_name']) ? (string) $data['admin_name'] : null;
            $this->adminUsername = isset($data['admin_username']) ? 
                (string) $data['admin_username'] : null;
            $this->adminDisplayName = $this->adminName ?? $this->adminUsername ?? 'System';
            $this->adminInitials = $this->generateInitials($this->adminDisplayName);
        }
        
        // Optional fields from data
        if (isset($data['action_type_label'])) {
            $this->actionTypeLabel = (string) $data['action_type_label'];
        }
        if (isset($data['action_icon'])) {
            $this->actionIcon = (string) $data['action_icon'];
        }
        if (isset($data['action_color_class'])) {
            $this->actionColorClass = (string) $data['action_color_class'];
        }
        if (isset($data['entity_reference'])) {
            $this->entityReference = (string) $data['entity_reference'];
        }
        if (isset($data['time_ago'])) {
            $this->timeAgo = (string) $data['time_ago'];
        }
        if (isset($data['truncated_summary'])) {
            $this->truncatedSummary = (string) $data['truncated_summary'];
        }
        
        // Calculate fields if not provided
        if (empty($this->actionTypeLabel)) {
            $this->actionTypeLabel = $this->generateActionTypeLabel($this->actionType);
        }
        if (empty($this->actionIcon)) {
            $this->actionIcon = $this->generateActionIcon($this->actionType);
        }
        if (empty($this->actionColorClass)) {
            $this->actionColorClass = $this->generateActionColorClass($this->actionType);
        }
        if (empty($this->entityReference)) {
            $this->entityReference = $this->generateEntityReference($this->entityType, $this->entityId);
        }
        if (empty($this->timeAgo)) {
            $this->timeAgo = $this->calculateTimeAgo($this->performedAt);
        }
        if (empty($this->truncatedSummary) && $this->changesSummary) {
            $this->truncatedSummary = $this->truncateSummary($this->changesSummary, $this->summaryLength);
        }
        
        // Parse values if needed
        if ($this->includeValues) {
            if ($this->oldValues) {
                $this->oldValuesArray = $this->parseJsonIfValid($this->oldValues);
            }
            if ($this->newValues) {
                $this->newValuesArray = $this->parseJsonIfValid($this->newValues);
            }
            if ($this->changesSummary) {
                $this->parsedChanges = $this->parseChangesSummary($this->changesSummary);
            }
        }
    }
    
    private function formatTimestampFromString(?string $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        
        try {
            $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp);
            if ($dateTime) {
                return $dateTime->format($this->dateFormat);
            }
        } catch (\Exception $e) {
            // If format is different, try generic parsing
            try {
                $dateTime = new DateTimeImmutable($timestamp);
                return $dateTime->format($this->dateFormat);
            } catch (\Exception $e) {
                return $timestamp;
            }
        }
        
        return $timestamp;
    }
    
    private function generateInitials(?string $name): string
    {
        if (!$name) {
            return '??';
        }
        
        $words = preg_split('/\s+/', trim($name));
        $initials = '';
        
        foreach ($words as $word) {
            if (!empty($word)) {
                $initials .= strtoupper($word[0]);
                if (strlen($initials) >= 2) {
                    break;
                }
            }
        }
        
        return $initials ?: '?';
    }
    
    private function generateActionTypeLabel(string $actionType): string
    {
        $labels = [
            'CREATE' => 'Created',
            'UPDATE' => 'Updated',
            'DELETE' => 'Deleted',
            'SOFT_DELETE' => 'Archived',
            'RESTORE' => 'Restored',
            'PUBLISH' => 'Published',
            'ARCHIVE' => 'Archived',
            'VERIFY' => 'Verified',
            'LOGIN' => 'Logged In',
            'LOGOUT' => 'Logged Out',
            'STATUS_CHANGE' => 'Status Changed',
        ];
        
        return $labels[$actionType] ?? ucfirst(strtolower($actionType));
    }
    
    private function generateActionIcon(string $actionType): string
    {
        $icons = [
            'CREATE' => 'fas fa-plus-circle',
            'UPDATE' => 'fas fa-edit',
            'DELETE' => 'fas fa-trash',
            'SOFT_DELETE' => 'fas fa-archive',
            'RESTORE' => 'fas fa-undo',
            'PUBLISH' => 'fas fa-paper-plane',
            'ARCHIVE' => 'fas fa-archive',
            'VERIFY' => 'fas fa-check-circle',
            'LOGIN' => 'fas fa-sign-in-alt',
            'LOGOUT' => 'fas fa-sign-out-alt',
            'STATUS_CHANGE' => 'fas fa-exchange-alt',
        ];
        
        return $icons[$actionType] ?? 'fas fa-history';
    }
    
    private function generateActionColorClass(string $actionType): string
    {
        $colors = [
            'CREATE' => 'bg-green-100 text-green-800',
            'UPDATE' => 'bg-blue-100 text-blue-800',
            'DELETE' => 'bg-red-100 text-red-800',
            'SOFT_DELETE' => 'bg-yellow-100 text-yellow-800',
            'RESTORE' => 'bg-indigo-100 text-indigo-800',
            'PUBLISH' => 'bg-purple-100 text-purple-800',
            'ARCHIVE' => 'bg-yellow-100 text-yellow-800',
            'VERIFY' => 'bg-teal-100 text-teal-800',
            'LOGIN' => 'bg-green-100 text-green-800',
            'LOGOUT' => 'bg-gray-100 text-gray-800',
            'STATUS_CHANGE' => 'bg-orange-100 text-orange-800',
        ];
        
        return $colors[$actionType] ?? 'bg-gray-100 text-gray-800';
    }
    
    private function generateEntityReference(string $entityType, int $entityId): string
    {
        $entityLabels = [
            'PRODUCT' => 'Product',
            'CATEGORY' => 'Category',
            'LINK' => 'Link',
            'ADMIN' => 'Admin',
            'BADGE' => 'Badge',
            'MARKETPLACE' => 'Marketplace',
        ];
        
        $label = $entityLabels[$entityType] ?? $entityType;
        return "{$label} #{$entityId}";
    }
    
    private function calculateTimeAgo(string $timestamp): string
    {
        try {
            $dateTime = DateTimeImmutable::createFromFormat($this->dateFormat, $timestamp);
            if (!$dateTime) {
                $dateTime = new DateTimeImmutable($timestamp);
            }
            
            $now = new DateTimeImmutable();
            $diff = $now->getTimestamp() - $dateTime->getTimestamp();
            
            if ($diff < 60) {
                return 'just now';
            } elseif ($diff < 3600) {
                $minutes = floor($diff / 60);
                return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 86400) {
                $hours = floor($diff / 3600);
                return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 604800) {
                $days = floor($diff / 86400);
                return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 2592000) {
                $weeks = floor($diff / 604800);
                return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
            } else {
                $months = floor($diff / 2592000);
                return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
            }
        } catch (\Exception $e) {
            return 'some time ago';
        }
    }
    
    private function truncateSummary(string $summary, int $length): string
    {
        if (strlen($summary) <= $length) {
            return $summary;
        }
        
        $truncated = substr($summary, 0, $length);
        // Don't cut off in the middle of a word if possible
        $lastSpace = strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = substr($truncated, 0, $lastSpace);
        }
        
        return $truncated . '...';
    }
    
    private function parseJsonIfValid(?string $json): ?array
    {
        if (!$json) {
            return null;
        }
        
        $data = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $data : null;
    }
    
    private function parseChangesSummary(string $summary): ?array
    {
        // Try to parse as JSON first
        $parsed = $this->parseJsonIfValid($summary);
        if ($parsed !== null) {
            return $parsed;
        }
        
        // If not JSON, try to extract key-value pairs
        if (strpos($summary, ':') !== false) {
            $lines = explode(', ', $summary);
            $changes = [];
            
            foreach ($lines as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $changes[$key] = $value;
                }
            }
            
            return !empty($changes) ? $changes : null;
        }
        
        return null;
    }
    
    public function wasPerformedBy(int $adminId): bool
    {
        return $this->adminId === $adminId;
    }
    
    public function wasSystemAction(): bool
    {
        return $this->adminId === null || $this->adminId === 0;
    }
    
    public function isRecent(int $minutes = 15): bool
    {
        try {
            $performedAt = DateTimeImmutable::createFromFormat($this->dateFormat, $this->performedAt);
            if (!$performedAt) {
                return false;
            }
            
            $now = new DateTimeImmutable();
            $diff = $now->getTimestamp() - $performedAt->getTimestamp();
            
            return $diff <= ($minutes * 60);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function hasChanges(): bool
    {
        return !empty($this->oldValues) || !empty($this->newValues) || !empty($this->changesSummary);
    }
    
    public function toTimelineArray(): array
    {
        return [
            'id' => $this->id,
            'action_type' => $this->actionType,
            'action_type_label' => $this->actionTypeLabel,
            'action_icon' => $this->actionIcon,
            'action_color_class' => $this->actionColorClass,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'entity_reference' => $this->entityReference,
            'performed_at' => $this->performedAt,
            'time_ago' => $this->timeAgo,
            'admin_display_name' => $this->adminDisplayName,
            'admin_username' => $this->adminUsername,
            'admin_initials' => $this->adminInitials,
            'truncated_summary' => $this->truncatedSummary,
            'is_recent' => $this->isRecent(),
            'has_changes' => $this->hasChanges(),
        ];
    }
    
    public function toDetailArray(): array
    {
        $data = $this->toTimelineArray();
        
        // Add detail fields
        $data['admin_id'] = $this->adminId;
        $data['admin_name'] = $this->adminName;
        $data['ip_address'] = $this->ipAddress;
        $data['user_agent'] = $this->userAgent;
        $data['changes_summary'] = $this->changesSummary;
        $data['was_system_action'] = $this->wasSystemAction();
        
        // Add parsed values if available
        if ($this->includeValues) {
            if ($this->oldValuesArray !== null) {
                $data['old_values'] = $this->oldValuesArray;
            } else {
                $data['old_values'] = $this->oldValues;
            }
            
            if ($this->newValuesArray !== null) {
                $data['new_values'] = $this->newValuesArray;
            } else {
                $data['new_values'] = $this->newValues;
            }
            
            if ($this->parsedChanges !== null) {
                $data['parsed_changes'] = $this->parsedChanges;
            }
        }
        
        return $data;
    }
    
    public function toArray(): array
    {
        return $this->toDetailArray();
    }
    
    public function getCacheKey(string $prefix = 'audit_log_response_'): string
    {
        $parts = [
            $prefix,
            $this->id,
            $this->includeValues ? 'with_values' : 'without_values',
            substr(md5($this->performedAt), 0, 8),
        ];
        
        return implode('_', array_filter($parts));
    }
    
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'action_type' => $this->actionType,
            'action_type_label' => $this->actionTypeLabel,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'performed_at' => $this->performedAt,
            'admin_display_name' => $this->adminDisplayName,
            'truncated_summary' => $this->truncatedSummary,
        ];
    }
    
    // Getters for individual properties
    public function getId(): ?int { return $this->id; }
    public function getAdminId(): ?int { return $this->adminId; }
    public function getActionType(): string { return $this->actionType; }
    public function getEntityType(): string { return $this->entityType; }
    public function getEntityId(): int { return $this->entityId; }
    public function getOldValues(): ?string { return $this->oldValues; }
    public function getNewValues(): ?string { return $this->newValues; }
    public function getChangesSummary(): ?string { return $this->changesSummary; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getPerformedAt(): string { return $this->performedAt; }
    public function getAdminName(): ?string { return $this->adminName; }
    public function getAdminUsername(): ?string { return $this->adminUsername; }
    public function getAdminInitials(): ?string { return $this->adminInitials; }
    public function getAdminDisplayName(): ?string { return $this->adminDisplayName; }
    public function getActionTypeLabel(): ?string { return $this->actionTypeLabel; }
    public function getActionIcon(): ?string { return $this->actionIcon; }
    public function getActionColorClass(): ?string { return $this->actionColorClass; }
    public function getEntityReference(): ?string { return $this->entityReference; }
    public function getTimeAgo(): ?string { return $this->timeAgo; }
    public function getTruncatedSummary(): ?string { return $this->truncatedSummary; }
    public function getOldValuesArray(): ?array { return $this->oldValuesArray; }
    public function getNewValuesArray(): ?array { return $this->newValuesArray; }
    public function getParsedChanges(): ?array { return $this->parsedChanges; }
    
    public function withConfig(array $config): self
    {
        $clone = clone $this;
        $clone->applyConfiguration($config);
        return $clone;
    }
    
    public function withAdditionalData(array $additionalData): self
    {
        $clone = clone $this;
        
        foreach ($additionalData as $key => $value) {
            if (property_exists($clone, $key)) {
                $clone->$key = $value;
            }
        }
        
        // Recalculate derived fields if needed
        if (isset($additionalData['performed_at'])) {
            $clone->timeAgo = $clone->calculateTimeAgo($clone->performedAt);
        }
        
        if (isset($additionalData['changes_summary'])) {
            $clone->truncatedSummary = $clone->truncateSummary($clone->changesSummary, $clone->summaryLength);
            if ($clone->includeValues) {
                $clone->parsedChanges = $clone->parseChangesSummary($clone->changesSummary);
            }
        }
        
        return $clone;
    }
    
    public function toJson(bool $pretty = false): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }
        
        return json_encode($this->toArray(), $options);
    }
}