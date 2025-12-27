<?php

namespace App\Entities;

use DateTimeImmutable;

/**
 * Admin Entity
 *
 * Represents an administrator in the system with authentication capabilities.
 * Core entity for admin management, authentication, and role-based access control.
 *
 * @package App\Entities
 */
final class Admin extends BaseEntity
{
    /**
     * Unique username for login
     */
    private string $username;

    /**
     * Email address (unique)
     */
    private string $email;

    /**
     * Hashed password (bcrypt)
     */
    private string $password_hash = '';

    /**
     * Admin's full name
     */
    private string $name;

    /**
     * Role: admin or super_admin
     */
    private string $role = 'admin';

    /**
     * Whether admin account is active
     */
    private bool $active = true;

    /**
     * Last login timestamp
     */
    private ?DateTimeImmutable $last_login = null;

    /**
     * Failed login attempts count
     */
    private int $login_attempts = 0;

    /**
     * Plain text password (temporary, not persisted)
     */
    private ?string $password = null;

    /**
     * Admin constructor
     */
    public function __construct(string $username, string $email, string $name)
    {
        $this->username = $username;
        $this->email = $email;
        $this->name = $name;
        $this->initialize();
    }

    // ==================== GETTER METHODS ====================

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->password_hash;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getLastLogin(): ?DateTimeImmutable
    {
        return $this->last_login;
    }

