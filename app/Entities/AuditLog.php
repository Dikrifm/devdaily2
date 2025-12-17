<?php

namespace App\Entities;

use DateTimeImmutable;

/**
 * Audit Log Entity
 * 
 * Represents an immutable audit log entry for tracking all administrative actions.
 * This entity does NOT extend BaseEntity because audit logs have a different structure
 * and are immutable (cannot be updated or deleted).
 * 
 * @package App\Entities
 */
class AuditLog
{
    /**
     * Log entry ID
     * 
     * @var int|null
     */
    private ?int $id = null;

    /**
     * Admin who performed the action (nullable for system actions)
     * 
     * @var int|null
     */
    private ?int $admin_id = null;

    /**
     * Type of action performed
     * 
     * @var string
     */
    private string $action_type;

    /**
     * Type of entity affected
     * 
     * @var string
     */
    private string $entity_type;

    /**
     * ID of entity affected
     * 
     * @var int
     */
    private int $entity_id;

    /**
     * JSON snapshot of values before change
     * 
     * @var string|null
     */
    private ?string $old_values = null;

    /**
     * JSON snapshot of values after change
     * 
     * @var string|null
     */
    private ?string $new_values = null;

    /**
     * Human-readable summary of changes
     * 
     * @var string|null
     */
    private ?string $changes_summary = null;

    /**
     * IP address of the requester
     * 
     * @var string|null
     */
    private ?string $ip_address = null;

    /**
     * User agent string of the requester
     * 
     * @var string|null
     */
    private ?string $user_agent = null;

    /**
     * When the action was performed
     * 
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $performed_at = null;

    /**
     * AuditLog constructor
     * 
     * @param string $action_type
     * @param string $entity_type
     * @param int $entity_id
     */
    public function __construct(string $action_type, string $entity_type, int $entity_id)
    {
        $this->action_type = $action_type;
        $this->entity_type = $entity_type;
        $this->entity_id = $entity_id;
        $this->performed_at = new DateTimeImmutable();
    }

    // ==================== GETTER METHODS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAdminId(): ?int
    {
        return $this->admin_id;
    }

    public function getActionType(): string
    {
        return $this->action_type;
    }

    public function getEntityType(): string
    {
        return $this->entity_type;
    }

    public function getEntityId(): int
    {
        return $this->entity_id;
    }

    public function getOldValues(): ?string
    {
        return $this->old_values;
    }

    public function getNewValues(): ?string
    {
        return $this->new_values;
    }

    public function getChangesSummary(): ?string
    {
        return $this->changes_summary;
    }

    public function getIpAddress(): ?string
    {
        return $this->ip_address;
    }

    public function getUserAgent(): ?string
    {
        return $this->user_agent;
    }

    public function getPerformedAt(): ?DateTimeImmutable
    {
        return $this->performed_at;
    }

    public function getAdminName(): ?string
    {
        return $this->admin_name;
    }

    public function getAdminUsername(): ?string
    {
        return $this->admin_username;
    }

    // ==================== SETTER METHODS ====================

