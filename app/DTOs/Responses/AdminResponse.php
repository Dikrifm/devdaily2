<?php

namespace App\DTOs\Responses;

use App\Entities\Admin;
use DateTimeImmutable;
use InvalidArgumentException;

class AdminResponse
{
    private int $id;
    private string $username;
    private string $email;
    private string $name;
    private string $role;
    private bool $active;
    private ?string $lastLogin;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $lastActivity;
    
    // Optional fields (only included when requested)
    private ?int $loginAttempts = null;
    private ?string $roleLabel = null;
    private ?string $roleColorClass = null;
    private ?string $statusLabel = null;
    private ?string $statusColorClass = null;
    private ?string $initials = null;
    private ?array $permissions = null;
    private ?array $activityLogs = null;
    private ?array $loginHistory = null;
    private ?array $sessions = null;
    
    private bool $includeSensitive = false;
    private bool $includeDetails = false;
    private string $dateFormat = 'Y-m-d H:i:s';
    
    private function __construct()
    {
        // Private constructor to enforce use of factory methods
    }
    
    public static function fromEntity(Admin $admin, array $options = []): self
    {
        $response = new self();
        $response->applyConfiguration($options);
        $response->populateFromEntity($admin);
        return $response;
    }
    
    public static function fromArray(array $data, array $options = []): self
    {
        $response = new self();
        $response->applyConfiguration($options);
        $response->populateFromArray($data);
        return $response;
    }
    
    public static function collection(array $admins, array $options = []): array
    {
        $collection = [];
        foreach ($admins as $admin) {
            if ($admin instanceof Admin) {
                $collection[] = self::fromEntity($admin, $options);
            } elseif (is_array($admin)) {
                $collection[] = self::fromArray($admin, $options);
            } else {
                throw new InvalidArgumentException(
                    'Each item must be an instance of Admin or an array'
                );
            }
        }
        return $collection;
    }
    
    private function applyConfiguration(array $config): void
    {
        $this->includeSensitive = $config['include_sensitive'] ?? false;
        $this->includeDetails = $config['include_details'] ?? false;
        $this->dateFormat = $config['date_format'] ?? 'Y-m-d H:i:s';
        
        // If including details, automatically include some optional fields
        if ($this->includeDetails) {
            $this->loginAttempts = $this->loginAttempts ?? 0;
        }
    }
    
    private function populateFromEntity(Admin $admin): void
    {
        $this->id = $admin->getId();
        $this->username = $admin->getUsername();
        $this->email = $admin->getEmail();
        $this->name = $admin->getName();
        $this->role = $admin->getRole();
        $this->active = $admin->isActive();
        
        // Format timestamps
        $this->lastLogin = $this->formatTimestamp($admin->getLastLogin());
        $this->createdAt = $this->formatTimestamp($admin->getCreatedAt());
        $this->updatedAt = $this->formatTimestamp($admin->getUpdatedAt());
        
        // Calculate last activity (most recent of lastLogin or updatedAt)
        $this->lastActivity = $this->calculateLastActivity(
            $admin->getLastLogin(),
            $admin->getUpdatedAt()
        );
        
        // Include optional fields if configured
        if ($this->includeDetails) {
            $this->loginAttempts = $admin->getLoginAttempts();
        }
        
        // Always include calculated fields
        $this->initials = $admin->getInitials();
        $this->roleLabel = $admin->getRoleLabel();
        $this->roleColorClass = $admin->getRoleColorClass();
        $this->statusLabel = $admin->getStatusLabel();
        $this->statusColorClass = $admin->getStatusColorClass();
    }
    