    public function getLoginAttempts(): int
    {
        return $this->login_attempts;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    // ==================== SETTER METHODS ====================

    public function setUsername(string $username): self
    {
        if ($this->username === $username) {
            return $this;
        }

        $this->trackChange('username', $this->username, $username);
        $this->username = $username;
        $this->markAsUpdated();
        return $this;
    }

    public function setEmail(string $email): self
    {
        if ($this->email === $email) {
            return $this;
        }

        $this->trackChange('email', $this->email, $email);
        $this->email = $email;
        $this->markAsUpdated();
        return $this;
    }

    public function setPasswordHash(string $password_hash): self
    {
        // Only track change if hash actually changes
        if ($this->password_hash !== $password_hash) {
            $this->trackChange('password_hash', '[HIDDEN]', '[HIDDEN]');
            $this->password_hash = $password_hash;
            $this->markAsUpdated();
        }
        return $this;
    }

    public function setName(string $name): self
    {
        if ($this->name === $name) {
            return $this;
        }

        $this->trackChange('name', $this->name, $name);
        $this->name = $name;
        $this->markAsUpdated();
        return $this;
    }

    public function setRole(string $role): self
    {
        if (!in_array($role, ['admin', 'super_admin'])) {
            throw new \InvalidArgumentException('Role must be either "admin" or "super_admin"');
        }

        if ($this->role === $role) {
            return $this;
        }

        $this->trackChange('role', $this->role, $role);
        $this->role = $role;
        $this->markAsUpdated();
        return $this;
    }

    public function setActive(bool $active): self
    {
        if ($this->active === $active) {
            return $this;
        }

        $this->trackChange('active', $this->active, $active);
        $this->active = $active;
        $this->markAsUpdated();
        return $this;
    }
  
    public function setLastLogin(\DateTimeInterface|string|null $last_login): self
    {
        if (is_string($last_login)) {
            $last_login = new DateTimeImmutable($last_login);
        }

        if ($last_login instanceof DateTimeImmutable) {
            if ($this->last_login && $this->last_login->format('Y-m-d H:i:s') === $last_login->format('Y-m-d H:i:s')) {
                return $this;
            }

            $oldValue = $this->last_login instanceof \DateTimeImmutable ? $this->last_login->format('Y-m-d H:i:s') : null;
            $newValue = $last_login->format('Y-m-d H:i:s');

            $this->trackChange('last_login', $oldValue, $newValue);
            $this->last_login = $last_login;
            // Don't mark as updated for login timestamps
        }

        return $this;
    }

    public function setLoginAttempts(int $login_attempts): self
    {
        if ($this->login_attempts === $login_attempts) {
            return $this;
        }

        $this->trackChange('login_attempts', $this->login_attempts, $login_attempts);
        $this->login_attempts = $login_attempts;
        // Don't mark as updated for login attempts
        return $this;
    }

    public function setPassword(?string $password): self
    {
        $this->password = $password;
        return $this;
    }

    // ==================== BUSINESS LOGIC METHODS ====================
    /**
     * Check if admin is a super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    /**
     * Check if admin is a regular admin
     */
    public function isRegularAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Activate admin account
     */
    public function activate(): self
    {
        return $this->setActive(true);
    }

    /**
     * Deactivate admin account
     */
    public function deactivate(): self
    {
        return $this->setActive(false);
    }

    /**
     * Promote to super admin
     */
    public function promoteToSuperAdmin(): self
    {
        return $this->setRole('super_admin');
    }

    /**
     * Demote to regular admin
     */
    public function demoteToAdmin(): self
    {
        return $this->setRole('admin');
    }

    public function recordLogin(): self
    {
        $this->setLastLogin(new DateTimeImmutable());
        $this->setLoginAttempts(0);
        return $this;
    }

    /*
     * Record failed login attempt
     */
    public function recordFailedLogin(): self
    {
        $this->setLoginAttempts($this->login_attempts + 1);
        return $this;
    }

    /***
     * Reset login attempts
     */
    public function resetLoginAttempts(): self
    {
        return $this->setLoginAttempts(0);
    }

    /**
     * Check if account is locked due to too many failed attempts
     *
     * @param int $maxAttempts Maximum allowed attempts before lockout
     */
    public function isLocked(int $maxAttempts = 5): bool
    {
        return $this->login_attempts >= $maxAttempts;
    }

    /**
     * Check if password needs rehash
     */
    public function passwordNeedsRehash(): bool
    {
        return password_needs_rehash($this->password_hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /**
     * Verify password against stored hash
     */
    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password_hash);
    }

    /**
    * @param array<string, mixed> $options  <-- Tambahkan ini
    */
    public function setPasswordWithHash(string $password, array $options = ['cost' => 12]): self
    {
        $this->password = $password;
        $this->setPasswordHash(password_hash($password, PASSWORD_BCRYPT, $options));
        return $this;
    }

    /**
     * Check if admin can be archived
     * Business rule: Cannot archive own account, cannot archive last super admin
     *
     * @param int $currentAdminId The ID of admin performing the action
     * @param int $superAdminCount Total number of active super admins
     * @return array{can: bool, reason: string}
     */
    public function canBeArchivedBy(int $currentAdminId, int $superAdminCount = 1): array
    {
        // Cannot archive self
        if ($this->getId() === $currentAdminId) {
            return ['can' => false, 'reason' => 'Cannot archive your own account'];
        }

        // Cannot archive if not active
        if (!$this->active) {
            return ['can' => false, 'reason' => 'Account is already deactivated'];
        }

        // If this is a super admin, check if it's the last one
        if ($this->isSuperAdmin() && $superAdminCount <= 1) {
            return ['can' => false, 'reason' => 'Cannot archive the last super admin'];
        }

        return ['can' => true, 'reason' => ''];
    }

    /**
     * Check if admin can be deleted
     * Business rule: Cannot delete own account, cannot delete last super admin
     *
     * @return array{can: bool, reason: string}
     */
    public function canBeDeletedBy(int $currentAdminId, int $superAdminCount = 1): array
    {
        $archiveCheck = $this->canBeArchivedBy($currentAdminId, $superAdminCount);

        if (!$archiveCheck['can']) {
            return $archiveCheck;
        }

        // Additional checks for deletion (beyond archiving) could go here
        // For example: check if admin has created any content that needs reassignment

        return ['can' => true, 'reason' => ''];
    }

    /**
     * Get admin initials for avatar
     */
    public function getInitials(): string
    {
        $names = explode(' ', $this->name);
        $initials = '';

        foreach ($names as $name) {
            if ($name !== '' && $name !== '0') {
                $initials .= strtoupper(substr($name, 0, 1));
                if (strlen($initials) >= 2) {
                    break;
                }
            }
        }

        return $initials ?: strtoupper(substr($this->username, 0, 2));
    }

    /**
     * Get Tailwind CSS color class based on role
     */
    public function getRoleColorClass(): string
    {
        return $this->isSuperAdmin()
            ? 'bg-purple-100 text-purple-800'
            : 'bg-blue-100 text-blue-800';
    }

    /**
     * Get role display label
     */
    public function getRoleLabel(): string
    {
        return $this->isSuperAdmin() ? 'Super Admin' : 'Admin';
    }

    /**
     * Get status display label
     */
    public function getStatusLabel(): string
    {
        return $this->active ? 'Active' : 'Inactive';
    }

    /**
     * Get status color class
     */
    public function getStatusColorClass(): string
    {
        return $this->active
            ? 'bg-green-100 text-green-800'
            : 'bg-red-100 text-red-800';
    }

    /**
     * Format last login for display
     */
    public function getFormattedLastLogin(): string
    {
        if (!$this->last_login instanceof \DateTimeImmutable) {
            return 'Never logged in';
        }

        $now = new DateTimeImmutable();
        $diff = $now->diff($this->last_login);

        if ($diff->days > 30) {
            return $this->last_login->format('Y-m-d');
        } elseif ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    }

    /**
     * Check if admin has logged in recently (within 24 hours)
     */
    public function isRecentlyActive(): bool
    {
        if (!$this->last_login instanceof \DateTimeImmutable) {
            return false;
        }

        $now = new DateTimeImmutable();
        $diff = $now->diff($this->last_login);

        return $diff->days === 0 && $diff->h < 24;
    }

    /**
     * Validate admin entity
     * Override parent validation with admin-specific rules
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $parentValidation = parent::validate();
        $errors = $parentValidation['errors'];

        // Admin-specific validation
        if ($this->username === '' || $this->username === '0') {
            $errors[] = 'Username cannot be empty';
        }

        if (!preg_match('/^\w+$/', $this->username)) {
            $errors[] = 'Username can only contain letters, numbers, and underscores';
        }

        if ($this->email === '' || $this->email === '0') {
            $errors[] = 'Email cannot be empty';
        }

        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email address is not valid';
        }

        if ($this->name === '' || $this->name === '0') {
            $errors[] = 'Name cannot be empty';
        }

        if (strlen($this->name) > 100) {
            $errors[] = 'Name cannot exceed 100 characters';
        }

        if (!in_array($this->role, ['admin', 'super_admin'])) {
            $errors[] = 'Role must be either admin or super_admin';
        }

        // Password validation (only if password is set)
        if ($this->password !== null) {
            if (strlen($this->password) < 8) {
                $errors[] = 'Password must be at least 8 characters long';
            }

            if (!preg_match('/[A-Z]/', $this->password)) {
                $errors[] = 'Password must contain at least one uppercase letter';
            }

            if (!preg_match('/[a-z]/', $this->password)) {
                $errors[] = 'Password must contain at least one lowercase letter';
            }

            if (!preg_match('/\d/', $this->password)) {
                $errors[] = 'Password must contain at least one number';
            }
        }

        // Password hash validation (if set)
        if ($this->password_hash !== '' && $this->password_hash !== '0' && !password_get_info($this->password_hash)) {
            $errors[] = 'Password hash is not valid';
        }

        if ($this->login_attempts < 0) {
            $errors[] = 'Login attempts cannot be negative';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Prepare for save with password hashing
     */
    public function prepareForSave(bool $isUpdate = false): void
    {
        parent::prepareForSave($isUpdate);

        // Hash password if plain password is set
        if ($this->password !== null) {
            $this->setPasswordWithHash($this->password);
            $this->password = null; // Clear plain password after hashing
        }
    }

    // ==================== SERIALIZATION METHODS ====================
    /**
    * @return array<string, mixed>  <-- Tambahkan ini
    */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'username' => $this->getUsername(),
            'email' => $this->getEmail(),
            'name' => $this->getName(),
            'role' => $this->getRole(),
            'role_label' => $this->getRoleLabel(),
            'role_color_class' => $this->getRoleColorClass(),
            'active' => $this->isActive(),
            'status_label' => $this->getStatusLabel(),
            'status_color_class' => $this->getStatusColorClass(),
            'last_login' => $this->getLastLogin(),
            'formatted_last_login' => $this->getFormattedLastLogin(),
            'login_attempts' => $this->getLoginAttempts(),
            'is_super_admin' => $this->isSuperAdmin(),
            'is_regular_admin' => $this->isRegularAdmin(),
            'is_locked' => $this->isLocked(),
            'is_recently_active' => $this->isRecentlyActive(),
            'initials' => $this->getInitials(),
            'password_needs_rehash' => $this->passwordNeedsRehash(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'deleted_at' => $this->getDeletedAt(),
            'is_deleted' => $this->isDeleted(),
        ];
    }

