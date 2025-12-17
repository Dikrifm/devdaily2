<?php

namespace App\Repositories\Interfaces;

use App\Entities\Admin;
use App\Exceptions\AdminNotFoundException;

interface AdminRepositoryInterface
{
    // ==================== BASIC CRUD OPERATIONS ====================
    
    /**
     * Find admin by ID
     *
     * @param int $id Admin ID
     * @param bool $withTrashed Include soft deleted admins
     * @return Admin|null
     */
    public function find(int $id, bool $withTrashed = false): ?Admin;
    
    /**
     * Find admin by username
     *
     * @param string $username Username
     * @param bool $withTrashed Include soft deleted admins
     * @return Admin|null
     */
    public function findByUsername(string $username, bool $withTrashed = false): ?Admin;
    
    /**
     * Find admin by email
     *
     * @param string $email Email address
     * @param bool $withTrashed Include soft deleted admins
     * @return Admin|null
     */
    public function findByEmail(string $email, bool $withTrashed = false): ?Admin;
    
    /**
     * Find admin by identifier (username or email)
     *
     * @param string $identifier Username or email
     * @param bool $withTrashed Include soft deleted admins
     * @return Admin|null
     */
    public function findByIdentifier(string $identifier, bool $withTrashed = false): ?Admin;
    
    /**
     * Get all admins with filtering
     *
     * @param array $filters [
     *     'role' => string,
     *     'active' => bool,
     *     'search' => string,
     *     'date_from' => string,
     *     'date_to' => string,
     *     'last_login_from' => string,
     *     'last_login_to' => string
     * ]
     * @param string $sortBy
     * @param string $sortDirection
     * @param bool $withTrashed Include soft deleted admins
     * @return array
     */
    public function findAll(
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'DESC',
        bool $withTrashed = false
    ): array;
    
    /**
     * Save admin (create or update)
     *
     * @param Admin $admin
     * @return Admin
     * @throws \RuntimeException
     */
    public function save(Admin $admin): Admin;
    
    /**
     * Delete admin
     *
     * @param int $id Admin ID
     * @param bool $force Permanent deletion
     * @return bool
     */
    public function delete(int $id, bool $force = false): bool;
    
    /**
     * Restore soft deleted admin
     *
     * @param int $id Admin ID
     * @return bool
     */
    public function restore(int $id): bool;
    
    /**
     * Check if admin exists
     *
     * @param int $id Admin ID
     * @param bool $withTrashed Include soft deleted admins
     * @return bool
     */
    public function exists(int $id, bool $withTrashed = false): bool;
    
    // ==================== AUTHENTICATION & SECURITY ====================
    
    /**
     * Authenticate admin with credentials
     *
     * @param string $identifier Username or email
     * @param string $password Plain text password
     * @param string $ipAddress Client IP address
     * @return array [success => bool, admin => Admin|null, message => string]
     */
    public function authenticate(string $identifier, string $password, string $ipAddress): array;
    
    /**
     * Verify password against hash
     *
     * @param string $password Plain text password
     * @param string $hash Password hash
     * @return bool
     */
    public function verifyPassword(string $password, string $hash): bool;
    
    /**
     * Hash password using secure algorithm
     *
     * @param string $password Plain text password
     * @return string Password hash
     */
    public function hashPassword(string $password): string;
    
    /**
     * Check if password needs rehash
     *
     * @param string $hash Password hash
     * @return bool
     */
    public function passwordNeedsRehash(string $hash): bool;
    
    /**
     * Update admin password
     *
     * @param int $adminId Admin ID
     * @param string $newPassword New plain text password
     * @return bool
     */
    public function updatePassword(int $adminId, string $newPassword): bool;
    
    /**
     * Generate random secure password
     *
     * @param int $length Password length
     * @return string
     */
    public function generateRandomPassword(int $length = 12): string;
    
    /**
     * Validate password strength
     *
     * @param string $password Password to validate
     * @return array [is_valid => bool, errors => string[], score => int]
     */
    public function validatePasswordStrength(string $password): array;
    
    // ==================== LOGIN & SESSION MANAGEMENT ====================
    
