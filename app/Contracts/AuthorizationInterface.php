<?php

namespace App\Contracts;

use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Requests\Authorization\AssignPermissionRequest;
use App\DTOs\Requests\Authorization\CreateRoleRequest;
use App\DTOs\Requests\Authorization\UpdateRoleRequest;
use App\DTOs\Requests\Authorization\CheckPermissionRequest;
use App\DTOs\Responses\Authorization\PermissionResponse;
use App\DTOs\Responses\Authorization\RoleResponse;
use App\DTOs\Responses\BulkActionResult;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Authorization Interface
 * 
 * Contract untuk layanan manajemen otorisasi dan permissions.
 * Mengatur role-based access control (RBAC) dengan granular permissions.
 */
interface AuthorizationInterface extends BaseInterface
{
    // ==================== ROLE MANAGEMENT ====================
    
    /**
     * Membuat role baru
     */
    public function createRole(CreateRoleRequest $request): RoleResponse;
    
    /**
     * Mendapatkan detail role
     */
    public function getRole(int $roleId): RoleResponse;
    
    /**
     * Mendapatkan role berdasarkan nama
     */
    public function getRoleByName(string $roleName): RoleResponse;
    
    /**
     * Memperbarui role
     */
    public function updateRole(UpdateRoleRequest $request): RoleResponse;
    
    /**
     * Menghapus role
     */
    public function deleteRole(int $roleId, ?string $reason = null): bool;
    
    /**
     * Mendapatkan daftar role dengan pagination
     */
    public function listRoles(PaginationQuery $pagination, array $filters = []): array;
    
    /**
     * Mencari role berdasarkan kriteria
     */
    public function searchRoles(string $searchTerm, PaginationQuery $pagination): array;
    
    /**
     * Mendapatkan semua role aktif
     */
    public function getActiveRoles(): array;
    
    /**
     * Mengaktifkan role
     */
    public function activateRole(int $roleId): RoleResponse;
    
    /**
     * Menonaktifkan role
     */
    public function deactivateRole(int $roleId, ?string $reason = null): RoleResponse;
    
    // ==================== PERMISSION MANAGEMENT ====================
    
    /**
     * Mendapatkan semua permission yang tersedia
     */
    public function getAllPermissions(): array;
    
    /**
     * Mendapatkan permission berdasarkan ID
     */
    public function getPermission(int $permissionId): PermissionResponse;
    
    /**
     * Mendapatkan permission berdasarkan kode
     */
    public function getPermissionByCode(string $permissionCode): PermissionResponse;
    
    /**
     * Membuat permission baru
     */
    public function createPermission(string $name, string $code, ?string $description = null): PermissionResponse;
    
    /**
     * Memperbarui permission
     */
    public function updatePermission(int $permissionId, array $updates): PermissionResponse;
    
    /**
     * Menghapus permission
     */
    public function deletePermission(int $permissionId, ?string $reason = null): bool;
    
    // ==================== ROLE-PERMISSION ASSIGNMENT ====================
    
    /**
     * Menetapkan permission ke role
     */
    public function assignPermissionToRole(AssignPermissionRequest $request): RoleResponse;
    
    /**
     * Mencabut permission dari role
     */
    public function revokePermissionFromRole(int $roleId, int $permissionId, ?string $reason = null): RoleResponse;
    
    /**
     * Menetapkan multiple permissions ke role
     */
    public function assignMultiplePermissionsToRole(int $roleId, array $permissionIds): RoleResponse;
    
    /**
     * Mencabut multiple permissions dari role
     */
    public function revokeMultiplePermissionsFromRole(int $roleId, array $permissionIds): RoleResponse;
    
    /**
     * Mendapatkan permissions untuk role tertentu
     */
    public function getRolePermissions(int $roleId): array;
    
    /**
     * Mendapatkan roles yang memiliki permission tertentu
     */
    public function getPermissionRoles(int $permissionId): array;
    
    // ==================== ADMIN-ROLE ASSIGNMENT ====================
    
    /**
     * Menetapkan role ke admin
     */
    public function assignRoleToAdmin(int $adminId, int $roleId): bool;
    
    /**
     * Mencabut role dari admin
     */
    public function revokeRoleFromAdmin(int $adminId, int $roleId, ?string $reason = null): bool;
    
    /**
     * Menetapkan multiple roles ke admin
     */
    public function assignMultipleRolesToAdmin(int $adminId, array $roleIds): array;
    
    /**
     * Mencabut multiple roles dari admin
     */
    public function revokeMultipleRolesFromAdmin(int $adminId, array $roleIds): array;
    
