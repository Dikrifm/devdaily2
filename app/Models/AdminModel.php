<?php

namespace App\Models;

use App\Entities\Admin;
use CodeIgniter\Exceptions\ModelException;
use Exception;

/**
 * Admin Model
 * 
 * Handles admin authentication, authorization, and management.
 * Core security model with brute force protection and audit logging.
 * 
 * @package App\Models
 */
class AdminModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'admins';

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
    protected $returnType = Admin::class;

    /**
     * Allowed fields for mass assignment
     * Note: password_hash should only be set via setPassword() method
     * 
     * @var array
     */
    protected $allowedFields = [
        'username',
        'email',
        'name',
        'role',
        'active',
        'last_login',
        'login_attempts',
        'password_hash' // Only for updates via changePassword()
    ];

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'username' => 'required|alpha_numeric_space|min_length[3]|max_length[50]|is_unique[admins.username,id,{id}]',
        'email'    => 'required|valid_email|max_length[100]|is_unique[admins.email,id,{id}]',
        'name'     => 'required|string|max_length[100]',
        'role'     => 'required|in_list[admin,super_admin]',
        'active'   => 'required|in_list[0,1]',
        'password' => 'permit_empty|min_length[8]' // Only for validation, not saved
    ];

    /**
     * Validation messages
     * 
     * @var array
     */
    protected $validationMessages = [
        'username' => [
            'is_unique' => 'This username is already taken.',
            'required'  => 'Username is required.'
        ],
        'email' => [
            'valid_email' => 'Please provide a valid email address.',
            'is_unique'   => 'This email is already registered.'
        ],
        'role' => [
            'in_list' => 'Role must be either admin or super_admin.'
        ]
    ];

    /**
     * Maximum login attempts before lockout
     * 
     * @var int
     */
    public const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Lockout duration in minutes
     * 
     * @var int
     */
    public const LOCKOUT_DURATION = 15;

    /**
     * Password hash options (bcrypt)
     * 
     * @var array
     */
    public const PASSWORD_OPTIONS = [
        'cost' => 12 // Higher cost = more secure but slower
    ];

    /**
     * Before insert callback
     * 
     * @param array $data
     * @return array
     */
    protected function beforeInsert(array $data): array
    {
        // Set default active status if not provided
        if (!isset($data['active'])) {
            $data['active'] = 1;
        }

        // Set default role if not provided
        if (!isset($data['role'])) {
            $data['role'] = 'admin';
        }

        // Set timestamps
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $data;
    }

    /**
     * Before update callback
     * 
     * @param array $data
     * @return array
     */
    protected function beforeUpdate(array $data): array
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $data;
    }

    /**
     * Authenticate admin by credentials
     * 
     * @param string $identifier Username or email
     * @param string $password Plain text password
     * @param string $ipAddress Client IP for logging
     * @return Admin|false Returns Admin entity on success, false on failure
     */
    public function authenticate(string $identifier, string $password, string $ipAddress)
    {
        // Find admin by username or email
        $admin = $this->where('username', $identifier)
                      ->orWhere('email', $identifier)
                      ->first();

        if (!$admin) {
            log_message('info', "Failed login attempt for identifier '{$identifier}' from IP {$ipAddress}: User not found");
            return false;
        }

        // Check if account is active
        if (!$admin->active) {
            log_message('warning', "Inactive admin account login attempt: {$admin->username} (ID: {$admin->id})");
            return false;
        }

        // Check if account is locked
        if ($this->isAccountLocked($admin)) {
            log_message('warning', "Locked admin account login attempt: {$admin->username} (ID: {$admin->id})");
            return false;
        }

        // Verify password
        if (!$this->verifyPassword($password, $admin->password_hash)) {
            // Increment login attempts
            $this->incrementLoginAttempts($admin->id);
            
            log_message('info', "Failed password for admin: {$admin->username} (ID: {$admin->id}) from IP {$ipAddress}");
            return false;
        }

        // Reset login attempts on successful login
        $this->resetLoginAttempts($admin->id);
        
        // Update last login
        $this->updateLastLogin($admin->id);
        
        log_message('info', "Successful login for admin: {$admin->username} (ID: {$admin->id}) from IP {$ipAddress}");
        
        return $admin;
    }

    /**
     * Verify password against hash
     * 
     * @param string $password Plain text password
     * @param string $hash Password hash
     * @return bool
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Create password hash
     * 
     * @param string $password Plain text password
     * @return string Hashed password
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, self::PASSWORD_OPTIONS);
    }

    /**
     * Check if password needs rehash
     * 
     * @param string $hash Current password hash
     * @return bool
     */
    public function passwordNeedsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, self::PASSWORD_OPTIONS);
    }

    /**
     * Update admin password
     * 
     * @param int $adminId
     * @param string $newPassword
     * @return bool
     */
    public function updatePassword(int $adminId, string $newPassword): bool
    {
        $hash = $this->hashPassword($newPassword);
        
        return $this->update($adminId, [
            'password_hash' => $hash,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Increment login attempts
     * 
     * @param int $adminId
     * @return bool
     */
    public function incrementLoginAttempts(int $adminId): bool
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return false;
        }

        $attempts = $admin->login_attempts + 1;
        
        return $this->update($adminId, [
            'login_attempts' => $attempts,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Reset login attempts
     * 
     * @param int $adminId
     * @return bool
     */
    public function resetLoginAttempts(int $adminId): bool
    {
        return $this->update($adminId, [
            'login_attempts' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Update last login timestamp
     * 
     * @param int $adminId
     * @return bool
     */
    public function updateLastLogin(int $adminId): bool
    {
        return $this->update($adminId, [
            'last_login' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Check if account is locked due to too many login attempts
     * 
     * @param Admin $admin
     * @return bool
     */
    public function isAccountLocked(Admin $admin): bool
    {
        if ($admin->login_attempts < self::MAX_LOGIN_ATTEMPTS) {
            return false;
        }

        // Check if lockout duration has passed
        if ($admin->last_login) {
            $lastAttempt = strtotime($admin->updated_at);
            $lockoutUntil = $lastAttempt + (self::LOCKOUT_DURATION * 60);
            
            if (time() > $lockoutUntil) {
                // Lockout period expired, reset attempts
                $this->resetLoginAttempts($admin->id);
                return false;
            }
        }

        return true;
    }

    /**
     * Get lockout time remaining in minutes
     * 
     * @param Admin $admin
     * @return int|null Minutes remaining, null if not locked
     */
    public function getLockoutRemaining(Admin $admin): ?int
    {
        if ($admin->login_attempts < self::MAX_LOGIN_ATTEMPTS) {
            return null;
        }

        if (!$admin->updated_at) {
            return null;
        }

        $lastAttempt = strtotime($admin->updated_at);
        $lockoutUntil = $lastAttempt + (self::LOCKOUT_DURATION * 60);
        $remaining = ceil(($lockoutUntil - time()) / 60);

        return max(0, (int) $remaining);
    }

    /**
     * Find admin by username
     * 
     * @param string $username
     * @return Admin|null
     */
    public function findByUsername(string $username): ?Admin
    {
        return $this->where('username', $username)->first();
    }

    /**
     * Find admin by email
     * 
     * @param string $email
     * @return Admin|null
     */
    public function findByEmail(string $email): ?Admin
    {
        return $this->where('email', $email)->first();
    }

    /**
     * Find all active admins
     * 
     * @return array
     */
    public function findAllActive(): array
    {
        return $this->where('active', 1)
                    ->where('deleted_at', null)
                    ->orderBy('name', 'ASC')
                    ->findAll();
    }

    /**
     * Find all super admins
     * 
     * @return array
     */
    public function findSuperAdmins(): array
    {
        return $this->where('role', 'super_admin')
                    ->where('active', 1)
                    ->where('deleted_at', null)
                    ->findAll();
    }

    /**
     * Activate admin account
     * 
     * @param int $adminId
     * @return bool
     */
    public function activate(int $adminId): bool
    {
        return $this->update($adminId, [
            'active' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Deactivate admin account
     * 
     * @param int $adminId
     * @return bool
     */
    public function deactivate(int $adminId): bool
    {
        return $this->update($adminId, [
            'active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Promote admin to super admin
     * 
     * @param int $adminId
     * @return bool
     */
    public function promoteToSuperAdmin(int $adminId): bool
    {
        return $this->update($adminId, [
            'role' => 'super_admin',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Demote super admin to admin
     * 
     * @param int $adminId
     * @return bool
     */
    public function demoteToAdmin(int $adminId): bool
    {
        return $this->update($adminId, [
            'role' => 'admin',
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Check if admin can be deleted (business rules)
     * 
     * @param int $adminId
     * @return array [bool $canDelete, string $reason]
     */
    public function canDelete(int $adminId): array
    {
        $admin = $this->find($adminId);
        if (!$admin) {
            return [false, 'Admin not found'];
        }

        // Cannot delete self
        if ($adminId == session('admin_id')) {
            return [false, 'Cannot delete your own account'];
        }

        // Check if admin is a super admin
        if ($admin->role === 'super_admin') {
            // Check if this is the last super admin
            $superAdmins = $this->findSuperAdmins();
            if (count($superAdmins) <= 1) {
                return [false, 'Cannot delete the last super admin'];
            }
        }

        // Check if admin has verified products (foreign key constraint)
        // This would require a check in products table
        // For now, we'll assume it's okay

        return [true, ''];
    }

    /**
     * Create new admin with password
     * 
     * @param array $data Admin data including 'password'
     * @return int|false Insert ID or false on failure
     */
    public function createAdmin(array $data)
    {
        $this->db->transStart();

        try {
            // Hash password if provided
            if (isset($data['password']) && !empty($data['password'])) {
                $data['password_hash'] = $this->hashPassword($data['password']);
                unset($data['password']);
            }

            // Insert admin
            $adminId = $this->insert($data, true); // Return ID

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                log_message('error', 'Failed to create admin: ' . json_encode($data));
                return false;
            }

            log_message('info', "Admin created: {$data['username']} (ID: {$adminId})");
            return $adminId;

        } catch (Exception $e) {
            $this->db->transRollback();
            log_message('error', 'Admin creation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update admin profile (excluding password)
     * 
     * @param int $adminId
     * @param array $data
     * @return bool
     */
    public function updateProfile(int $adminId, array $data): bool
    {
        // Remove password fields if present
        unset($data['password'], $data['password_hash']);

        return $this->update($adminId, $data);
    }

    /**
     * Count total admins
     * 
     * @return int
     */
    public function countTotal(): int
    {
        return $this->where('deleted_at', null)->countAllResults();
    }

    /**
     * Count active admins
     * 
     * @return int
     */
    public function countActive(): int
    {
        return $this->where('active', 1)
                    ->where('deleted_at', null)
                    ->countAllResults();
    }

    /**
     * Get admin statistics for dashboard
     * 
     * @return array
     */
    public function getDashboardStats(): array
    {
        $cacheKey = $this->cacheKey('dashboard_stats');
        
        return $this->cached($cacheKey, function() {
            $stats = [
                'total_admins' => $this->countTotal(),
                'active_admins' => $this->countActive(),
                'super_admins' => $this->where('role', 'super_admin')
                                      ->where('deleted_at', null)
                                      ->countAllResults(),
                'recent_logins' => $this->where('last_login >=', date('Y-m-d H:i:s', strtotime('-7 days')))
                                       ->where('deleted_at', null)
                                       ->countAllResults(),
            ];

            return $stats;
        }, 300); // 5 minutes cache
    }

    /**
     * Search admins with filters
     * 
     * @param array $filters [search, role, active]
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function searchAdmins(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $builder = $this->builder();

        // Apply filters
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $builder->groupStart()
                    ->like('username', $search)
                    ->orLike('email', $search)
                    ->orLike('name', $search)
                    ->groupEnd();
        }

        if (isset($filters['role']) && in_array($filters['role'], ['admin', 'super_admin'])) {
            $builder->where('role', $filters['role']);
        }

        if (isset($filters['active'])) {
            $builder->where('active', (int) $filters['active']);
        }

        // Exclude deleted
        $builder->where('deleted_at', null);

        // Count total for pagination
        $total = $builder->countAllResults(false);

        // Get results
        $results = $builder->orderBy('name', 'ASC')
                          ->limit($limit, $offset)
                          ->get()
                          ->getResult($this->returnType);

        return [
            'total' => $total,
            'results' => $results,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Override delete to prevent deleting certain admins
     * 
     * @param mixed $id
     * @param bool $purge
     * @return bool
     */
    public function delete($id = null, bool $purge = false)
    {
        if ($id !== null) {
            [$canDelete, $reason] = $this->canDelete($id);
            
            if (!$canDelete) {
                throw new ModelException("Cannot delete admin: {$reason}");
            }
        }

        return parent::delete($id, $purge);
    }

    /**
     * Generate a secure random password
     * 
     * @param int $length
     * @return string
     */
    public function generateRandomPassword(int $length = 12): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-=+';
        $password = '';
        
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        
        return $password;
    }

    /**
     * Validate admin data for update
     * 
     * @param array $data
     * @param int $adminId
     * @return array [bool $valid, array $errors]
     */
    public function validateAdminData(array $data, int $adminId): array
    {
        // Remove password from validation if present
        unset($data['password']);
        
        // Temporarily set the ID for unique validation
        if (isset($data['username']) || isset($data['email'])) {
            $this->validationRules['username'] = "required|alpha_numeric_space|min_length[3]|max_length[50]|is_unique[admins.username,id,{$adminId}]";
            $this->validationRules['email'] = "required|valid_email|max_length[100]|is_unique[admins.email,id,{$adminId}]";
        }
        
        return $this->validateData($data);
    }
}