    /**
     * Record successful login
     *
     * @param int $adminId Admin ID
     * @param string $ipAddress Client IP address
     * @param string $userAgent User agent string
     * @return bool
     */
    public function recordSuccessfulLogin(int $adminId, string $ipAddress, string $userAgent): bool;
    
    /**
     * Record failed login attempt
     *
     * @param int $adminId Admin ID
     * @param string $ipAddress Client IP address
     * @param string $reason Failure reason
     * @return bool
     */
    public function recordFailedLogin(int $adminId, string $ipAddress, string $reason = 'invalid_credentials'): bool;
    
    /**
     * Reset login attempts counter
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function resetLoginAttempts(int $adminId): bool;
    
    /**
     * Increment login attempts counter
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function incrementLoginAttempts(int $adminId): bool;
    
    /**
     * Check if account is locked due to too many failed attempts
     *
     * @param int $adminId Admin ID
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $lockoutDuration Lockout duration in minutes
     * @return array [is_locked => bool, attempts_remaining => int, lockout_until => string|null]
     */
    public function isAccountLocked(int $adminId, int $maxAttempts = 5, int $lockoutDuration = 15): array;
    
    /**
     * Get login attempts count
     *
     * @param int $adminId Admin ID
     * @param string $timeWindow Time window (e.g., '1 hour', '24 hours')
     * @return int
     */
    public function getLoginAttemptsCount(int $adminId, string $timeWindow = '1 hour'): int;
    
    /**
     * Clear all login attempts (unlock account)
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function clearLoginAttempts(int $adminId): bool;
    
    /**
     * Record logout
     *
     * @param int $adminId Admin ID
     * @param string $ipAddress Client IP address
     * @return bool
     */
    public function recordLogout(int $adminId, string $ipAddress): bool;
    
    // ==================== ROLE & PERMISSION MANAGEMENT ====================
    
    /**
     * Promote admin to super admin
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function promoteToSuperAdmin(int $adminId): bool;
    
    /**
     * Demote super admin to regular admin
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function demoteToAdmin(int $adminId): bool;
    
    /**
     * Check if admin has specific role
     *
     * @param int $adminId Admin ID
     * @param string $role Role to check
     * @return bool
     */
    public function hasRole(int $adminId, string $role): bool;
    
    /**
     * Check if admin is super admin
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function isSuperAdmin(int $adminId): bool;
    
    /**
     * Check if admin is regular admin
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function isRegularAdmin(int $adminId): bool;
    
    /**
     * Get admin permissions
     *
     * @param int $adminId Admin ID
     * @return array List of permissions
     */
    public function getPermissions(int $adminId): array;
    
    /**
     * Check if admin has permission
     *
     * @param int $adminId Admin ID
     * @param string $permission Permission to check
     * @return bool
     */
    public function hasPermission(int $adminId, string $permission): bool;
    
    /**
     * Update admin permissions
     *
     * @param int $adminId Admin ID
     * @param array $permissions List of permissions
     * @return bool
     */
    public function updatePermissions(int $adminId, array $permissions): bool;
    
    /**
     * Get all super admins
     *
     * @param bool $activeOnly Only active super admins
     * @return array
     */
    public function findSuperAdmins(bool $activeOnly = true): array;
    
    /**
     * Count super admins
     *
     * @param bool $activeOnly Only active super admins
     * @return int
     */
    public function countSuperAdmins(bool $activeOnly = true): int;
    
    // ==================== STATUS & ACTIVATION MANAGEMENT ====================
    
    /**
     * Activate admin account
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function activate(int $adminId): bool;
    
    /**
     * Deactivate admin account
     *
     * @param int $adminId Admin ID
     * @param string|null $reason Reason for deactivation
     * @return bool
     */
    public function deactivate(int $adminId, ?string $reason = null): bool;
    
    /**
     * Suspend admin account (temporary deactivation)
     *
     * @param int $adminId Admin ID
     * @param string $reason Suspension reason
     * @param \DateTimeInterface|null $until Suspend until date
     * @return bool
     */
    public function suspend(int $adminId, string $reason, ?\DateTimeInterface $until = null): bool;
    