    /**
     * Mendapatkan roles untuk admin tertentu
     */
    public function getAdminRoles(int $adminId): array;
    
    /**
     * Mendapatkan admins yang memiliki role tertentu
     */
    public function getRoleAdmins(int $roleId, PaginationQuery $pagination): array;
    
    // ==================== PERMISSION CHECKING ====================
    
    /**
     * Memeriksa apakah admin memiliki permission tertentu
     */
    public function hasPermission(CheckPermissionRequest $request): bool;
    
    /**
     * Memeriksa dan throw exception jika tidak memiliki permission
     */
    public function authorizePermission(CheckPermissionRequest $request): void;
    
    /**
     * Memeriksa apakah admin memiliki salah satu dari permissions
     */
    public function hasAnyPermission(int $adminId, array $permissionCodes): bool;
    
    /**
     * Memeriksa apakah admin memiliki semua permissions
     */
    public function hasAllPermissions(int $adminId, array $permissionCodes): bool;
    
    /**
     * Memeriksa apakah admin memiliki role tertentu
     */
    public function hasRole(int $adminId, string $roleName): bool;
    
    /**
     * Memeriksa apakah admin memiliki salah satu dari roles
     */
    public function hasAnyRole(int $adminId, array $roleNames): bool;
    
    /**
     * Memeriksa apakah admin memiliki semua roles
     */
    public function hasAllRoles(int $adminId, array $roleNames): bool;
    
    /**
     * Mendapatkan semua permissions untuk admin (dari semua roles)
     */
    public function getAdminPermissions(int $adminId): array;
    
    // ==================== BULK OPERATIONS ====================
    
    /**
     * Bulk assign roles ke multiple admins
     */
    public function bulkAssignRoles(array $adminIds, array $roleIds, ?string $reason = null): BulkActionResult;
    
    /**
     * Bulk revoke roles dari multiple admins
     */
    public function bulkRevokeRoles(array $adminIds, array $roleIds, ?string $reason = null): BulkActionResult;
    
    /**
     * Bulk activate roles
     */
    public function bulkActivateRoles(array $roleIds): BulkActionResult;
    
    /**
     * Bulk deactivate roles
     */
    public function bulkDeactivateRoles(array $roleIds, ?string $reason = null): BulkActionResult;
    
    // ==================== PERMISSION CACHING ====================
    
    /**
     * Clear permission cache untuk admin tertentu
     */
    public function clearAdminPermissionCache(int $adminId): bool;
    
    /**
     * Clear permission cache untuk role tertentu
     */
    public function clearRolePermissionCache(int $roleId): bool;
    
    /**
     * Clear semua permission cache
     */
    public function clearAllPermissionCache(): bool;
    
    // ==================== VALIDATION & UTILITIES ====================
    
    /**
     * Validasi permission hierarchy
     */
    public function validatePermissionHierarchy(int $permissionId, int $parentPermissionId): array;
    
    /**
     * Mendapatkan permission tree (hierarchical)
     */
    public function getPermissionTree(): array;
    
    /**
     * Mendapatkan role hierarchy
     */
    public function getRoleHierarchy(): array;
    
    /**
     * Memeriksa permission inheritance
     */
    public function checkPermissionInheritance(int $roleId, string $permissionCode): bool;
    
    /**
     * Mendapatkan effective permissions (termasuk inherited)
     */
    public function getEffectivePermissions(int $adminId): array;
    
    // ==================== SYSTEM & HEALTH ====================
    
    /**
     * Mendapatkan statistics otorisasi
     */
    public function getAuthorizationStatistics(): array;
    
    /**
     * Mendapatkan activity timeline otorisasi
     */
    public function getAuthorizationActivityTimeline(int $days = 30): array;
    
    /**
     * Mendapatkan permission usage statistics
     */
    public function getPermissionUsageStatistics(): array;
    
    /**
     * Mendapatkan role usage statistics
     */
    public function getRoleUsageStatistics(): array;
    
    /**
     * Mendapatkan unused permissions
     */
    public function getUnusedPermissions(PaginationQuery $pagination): array;
    
    /**
     * Mendapatkan unused roles
     */
    public function getUnusedRoles(PaginationQuery $pagination): array;
    
    /**
     * Mendapatkan authorization health status
     */
    public function getAuthorizationHealthStatus(): array;
    
    /**
     * Mendapatkan configuration otorisasi
     */
    public function getAuthorizationConfiguration(): array;
    
    /**
     * Update authorization configuration
     */
    public function updateAuthorizationConfiguration(array $config): array;
    
    /**
     * Initialize default roles and permissions
     */
    public function initializeDefaultAuthorization(): array;
}