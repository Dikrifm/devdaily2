<?php

namespace App\Repositories\Concrete;

use App\Entities\Admin;
use App\Exceptions\AdminNotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AdminModel;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Services\AuditService;
use App\Services\CacheService;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

class AdminRepository implements AdminRepositoryInterface
{
    private AdminModel $adminModel;
    private CacheService $cacheService;
    private AuditService $auditService;
    private ConnectionInterface $db;

    private int $cacheTtl = 1800; // 30 menit untuk admin data
    private string $cachePrefix = 'admin_repo_';

    // Cache keys constants
    private const CACHE_KEY_FIND = 'find_';
    private const CACHE_KEY_BY_USERNAME = 'by_username_';
    private const CACHE_KEY_BY_EMAIL = 'by_email_';
    private const CACHE_KEY_ALL_ACTIVE = 'all_active';
    private const CACHE_KEY_SUPER_ADMINS = 'super_admins';
    private const CACHE_KEY_STATS = 'stats_';
    private const CACHE_KEY_ACTIVITY_LOGS = 'activity_logs_';
    private const CACHE_KEY_LOGIN_HISTORY = 'login_history_';
    private const CACHE_KEY_API_TOKENS = 'api_tokens_';

    // Security constants
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 menit dalam detik
    private const PASSWORD_MIN_LENGTH = 8;
    private const PASSWORD_OPTIONS = ['cost' => 12];

    public function __construct(
        AdminModel $adminModel,
        CacheService $cacheService,
        AuditService $auditService,
        ConnectionInterface $db
    ) {
        $this->adminModel = $adminModel;
        $this->cacheService = $cacheService;
        $this->auditService = $auditService;
        $this->db = $db;
    }

    // ==================== BASIC CRUD OPERATIONS ====================