    /**
     * Unsuspend admin account
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function unsuspend(int $adminId): bool;
    
    /**
     * Check if admin account is active
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function isActive(int $adminId): bool;
    
    /**
     * Check if admin account is suspended
     *
     * @param int $adminId Admin ID
     * @return bool
     */
    public function isSuspended(int $adminId): bool;
    
    /**
     * Get account status
     *
     * @param int $adminId Admin ID
     * @return string active|inactive|suspended|locked
     */
    public function getAccountStatus(int $adminId): string;
    
    // ==================== SEARCH & FILTER ====================
    
    /**
     * Search admins by keyword (name, username, email)
     *
     * @param string $keyword Search term
     * @param bool $activeOnly Only active admins
     * @param bool $withTrashed Include soft deleted admins
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function search(
        string $keyword,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 50,
        int $offset = 0
    ): array;
    
    /**
     * Find admins by role
     *
     * @param string $role Role to filter by
     * @param bool $activeOnly Only active admins
     * @param int $limit Result limit
     * @return array
     */
    public function findByRole(string $role, bool $activeOnly = true, int $limit = 100): array;
    
    /**
     * Find admins by IDs
     *
     * @param array $adminIds Array of admin IDs
     * @param bool $activeOnly Only active admins
     * @param bool $withTrashed Include soft deleted admins
     * @return array
     */
    public function findByIds(
        array $adminIds,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array;
    
    /**
     * Find recently active admins
     *
     * @param int $hoursActiveWithin Hours within last activity
     * @param int $limit Result limit
     * @return array
     */
    public function findRecentlyActive(int $hoursActiveWithin = 24, int $limit = 20): array;
    
    /**
     * Find inactive admins (not logged in for a period)
     *
     * @param int $daysInactive Days since last login
     * @param int $limit Result limit
     * @return array
     */
    public function findInactive(int $daysInactive = 30, int $limit = 50): array;
    
    // ==================== STATISTICS & ANALYTICS ====================
    
    /**
     * Get admin statistics
     *
     * @param int|null $adminId Admin ID (null for system-wide)
     * @return array
     */
    public function getStatistics(?int $adminId = null): array;
    
    /**
     * Count admins by status
     *
     * @param bool $withTrashed Include soft deleted admins
     * @return array [active => int, inactive => int, suspended => int, locked => int]
     */
    public function countByStatus(bool $withTrashed = false): array;
    
    /**
     * Count admins by role
     *
     * @param bool $activeOnly Only active admins
     * @return array [role => count]
     */
    public function countByRole(bool $activeOnly = true): array;
    
    /**
     * Count total admins
     *
     * @param bool $withTrashed Include soft deleted admins
     * @return int
     */
    public function countAll(bool $withTrashed = false): int;
    
    /**
     * Count active admins
     *
     * @return int
     */
    public function countActive(): int;
    
    /**
     * Get login activity statistics
     *
     * @param string $period Time period (day, week, month, year)
     * @return array [total_logins, failed_logins, unique_admins, avg_logins_per_admin]
     */
    public function getLoginActivityStats(string $period = 'month'): array;
    
    /**
     * Get admin activity ranking
     *
     * @param string $period Time period
     * @param string $metric logins|actions|created_items
     * @param int $limit Top N admins
     * @return array
     */
    public function getActivityRanking(string $period = 'month', string $metric = 'actions', int $limit = 10): array;
    
    /**
     * Get admin dashboard statistics
     *
     * @return array
     */
    public function getDashboardStats(): array;
    
    // ==================== BATCH & BULK OPERATIONS ====================
    
    /**
     * Bulk update admins
     *
     * @param array $adminIds Array of admin IDs
     * @param array $updateData Data to update
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $adminIds, array $updateData): int;
    
    /**
     * Bulk activate admins
     *
     * @param array $adminIds Array of admin IDs
     * @return int Number of activated admins
     */
    public function bulkActivate(array $adminIds): int;
    
    /**
     * Bulk deactivate admins
     *
     * @param array $adminIds Array of admin IDs
     * @param string|null $reason Reason for deactivation
     * @return int Number of deactivated admins
     */
    public function bulkDeactivate(array $adminIds, ?string $reason = null): int;
    
    /**
     * Bulk delete admins
     *
     * @param array $adminIds Array of admin IDs
     * @param bool $force Permanent deletion
     * @return int Number of deleted admins
     */
    public function bulkDelete(array $adminIds, bool $force = false): int;
    
    /**
     * Bulk restore admins
     *
     * @param array $adminIds Array of admin IDs
     * @return int Number of restored admins
     */
    public function bulkRestore(array $adminIds): int;
    
    /**
     * Bulk update roles
     *
     * @param array $adminIds Array of admin IDs
     * @param string $newRole New role
     * @return int Number of updated admins
     */
    public function bulkUpdateRoles(array $adminIds, string $newRole): int;
    
    /**
     * Bulk reset passwords
     *
     * @param array $adminIds Array of admin IDs
     * @param bool $generateNew Generate new random passwords
     * @param string|null $newPassword New password (if not generating)
     * @return array [processed => int, updated => int, passwords => array]
     */
    public function bulkResetPasswords(
        array $adminIds,
        bool $generateNew = true,
        ?string $newPassword = null
    ): array;
    
    // ==================== VALIDATION & BUSINESS RULES ====================
    
    /**
     * Check if admin can be deleted
     *
     * @param int $adminId Admin ID to delete
     * @param int $currentAdminId Admin performing the deletion
     * @return array [can_delete => bool, reasons => string[], is_self => bool, is_last_super_admin => bool]
     */
    public function canDelete(int $adminId, int $currentAdminId): array;
    
    /**
     * Check if admin can be deactivated
     *
     * @param int $adminId Admin ID to deactivate
     * @param int $currentAdminId Admin performing the action
     * @return array [can_deactivate => bool, reasons => string[], is_self => bool]
     */
    public function canDeactivate(int $adminId, int $currentAdminId): array;
    
    /**
     * Check if username is unique
     *
     * @param string $username Username to check
     * @param int|null $excludeId Admin ID to exclude (for updates)
     * @return bool
     */
    public function isUsernameUnique(string $username, ?int $excludeId = null): bool;
    
    /**
     * Check if email is unique
     *
     * @param string $email Email to check
     * @param int|null $excludeId Admin ID to exclude (for updates)
     * @return bool
     */
    public function isEmailUnique(string $email, ?int $excludeId = null): bool;
    
    /**
     * Validate admin business rules
     *
     * @param Admin $admin
     * @return array [is_valid => bool, errors => string[]]
     */
    public function validate(Admin $admin): array;
    
    /**
     * Validate admin data for create/update
     *
     * @param array $data Admin data
     * @param int|null $adminId Admin ID for updates (null for create)
     * @return array [is_valid => bool, errors => string[], validated_data => array]
     */
    public function validateAdminData(array $data, ?int $adminId = null): array;
    
    /**
     * Check if admin can perform action on entity
     *
     * @param int $adminId Admin ID
     * @param string $action Action to perform
     * @param string|null $entityType Entity type
     * @param int|null $entityId Entity ID
     * @return array [can_perform => bool, reasons => string[]]
     */
    public function canPerformAction(
        int $adminId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null
    ): array;
    
    // ==================== CACHE MANAGEMENT ====================
    
    /**
     * Clear admin caches
     *
     * @param int|null $adminId Specific admin ID (null for all)
     * @return void
     */
    public function clearCache(?int $adminId = null): void;
    
    /**
     * Get cache TTL setting
     *
     * @return int Cache TTL in seconds
     */
    public function getCacheTtl(): int;
    
    /**
     * Set cache TTL
     *
     * @param int $ttl Cache TTL in seconds
     * @return self
     */
    public function setCacheTtl(int $ttl): self;
    
    // ==================== UTILITY & HELPER METHODS ====================
    
    /**
     * Get system admin (special admin for system operations)
     *
     * @return Admin|null
     */
    public function getSystemAdmin(): ?Admin;
    
    /**
     * Create system admin if not exists
     *
     * @return Admin
     */
    public function createSystemAdmin(): Admin;
    
    /**
     * Get admin profile with extended information
     *
     * @param int $adminId Admin ID
     * @return array
     */
    public function getProfile(int $adminId): array;
    
    /**
     * Update admin profile
     *
     * @param int $adminId Admin ID
     * @param array $profileData Profile data
     * @return bool
     */
    public function updateProfile(int $adminId, array $profileData): bool;
    
    /**
     * Get admin activity logs
     *
     * @param int $adminId Admin ID
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function getActivityLogs(int $adminId, int $limit = 50, int $offset = 0): array;
    
    /**
     * Get admin login history
     *
     * @param int $adminId Admin ID
     * @param int $limit Result limit
     * @return array
     */
    public function getLoginHistory(int $adminId, int $limit = 20): array;
    
    /**
     * Get admin sessions (active)
     *
     * @param int $adminId Admin ID
     * @return array
     */
    public function getActiveSessions(int $adminId): array;
    
    /**
     * Terminate admin session
     *
     * @param int $adminId Admin ID
     * @param string $sessionId Session ID
     * @return bool
     */
    public function terminateSession(int $adminId, string $sessionId): bool;
    
    /**
     * Terminate all admin sessions except current
     *
     * @param int $adminId Admin ID
     * @param string $currentSessionId Current session ID
     * @return int Number of terminated sessions
     */
    public function terminateAllOtherSessions(int $adminId, string $currentSessionId): int;
    
    /**
     * Generate API token for admin
     *
     * @param int $adminId Admin ID
     * @param string $tokenName Token name/description
     * @param array $scopes Token scopes
     * @param \DateTimeInterface|null $expiresAt Expiration date
     * @return array [token => string, token_id => string]
     */
    public function generateApiToken(
        int $adminId,
        string $tokenName,
        array $scopes = [],
        ?\DateTimeInterface $expiresAt = null
    ): array;
    
    /**
     * Revoke API token
     *
     * @param int $adminId Admin ID
     * @param string $tokenId Token ID
     * @return bool
     */
    public function revokeApiToken(int $adminId, string $tokenId): bool;
    
    /**
     * Get admin API tokens
     *
     * @param int $adminId Admin ID
     * @param bool $activeOnly Only active tokens
     * @return array
     */
    public function getApiTokens(int $adminId, bool $activeOnly = true): array;
    
    /**
     * Get admin suggestions for dropdowns/autocomplete
     *
     * @param string|null $query Search query
     * @param bool $activeOnly Only active admins
     * @param int $limit Result limit
     * @return array [id => name, ...]
     */
    public function getSuggestions(?string $query = null, bool $activeOnly = true, int $limit = 20): array;
    
    /**
     * Get admin initials (for avatars)
     *
     * @param int $adminId Admin ID
     * @return string
     */
    public function getInitials(int $adminId): string;
    
    /**
     * Get admin display name
     *
     * @param int $adminId Admin ID
     * @return string
     */
    public function getDisplayName(int $adminId): string;
    
    /**
     * Get admin summary for quick views
     *
     * @param int $adminId Admin ID
     * @return array
     */
    public function getSummary(int $adminId): array;
    
    /**
     * Export admin data
     *
     * @param int $adminId Admin ID
     * @param string $format Export format (array, json, csv)
     * @return mixed
     */
    public function exportData(int $adminId, string $format = 'array');
    
    /**
     * Import admin data
     *
     * @param array $data Admin data
     * @param bool $updateExisting Update if exists
     * @return array [created => int, updated => int, errors => array]
     */
    public function importData(array $data, bool $updateExisting = false): array;
    
    /**
     * Check admin health status (for monitoring)
     *
     * @param int $adminId Admin ID
     * @return array [status => string, issues => array, last_activity => string]
     */
    public function getHealthStatus(int $adminId): array;
    
    /**
     * Find similar admins (by role/activity)
     *
     * @param int $adminId Admin ID
     * @param int $limit Result limit
     * @return array
     */
    public function findSimilar(int $adminId, int $limit = 5): array;
    
    /**
     * Get admin notification preferences
     *
     * @param int $adminId Admin ID
     * @return array
     */
    public function getNotificationPreferences(int $adminId): array;
    
    /**
     * Update admin notification preferences
     *
     * @param int $adminId Admin ID
     * @param array $preferences Notification preferences
     * @return bool
     */
    public function updateNotificationPreferences(int $adminId, array $preferences): bool;
}