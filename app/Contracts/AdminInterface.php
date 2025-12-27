<?php

namespace App\Contracts;

use App\DTOs\Requests\Admin\CreateAdminRequest;
use App\DTOs\Requests\Admin\UpdateAdminRequest;
use App\DTOs\Requests\Admin\ChangeAdminPasswordRequest;
use App\DTOs\Requests\Admin\ToggleAdminStatusRequest;
use App\DTOs\Responses\AdminResponse;
use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Responses\BulkActionResult;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;

/**
 * Admin Service Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for admin management operations.
 * Defines protocol for admin CRUD, authentication, and administrative functions.
 *
 * @package App\Contracts
 */
interface AdminInterface extends BaseInterface
{
    // ==================== ADMIN CRUD OPERATIONS ====================

    /**
     * Create new admin with validation and audit logging
     *
     * @param CreateAdminRequest $request
     * @return AdminResponse
     * @throws ValidationException
     * @throws DomainException
     */
    public function createAdmin(CreateAdminRequest $request): AdminResponse;

    /**
     * Get admin by ID with full hydration
     *
     * @param int $adminId
     * @return AdminResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getAdmin(int $adminId): AdminResponse;

    /**
     * Update admin profile and metadata
     *
     * @param UpdateAdminRequest $request
     * @return AdminResponse
     * @throws NotFoundException
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function updateAdmin(UpdateAdminRequest $request): AdminResponse;

    /**
     * Change admin password with security validation
     *
     * @param ChangeAdminPasswordRequest $request
     * @return AdminResponse
     * @throws NotFoundException
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function changePassword(ChangeAdminPasswordRequest $request): AdminResponse;

    /**
     * Toggle admin active status (activate/deactivate)
     *
     * @param ToggleAdminStatusRequest $request
     * @return AdminResponse
     * @throws NotFoundException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function toggleStatus(ToggleAdminStatusRequest $request): AdminResponse;

    /**
     * Archive admin (soft delete with validation)
     *
     * @param int $adminId
     * @param string|null $reason
     * @return AdminResponse
     * @throws NotFoundException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function archiveAdmin(int $adminId, ?string $reason = null): AdminResponse;

    /**
     * Restore archived admin
     *
     * @param int $adminId
     * @return AdminResponse
     * @throws NotFoundException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function restoreAdmin(int $adminId): AdminResponse;

    /**
     * Permanently delete admin (hard delete)
     *
     * @param int $adminId
     * @param string|null $reason
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function deleteAdmin(int $adminId, ?string $reason = null): bool;

    // ==================== ADMIN LISTING & SEARCH ====================

    /**
     * Get paginated list of admins with filters
     *
     * @param PaginationQuery $pagination
     * @param array<string, mixed> $filters
     * @return array{
     *     items: array<AdminResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     */
    public function listAdmins(PaginationQuery $pagination, array $filters = []): array;

    /**
     * Search admins by name, email, or username
     *
     * @param string $searchTerm
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AdminResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     */
    public function searchAdmins(string $searchTerm, PaginationQuery $pagination): array;

    /**
     * Get all active admins
     *
     * @return array<AdminResponse>
     */
    public function getActiveAdmins(): array;

    /**
     * Get all super admins
     *
     * @return array<AdminResponse>
     */
    public function getSuperAdmins(): array;

    /**
     * Get all inactive admins
     *
     * @return array<AdminResponse>
     */
    public function getInactiveAdmins(): array;

    /**
     * Get all archived admins
     *
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AdminResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     */
    public function getArchivedAdmins(PaginationQuery $pagination): array;

    // ==================== ADMIN AUTHENTICATION & SESSION ====================

    /**
     * Record successful admin login
     *
     * @param int $adminId
     * @param array<string, mixed> $sessionData
     * @return AdminResponse
     * @throws NotFoundException
     */
    public function recordLogin(int $adminId, array $sessionData): AdminResponse;

    /**
     * Record failed login attempt
     *
     * @param int $adminId
     * @return AdminResponse
     * @throws NotFoundException
     */
    public function recordFailedLogin(int $adminId): AdminResponse;

    /**
     * Reset login attempts counter
     *
     * @param int $adminId
     * @return AdminResponse
     * @throws NotFoundException
     */
    public function resetLoginAttempts(int $adminId): AdminResponse;

    /**
     * Check if admin account is locked
     *
     * @param int $adminId
     * @param int $maxAttempts
     * @return bool
     * @throws NotFoundException
     */
    public function isAccountLocked(int $adminId, int $maxAttempts = 5): bool;

    /**
     * Update last login timestamp
     *
     * @param int $adminId
     * @return AdminResponse
     * @throws NotFoundException
     */
    public function updateLastLogin(int $adminId): AdminResponse;

    // ==================== BULK OPERATIONS ====================