    /**
    * @param array<string, mixed> $data  <-- Tambahkan ini
    */
    public static function fromArray(array $data): static
    {
        $admin = new static(
          self::ensureString($data['username']),
          self::ensureString($data['email']),
          self::ensureString($data['name'])
        );

        if (isset($data['id'])) {
            $admin->setId(self::ensureInt($data['id']));
        }

        if (isset($data['password_hash'])) {
            $admin->setPasswordHash(self::ensureString($data['password_hash']));
        }

        if (isset($data['role'])) {
            $admin->setRole(self::ensureString($data['role']));
        }

        if (isset($data['active'])) {
            $admin->setActive(self::ensureBool( $data['active']));
        }

        if (isset($data['last_login'])) {
            $admin->setLastLogin(self::ensureString($data['last_login']));
        }

        if (isset($data['login_attempts'])) {
            $admin->setLoginAttempts(self::ensureInt( $data['login_attempts']));
        }

        if (isset($data['password'])) {
            $admin->setPassword(self::ensureString($data['password']));
        }

        if (isset($data['created_at']) && $data['created_at'] instanceof DateTimeImmutable) {
            $admin->setCreatedAt($data['created_at']);
        }

        if (isset($data['updated_at']) && $data['updated_at'] instanceof DateTimeImmutable) {
            $admin->setUpdatedAt($data['updated_at']);
        }

        if (isset($data['deleted_at']) && $data['deleted_at'] instanceof DateTimeImmutable) {
            $admin->setDeletedAt($data['deleted_at']);
        }

        return $admin;
    }