    private function populateFromArray(array $data): void
    {
        $this->id = (int) ($data['id'] ?? 0);
        $this->username = (string) ($data['username'] ?? '');
        $this->email = (string) ($data['email'] ?? '');
        $this->name = (string) ($data['name'] ?? '');
        $this->role = (string) ($data['role'] ?? 'admin');
        $this->active = (bool) ($data['active'] ?? true);
        
        $this->lastLogin = $this->formatTimestampFromString($data['last_login'] ?? null);
        $this->createdAt = $this->formatTimestampFromString($data['created_at'] ?? null);
        $this->updatedAt = $this->formatTimestampFromString($data['updated_at'] ?? null);
        
        // Calculate last activity
        $lastLogin = !empty($data['last_login']) ? 
            DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['last_login']) : null;
        $updatedAt = !empty($data['updated_at']) ?
            DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['updated_at']) : null;
        $this->lastActivity = $this->calculateLastActivity($lastLogin, $updatedAt);
        
        // Optional fields from data
        if ($this->includeDetails || isset($data['login_attempts'])) {
            $this->loginAttempts = (int) ($data['login_attempts'] ?? 0);
        }
        
        // Additional data that might be passed
        $this->permissions = $data['permissions'] ?? null;
        $this->activityLogs = $data['activity_logs'] ?? null;
        $this->loginHistory = $data['login_history'] ?? null;
        $this->sessions = $data['sessions'] ?? null;
        
        // Calculate fields if not provided
        if (empty($this->initials)) {
            $this->initials = $this->generateInitials($this->name);
        }
        
        if (empty($this->roleLabel)) {
            $this->roleLabel = $this->generateRoleLabel($this->role);
        }
        
        if (empty($this->roleColorClass)) {
            $this->roleColorClass = $this->generateRoleColorClass($this->role);
        }
        
        if (empty($this->statusLabel)) {
            $this->statusLabel = $this->active ? 'Active' : 'Inactive';
        }
        
        if (empty($this->statusColorClass)) {
            $this->statusColorClass = $this->active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
        }
    }
    
    private function formatTimestamp(?DateTimeImmutable $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        return $timestamp->format($this->dateFormat);
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
    
    private function calculateLastActivity(
        ?DateTimeImmutable $lastLogin,
        ?DateTimeImmutable $updatedAt
    ): ?string {
        $timestamps = array_filter([$lastLogin, $updatedAt]);
        
        if (empty($timestamps)) {
            return null;
        }
        
        // Get the most recent timestamp
        $latest = max($timestamps);
        return $this->formatTimestamp($latest);
    }
    
    private function generateInitials(string $name): string
    {
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
        
        return $initials ?: '??';
    }
    
    private function generateRoleLabel(string $role): string
    {
        $labels = [
            'super_admin' => 'Super Admin',
            'admin' => 'Admin',
            'editor' => 'Editor',
            'viewer' => 'Viewer',
        ];
        
        return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
    
    private function generateRoleColorClass(string $role): string
    {
        $colors = [
            'super_admin' => 'bg-purple-100 text-purple-800',
            'admin' => 'bg-blue-100 text-blue-800',
            'editor' => 'bg-green-100 text-green-800',
            'viewer' => 'bg-gray-100 text-gray-800',
        ];
        
        return $colors[$role] ?? 'bg-gray-100 text-gray-800';
    }
    
    public function isRecentlyActive(int $minutes = 15): bool
    {
        if (!$this->lastActivity) {
            return false;
        }
        
        try {
            $lastActivity = DateTimeImmutable::createFromFormat($this->dateFormat, $this->lastActivity);
            $now = new DateTimeImmutable();
            $diff = $now->getTimestamp() - $lastActivity->getTimestamp();
            
            return $diff <= ($minutes * 60);
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'name' => $this->name,
            'role' => $this->role,
            'active' => $this->active,
            'last_login' => $this->lastLogin,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'last_activity' => $this->lastActivity,
            'initials' => $this->initials,
            'role_label' => $this->roleLabel,
            'role_color_class' => $this->roleColorClass,
            'status_label' => $this->statusLabel,
            'status_color_class' => $this->statusColorClass,
            'is_recently_active' => $this->isRecentlyActive(),
        ];
    }
    
    public function toAdminArray(): array
    {
        $data = $this->toPublicArray();
        
        // Add admin-only fields
        if ($this->includeDetails || $this->includeSensitive) {
            $data['login_attempts'] = $this->loginAttempts;
        }
        
        if ($this->permissions !== null) {
            $data['permissions'] = $this->permissions;
        }
        
        if ($this->activityLogs !== null) {
            $data['activity_logs'] = $this->activityLogs;
        }
        
        if ($this->loginHistory !== null) {
            $data['login_history'] = $this->loginHistory;
        }
        
        if ($this->sessions !== null) {
            $data['sessions'] = $this->sessions;
        }
        
        return $data;
    }
    
    public function toArray(): array
    {
        return $this->toAdminArray();
    }
    
    public function getCacheKey(string $prefix = 'admin_response_'): string
    {
        $parts = [
            $prefix,
            $this->id,
            $this->includeSensitive ? 'sensitive' : 'public',
            $this->includeDetails ? 'detailed' : 'basic',
            substr(md5($this->updatedAt ?? ''), 0, 8),
        ];
        
        return implode('_', array_filter($parts));
    }
    
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'active' => $this->active,
            'initials' => $this->initials,
        ];
    }
    
    // Getters for individual properties
    public function getId(): int { return $this->id; }
    public function getUsername(): string { return $this->username; }
    public function getEmail(): string { return $this->email; }
    public function getName(): string { return $this->name; }
    public function getRole(): string { return $this->role; }
    public function isActive(): bool { return $this->active; }
    public function getLastLogin(): ?string { return $this->lastLogin; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getLastActivity(): ?string { return $this->lastActivity; }
    public function getInitials(): ?string { return $this->initials; }
    public function getRoleLabel(): ?string { return $this->roleLabel; }
    public function getRoleColorClass(): ?string { return $this->roleColorClass; }
    public function getStatusLabel(): ?string { return $this->statusLabel; }
    public function getStatusColorClass(): ?string { return $this->statusColorClass; }
    public function getLoginAttempts(): ?int { return $this->loginAttempts; }
    public function getPermissions(): ?array { return $this->permissions; }
    public function getActivityLogs(): ?array { return $this->activityLogs; }
    public function getLoginHistory(): ?array { return $this->loginHistory; }
    public function getSessions(): ?array { return $this->sessions; }
    
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