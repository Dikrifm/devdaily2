<?php

namespace App\Repositories\Interfaces;

use App\Repositories\BaseRepositoryInterface;
use App\Entities\Admin;
use App\Repositories\BaseRepositoryInterface;

/**
 * Admin Repository Interface
 * 
 * Contract for Admin-specific data operations with caching and transaction management.
 * Extends BaseRepositoryInterface with type-specific Admin operations.
 * 
 * @extends App\Repositories\BaseRepositoryInterface<Admin>
 */
interface AdminRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find Admin by username
     * 
     * @param string $username
     * @return Admin|null
     */
    public function findByUsername(string $username): ?Admin;

    /**
     * Find Admin by email
     * 
     * @param string $email
     * @return Admin|null
     */
    public function findByEmail(string $email): ?Admin;

    /**
     * Find Admin by username or email
     * 
     * @param string $identifier
     * @return Admin|null
     */
    public function findByUsernameOrEmail(string $identifier): ?Admin;

    /**
     * Verify Admin credentials
     * 
     * @param string $identifier
     * @param string $password
     * @return Admin|null
     */
    public function verifyCredentials(string $identifier, string $password): ?Admin;

    /**
     * Find active Admin by ID (with active status check)
     * 
     * @param int|string $id
     * @return Admin|null
     */
    public function findActiveById(int|string $id): ?Admin;

    /**
     * Find all Super Admins
     * 
     * @return array<Admin>
     */
    public function findSuperAdmins(): array;

    /**
     * Find all Regular Admins
     * 
     * @return array<Admin>
     */
    public function findRegularAdmins(): array;

    /**
     * Count Super Admins
     * 
     * @return int
     */
    public function countSuperAdmins(): int;

    /**
     * Check if admin is the last Super Admin
     * 
     * @param int $adminId
     * @return bool
     */
    public function isLastSuperAdmin(int $adminId): bool;

    /**
     * Find all active Admins
     * 
     * @return array<Admin>
     */
    public function findActiveAdmins(): array;

    /**
     * Find all inactive Admins
     * 
     * @return array<Admin>
     */
    public function findInactiveAdmins(): array;

    /**
     * Find locked Admins (exceeded max login attempts)
     * 
     * @param int $maxAttempts
     * @return array<Admin>
     */
    public function findLockedAdmins(int $maxAttempts = 5): array;

    /**
     * Find Admins inactive for specified days
     * 
     * @param int $days
     * @return array<Admin>
     */
    public function findInactiveForDays(int $days = 30): array;

    /**
     * Find Admins needing password rehash
     * 
     * @return array<Admin>
     */
    public function findAdminsNeedingPasswordRehash(): array;

    /**
     * Search Admins with pagination
     * 
     * @param string $searchTerm
     * @param int $limit
     * @param int $offset
     * @return array<Admin>
     */
    public function searchAdmins(string $searchTerm, int $limit = 20, int $offset = 0): array;

    /**
     * Paginate Admins with filters
     * 
     * @param array<string, mixed> $filters
     * @param int $perPage
     * @param int $page
     * @return array{
     *     data: array<Admin>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         from: int,
     *         to: int
     *     }
     * }
     */
    public function paginateWithFilters(array $filters = [], int $perPage = 25, int $page = 1): array;

    /**
     * Record successful login
     * 
     * @param int $adminId
     * @return bool
     */
    public function recordSuccessfulLogin(int $adminId): bool;

    /**
     * Record failed login attempt
     * 
     * @param int $adminId
     * @return bool
     */
    public function recordFailedLoginAttempt(int $adminId): bool;

    /**
     * Reset login attempts
     * 
     * @param int $adminId
     * @return bool
     */
    public function resetLoginAttempts(int $adminId): bool;

    /**
     * Update Admin password hash
     * 
     * @param int $adminId
     * @param string $newPasswordHash
     * @return bool
     */
    public function updatePassword(int $adminId, string $newPasswordHash): bool;

    /**
     * Activate Admin account
     * 
     * @param int $adminId
     * @return bool
     */
    public function activateAccount(int $adminId): bool;

    /**
     * Deactivate Admin account
     * 
     * @param int $adminId
     * @return bool
     */
    public function deactivateAccount(int $adminId): bool;

    /**
     * Update Admin role
     * 
     * @param int $adminId
     * @param string $newRole
     * @return bool
     */
    public function updateRole(int $adminId, string $newRole): bool;

    /**
     * Get Admin statistics
     * 
     * @return array<string, mixed>
     */
    public function getStatistics(): array;

    /**
     * Get Admin activity timeline
     * 
     * @param int $days
     * @return array<string, mixed>
     */
    public function getActivityTimeline(int $days = 30): array;

    /**
     * Check if username exists
     * 
     * @param string $username
     * @param int|string|null $excludeId
     * @return bool
     */
    public function usernameExists(string $username, int|string|null $excludeId = null): bool;

    /**
     * Check if email exists
     * 
     * @param string $email
     * @param int|string|null $excludeId
     * @return bool
     */
    public function emailExists(string $email, int|string|null $excludeId = null): bool;

    /**
     * Bulk archive Admins
     * 
     * @param array<int|string> $ids
     * @return int
     */
    public function bulkArchive(array $ids): int;

    /**
     * Bulk restore Admins
     * 
     * @param array<int|string> $ids
     * @return int
     */
    public function bulkRestore(array $ids): int;

    /**
     * Initialize system Admin
     * 
     * @return array<string, mixed>
     */
    public function initializeSystemAdmin(): array;

    /**
     * Create sample Admin for testing
     * 
     * @param array<string, mixed> $overrides
     * @return Admin
     */
    public function createSample(array $overrides = []): Admin;
}