    /**
     * Bulk archive admins
     *
     * @param array<int> $adminIds
     * @param string|null $reason
     * @return BulkActionResult
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function bulkArchive(array $adminIds, ?string $reason = null): BulkActionResult;

    /**
     * Bulk restore admins
     *
     * @param array<int> $adminIds
     * @return BulkActionResult
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function bulkRestore(array $adminIds): BulkActionResult;

    /**
     * Bulk activate admins
     *
     * @param array<int> $adminIds
     * @return BulkActionResult
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function bulkActivate(array $adminIds): BulkActionResult;

    /**
     * Bulk deactivate admins
     *
     * @param array<int> $adminIds
     * @param string|null $reason
     * @return BulkActionResult
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function bulkDeactivate(array $adminIds, ?string $reason = null): BulkActionResult;

    /**
     * Bulk change admin roles
     *
     * @param array<int> $adminIds
     * @param string $newRole
     * @param string|null $reason
     * @return BulkActionResult
     * @throws DomainException
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function bulkChangeRole(array $adminIds, string $newRole, ?string $reason = null): BulkActionResult;

    // ==================== ADMIN ROLE & PERMISSION MANAGEMENT ====================

    /**
     * Change admin role with validation
     *
     * @param int $adminId
     * @param string $newRole
     * @param string|null $reason
     * @return AdminResponse
     * @throws NotFoundException
     * @throws DomainException
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function changeRole(int $adminId, string $newRole, ?string $reason = null): AdminResponse;

    /**
     * Promote admin to super admin
     *
     * @param int $adminId
     * @param string|null $reason
     * @return AdminResponse
     * @throws NotFoundException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function promoteToSuperAdmin(int $adminId, ?string $reason = null): AdminResponse;

    /**
     * Demote admin to regular admin
     *
     * @param int $adminId
     * @param string|null $reason
     * @return AdminResponse
     * @throws NotFoundException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function demoteToAdmin(int $adminId, ?string $reason = null): AdminResponse;

    /**
     * Check if admin is super admin
     *
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     */
    public function isSuperAdmin(int $adminId): bool;

    /**
     * Check if admin is last super admin (cannot be demoted/deleted)
     *
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     */
    public function isLastSuperAdmin(int $adminId): bool;

    /**
     * Get admin permissions
     *
     * @param int $adminId
     * @return array<string, bool>
     * @throws NotFoundException
     */
    public function getPermissions(int $adminId): array;

    // ==================== ADMIN VALIDATION & BUSINESS RULES ====================

    /**
     * Check if username is available
     *
     * @param string $username
     * @param int|null $excludeAdminId
     * @return bool
     */
    public function isUsernameAvailable(string $username, ?int $excludeAdminId = null): bool;

    /**
     * Check if email is available
     *
     * @param string $email
     * @param int|null $excludeAdminId
     * @return bool
     */
    public function isEmailAvailable(string $email, ?int $excludeAdminId = null): bool;

    /**
     * Validate admin can be archived
     *
     * @param int $adminId
     * @return array{
     *     can_archive: bool,
     *     reasons: array<string>,
     *     warnings: array<string>
     * }
     * @throws NotFoundException
     */
    public function validateCanArchive(int $adminId): array;

    /**
     * Validate admin can be deleted
     *
     * @param int $adminId
     * @return array{
     *     can_delete: bool,
     *     reasons: array<string>,
     *     warnings: array<string>
     * }
     * @throws NotFoundException
     */
    public function validateCanDelete(int $adminId): array;

    /**
     * Validate admin can be demoted
     *
     * @param int $adminId
     * @return array{
     *     can_demote: bool,
     *     reasons: array<string>,
     *     warnings: array<string>
     * }
     * @throws NotFoundException
     */
    public function validateCanDemote(int $adminId): array;

    // ==================== ADMIN STATISTICS & REPORTING ====================

    /**
     * Get admin statistics
     *
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     super_admins: int,
     *     regular_admins: int,
     *     archived: int,
     *     locked: int,
     *     need_password_rehash: int
     * }
     */
    public function getStatistics(): array;

    /**
     * Get admin activity timeline
     *
     * @param int $days
     * @param int|null $adminId
     * @return array<int, array{
     *     date: string,
     *     logins: int,
     *     actions: int,
     *     admin_id: int|null,
     *     admin_name: string|null
     * }>
     */
    public function getActivityTimeline(int $days = 30, ?int $adminId = null): array;

    /**
     * Get admin performance metrics
     *
     * @param int $adminId
     * @param int $days
     * @return array{
     *     login_count: int,
     *     action_count: int,
     *     avg_actions_per_day: float,
     *     last_active: string|null,
     *     common_actions: array<string, int>
     * }
     * @throws NotFoundException
     */
    public function getPerformanceMetrics(int $adminId, int $days = 30): array;

    /**
     * Get admins needing password rehash
     *
     * @param PaginationQuery $pagination
     * @return array{
     *     items: array<AdminResponse>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int
     *     }
     * }
     */
    public function getAdminsNeedingPasswordRehash(PaginationQuery $pagination): array;

    // ==================== SYSTEM ADMIN MANAGEMENT ====================

    /**
     * Initialize system admin (first admin)
     *
     * @param array<string, mixed> $adminData
     * @return AdminResponse
     * @throws DomainException
     */
    public function initializeSystemAdmin(array $adminData): AdminResponse;

    /**
     * Check if system admin exists
     *
     * @return bool
     */
    public function systemAdminExists(): bool;

    /**
     * Get system admin
     *
     * @return AdminResponse
     * @throws NotFoundException
     */
    public function getSystemAdmin(): AdminResponse;

    // ==================== ADMIN PROFILE & SETTINGS ====================

    /**
     * Update admin profile (self-service)
     *
     * @param int $adminId
     * @param array<string, mixed> $profileData
     * @return AdminResponse
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function updateProfile(int $adminId, array $profileData): AdminResponse;

    /**
     * Verify admin password
     *
     * @param int $adminId
     * @param string $password
     * @return bool
     * @throws NotFoundException
     */
    public function verifyPassword(int $adminId, string $password): bool;

    /**
     * Check if admin needs password rehash
     *
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     */
    public function needsPasswordRehash(int $adminId): bool;

    /**
     * Force password change on next login
     *
     * @param int $adminId
     * @param bool $force
     * @return AdminResponse
     * @throws NotFoundException
     */
    public function forcePasswordChange(int $adminId, bool $force = true): AdminResponse;
}