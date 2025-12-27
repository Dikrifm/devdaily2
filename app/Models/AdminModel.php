<?php

namespace App\Models;

use App\Entities\Admin;
use CodeIgniter\Database\BaseResult;
use RuntimeException;

/**
 * Admin Model
 * 
 * Layer 2: SQL Encapsulator for Admin entities.
 * 0% Business Logic - Pure data access layer for administrator accounts.
 * 
 * @package App\Models
 */
final class AdminModel extends BaseModel
{
    /**
     * Database table name
     * 
     * @var string
     */
    protected $table = 'admins';

    /**
     * Primary key column
     * 
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Entity class for hydration
     * 
     * @var class-string<Admin>
     */
    protected $returnType = Admin::class;

    /**
     * Fields allowed for mass assignment
     * 
     * @var array<string>
     */
    protected $allowedFields = [
        'username',
        'email',
        'password_hash',
        'name',
        'role',
        'active',
        'last_login',
        'login_attempts',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Validation rules for insert/update
     * 
     * @var array<string, array<string, string>>
     */
    protected $validationRules = [
        'username' => [
            'label' => 'Username',
            'rules' => 'required|alpha_numeric|min_length[3]|max_length[50]|is_unique[admins.username,id,{id}]',
            'errors' => [
                'required' => 'Username is required',
                'alpha_numeric' => 'Username can only contain letters and numbers',
                'min_length' => 'Username must be at least 3 characters',
                'max_length' => 'Username cannot exceed 50 characters',
                'is_unique' => 'This username is already taken'
            ]
        ],
        'email' => [
            'label' => 'Email',
            'rules' => 'required|valid_email|max_length[100]|is_unique[admins.email,id,{id}]',
            'errors' => [
                'required' => 'Email is required',
                'valid_email' => 'Email must be a valid email address',
                'max_length' => 'Email cannot exceed 100 characters',
                'is_unique' => 'This email is already registered'
            ]
        ],
        'name' => [
            'label' => 'Name',
            'rules' => 'required|max_length[100]',
            'errors' => [
                'required' => 'Name is required',
                'max_length' => 'Name cannot exceed 100 characters'
            ]
        ],
        'role' => [
            'label' => 'Role',
            'rules' => 'required|in_list[admin,super_admin]',
            'errors' => [
                'required' => 'Role is required',
                'in_list' => 'Role must be either admin or super_admin'
            ]
        ],
        'active' => [
            'label' => 'Active Status',
            'rules' => 'required|in_list[0,1]',
            'errors' => [
                'required' => 'Active status is required',
                'in_list' => 'Active status must be either 0 or 1'
            ]
        ]
    ];

    /**
     * Whether to use timestamps
     * Override from BaseModel for explicit declaration
     * 
     * @var bool
     */
    protected $useTimestamps = true;

    /**
     * Whether to use soft deletes
     * Override from BaseModel for explicit declaration
     * 
     * @var bool
     */
    protected $useSoftDeletes = true;

    /**
     * Date format for timestamps
     * 
     * @var string
     */
    protected $dateFormat = 'datetime';

    // ==================== AUTHENTICATION QUERY METHODS ====================

    /**
     * Find admin by username (case-insensitive)
     * 
     * @param string $username Username to search
     * @return Admin|null
     */
    public function findByUsername(string $username): ?Admin
    {
        $result = $this->where('LOWER(username)', strtolower($username))
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Admin ? $result : null;
    }

    /**
     * Find admin by email (case-insensitive)
     * 
     * @param string $email Email to search
     * @return Admin|null
     */
    public function findByEmail(string $email): ?Admin
    {
        $result = $this->where('LOWER(email)', strtolower($email))
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Admin ? $result : null;
    }

    /**
     * Find admin by username or email (for login)
     * 
     * @param string $identifier Username or email
     * @return Admin|null
     */
    public function findByUsernameOrEmail(string $identifier): ?Admin
    {
        $result = $this->groupStart()
                      ->where('LOWER(username)', strtolower($identifier))
                      ->orWhere('LOWER(email)', strtolower($identifier))
                      ->groupEnd()
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Admin ? $result : null;
    }

    /**
     * Verify admin credentials
     * 
     * @param string $identifier Username or email
     * @param string $password Plain text password
     * @return Admin|null Returns Admin if credentials are valid, null otherwise
     */
    public function verifyCredentials(string $identifier, string $password): ?Admin
    {
        $admin = $this->findByUsernameOrEmail($identifier);
        
        if (!$admin instanceof Admin) {
            return null;
        }

        // Check if account is active
        if (!$admin->isActive()) {
            return null;
        }

        // Check if account is locked
        if ($admin->isLocked()) {
            return null;
        }

        // Verify password
        if (!$admin->verifyPassword($password)) {
            return null;
        }

        return $admin;
    }

    /**
     * Find active admin by ID
     * Override from BaseModel to ensure only active admins are returned
     * 
     * @param int|string|null $id
     * @return Admin|null
     */
    public function findActiveById(int|string|null $id): ?Admin
    {
        if ($id === null) {
            return null;
        }
        
        $result = $this->where($this->table . '.' . $this->primaryKey, $id)
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->first();

        return $result instanceof Admin ? $result : null;
    }

    // ==================== ROLE-BASED QUERY METHODS ====================

    /**
     * Find all super admins
     * 
     * @return Admin[]
     */
    public function findSuperAdmins(): array
    {
        $result = $this->where('role', 'super_admin')
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Find all regular admins
     * 
     * @return Admin[]
     */
    public function findRegularAdmins(): array
    {
        $result = $this->where('role', 'admin')
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Count super admins
     * 
     * @return int
     */
    public function countSuperAdmins(): int
    {
        $result = $this->where('role', 'super_admin')
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->countAllResults();

        return (int) $result;
    }

    /**
     * Check if admin is the last super admin
     * 
     * @param int $adminId Admin ID to check
     * @return bool
     */
    public function isLastSuperAdmin(int $adminId): bool
    {
        // Get the admin
        $admin = $this->findActiveById($adminId);
        if (!$admin instanceof Admin || !$admin->isSuperAdmin()) {
            return false;
        }

        // Count super admins excluding this one
        $count = $this->where('role', 'super_admin')
                     ->where($this->deletedField, null)
                     ->where('active', 1)
                     ->where($this->primaryKey . ' !=', $adminId)
                     ->countAllResults();

        return $count === 0;
    }

    // ==================== STATUS & SECURITY QUERY METHODS ====================

    /**
     * Find active admins
     * 
     * @return Admin[]
     */
    public function findActiveAdmins(): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Find inactive admins
     * 
     * @return Admin[]
     */
    public function findInactiveAdmins(): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 0)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Find locked admins (too many login attempts)
     * 
     * @param int $maxAttempts Maximum allowed attempts before lockout
     * @return Admin[]
     */
    public function findLockedAdmins(int $maxAttempts = 5): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->where('login_attempts >=', $maxAttempts)
                      ->orderBy('login_attempts', 'DESC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Find admins who haven't logged in recently
     * 
     * @param int $days Number of days since last login
     * @return Admin[]
     */
    public function findInactiveForDays(int $days = 30): array
    {
        $date = new \DateTime("-{$days} days");
        $threshold = $date->format('Y-m-d H:i:s');

        $result = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->groupStart()
                      ->where('last_login IS NULL', null)
                      ->orWhere('last_login <', $threshold)
                      ->groupEnd()
                      ->orderBy('last_login', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Find admins with password that needs rehash
     * 
     * @return Admin[]
     */
    public function findAdminsNeedingPasswordRehash(): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->findAll();

        return array_filter(
            $result,
            fn($admin) => $admin instanceof Admin && $admin->passwordNeedsRehash()
        );
    }

    // ==================== SEARCH & FILTER METHODS ====================

    /**
     * Search admins by name, username, or email
     * 
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return Admin[]
     */
    public function searchAdmins(string $searchTerm, int $limit = 20, int $offset = 0): array
    {
        $result = $this->groupStart()
                      ->like('name', $searchTerm, 'both')
                      ->orLike('username', $searchTerm, 'both')
                      ->orLike('email', $searchTerm, 'both')
                      ->groupEnd()
                      ->where($this->deletedField, null)
                      ->orderBy('name', 'ASC')
                      ->findAll($limit, $offset);

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    /**
     * Get paginated admins with optional filters
     * 
     * @param array{
     *     role?: string|null,
     *     active?: bool|null,
     *     search?: string|null
     * } $filters
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: Admin[], pager: \CodeIgniter\Pager\Pager, total: int}
     */
    public function paginateWithFilters(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $builder = $this->builder();

        // Apply filters
        $builder->where($this->deletedField, null);

        if (isset($filters['role']) && $filters['role'] !== null) {
            $builder->where('role', $filters['role']);
        }

        if (isset($filters['active']) && $filters['active'] !== null) {
            $builder->where('active', $filters['active'] ? 1 : 0);
        }

        if (isset($filters['search']) && $filters['search'] !== null) {
            $builder->groupStart()
                   ->like('name', $filters['search'], 'both')
                   ->orLike('username', $filters['search'], 'both')
                   ->orLike('email', $filters['search'], 'both')
                   ->groupEnd();
        }

        // Get total count
        $total = $builder->countAllResults(false);
        
        // Get paginated data
        $data = $builder->orderBy('name', 'ASC')
                       ->paginate($perPage, 'default', $page);

        $pager = $this->pager;

        return [
            'data' => array_filter($data, fn($item) => $item instanceof Admin),
            'pager' => $pager,
            'total' => (int) $total
        ];
    }

    /**
     * Find admins by IDs (batch lookup)
     * 
     * @param array<int|string> $ids Array of admin IDs
     * @return Admin[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = $this->whereIn($this->primaryKey, $ids)
                      ->where($this->deletedField, null)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Admin);
    }

    // ==================== SECURITY UPDATE METHODS ====================

    /**
     * Record successful login for admin
     * 
     * @param int $adminId Admin ID
     * @return bool
     */
    public function recordSuccessfulLogin(int $adminId): bool
    {
        $data = [
            'last_login' => date('Y-m-d H:i:s'),
            'login_attempts' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    /**
     * Record failed login attempt for admin
     * 
     * @param int $adminId Admin ID
     * @return bool
     */
    public function recordFailedLoginAttempt(int $adminId): bool
    {
        // Get current attempts
        $admin = $this->findActiveById($adminId);
        if (!$admin instanceof Admin) {
            return false;
        }

        $newAttempts = $admin->getLoginAttempts() + 1;
        $data = [
            'login_attempts' => $newAttempts,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    /**
     * Reset login attempts for admin
     * 
     * @param int $adminId Admin ID
     * @return bool
     */
    public function resetLoginAttempts(int $adminId): bool
    {
        $data = [
            'login_attempts' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    /**
     * Update admin password
     * 
     * @param int $adminId Admin ID
     * @param string $newPasswordHash New password hash
     * @return bool
     */
    public function updatePassword(int $adminId, string $newPasswordHash): bool
    {
        $data = [
            'password_hash' => $newPasswordHash,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    /**
     * Activate admin account
     * 
     * @param int $adminId Admin ID
     * @return bool
     */
    public function activateAccount(int $adminId): bool
    {
        $data = [
            'active' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    /**
     * Deactivate admin account
     * 
     * @param int $adminId Admin ID
     * @return bool
     */
    public function deactivateAccount(int $adminId): bool
    {
        $data = [
            'active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    /**
     * Update admin role
     * 
     * @param int $adminId Admin ID
     * @param string $newRole New role (admin or super_admin)
     * @return bool
     */
    public function updateRole(int $adminId, string $newRole): bool
    {
        if (!in_array($newRole, ['admin', 'super_admin'])) {
            return false;
        }

        $data = [
            'role' => $newRole,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($adminId, $data);
    }

    // ==================== STATISTICS & REPORTING ====================

    /**
     * Get admin statistics
     * 
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     super_admins: int,
     *     regular_admins: int,
     *     locked: int,
     *     recently_active: int
     * }
     */
    public function getStatistics(): array
    {
        $total = $this->where($this->deletedField, null)
                     ->countAllResults();
        
        $active = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->countAllResults();
        
        $inactive = $this->where($this->deletedField, null)
                        ->where('active', 0)
                        ->countAllResults();
        
        $superAdmins = $this->where($this->deletedField, null)
                           ->where('role', 'super_admin')
                           ->where('active', 1)
                           ->countAllResults();
        
        $regularAdmins = $this->where($this->deletedField, null)
                             ->where('role', 'admin')
                             ->where('active', 1)
                             ->countAllResults();
        
        // Locked admins (5 or more attempts)
        $locked = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->where('login_attempts >=', 5)
                      ->countAllResults();
        
        // Recently active (within 24 hours)
        $date = new \DateTime('-24 hours');
        $recentlyActive = $this->where($this->deletedField, null)
                              ->where('active', 1)
                              ->where('last_login >=', $date->format('Y-m-d H:i:s'))
                              ->countAllResults();

        return [
            'total' => (int) $total,
            'active' => (int) $active,
            'inactive' => (int) $inactive,
            'super_admins' => (int) $superAdmins,
            'regular_admins' => (int) $regularAdmins,
            'locked' => (int) $locked,
            'recently_active' => (int) $recentlyActive
        ];
    }

    /**
     * Get admin activity timeline (logins per day)
     * 
     * @param int $days Number of days to look back
     * @return array<array{date: string, login_count: int}>
     */
    public function getActivityTimeline(int $days = 30): array
    {
        $startDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $builder = $this->builder();
        
        $query = $builder->select("DATE(last_login) as date, COUNT(*) as login_count")
                        ->where($this->deletedField, null)
                        ->where('active', 1)
                        ->where('last_login >=', $startDate . ' 00:00:00')
                        ->where('last_login IS NOT NULL', null)
                        ->groupBy('DATE(last_login)')
                        ->orderBy('date', 'ASC')
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $results[] = [
                'date' => $row['date'],
                'login_count' => (int) $row['login_count']
            ];
        }

        return $results;
    }

    /**
     * Check if username exists (excluding current ID)
     * 
     * @param string $username Username to check
     * @param int|string|null $excludeId ID to exclude (for updates)
     * @return bool
     */
    public function usernameExists(string $username, int|string|null $excludeId = null): bool
    {
        $query = $this->where('LOWER(username)', strtolower($username))
                     ->where($this->deletedField, null);

        if ($excludeId !== null) {
            $query->where($this->primaryKey . ' !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }

    /**
     * Check if email exists (excluding current ID)
     * 
     * @param string $email Email to check
     * @param int|string|null $excludeId ID to exclude (for updates)
     * @return bool
     */
    public function emailExists(string $email, int|string|null $excludeId = null): bool
    {
        $query = $this->where('LOWER(email)', strtolower($email))
                     ->where($this->deletedField, null);

        if ($excludeId !== null) {
            $query->where($this->primaryKey . ' !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }

    // ==================== OVERRIDDEN METHODS ====================

    /**
     * Insert admin with validation
     * Override to handle password hashing through entity
     * 
     * @param array<string, mixed>|object|null $data
     * @return int|string|false
     */
    public function insert($data = null, bool $returnID = true)
    {
        // Convert Admin entity to array if needed
        if ($data instanceof Admin) {
            // Let the entity prepare itself
            $data->prepareForSave(false);
            
            $data = [
                'username' => $data->getUsername(),
                'email' => $data->getEmail(),
                'password_hash' => $data->getPasswordHash(),
                'name' => $data->getName(),
                'role' => $data->getRole(),
                'active' => $data->isActive() ? 1 : 0,
                'login_attempts' => $data->getLoginAttempts(),
                'last_login' => $data->getLastLogin()?->format('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        return parent::insert($data, $returnID);
    }

    /**
     * Update admin with validation
     * Override to handle password hashing through entity
     * 
     * @param array<string, mixed>|int|string|null $id
     * @param array<string, mixed>|object|null $data
     * @return bool
     */
    public function update($id = null, $data = null): bool
    {
        // Convert Admin entity to array if needed
        if ($data instanceof Admin) {
            // Let the entity prepare itself
            $data->prepareForSave(true);
            
            $updateData = [
                'username' => $data->getUsername(),
                'email' => $data->getEmail(),
                'name' => $data->getName(),
                'role' => $data->getRole(),
                'active' => $data->isActive() ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Only update password_hash if it has changed
            if ($data->getPasswordHash() !== '') {
                $updateData['password_hash'] = $data->getPasswordHash();
            }

            // Only update if values changed
            return $this->updateIfChanged($id, $updateData);
        }

        return parent::update($id, $data);
    }

    /**
     * Physical delete protection
     * Override to enforce soft delete only for admins
     * 
     * @throws RuntimeException Always, since physical deletes are disabled
     */
    public function delete($id = null, bool $purge = false): bool
    {
        if ($purge) {
            throw new RuntimeException('Physical deletes are disabled in MVP. Use archive() method.');
        }

        return parent::delete($id, false);
    }

    /**
     * Bulk archive admins with safety checks
     * 
     * @param array<int|string> $ids Admin IDs to archive
     * @return int Number of archived admins
     */
    public function bulkArchive(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $data = [
            $this->deletedField => date('Y-m-d H:i:s'),
            $this->updatedField => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Bulk restore archived admins
     * 
     * @param array<int|string> $ids Admin IDs to restore
     * @return int Number of restored admins
     */
    public function bulkRestore(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $data = [
            $this->deletedField => null,
            $this->updatedField => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Initialize system admin if not exists
     * Useful for database seeding
     * 
     * @return array{created: bool, message: string}
     */
    public function initializeSystemAdmin(): array
    {
        $systemAdmin = Admin::createSystemAdmin();
        
        // Check if system admin already exists
        $existing = $this->findByUsername('system');
        if ($existing instanceof Admin) {
            return [
                'created' => false,
                'message' => 'System admin already exists'
            ];
        }

        // Check if email exists
        $existingEmail = $this->findByEmail('system@devdaily.local');
        if ($existingEmail instanceof Admin) {
            return [
                'created' => false,
                'message' => 'System admin email already registered'
            ];
        }

        $this->insert($systemAdmin);
        
        return [
            'created' => true,
            'message' => 'System admin created successfully'
        ];
    }

    /**
     * Create sample admin (for testing/demo)
     * 
     * @param array<string, mixed> $overrides Override default values
     * @return Admin
     */
    public function createSample(array $overrides = []): Admin
    {
        $defaults = [
            'username' => 'sample_admin',
            'email' => 'sample@example.com',
            'name' => 'Sample Admin',
            'role' => 'admin',
            'active' => true
        ];

        $data = array_merge($defaults, $overrides);

        $admin = new Admin($data['username'], $data['email'], $data['name']);
        $admin->setRole($data['role']);
        $admin->setActive($data['active']);
        $admin->setPasswordWithHash('SamplePassword123!');
        $admin->setLastLogin(new \DateTimeImmutable('-2 hours'));

        return $admin;
    }
}