    public function find(int $id, bool $withTrashed = false): ?Admin
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_FIND . $id . '_' . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($id, $withTrashed) {
            $admin = $withTrashed
                ? $this->adminModel->withDeleted()->find($id)
                : $this->adminModel->find($id);

            if (!$admin instanceof Admin) {
                return null;
            }

            return $admin;
        }, $this->cacheTtl);
    }

    public function findByUsername(string $username, bool $withTrashed = false): ?Admin
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_BY_USERNAME . $username . '_' . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($username, $withTrashed) {
            $method = $withTrashed ? 'withDeleted' : 'where';
            $this->adminModel->$method(['username' => $username]);

            return $this->adminModel->first();
        }, $this->cacheTtl);
    }

    public function findByEmail(string $email, bool $withTrashed = false): ?Admin
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_BY_EMAIL . $email . '_' . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($email, $withTrashed) {
            $method = $withTrashed ? 'withDeleted' : 'where';
            $this->adminModel->$method(['email' => $email]);

            return $this->adminModel->first();
        }, $this->cacheTtl);
    }

    public function findByIdentifier(string $identifier, bool $withTrashed = false): ?Admin
    {
        // Coba cari dengan username
        $admin = $this->findByUsername($identifier, $withTrashed);
        if ($admin) {
            return $admin;
        }

        // Coba cari dengan email
        return $this->findByEmail($identifier, $withTrashed);
    }

    public function findAll(
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'DESC',
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKey(
            'find_all_' .
            md5(serialize($filters)) . '_' .
            "{$sortBy}_{$sortDirection}_" .
            ($withTrashed ? 'with' : 'without')
        );

        return $this->cacheService->remember($cacheKey, function () use ($filters, $sortBy, $sortDirection, $withTrashed) {
            $builder = $withTrashed
                ? $this->adminModel->withDeleted()
                : $this->adminModel;

            // Apply filters
            $this->applyFilters($builder, $filters);

            // Apply sorting
            $builder->orderBy($sortBy, $sortDirection);

            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }

    public function save(Admin $admin): Admin
    {
        $isUpdate = $admin->getId() !== null;
        $oldData = $isUpdate ? $this->find($admin->getId(), true)?->toArray() : null;

        try {
            $this->db->transBegin();

            // Validate before save
            $validationResult = $this->validate($admin);
            if (!$validationResult['is_valid']) {
                throw new ValidationException(
                    'Admin validation failed',
                    $validationResult['errors']
                );
            }

            // Check for unique username (if changed)
            if (!$this->isUsernameUnique($admin->getUsername(), $admin->getId())) {
                throw new ValidationException(
                    'Username must be unique',
                    ['username' => 'This username is already taken']
                );
            }

            // Check for unique email (if changed)
            if (!$this->isEmailUnique($admin->getEmail(), $admin->getId())) {
                throw new ValidationException(
                    'Email must be unique',
                    ['email' => 'This email is already registered']
                );
            }

            // Handle password if provided
            if ($admin->getPassword() !== null) {
                $this->handlePasswordUpdate($admin);
            }

            // Prepare for save
            $admin->prepareForSave($isUpdate);

            // Save to database
            $saved = $isUpdate
                ? $this->adminModel->update($admin->getId(), $admin)
                : $this->adminModel->insert($admin);

            if (!$saved) {
                throw new RuntimeException(
                    'Failed to save admin: ' .
                    implode(', ', $this->adminModel->errors())
                );
            }

            // If new admin, get the ID
            if (!$isUpdate) {
                $admin->setId($this->adminModel->getInsertID());
            }

            // Clear relevant caches
            $this->clearCache($admin->getId());

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $isUpdate ? 'UPDATE' : 'CREATE';
                $currentAdminId = service('auth')->user()?->getId() ?? 0;

                $this->auditService->logCrudOperation(
                    'ADMIN',
                    $admin->getId(),
                    $action,
                    $currentAdminId,
                    $oldData,
                    $admin->toArray()
                );
            }

            $this->db->transCommit();

            return $admin;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'AdminRepository save failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to save admin: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(int $id, bool $force = false): bool
    {
        $admin = $this->find($id, true);
        if (!$admin) {
            throw AdminNotFoundException::forId($id);
        }

        // Check if can be deleted
        $canDeleteResult = $this->canDelete($id, service('auth')->user()?->getId() ?? 0);
        if (!$canDeleteResult['can_delete'] && !$force) {
            throw new ValidationException(
                'Cannot delete admin',
                $canDeleteResult['reasons']
            );
        }

        try {
            $this->db->transBegin();

            $oldData = $admin->toArray();
            $currentAdminId = service('auth')->user()?->getId() ?? 0;

            if ($force) {
                // Permanent deletion
                $deleted = $this->adminModel->delete($id, true);
            } else {
                // Soft delete
                $admin->softDelete();
                $deleted = $this->adminModel->save($admin);
            }

            if (!$deleted) {
                throw new RuntimeException('Failed to delete admin');
            }

            // Terminate all active sessions
            $this->terminateAllOtherSessions($id, '');

            // Clear caches
            $this->clearCache($id);

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $force ? 'DELETE' : 'SOFT_DELETE';
                $this->auditService->logCrudOperation(
                    'ADMIN',
                    $id,
                    $action,
                    $currentAdminId,
                    $oldData,
                    null
                );
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'AdminRepository delete failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to delete admin: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restore(int $id): bool
    {
        $admin = $this->find($id, true);
        if (!$admin || !$admin->isDeleted()) {
            return false;
        }

        try {
            $this->db->transBegin();

            $admin->restore();
            $restored = $this->adminModel->save($admin);

            if (!$restored) {
                throw new RuntimeException('Failed to restore admin');
            }

            // Clear caches
            $this->clearCache($id);

            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $currentAdminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logCrudOperation(
                    'ADMIN',
                    $id,
                    'RESTORE',
                    $currentAdminId,
                    null,
                    $admin->toArray()
                );
            }

            $this->db->transCommit();

            return true;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'AdminRepository restore failed: ' . $e->getMessage());
            return false;
        }
    }

    public function exists(int $id, bool $withTrashed = false): bool
    {
        $cacheKey = $this->getCacheKey("exists_{$id}_" . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($id, $withTrashed) {
            $builder = $withTrashed
                ? $this->adminModel->withDeleted()
                : $this->adminModel;

            return $builder->find($id) !== null;
        }, 300);
    }

    // ==================== AUTHENTICATION & SECURITY ====================

    public function authenticate(string $identifier, string $password, string $ipAddress): array
    {
        // Cari admin dengan identifier
        $admin = $this->findByIdentifier($identifier);

        if (!$admin) {
            return [
                'success' => false,
                'admin' => null,
                'message' => 'Invalid credentials',
                'code' => 'INVALID_CREDENTIALS'
            ];
        }

        // Check if account is active
        if (!$admin->isActive()) {
            $this->recordFailedLogin($admin->getId(), $ipAddress, 'account_inactive');
            return [
                'success' => false,
                'admin' => $admin,
                'message' => 'Account is inactive',
                'code' => 'ACCOUNT_INACTIVE'
            ];
        }

        // Check if account is suspended
        if ($this->isSuspended($admin->getId())) {
            $this->recordFailedLogin($admin->getId(), $ipAddress, 'account_suspended');
            return [
                'success' => false,
                'admin' => $admin,
                'message' => 'Account is suspended',
                'code' => 'ACCOUNT_SUSPENDED'
            ];
        }

        // Check if account is locked
        $lockStatus = $this->isAccountLocked($admin->getId(), self::MAX_LOGIN_ATTEMPTS, self::LOCKOUT_DURATION);
        if ($lockStatus['is_locked']) {
            $this->recordFailedLogin($admin->getId(), $ipAddress, 'account_locked');
            return [
                'success' => false,
                'admin' => $admin,
                'message' => 'Account is locked. Try again later.',
                'code' => 'ACCOUNT_LOCKED',
                'lockout_until' => $lockStatus['lockout_until']
            ];
        }

        // Verify password
        if (!$admin->verifyPassword($password)) {
            // Record failed attempt
            $this->recordFailedLogin($admin->getId(), $ipAddress, 'invalid_password');

            // Check if locked after this attempt
            $lockStatus = $this->isAccountLocked($admin->getId(), self::MAX_LOGIN_ATTEMPTS, self::LOCKOUT_DURATION);

            return [
                'success' => false,
                'admin' => $admin,
                'message' => 'Invalid credentials',
                'code' => 'INVALID_PASSWORD',
                'attempts_remaining' => $lockStatus['attempts_remaining'],
                'is_locked' => $lockStatus['is_locked']
            ];
        }

        // Password correct - successful authentication
        $this->recordSuccessfulLogin($admin->getId(), $ipAddress, '');
        $this->resetLoginAttempts($admin->getId());

        return [
            'success' => true,
            'admin' => $admin,
            'message' => 'Authentication successful',
            'code' => 'SUCCESS'
        ];
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, self::PASSWORD_OPTIONS);
    }

    public function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, self::PASSWORD_OPTIONS);
    }

    public function updatePassword(int $adminId, string $newPassword): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        try {
            $admin->setPasswordWithHash($newPassword, self::PASSWORD_OPTIONS);
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);

                // Log password change
                if ($this->auditService->isEnabled()) {
                    $currentAdminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logCrudOperation(
                        'ADMIN',
                        $adminId,
                        'UPDATE',
                        $currentAdminId,
                        ['password' => '***'],
                        ['password' => '***'],
                        'Password changed'
                    );
                }
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository updatePassword failed: ' . $e->getMessage());
            return false;
        }
    }

    public function generateRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        $password = '';

        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $password;
    }

    public function validatePasswordStrength(string $password): array
    {
        $errors = [];
        $score = 0;

        // Length check
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least ' . self::PASSWORD_MIN_LENGTH . ' characters long';
        } else {
            $score++;
        }

        // Contains uppercase
        if (preg_match('/[A-Z]/', $password)) {
            $score++;
        } else {
            $errors[] = 'Password must contain at least one uppercase letter';
        }

        // Contains lowercase
        if (preg_match('/[a-z]/', $password)) {
            $score++;
        } else {
            $errors[] = 'Password must contain at least one lowercase letter';
        }

        // Contains number
        if (preg_match('/[0-9]/', $password)) {
            $score++;
        } else {
            $errors[] = 'Password must contain at least one number';
        }

        // Contains special character
        if (preg_match('/[^A-Za-z0-9]/', $password)) {
            $score++;
        } else {
            $errors[] = 'Password must contain at least one special character';
        }

        // Strength rating
        $strength = 'weak';
        if ($score >= 4) {
            $strength = 'good';
        }
        if ($score >= 5) {
            $strength = 'strong';
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'score' => $score,
            'strength' => $strength,
            'requirements' => [
                'min_length' => self::PASSWORD_MIN_LENGTH,
                'requires_uppercase' => true,
                'requires_lowercase' => true,
                'requires_number' => true,
                'requires_special' => true,
            ]
        ];
    }

    // ==================== LOGIN & SESSION MANAGEMENT ====================

    public function recordSuccessfulLogin(int $adminId, string $ipAddress, string $userAgent): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        try {
            $admin->recordLogin();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                // Log login in login_history table
                $loginData = [
                    'admin_id' => $adminId,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'success' => true,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // $this->db->table('admin_login_history')->insert($loginData);

                // Create session record
                $sessionId = session_id();
                if ($sessionId) {
                    $sessionData = [
                        'admin_id' => $adminId,
                        'session_id' => $sessionId,
                        'ip_address' => $ipAddress,
                        'user_agent' => $userAgent,
                        'last_activity' => date('Y-m-d H:i:s'),
                        'created_at' => date('Y-m-d H:i:s')
                    ];

                    // $this->db->table('admin_sessions')->insert($sessionData);
                }

                $this->clearCache($adminId);
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository recordSuccessfulLogin failed: ' . $e->getMessage());
            return false;
        }
    }

    public function recordFailedLogin(int $adminId, string $ipAddress, string $reason = 'invalid_credentials'): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            // Admin not found, but we might want to log the attempt anyway
            $loginData = [
                'admin_id' => null,
                'identifier' => 'unknown',
                'ip_address' => $ipAddress,
                'success' => false,
                'reason' => $reason,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // $this->db->table('failed_login_attempts')->insert($loginData);
            return true;
        }

        try {
            // Increment login attempts
            $admin->recordFailedLogin();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                // Log failed attempt
                $loginData = [
                    'admin_id' => $adminId,
                    'ip_address' => $ipAddress,
                    'user_agent' => '',
                    'success' => false,
                    'reason' => $reason,
                    'created_at' => date('Y-m-d H:i:s')
                ];

                // $this->db->table('admin_login_history')->insert($loginData);

                $this->clearCache($adminId);
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository recordFailedLogin failed: ' . $e->getMessage());
            return false;
        }
    }

    public function resetLoginAttempts(int $adminId): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        try {
            $admin->resetLoginAttempts();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository resetLoginAttempts failed: ' . $e->getMessage());
            return false;
        }
    }

    public function incrementLoginAttempts(int $adminId): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        try {
            $admin->recordFailedLogin();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository incrementLoginAttempts failed: ' . $e->getMessage());
            return false;
        }
    }

    public function isAccountLocked(int $adminId, int $maxAttempts = 5, int $lockoutDuration = 15): array
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return [
                'is_locked' => false,
                'attempts_remaining' => $maxAttempts,
                'lockout_until' => null,
                'current_attempts' => 0
            ];
        }

        $loginAttempts = $admin->getLoginAttempts();
        $lastLogin = $admin->getLastLogin();

        // Check if lockout period has expired
        $isLocked = false;
        $lockoutUntil = null;

        if ($loginAttempts >= $maxAttempts && $lastLogin) {
            $lockoutExpires = $lastLogin->getTimestamp() + ($lockoutDuration * 60);
            $currentTime = time();

            if ($currentTime < $lockoutExpires) {
                $isLocked = true;
                $lockoutUntil = date('Y-m-d H:i:s', $lockoutExpires);
            } else {
                // Lockout period expired, reset attempts
                $this->resetLoginAttempts($adminId);
                $loginAttempts = 0;
            }
        }

        $attemptsRemaining = max(0, $maxAttempts - $loginAttempts);

        return [
            'is_locked' => $isLocked,
            'attempts_remaining' => $attemptsRemaining,
            'lockout_until' => $lockoutUntil,
            'current_attempts' => $loginAttempts,
            'max_attempts' => $maxAttempts,
            'lockout_duration_minutes' => $lockoutDuration
        ];
    }

    public function getLoginAttemptsCount(int $adminId, string $timeWindow = '1 hour'): int
    {
        // Query login_history table for recent failed attempts
        $count = 0;

        try {
            // $builder = $this->db->table('admin_login_history');
            // $builder->where('admin_id', $adminId)
            //         ->where('success', false)
            //         ->where("created_at >= DATE_SUB(NOW(), INTERVAL {$timeWindow})");
            // $count = $builder->countAllResults();
        } catch (\Exception $e) {
            log_message('error', 'AdminRepository getLoginAttemptsCount failed: ' . $e->getMessage());
        }

        return $count;
    }

    public function clearLoginAttempts(int $adminId): bool
    {
        return $this->resetLoginAttempts($adminId);
    }

    public function recordLogout(int $adminId, string $ipAddress): bool
    {
        try {
            // Remove session record
            $sessionId = session_id();
            if ($sessionId) {
                // $this->db->table('admin_sessions')
                //          ->where('admin_id', $adminId)
                //          ->where('session_id', $sessionId)
                //          ->delete();
            }

            // Log logout
            $logoutData = [
                'admin_id' => $adminId,
                'ip_address' => $ipAddress,
                'action' => 'logout',
                'created_at' => date('Y-m-d H:i:s')
            ];

            // $this->db->table('admin_activity_logs')->insert($logoutData);

            return true;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository recordLogout failed: ' . $e->getMessage());
            return false;
        }
    }

    // ==================== ROLE & PERMISSION MANAGEMENT ====================

    public function promoteToSuperAdmin(int $adminId): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true; // Already super admin
        }

        try {
            $admin->promoteToSuperAdmin();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);

                // Log role change
                if ($this->auditService->isEnabled()) {
                    $currentAdminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logStateTransition(
                        'ADMIN',
                        $adminId,
                        'admin',
                        'super_admin',
                        $currentAdminId,
                        'Promoted to super admin'
                    );
                }
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository promoteToSuperAdmin failed: ' . $e->getMessage());
            return false;
        }
    }

    public function demoteToAdmin(int $adminId): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        if (!$admin->isSuperAdmin()) {
            return true; // Already regular admin
        }

        // Check if this is the last super admin
        $superAdminCount = $this->countSuperAdmins();
        if ($superAdminCount <= 1) {
            throw new ValidationException(
                'Cannot demote the last super admin'
            );
        }

        try {
            $admin->demoteToAdmin();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);

                // Log role change
                if ($this->auditService->isEnabled()) {
                    $currentAdminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logStateTransition(
                        'ADMIN',
                        $adminId,
                        'super_admin',
                        'admin',
                        $currentAdminId,
                        'Demoted to admin'
                    );
                }
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository demoteToAdmin failed: ' . $e->getMessage());
            return false;
        }
    }

    public function hasRole(int $adminId, string $role): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        return $admin->getRole() === $role;
    }

    public function isSuperAdmin(int $adminId): bool
    {
        return $this->hasRole($adminId, 'super_admin');
    }

    public function isRegularAdmin(int $adminId): bool
    {
        return $this->hasRole($adminId, 'admin');
    }

    public function getPermissions(int $adminId): array
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return [];
        }

        // Default permissions based on role
        $permissions = [];

        if ($admin->isSuperAdmin()) {
            $permissions = [
                'manage_admins',
                'manage_products',
                'manage_categories',
                'manage_marketplaces',
                'manage_links',
                'view_analytics',
                'manage_settings',
                'manage_audit_logs',
                'manage_backups',
                'manage_api_keys'
            ];
        } else {
            $permissions = [
                'manage_products',
                'manage_categories',
                'manage_links',
                'view_analytics'
            ];
        }

        return $permissions;
    }

    public function hasPermission(int $adminId, string $permission): bool
    {
        $permissions = $this->getPermissions($adminId);
        return in_array($permission, $permissions);
    }

    public function updatePermissions(int $adminId, array $permissions): bool
    {
        // In this implementation, permissions are role-based
        // For custom permissions, you'd need a separate permissions table
        // For now, we'll just validate that the permissions are valid

        $validPermissions = [
            'manage_admins',
            'manage_products',
            'manage_categories',
            'manage_marketplaces',
            'manage_links',
            'view_analytics',
            'manage_settings',
            'manage_audit_logs',
            'manage_backups',
            'manage_api_keys'
        ];

        // Validate all permissions are valid
        foreach ($permissions as $permission) {
            if (!in_array($permission, $validPermissions)) {
                throw new ValidationException(
                    "Invalid permission: {$permission}"
                );
            }
        }

        // Log permission change (would actually update in database)
        if ($this->auditService->isEnabled()) {
            $currentAdminId = service('auth')->user()?->getId() ?? 0;
            $this->auditService->logCrudOperation(
                'ADMIN_PERMISSIONS',
                $adminId,
                'UPDATE',
                $currentAdminId,
                [],
                ['permissions' => $permissions],
                'Permissions updated'
            );
        }

        return true;
    }

    public function findSuperAdmins(bool $activeOnly = true): array
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_SUPER_ADMINS . ($activeOnly ? 'active' : 'all'));

        return $this->cacheService->remember($cacheKey, function () use ($activeOnly) {
            $builder = $this->adminModel->where('role', 'super_admin');

            if ($activeOnly) {
                $builder->where('active', true);
            }

            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }

    public function countSuperAdmins(bool $activeOnly = true): int
    {
        $cacheKey = $this->getCacheKey('count_super_admins_' . ($activeOnly ? 'active' : 'all'));

        return $this->cacheService->remember($cacheKey, function () use ($activeOnly) {
            $builder = $this->adminModel->where('role', 'super_admin');

            if ($activeOnly) {
                $builder->where('active', true);
            }

            return $builder->countAllResults();
        }, 300);
    }

    // ==================== STATUS & ACTIVATION MANAGEMENT ====================

    public function activate(int $adminId): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        if ($admin->isActive()) {
            return true; // Already active
        }

        try {
            $admin->activate();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);

                // Log activation
                if ($this->auditService->isEnabled()) {
                    $currentAdminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logStateTransition(
                        'ADMIN',
                        $adminId,
                        'inactive',
                        'active',
                        $currentAdminId,
                        'Account activated'
                    );
                }
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository activate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function deactivate(int $adminId, ?string $reason = null): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        if (!$admin->isActive()) {
            return true; // Already inactive
        }

        try {
            $admin->deactivate();
            $updated = $this->adminModel->save($admin);

            if ($updated) {
                $this->clearCache($adminId);

                // Terminate all active sessions
                $this->terminateAllOtherSessions($adminId, '');

                // Log deactivation
                if ($this->auditService->isEnabled()) {
                    $currentAdminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logStateTransition(
                        'ADMIN',
                        $adminId,
                        'active',
                        'inactive',
                        $currentAdminId,
                        $reason ?? 'Account deactivated'
                    );
                }
            }

            return $updated;

        } catch (\Exception $e) {
            log_message('error', 'AdminRepository deactivate failed: ' . $e->getMessage());
            return false;
        }
    }

    public function suspend(int $adminId, string $reason, ?\DateTimeInterface $until = null): bool
    {
        // In this implementation, suspension is treated as deactivation with notes
        // You might want to implement a separate suspension system

        $suspended = $this->deactivate($adminId, "Suspended: {$reason}");

        if ($suspended && $until) {
            // Store suspension details in a separate table
            $suspensionData = [
                'admin_id' => $adminId,
                'reason' => $reason,
                'suspended_until' => $until->format('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s')
            ];

            // $this->db->table('admin_suspensions')->insert($suspensionData);
        }

        return $suspended;
    }

    public function unsuspend(int $adminId): bool
    {
        return $this->activate($adminId);
    }

    public function isActive(int $adminId): bool
    {
        $admin = $this->find($adminId);
        return $admin ? $admin->isActive() : false;
    }

    public function isSuspended(int $adminId): bool
    {
        // Check suspensions table
        // For now, check if inactive
        return !$this->isActive($adminId);
    }

    public function getAccountStatus(int $adminId): string
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return 'not_found';
        }

        if ($admin->isDeleted()) {
            return 'deleted';
        }

        if (!$admin->isActive()) {
            return 'inactive';
        }

        $lockStatus = $this->isAccountLocked($adminId);
        if ($lockStatus['is_locked']) {
            return 'locked';
        }

        // Check if suspended (would require checking suspensions table)
        // For now, just return active
        return 'active';
    }

    // ==================== SEARCH & FILTER ====================

    public function search(
        string $keyword,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(
            'search_' . md5($keyword) . '_' .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with_' : 'without_') .
            "{$limit}_{$offset}"
        );

        return $this->cacheService->remember($cacheKey, function () use ($keyword, $activeOnly, $withTrashed, $limit, $offset) {
            $builder = $withTrashed
                ? $this->adminModel->withDeleted()
                : $this->adminModel;

            if ($activeOnly) {
                $builder->where('active', true);
            }

            $builder->groupStart();
            $builder->like('username', $keyword);
            $builder->orLike('email', $keyword);
            $builder->orLike('name', $keyword);
            $builder->groupEnd();

            $builder->orderBy('name', 'ASC')
                   ->limit($limit, $offset);

            $result = $builder->findAll();
            return $result ?: [];
        }, 300);
    }

    public function findByRole(string $role, bool $activeOnly = true, int $limit = 100): array
    {
        $cacheKey = $this->getCacheKey('by_role_' . $role . '_' . ($activeOnly ? 'active' : 'all') . '_' . $limit);

        return $this->cacheService->remember($cacheKey, function () use ($role, $activeOnly, $limit) {
            $builder = $this->adminModel->where('role', $role);

            if ($activeOnly) {
                $builder->where('active', true);
            }

            $builder->orderBy('name', 'ASC')
                   ->limit($limit);

            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }

    public function findByIds(
        array $adminIds,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array {
        if (empty($adminIds)) {
            return [];
        }

        $cacheKey = $this->getCacheKey(
            'by_ids_' . md5(implode(',', $adminIds)) . '_' .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with' : 'without')
        );

        return $this->cacheService->remember($cacheKey, function () use ($adminIds, $activeOnly, $withTrashed) {
            $builder = $withTrashed
                ? $this->adminModel->withDeleted()
                : $this->adminModel;

            if ($activeOnly) {
                $builder->where('active', true);
            }

            $builder->whereIn('id', $adminIds)
                   ->orderBy('name', 'ASC');

            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }

    // ==================== STATISTICS & ANALYTICS ====================

    public function getStatistics(?int $adminId = null): array
    {
        if ($adminId) {
            return $this->getAdminStatistics($adminId);
        }

        return $this->getSystemStatistics();
    }

    public function countByStatus(bool $withTrashed = false): array
    {
        $cacheKey = $this->getCacheKey('count_by_status_' . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($withTrashed) {
            $builder = $withTrashed
                ? $this->adminModel->withDeleted()
                : $this->adminModel;

            $total = $builder->countAllResults();

            $builder->where('active', true);
            $active = $builder->countAllResults();

            $builder->where('active', false);
            $inactive = $builder->countAllResults();

            // Check locked accounts (active but with max login attempts recently)
            $locked = 0;
            $admins = $this->findAll(['active' => true], 'id', 'ASC', false);
            foreach ($admins as $admin) {
                $lockStatus = $this->isAccountLocked($admin->getId());
                if ($lockStatus['is_locked']) {
                    $locked++;
                }
            }

            $suspended = 0; // Would query suspensions table

            $deleted = $withTrashed
                ? $this->adminModel->onlyDeleted()->countAllResults()
                : 0;

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'locked' => $locked,
                'suspended' => $suspended,
                'deleted' => $deleted,
            ];
        }, 300);
    }

    public function countByRole(bool $activeOnly = true): array
    {
        $cacheKey = $this->getCacheKey('count_by_role_' . ($activeOnly ? 'active' : 'all'));

        return $this->cacheService->remember($cacheKey, function () use ($activeOnly) {
            $roles = ['super_admin', 'admin'];
            $result = [];

            foreach ($roles as $role) {
                $builder = $this->adminModel->where('role', $role);

                if ($activeOnly) {
                    $builder->where('active', true);
                }

                $result[$role] = $builder->countAllResults();
            }

            return $result;
        }, 300);
    }

    public function countAll(bool $withTrashed = false): int
    {
        $cacheKey = $this->getCacheKey('count_all_' . ($withTrashed ? 'with' : 'without'));

        return $this->cacheService->remember($cacheKey, function () use ($withTrashed) {
            $builder = $withTrashed
                ? $this->adminModel->withDeleted()
                : $this->adminModel;

            return $builder->countAllResults();
        }, 300);
    }

    public function countActive(): int
    {
        $cacheKey = $this->getCacheKey('count_active');

        return $this->cacheService->remember($cacheKey, function () {
            return $this->adminModel->where('active', true)->countAllResults();
        }, 300);
    }

    public function getLoginActivityStats(string $period = 'month'): array
    {
        $cacheKey = $this->getCacheKey('login_activity_stats_' . $period);

        return $this->cacheService->remember($cacheKey, function () use ($period) {
            // This would query login_history table
            // For now, return placeholder data

            return [
                'total_logins' => 0,
                'failed_logins' => 0,
                'unique_admins' => 0,
                'avg_logins_per_admin' => 0,
                'period' => $period,
            ];
        }, 1800);
    }

    public function getDashboardStats(): array
    {
        $cacheKey = $this->getCacheKey('dashboard_stats');

        return $this->cacheService->remember($cacheKey, function () {
            $totalAdmins = $this->countAll();
            $activeAdmins = $this->countActive();
            $superAdmins = $this->countSuperAdmins();

            // Recent activity (last 24 hours)
            $recentLogins = 0; // Query login_history

            // System alerts
            $lockedAccounts = 0;
            $inactiveAdmins = $totalAdmins - $activeAdmins;

            return [
                'total_admins' => $totalAdmins,
                'active_admins' => $activeAdmins,
                'super_admins' => $superAdmins,
                'recent_logins' => $recentLogins,
                'locked_accounts' => $lockedAccounts,
                'inactive_admins' => $inactiveAdmins,
                'last_updated' => date('Y-m-d H:i:s'),
            ];
        }, 600); // 10 minutes cache
    }

    // ==================== BATCH & BULK OPERATIONS ====================

    public function bulkUpdate(array $adminIds, array $updateData): int
    {
        if (empty($adminIds) || empty($updateData)) {
            return 0;
        }

        try {
            $this->db->transBegin();

            $updated = 0;
            $currentAdminId = service('auth')->user()?->getId() ?? 0;

            foreach ($adminIds as $adminId) {
                try {
                    $admin = $this->find($adminId);
                    if (!$admin) {
                        continue;
                    }

                    // Apply updates (skip password updates in bulk)
                    foreach ($updateData as $field => $value) {
                        if ($field === 'password') {
                            continue; // Don't allow password updates in bulk
                        }

                        $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                        if (method_exists($admin, $setter)) {
                            $admin->$setter($value);
                        }
                    }

                    // Save updated admin
                    if ($this->save($admin)) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to update admin {$adminId}: " . $e->getMessage());
                    // Continue with other admins
                }
            }

            // Clear all caches
            $this->clearCache();

            $this->db->transCommit();

            return $updated;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'AdminRepository bulkUpdate failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function bulkActivate(array $adminIds): int
    {
        if (empty($adminIds)) {
            return 0;
        }

        try {
            $this->db->transBegin();

            $activated = 0;

            foreach ($adminIds as $adminId) {
                try {
                    if ($this->activate($adminId)) {
                        $activated++;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to activate admin {$adminId}: " . $e->getMessage());
                }
            }

            // Clear all caches
            $this->clearCache();

            $this->db->transCommit();

            return $activated;

        } catch (\Exception $e) {
            $this->db->transRollback();

            log_message('error', 'AdminRepository bulkActivate failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ==================== VALIDATION & BUSINESS RULES ====================

    public function canDelete(int $adminId, int $currentAdminId): array
    {
        $admin = $this->find($adminId, true);
        if (!$admin) {
            return [
                'can_delete' => false,
                'reasons' => ['Admin not found'],
                'is_self' => false,
                'is_last_super_admin' => false,
            ];
        }

        $reasons = [];
        $canDelete = true;
        $isSelf = ($adminId === $currentAdminId);
        $isLastSuperAdmin = false;

        // Check if trying to delete self
        if ($isSelf) {
            $canDelete = false;
            $reasons[] = 'Cannot delete your own account';
        }

        // Check if admin is a super admin
        if ($admin->isSuperAdmin()) {
            $superAdminCount = $this->countSuperAdmins();
            if ($superAdminCount <= 1) {
                $canDelete = false;
                $isLastSuperAdmin = true;
                $reasons[] = 'Cannot delete the last super admin';
            }
        }

        // Check if admin has recent activity (optional)
        // You might want to prevent deletion of admins with recent activity

        return [
            'can_delete' => $canDelete,
            'reasons' => $reasons,
            'is_self' => $isSelf,
            'is_last_super_admin' => $isLastSuperAdmin,
            'admin_role' => $admin->getRole(),
            'super_admin_count' => $this->countSuperAdmins(),
        ];
    }

    public function isUsernameUnique(string $username, ?int $excludeId = null): bool
    {
        $builder = $this->adminModel->where('username', $username);

        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() === 0;
    }

    public function isEmailUnique(string $email, ?int $excludeId = null): bool
    {
        $builder = $this->adminModel->where('email', $email);

        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }

        return $builder->countAllResults() === 0;
    }

    public function validate(Admin $admin): array
    {
        $errors = [];
        $isValid = true;

        // Required fields
        if (empty($admin->getUsername())) {
            $errors[] = 'Username is required';
            $isValid = false;
        }

        if (empty($admin->getEmail())) {
            $errors[] = 'Email is required';
            $isValid = false;
        }

        if (empty($admin->getName())) {
            $errors[] = 'Name is required';
            $isValid = false;
        }

        // Username validation
        $username = $admin->getUsername();
        if (strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters long';
            $isValid = false;
        }

        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = 'Username can only contain letters, numbers, and underscores';
            $isValid = false;
        }

        // Email validation
        if (!filter_var($admin->getEmail(), FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
            $isValid = false;
        }

        // Name validation
        if (strlen($admin->getName()) > 100) {
            $errors[] = 'Name cannot exceed 100 characters';
            $isValid = false;
        }

        // Role validation
        $validRoles = ['super_admin', 'admin'];
        if (!in_array($admin->getRole(), $validRoles)) {
            $errors[] = 'Invalid role';
            $isValid = false;
        }

        return [
            'is_valid' => $isValid,
            'errors' => $errors,
        ];
    }

    // ==================== PRIVATE HELPER METHODS ====================

    private function getCacheKey(string $suffix): string
    {
        return $this->cachePrefix . $suffix;
    }

    private function applyFilters(&$builder, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                if ($field === 'search') {
                    $builder->groupStart()
                           ->like('username', $value)
                           ->orLike('email', $value)
                           ->orLike('name', $value)
                           ->groupEnd();
                } elseif ($field === 'date_from') {
                    $builder->where('created_at >=', $value);
                } elseif ($field === 'date_to') {
                    $builder->where('created_at <=', $value);
                } elseif ($field === 'last_login_from') {
                    $builder->where('last_login >=', $value);
                } elseif ($field === 'last_login_to') {
                    $builder->where('last_login <=', $value);
                } elseif (is_array($value)) {
                    $builder->whereIn($field, $value);
                } else {
                    $builder->where($field, $value);
                }
            }
        }
    }

    private function handlePasswordUpdate(Admin $admin): void
    {
        $plainPassword = $admin->getPassword();

        if ($plainPassword) {
            // Validate password strength
            $validationResult = $this->validatePasswordStrength($plainPassword);
            if (!$validationResult['is_valid']) {
                throw new ValidationException(
                    'Password does not meet requirements',
                    $validationResult['errors']
                );
            }

            // Hash the password
            $admin->setPasswordWithHash($plainPassword, self::PASSWORD_OPTIONS);
        }
    }

    private function getAdminStatistics(int $adminId): array
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return [];
        }

        // Get login history count
        $loginCount = 0; // Query login_history

        // Get activity log count
        $activityCount = 0; // Query activity_logs

        // Get current session count
        $sessionCount = 0; // Query admin_sessions

        return [
            'id' => $admin->getId(),
            'username' => $admin->getUsername(),
            'name' => $admin->getName(),
            'role' => $admin->getRole(),
            'active' => $admin->isActive(),
            'login_count' => $loginCount,
            'activity_count' => $activityCount,
            'session_count' => $sessionCount,
            'last_login' => $admin->getLastLogin()?->format('Y-m-d H:i:s'),
            'login_attempts' => $admin->getLoginAttempts(),
            'created_at' => $admin->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $admin->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }

    private function getSystemStatistics(): array
    {
        $countByStatus = $this->countByStatus();
        $countByRole = $this->countByRole();

        // Recent logins (last 24 hours)
        $recentLogins = 0; // Query login_history

        // Failed login attempts (last 24 hours)
        $failedLogins = 0; // Query login_history

        return [
            'total_admins' => $countByStatus['total'],
            'active_admins' => $countByStatus['active'],
            'inactive_admins' => $countByStatus['inactive'],
            'locked_admins' => $countByStatus['locked'],
            'super_admins' => $countByRole['super_admin'] ?? 0,
            'regular_admins' => $countByRole['admin'] ?? 0,
            'recent_logins' => $recentLogins,
            'failed_logins' => $failedLogins,
            'last_updated' => date('Y-m-d H:i:s'),
        ];
    }

    public function clearCache(?int $adminId = null): void
    {
        if ($adminId) {
            // Clear specific admin caches
            $patterns = [
                $this->getCacheKey(self::CACHE_KEY_FIND . "{$adminId}_*"),
                $this->getCacheKey(self::CACHE_KEY_BY_USERNAME . "*"),
                $this->getCacheKey(self::CACHE_KEY_BY_EMAIL . "*"),
                $this->getCacheKey(self::CACHE_KEY_STATS . "{$adminId}"),
                $this->getCacheKey(self::CACHE_KEY_ACTIVITY_LOGS . "{$adminId}_*"),
                $this->getCacheKey(self::CACHE_KEY_LOGIN_HISTORY . "{$adminId}_*"),
                $this->getCacheKey(self::CACHE_KEY_API_TOKENS . "{$adminId}_*"),
                $this->getCacheKey("exists_{$adminId}_*"),
                $this->getCacheKey("profile_{$adminId}"),
            ];

            foreach ($patterns as $pattern) {
                $keys = $this->cacheService->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    $this->cacheService->deleteMultiple($keys);
                }
            }
        } else {
            // Clear all admin caches
            $patterns = [
                $this->getCacheKey('*'),
                $this->getCacheKey(self::CACHE_KEY_ALL_ACTIVE),
                $this->getCacheKey(self::CACHE_KEY_SUPER_ADMINS . '*'),
                $this->getCacheKey('count_*'),
                $this->getCacheKey('search_*'),
                $this->getCacheKey('by_ids_*'),
                $this->getCacheKey('by_role_*'),
                $this->getCacheKey('login_activity_stats_*'),
                $this->getCacheKey('dashboard_stats'),
            ];

            foreach ($patterns as $pattern) {
                $keys = $this->cacheService->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    $this->cacheService->deleteMultiple($keys);
                }
            }
        }
    }

    // ==================== FACTORY METHOD ====================

    public static function create(): self
    {
        $adminModel = model(AdminModel::class);
        $cacheService = service('cache');
        $auditService = service('audit');
        $db = db_connect();

        return new self(
            $adminModel,
            $cacheService,
            $auditService,
            $db
        );
    }

    // Note: Many more methods need to be implemented to complete the interface
    // This is a partial implementation focusing on core functionality

    public function getProfile(int $adminId): array
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return [];
        }

        $profile = $admin->toArray();

        // Add additional profile information
        $profile['permissions'] = $this->getPermissions($adminId);
        $profile['account_status'] = $this->getAccountStatus($adminId);

        // Get recent activity
        $profile['recent_activity'] = $this->getActivityLogs($adminId, 5, 0);

        // Get login history
        $profile['recent_logins'] = $this->getLoginHistory($adminId, 5);

        return $profile;
    }

    public function getActivityLogs(int $adminId, int $limit = 50, int $offset = 0): array
    {
        // Query activity_logs table
        // For now, return empty array
        return [];
    }

    public function getLoginHistory(int $adminId, int $limit = 20): array
    {
        // Query login_history table
        // For now, return empty array
        return [];
    }

    public function getActiveSessions(int $adminId): array
    {
        // Query admin_sessions table
        // For now, return empty array
        return [];
    }

    public function terminateSession(int $adminId, string $sessionId): bool
    {
        try {
            // $this->db->table('admin_sessions')
            //          ->where('admin_id', $adminId)
            //          ->where('session_id', $sessionId)
            //          ->delete();
            return true;
        } catch (\Exception $e) {
            log_message('error', 'AdminRepository terminateSession failed: ' . $e->getMessage());
            return false;
        }
    }

    public function terminateAllOtherSessions(int $adminId, string $currentSessionId): int
    {
        try {
            // $builder = $this->db->table('admin_sessions')
            //                     ->where('admin_id', $adminId);

            // if (!empty($currentSessionId)) {
            //     $builder->where('session_id !=', $currentSessionId);
            // }

            // $deleted = $builder->delete();
            // return $deleted ? $this->db->affectedRows() : 0;

            return 0; // Placeholder
        } catch (\Exception $e) {
            log_message('error', 'AdminRepository terminateAllOtherSessions failed: ' . $e->getMessage());
            return 0;
        }
    }

    public function generateApiToken(
        int $adminId,
        string $tokenName,
        array $scopes = [],
        ?\DateTimeInterface $expiresAt = null
    ): array {
        // Generate API token
        $token = bin2hex(random_bytes(32));
        $tokenId = uniqid('api_', true);

        // Store token in database
        $tokenData = [
            'admin_id' => $adminId,
            'token_id' => $tokenId,
            'token_hash' => hash('sha256', $token),
            'name' => $tokenName,
            'scopes' => json_encode($scopes),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'last_used_at' => null,
        ];

        // $this->db->table('admin_api_tokens')->insert($tokenData);

        return [
            'token' => $token,
            'token_id' => $tokenId,
            'name' => $tokenName,
            'scopes' => $scopes,
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ];
    }

    public function getSuggestions(?string $query = null, bool $activeOnly = true, int $limit = 20): array
    {
        $admins = $this->search($query ?? '', $activeOnly, false, $limit);

        $suggestions = [];
        foreach ($admins as $admin) {
            $suggestions[$admin->getId()] = $admin->getName() . ' (' . $admin->getUsername() . ')';
        }

        return $suggestions;
    }

    // --- IMPLEMENTASI OTOMATIS (STRICT STUBS) ---

    public function findRecentlyActive(int $hoursActiveWithin = 24, int $limit = 20): array
    {
        throw new \RuntimeException('Method findRecentlyActive belum diimplementasikan.');
    }

    public function findInactive(int $daysInactive = 30, int $limit = 50): array
    {
        throw new \RuntimeException('Method findInactive belum diimplementasikan.');
    }

    public function getActivityRanking(string $period = 'month', string $metric = 'actions', int $limit = 10): array
    {
        throw new \RuntimeException('Method getActivityRanking belum diimplementasikan.');
    }

    public function bulkDeactivate(array $adminIds, ?string $reason = null): int
    {
        throw new \RuntimeException('Method bulkDeactivate belum diimplementasikan.');
    }

    public function bulkDelete(array $adminIds, bool $force = false): int
    {
        throw new \RuntimeException('Method bulkDelete belum diimplementasikan.');
    }

    public function bulkRestore(array $adminIds): int
    {
        throw new \RuntimeException('Method bulkRestore belum diimplementasikan.');
    }

    public function bulkUpdateRoles(array $adminIds, string $newRole): int
    {
        throw new \RuntimeException('Method bulkUpdateRoles belum diimplementasikan.');
    }

    public function canDeactivate(int $adminId, int $currentAdminId): array
    {
        throw new \RuntimeException('Method canDeactivate belum diimplementasikan.');
    }

    public function validateAdminData(array $data, ?int $adminId = null): array
    {
        throw new \RuntimeException('Method validateAdminData belum diimplementasikan.');
    }

    public function getCacheTtl(): int
    {
        throw new \RuntimeException('Method getCacheTtl belum diimplementasikan.');
    }

    public function setCacheTtl(int $ttl): self
    {
        throw new \RuntimeException('Method setCacheTtl belum diimplementasikan.');
    }

    public function getSystemAdmin(): ?Admin
    {
        throw new \RuntimeException('Method getSystemAdmin belum diimplementasikan.');
    }

    public function createSystemAdmin(): Admin
    {
        throw new \RuntimeException('Method createSystemAdmin belum diimplementasikan.');
    }

    public function updateProfile(int $adminId, array $profileData): bool
    {
        throw new \RuntimeException('Method updateProfile belum diimplementasikan.');
    }

    public function revokeApiToken(int $adminId, string $tokenId): bool
    {
        throw new \RuntimeException('Method revokeApiToken belum diimplementasikan.');
    }

    public function getApiTokens(int $adminId, bool $activeOnly = true): array
    {
        throw new \RuntimeException('Method getApiTokens belum diimplementasikan.');
    }

    public function getInitials(int $adminId): string
    {
        throw new \RuntimeException('Method getInitials belum diimplementasikan.');
    }

    public function getDisplayName(int $adminId): string
    {
        throw new \RuntimeException('Method getDisplayName belum diimplementasikan.');
    }

    public function getSummary(int $adminId): array
    {
        throw new \RuntimeException('Method getSummary belum diimplementasikan.');
    }

    public function exportData(int $adminId, string $format = 'array')
    {
        throw new \RuntimeException('Method exportData belum diimplementasikan.');
    }

    public function importData(array $data, bool $updateExisting = false): array
    {
        throw new \RuntimeException('Method importData belum diimplementasikan.');
    }

    public function getHealthStatus(int $adminId): array
    {
        throw new \RuntimeException('Method getHealthStatus belum diimplementasikan.');
    }

    public function findSimilar(int $adminId, int $limit = 5): array
    {
        throw new \RuntimeException('Method findSimilar belum diimplementasikan.');
    }

    public function getNotificationPreferences(int $adminId): array
    {
        throw new \RuntimeException('Method getNotificationPreferences belum diimplementasikan.');
    }

    public function updateNotificationPreferences(int $adminId, array $preferences): bool
    {
        throw new \RuntimeException('Method updateNotificationPreferences belum diimplementasikan.');
    }

    // --- REVISI FINAL YANG SESUAI LOG ERROR ---

    public function bulkResetPasswords(array $adminIds, bool $generateNew = true, ?string $newPassword = null): array
    {
        throw new \RuntimeException('Method bulkResetPasswords belum diimplementasikan.');
    }

    // Perhatikan: Interface meminta return ARRAY, bukan Bool.
    // Dan ada tambahan parameter entityType & entityId.
    public function canPerformAction(int $adminId, string $action, ?string $entityType = null, ?int $entityId = null): array
    {
        throw new \RuntimeException('Method canPerformAction belum diimplementasikan.');
    }

}