    public function setId(?int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function setAdminId(?int $admin_id): self
    {
        $this->admin_id = $admin_id;
        return $this;
    }

    public function setActionType(string $action_type): self
    {
        $this->action_type = $action_type;
        return $this;
    }

    public function setEntityType(string $entity_type): self
    {
        $this->entity_type = $entity_type;
        return $this;
    }

    public function setEntityId(int $entity_id): self
    {
        $this->entity_id = $entity_id;
        return $this;
    }

    public function setOldValues(?string $old_values): self
    {
        $this->old_values = $old_values;
        return $this;
    }

    public function setNewValues(?string $new_values): self
    {
        $this->new_values = $new_values;
        return $this;
    }

    public function setChangesSummary(?string $changes_summary): self
    {
        $this->changes_summary = $changes_summary;
        return $this;
    }

    public function setIpAddress(?string $ip_address): self
    {
        $this->ip_address = $ip_address;
        return $this;
    }

    public function setUserAgent(?string $user_agent): self
    {
        $this->user_agent = $user_agent;
        return $this;
    }

    public function setPerformedAt($performed_at): self
    {
        if (is_string($performed_at)) {
            $this->performed_at = new DateTimeImmutable($performed_at);
        } elseif ($performed_at instanceof DateTimeImmutable) {
            $this->performed_at = $performed_at;
        } else {
            $this->performed_at = null;
        }
        return $this;
    }

    public function setAdminName(?string $admin_name): self
    {
        $this->admin_name = $admin_name;
        return $this;
    }

    public function setAdminUsername(?string $admin_username): self
    {
        $this->admin_username = $admin_username;
        return $this;
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    /**
     * Check if this log entry has old values
     * 
     * @return bool
     */
    public function hasOldValues(): bool
    {
        return !empty($this->old_values);
    }

    /**
     * Check if this log entry has new values
     * 
     * @return bool
     */
    public function hasNewValues(): bool
    {
        return !empty($this->new_values);
    }

    /**
     * Get old values as array
     * 
     * @return array|null
     */
    public function getOldValuesArray(): ?array
    {
        if (!$this->hasOldValues()) {
            return null;
        }

        return json_decode($this->old_values, true);
    }

    /**
     * Get new values as array
     * 
     * @return array|null
     */
    public function getNewValuesArray(): ?array
    {
        if (!$this->hasNewValues()) {
            return null;
        }

        return json_decode($this->new_values, true);
    }

    /**
     * Check if this log was performed by a specific admin
     * 
     * @param int $adminId
     * @return bool
     */
    public function wasPerformedBy(int $adminId): bool
    {
        return $this->admin_id === $adminId;
    }

    /**
     * Check if this log was performed by the system (no admin)
     * 
     * @return bool
     */
    public function wasSystemAction(): bool
    {
        return $this->admin_id === null;
    }

    /**
     * Get formatted performed at date
     * 
     * @param string $format
     * @return string
     */
    public function getFormattedPerformedAt(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->performed_at ? $this->performed_at->format($format) : '';
    }

    /**
     * Get human-readable action type
     * 
     * @return string
     */
    public function getActionTypeLabel(): string
    {
        $labels = [
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

        return $labels[$this->action_type] ?? ucfirst(str_replace('_', ' ', $this->action_type));
    }

    /**
     * Get action icon based on action type
     * 
     * @return string
     */
    public function getActionIcon(): string
    {
        $icons = [
            'create'          => 'fas fa-plus-circle',
            'update'          => 'fas fa-edit',
            'delete'          => 'fas fa-trash',
            'verify'          => 'fas fa-check-circle',
            'publish'         => 'fas fa-globe',
            'archive'         => 'fas fa-archive',
            'restore'         => 'fas fa-undo',
            'login'           => 'fas fa-sign-in-alt',
            'logout'          => 'fas fa-sign-out-alt',
            'password_change' => 'fas fa-key',
            'role_change'     => 'fas fa-user-tag',
            'status_change'   => 'fas fa-exchange-alt',
            'bulk_operation'  => 'fas fa-layer-group',
            'import'          => 'fas fa-file-import',
            'export'          => 'fas fa-file-export',
            'system'          => 'fas fa-robot'
        ];

        return $icons[$this->action_type] ?? 'fas fa-history';
    }

    /**
     * Get Tailwind CSS color class based on action type
     * 
     * @return string
     */
    public function getActionColorClass(): string
    {
        $colors = [
            'create'          => 'bg-blue-100 text-blue-800',
            'update'          => 'bg-yellow-100 text-yellow-800',
            'delete'          => 'bg-red-100 text-red-800',
            'verify'          => 'bg-green-100 text-green-800',
            'publish'         => 'bg-purple-100 text-purple-800',
            'archive'         => 'bg-gray-100 text-gray-800',
            'restore'         => 'bg-indigo-100 text-indigo-800',
            'login'           => 'bg-emerald-100 text-emerald-800',
            'logout'          => 'bg-orange-100 text-orange-800',
            'password_change' => 'bg-cyan-100 text-cyan-800',
            'role_change'     => 'bg-pink-100 text-pink-800',
            'status_change'   => 'bg-teal-100 text-teal-800',
            'bulk_operation'  => 'bg-amber-100 text-amber-800',
            'import'          => 'bg-lime-100 text-lime-800',
            'export'          => 'bg-fuchsia-100 text-fuchsia-800',
            'system'          => 'bg-slate-100 text-slate-800'
        ];

        return $colors[$this->action_type] ?? 'bg-gray-100 text-gray-800';
    }

    /**
     * Get admin display name (falls back to username or "System")
     * 
     * @return string
     */
    public function getAdminDisplayName(): string
    {
        if ($this->admin_name) {
            return $this->admin_name;
        }

        if ($this->admin_username) {
            return $this->admin_username;
        }

        return $this->wasSystemAction() ? 'System' : 'Unknown Admin';
    }

    /**
     * Get time elapsed since performed at
     * 
     * @return string
     */
    public function getTimeAgo(): string
    {
        if (!$this->performed_at) {
            return 'Unknown time';
        }

        $now = new DateTimeImmutable();
        $diff = $now->diff($this->performed_at);

        if ($diff->y > 0) {
            return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
        }

        if ($diff->m > 0) {
            return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
        }

        if ($diff->d > 0) {
            return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
        }

        if ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        }

        if ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }

        return 'Just now';
    }

    /**
     * Check if this log is recent (within last 24 hours)
     * 
     * @return bool
     */
    public function isRecent(): bool
    {
        if (!$this->performed_at) {
            return false;
        }

        $now = new DateTimeImmutable();
        $diff = $now->diff($this->performed_at);

        return $diff->days === 0 && $diff->h < 24;
    }

    /**
     * Get entity reference string
     * 
     * @return string
     */
    public function getEntityReference(): string
    {
        return $this->entity_type . ' #' . $this->entity_id;
    }

    /**
     * Check if changes summary contains specific text
     * 
     * @param string $search
     * @return bool
     */
    public function summaryContains(string $search): bool
    {
        if (!$this->changes_summary) {
            return false;
        }

        return stripos($this->changes_summary, $search) !== false;
    }

    /**
     * Get truncated changes summary for display
     * 
     * @param int $length
     * @return string
     */
    public function getTruncatedSummary(int $length = 100): string
    {
        if (!$this->changes_summary) {
            return '';
        }

        if (strlen($this->changes_summary) <= $length) {
            return $this->changes_summary;
        }

        return substr($this->changes_summary, 0, $length - 3) . '...';
    }

    // ==================== VALIDATION METHODS ====================

    /**
     * Validate audit log data
     * 
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $errors = [];

        // Required fields
        if (empty($this->action_type)) {
            $errors[] = 'Action type is required';
        }

        if (empty($this->entity_type)) {
            $errors[] = 'Entity type is required';
        }

        if (empty($this->entity_id)) {
            $errors[] = 'Entity ID is required';
        }

        // Validate JSON fields
        if ($this->old_values && !$this->isValidJson($this->old_values)) {
            $errors[] = 'Old values must be valid JSON';
        }

        if ($this->new_values && !$this->isValidJson($this->new_values)) {
            $errors[] = 'New values must be valid JSON';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Check if string is valid JSON
     * 
     * @param string $json
     * @return bool
     */
    private function isValidJson(string $json): bool
    {
        json_decode($json);
        return json_last_error() === JSON_ERROR_NONE;
    }

    // ==================== SERIALIZATION METHODS ====================

    /**
     * Convert entity to array representation
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'admin_id' => $this->getAdminId(),
            'action_type' => $this->getActionType(),
            'action_type_label' => $this->getActionTypeLabel(),
            'action_icon' => $this->getActionIcon(),
            'action_color_class' => $this->getActionColorClass(),
            'entity_type' => $this->getEntityType(),
            'entity_id' => $this->getEntityId(),
            'entity_reference' => $this->getEntityReference(),
            'old_values' => $this->getOldValues(),
            'old_values_array' => $this->getOldValuesArray(),
            'new_values' => $this->getNewValues(),
            'new_values_array' => $this->getNewValuesArray(),
            'changes_summary' => $this->getChangesSummary(),
            'truncated_summary' => $this->getTruncatedSummary(),
            'ip_address' => $this->getIpAddress(),
            'user_agent' => $this->getUserAgent(),
            'performed_at' => $this->getPerformedAt(),
            'formatted_performed_at' => $this->getFormattedPerformedAt(),
            'time_ago' => $this->getTimeAgo(),
            'admin_name' => $this->getAdminName(),
            'admin_username' => $this->getAdminUsername(),
            'admin_display_name' => $this->getAdminDisplayName(),
            'was_system_action' => $this->wasSystemAction(),
            'is_recent' => $this->isRecent(),
            'has_old_values' => $this->hasOldValues(),
            'has_new_values' => $this->hasNewValues(),
        ];
    }

    /**
     * Create entity from array data
     * 
     * @param array $data
     * @return static
     */
    public static function fromArray(array $data): static
    {
        $auditLog = new self(
            $data['action_type'] ?? '',
            $data['entity_type'] ?? '',
            $data['entity_id'] ?? 0
        );

        if (isset($data['id'])) {
            $auditLog->setId($data['id']);
        }

        if (isset($data['admin_id'])) {
            $auditLog->setAdminId($data['admin_id']);
        }

        if (isset($data['old_values'])) {
            $auditLog->setOldValues($data['old_values']);
        }

        if (isset($data['new_values'])) {
            $auditLog->setNewValues($data['new_values']);
        }

        if (isset($data['changes_summary'])) {
            $auditLog->setChangesSummary($data['changes_summary']);
        }

        if (isset($data['ip_address'])) {
            $auditLog->setIpAddress($data['ip_address']);
        }

        if (isset($data['user_agent'])) {
            $auditLog->setUserAgent($data['user_agent']);
        }

        if (isset($data['performed_at'])) {
            $auditLog->setPerformedAt($data['performed_at']);
        }

        if (isset($data['admin_name'])) {
            $auditLog->setAdminName($data['admin_name']);
        }

        if (isset($data['admin_username'])) {
            $auditLog->setAdminUsername($data['admin_username']);
        }

        return $auditLog;
    }

    /**
     * Create a sample audit log for testing/demo
     * 
     * @return static
     */
    public static function createSample(): static
    {
        $auditLog = new self('update', 'Product', 123);
        $auditLog->setAdminId(1);
        $auditLog->setAdminName('John Doe');
        $auditLog->setAdminUsername('johndoe');
        $auditLog->setChangesSummary('Updated product name from "Old Product" to "New Product"');
        $auditLog->setIpAddress('192.168.1.100');
        $auditLog->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $auditLog->setPerformedAt(new DateTimeImmutable('2024-01-15 14:30:00'));
        
        return $auditLog;
    }
}