    /*
     * Create system admin (super admin for initial setup)
     */
    public static function createSystemAdmin(): static
    {
        $admin = new static(
            'system',
            'system@devdaily.local',
            'System Administrator'
        );

        $admin->setRole('super_admin');
        $admin->setActive(true);
        $admin->setPasswordWithHash('SecureSystemPassword123!');

        return $admin;
    }

    public static function createSample(): static
    {
        $admin = new static(
            'johndoe',
            'john@example.com',
            'John Doe'
        );

        $admin->setRole('super_admin');
        $admin->setActive(true);
        $admin->setLastLogin(new DateTimeImmutable('-2 hours'));
        $admin->setLoginAttempts(0);
        $admin->setPasswordWithHash('Password123!');

        return $admin;
    }
    
        // =========================================================================
    // HELPER METHODS FOR PHPSTAN LEVEL 9 (SAFE CASTING)
    // =========================================================================

    /**
     * Memastikan input mixed diubah menjadi string dengan aman.
     */
    private static function ensureString(mixed $input): string
    {
        if (is_string($input) || is_numeric($input)) {
            return (string) $input;
        }
        return '';
    }

    /**
     * Memastikan input mixed diubah menjadi int dengan aman.
     */
    private static function ensureInt(mixed $input): int
    {
        if (is_numeric($input)) {
            return (int) $input;
        }
        return 0;
    }

    /**
     * Memastikan input mixed diubah menjadi boolean dengan aman.
     * Mampu menangani: true, "true", 1, "1", "on", "yes"
     */
    private static function ensureBool(mixed $input): bool
    {
        return filter_var($input, FILTER_VALIDATE_BOOLEAN);
    }

}
