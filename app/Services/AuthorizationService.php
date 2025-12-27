<?php

namespace App\Services;

use App\Contracts\AuthorizationInterface;
use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Requests\Authorization\AssignPermissionRequest;
use App\DTOs\Requests\Authorization\CreateRoleRequest;
use App\DTOs\Requests\Authorization\UpdateRoleRequest;
use App\DTOs\Requests\Authorization\CheckPermissionRequest;
use App\DTOs\Responses\Authorization\PermissionResponse;
use App\DTOs\Responses\Authorization\RoleResponse;
use App\DTOs\Responses\BulkActionResult;
use App\DTOs\Responses\BulkActionStatus;
use App\Entities\Admin;
use App\Entities\Authorization\Permission;
use App\Entities\Authorization\Role;
use App\Enums\Authorization\PermissionStatus;
use App\Enums\Authorization\RoleStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use App\Repositories\Interfaces\Authorization\PermissionRepositoryInterface;
use App\Repositories\Interfaces\Authorization\RoleRepositoryInterface;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Validators\AuthorizationValidator;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authorization Service
 * 
 * Layanan manajemen otorisasi berbasis Role-Based Access Control (RBAC)
 * dengan granular permissions dan inheritance support.
 */
final class AuthorizationService extends BaseService implements AuthorizationInterface
{
    private RoleRepositoryInterface $roleRepository;
    private PermissionRepositoryInterface $permissionRepository;
    private AdminRepositoryInterface $adminRepository;
    private AuditLogRepositoryInterface $auditLogRepository;
    private AuthorizationValidator $authorizationValidator;
    private array $configuration;

    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        RoleRepositoryInterface $roleRepository,
        PermissionRepositoryInterface $permissionRepository,
        AdminRepositoryInterface $adminRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        AuthorizationValidator $authorizationValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
        $this->adminRepository = $adminRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->authorizationValidator = $authorizationValidator;
        $this->configuration = $this->loadConfiguration();
        $this->initializedAt = date('Y-m-d H:i:s');
    }

    // ==================== ROLE MANAGEMENT ====================
    
    public function createRole(CreateRoleRequest $request): RoleResponse
    {
        return $this->transaction(function() use ($request) {
            // Validasi input
            $this->validateDTOOrFail($request, ['context' => 'create_role']);
            
            // Validasi bisnis rules
            $validationResult = $this->validateBusinessRules($request, ['context' => 'create_role']);
            if (!empty($validationResult['errors'])) {
                throw new ValidationException('Business validation failed', $validationResult['errors']);
            }
            
            // Cek duplicate role name
            if ($this->roleRepository->roleNameExists($request->getName())) {
                throw new DomainException("Role dengan nama '{$request->getName()}' sudah ada");
            }
            
            // Buat entity role
            $role = new Role();
            $role->setName($request->getName());
            $role->setDescription($request->getDescription());
            $role->setStatus(RoleStatus::ACTIVE);
            $role->setIsSystem(false);
            $role->setCreatedAt(new Time('now'));
            $role->setUpdatedAt(new Time('now'));
            
            // Simpan role
            $savedRole = $this->roleRepository->save($role);
            
            // Assign permissions jika ada
            if (!empty($request->getPermissionIds())) {
                foreach ($request->getPermissionIds() as $permissionId) {
                    $this->roleRepository->assignPermission($savedRole->getId(), $permissionId);
                }
            }
            
            // Audit log
            $this->audit(
                'role.create',
                'role',
                $savedRole->getId(),
                "Role '{$savedRole->getName()}' dibuat"
            );
            
            // Clear cache
            $this->clearRolePermissionCache($savedRole->getId());
            $this->clearAllPermissionCache();
            
            return $this->buildRoleResponse($savedRole);
        }, 'create_role');
    }
    
    public function getRole(int $roleId): RoleResponse
    {
        return $this->withCaching(
            "role:{$roleId}:v3",
            function() use ($roleId) {
                $role = $this->roleRepository->findById($roleId);
                if (!$role) {
                    throw new NotFoundException("Role dengan ID {$roleId} tidak ditemukan");
                }
                
                return $this->buildRoleResponse($role);
            },
            3600 // 1 hour cache
        );
    }
    
    public function getRoleByName(string $roleName): RoleResponse
    {
        $role = $this->roleRepository->findByName($roleName);
        if (!$role) {
            throw new NotFoundException("Role dengan nama '{$roleName}' tidak ditemukan");
        }
        
        return $this->buildRoleResponse($role);
    }
    
    public function updateRole(UpdateRoleRequest $request): RoleResponse
    {
        return $this->transaction(function() use ($request) {
            $this->validateDTOOrFail($request, ['context' => 'update_role']);
            
            $role = $this->roleRepository->findById($request->getRoleId());
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            // Cek jika role system (tidak bisa diupdate)
            if ($role->isSystem()) {
                throw new DomainException("Role system tidak dapat diubah");
            }
            
            // Update data
            if ($request->getName() !== null && $request->getName() !== $role->getName()) {
                // Cek duplicate
                if ($this->roleRepository->roleNameExists($request->getName(), $request->getRoleId())) {
                    throw new DomainException("Role dengan nama '{$request->getName()}' sudah ada");
                }
                $role->setName($request->getName());
            }
            
            if ($request->getDescription() !== null) {
                $role->setDescription($request->getDescription());
            }
            
            $role->setUpdatedAt(new Time('now'));
            
            // Simpan update
            $updatedRole = $this->roleRepository->save($role);
            
            // Audit log
            $this->audit(
                'role.update',
                'role',
                $role->getId(),
                "Role '{$role->getName()}' diperbarui"
            );
            
            // Clear cache
            $this->clearRolePermissionCache($role->getId());
            $this->clearAllPermissionCache();
            
            return $this->buildRoleResponse($updatedRole);
        }, 'update_role');
    }
    
    public function deleteRole(int $roleId, ?string $reason = null): bool
    {
        return $this->transaction(function() use ($roleId, $reason) {
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            // Cek jika role system (tidak bisa dihapus)
            if ($role->isSystem()) {
                throw new DomainException("Role system tidak dapat dihapus");
            }
            
            // Cek jika role sedang digunakan
            $adminCount = $this->roleRepository->countAdminAssignments($roleId);
            if ($adminCount > 0) {
                throw new DomainException("Role sedang digunakan oleh {$adminCount} admin, tidak dapat dihapus");
            }
            
            // Hapus role
            $deleted = $this->roleRepository->delete($roleId);
            
            if ($deleted) {
                // Audit log
                $this->audit(
                    'role.delete',
                    'role',
                    $roleId,
                    "Role '{$role->getName()}' dihapus" . ($reason ? ": {$reason}" : "")
                );
                
                // Clear cache
                $this->clearRolePermissionCache($roleId);
                $this->clearAllPermissionCache();
            }
            
            return $deleted;
        }, 'delete_role');
    }
    
    public function listRoles(PaginationQuery $pagination, array $filters = []): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('list_roles', array_merge($pagination->toArray(), $filters)),
            function() use ($pagination, $filters) {
                $result = $this->roleRepository->paginate($pagination, $filters);
                
                $roles = [];
                foreach ($result['data'] as $role) {
                    $roles[] = $this->buildRoleResponse($role);
                }
                
                return [
                    'data' => $roles,
                    'pagination' => $result['pagination']
                ];
            },
            1800 // 30 minutes cache
        );
    }
    
    public function searchRoles(string $searchTerm, PaginationQuery $pagination): array
    {
        return $this->roleRepository->search($searchTerm, $pagination);
    }
    
    public function getActiveRoles(): array
    {
        return $this->withCaching(
            'active_roles:v3',
            function() {
                $roles = $this->roleRepository->findByStatus(RoleStatus::ACTIVE);
                
                $response = [];
                foreach ($roles as $role) {
                    $response[] = $this->buildRoleResponse($role);
                }
                
                return $response;
            },
            7200 // 2 hours cache
        );
    }
    
    public function activateRole(int $roleId): RoleResponse
    {
        return $this->transaction(function() use ($roleId) {
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            $role->setStatus(RoleStatus::ACTIVE);
            $role->setUpdatedAt(new Time('now'));
            
            $updatedRole = $this->roleRepository->save($role);
            
            // Audit log
            $this->audit(
                'role.activate',
                'role',
                $roleId,
                "Role '{$role->getName()}' diaktifkan"
            );
            
            // Clear cache
            $this->clearRolePermissionCache($roleId);
            
            return $this->buildRoleResponse($updatedRole);
        }, 'activate_role');
    }
    
    public function deactivateRole(int $roleId, ?string $reason = null): RoleResponse
    {
        return $this->transaction(function() use ($roleId, $reason) {
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            // Cek jika role system
            if ($role->isSystem()) {
                throw new DomainException("Role system tidak dapat dinonaktifkan");
            }
            
            $role->setStatus(RoleStatus::INACTIVE);
            $role->setUpdatedAt(new Time('now'));
            
            $updatedRole = $this->roleRepository->save($role);
            
            // Audit log
            $this->audit(
                'role.deactivate',
                'role',
                $roleId,
                "Role '{$role->getName()}' dinonaktifkan" . ($reason ? ": {$reason}" : "")
            );
            
            // Clear cache
            $this->clearRolePermissionCache($roleId);
            
            return $this->buildRoleResponse($updatedRole);
        }, 'deactivate_role');
    }
    
    // ==================== PERMISSION MANAGEMENT ====================
    
    public function getAllPermissions(): array
    {
        return $this->withCaching(
            'all_permissions:v3',
            function() {
                $permissions = $this->permissionRepository->findAllActive();
                
                $response = [];
                foreach ($permissions as $permission) {
                    $response[] = $this->buildPermissionResponse($permission);
                }
                
                return $response;
            },
            86400 // 24 hours cache
        );
    }
    
    public function getPermission(int $permissionId): PermissionResponse
    {
        $permission = $this->permissionRepository->findById($permissionId);
        if (!$permission) {
            throw new NotFoundException("Permission tidak ditemukan");
        }
        
        return $this->buildPermissionResponse($permission);
    }
    
    public function getPermissionByCode(string $permissionCode): PermissionResponse
    {
        $permission = $this->permissionRepository->findByCode($permissionCode);
        if (!$permission) {
            throw new NotFoundException("Permission dengan kode '{$permissionCode}' tidak ditemukan");
        }
        
        return $this->buildPermissionResponse($permission);
    }
    
    public function createPermission(string $name, string $code, ?string $description = null): PermissionResponse
    {
        return $this->transaction(function() use ($name, $code, $description) {
            // Validasi input
            if (empty($name) || empty($code)) {
                throw new ValidationException('Nama dan kode permission harus diisi');
            }
            
            // Cek duplicate permission code
            if ($this->permissionRepository->permissionCodeExists($code)) {
                throw new DomainException("Permission dengan kode '{$code}' sudah ada");
            }
            
            // Buat entity permission
            $permission = new Permission();
            $permission->setName($name);
            $permission->setCode($code);
            $permission->setDescription($description);
            $permission->setStatus(PermissionStatus::ACTIVE);
            $permission->setCreatedAt(new Time('now'));
            $permission->setUpdatedAt(new Time('now'));
            
            // Simpan permission
            $savedPermission = $this->permissionRepository->save($permission);
            
            // Audit log
            $this->audit(
                'permission.create',
                'permission',
                $savedPermission->getId(),
                "Permission '{$savedPermission->getCode()}' dibuat"
            );
            
            // Clear cache
            $this->clearAllPermissionCache();
            
            return $this->buildPermissionResponse($savedPermission);
        }, 'create_permission');
    }
    
    public function updatePermission(int $permissionId, array $updates): PermissionResponse
    {
        return $this->transaction(function() use ($permissionId, $updates) {
            $permission = $this->permissionRepository->findById($permissionId);
            if (!$permission) {
                throw new NotFoundException("Permission tidak ditemukan");
            }
            
            // Update data
            if (isset($updates['name']) && $updates['name'] !== $permission->getName()) {
                $permission->setName($updates['name']);
            }
            
            if (isset($updates['description'])) {
                $permission->setDescription($updates['description']);
            }
            
            if (isset($updates['status'])) {
                $permission->setStatus(PermissionStatus::from($updates['status']));
            }
            
            $permission->setUpdatedAt(new Time('now'));
            
            // Simpan update
            $updatedPermission = $this->permissionRepository->save($permission);
            
            // Audit log
            $this->audit(
                'permission.update',
                'permission',
                $permissionId,
                "Permission '{$permission->getCode()}' diperbarui"
            );
            
            // Clear cache
            $this->clearAllPermissionCache();
            
            return $this->buildPermissionResponse($updatedPermission);
        }, 'update_permission');
    }
    
    public function deletePermission(int $permissionId, ?string $reason = null): bool
    {
        return $this->transaction(function() use ($permissionId, $reason) {
            $permission = $this->permissionRepository->findById($permissionId);
            if (!$permission) {
                throw new NotFoundException("Permission tidak ditemukan");
            }
            
            // Cek jika permission sedang digunakan
            $roleCount = $this->permissionRepository->countRoleAssignments($permissionId);
            if ($roleCount > 0) {
                throw new DomainException("Permission sedang digunakan oleh {$roleCount} role, tidak dapat dihapus");
            }
            
            // Hapus permission
            $deleted = $this->permissionRepository->delete($permissionId);
            
            if ($deleted) {
                // Audit log
                $this->audit(
                    'permission.delete',
                    'permission',
                    $permissionId,
                    "Permission '{$permission->getCode()}' dihapus" . ($reason ? ": {$reason}" : "")
                );
                
                // Clear cache
                $this->clearAllPermissionCache();
            }
            
            return $deleted;
        }, 'delete_permission');
    }
    
    // ==================== ROLE-PERMISSION ASSIGNMENT ====================
    
    public function assignPermissionToRole(AssignPermissionRequest $request): RoleResponse
    {
        return $this->transaction(function() use ($request) {
            $this->validateDTOOrFail($request, ['context' => 'assign_permission']);
            
            $role = $this->roleRepository->findById($request->getRoleId());
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            $permission = $this->permissionRepository->findById($request->getPermissionId());
            if (!$permission) {
                throw new NotFoundException("Permission tidak ditemukan");
            }
            
            // Cek jika sudah diassign
            if ($this->roleRepository->hasPermission($request->getRoleId(), $request->getPermissionId())) {
                throw new DomainException("Permission sudah diassign ke role ini");
            }
            
            // Assign permission
            $this->roleRepository->assignPermission($request->getRoleId(), $request->getPermissionId());
            
            // Audit log
            $this->audit(
                'role.assign_permission',
                'role',
                $request->getRoleId(),
                "Permission '{$permission->getCode()}' diassign ke role '{$role->getName()}'"
            );
            
            // Clear cache
            $this->clearRolePermissionCache($request->getRoleId());
            $this->clearAllPermissionCache();
            
            return $this->getRole($request->getRoleId());
        }, 'assign_permission');
    }
    
    public function revokePermissionFromRole(int $roleId, int $permissionId, ?string $reason = null): RoleResponse
    {
        return $this->transaction(function() use ($roleId, $permissionId, $reason) {
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            // Cek jika permission ada di role
            if (!$this->roleRepository->hasPermission($roleId, $permissionId)) {
                throw new DomainException("Permission tidak ditemukan di role ini");
            }
            
            // Revoke permission
            $this->roleRepository->revokePermission($roleId, $permissionId);
            
            // Audit log
            $permission = $this->permissionRepository->findById($permissionId);
            $permissionCode = $permission ? $permission->getCode() : $permissionId;
            $this->audit(
                'role.revoke_permission',
                'role',
                $roleId,
                "Permission '{$permissionCode}' di-revoke dari role '{$role->getName()}'" . 
                ($reason ? ": {$reason}" : "")
            );
            
            // Clear cache
            $this->clearRolePermissionCache($roleId);
            $this->clearAllPermissionCache();
            
            return $this->getRole($roleId);
        }, 'revoke_permission');
    }
    
    public function assignMultiplePermissionsToRole(int $roleId, array $permissionIds): RoleResponse
    {
        return $this->transaction(function() use ($roleId, $permissionIds) {
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            $assignedCount = 0;
            foreach ($permissionIds as $permissionId) {
                if (!$this->roleRepository->hasPermission($roleId, $permissionId)) {
                    $this->roleRepository->assignPermission($roleId, $permissionId);
                    $assignedCount++;
                }
            }
            
            if ($assignedCount > 0) {
                // Audit log
                $this->audit(
                    'role.assign_multiple_permissions',
                    'role',
                    $roleId,
                    "{$assignedCount} permission diassign ke role '{$role->getName()}'"
                );
                
                // Clear cache
                $this->clearRolePermissionCache($roleId);
                $this->clearAllPermissionCache();
            }
            
            return $this->getRole($roleId);
        }, 'assign_multiple_permissions');
    }
    
    public function revokeMultiplePermissionsFromRole(int $roleId, array $permissionIds): RoleResponse
    {
        return $this->transaction(function() use ($roleId, $permissionIds) {
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            $revokedCount = 0;
            foreach ($permissionIds as $permissionId) {
                if ($this->roleRepository->hasPermission($roleId, $permissionId)) {
                    $this->roleRepository->revokePermission($roleId, $permissionId);
                    $revokedCount++;
                }
            }
            
            if ($revokedCount > 0) {
                // Audit log
                $this->audit(
                    'role.revoke_multiple_permissions',
                    'role',
                    $roleId,
                    "{$revokedCount} permission di-revoke dari role '{$role->getName()}'"
                );
                
                // Clear cache
                $this->clearRolePermissionCache($roleId);
                $this->clearAllPermissionCache();
            }
            
            return $this->getRole($roleId);
        }, 'revoke_multiple_permissions');
    }
    
    public function getRolePermissions(int $roleId): array
    {
        return $this->withCaching(
            "role:{$roleId}:permissions:v3",
            function() use ($roleId) {
                $permissions = $this->roleRepository->getPermissions($roleId);
                
                $response = [];
                foreach ($permissions as $permission) {
                    $response[] = $this->buildPermissionResponse($permission);
                }
                
                return $response;
            },
            3600 // 1 hour cache
        );
    }
    
    public function getPermissionRoles(int $permissionId): array
    {
        return $this->withCaching(
            "permission:{$permissionId}:roles:v3",
            function() use ($permissionId) {
                $roles = $this->permissionRepository->getAssignedRoles($permissionId);
                
                $response = [];
                foreach ($roles as $role) {
                    $response[] = $this->buildRoleResponse($role);
                }
                
                return $response;
            },
            3600 // 1 hour cache
        );
    }
    
    // ==================== ADMIN-ROLE ASSIGNMENT ====================
    
    public function assignRoleToAdmin(int $adminId, int $roleId): bool
    {
        return $this->transaction(function() use ($adminId, $roleId) {
            $admin = $this->adminRepository->findById($adminId);
            if (!$admin) {
                throw new NotFoundException("Admin tidak ditemukan");
            }
            
            $role = $this->roleRepository->findById($roleId);
            if (!$role) {
                throw new NotFoundException("Role tidak ditemukan");
            }
            
            // Cek jika sudah diassign
            if ($this->roleRepository->isAdminAssigned($adminId, $roleId)) {
                return false;
            }
            
            // Assign role
            $this->roleRepository->assignToAdmin($adminId, $roleId);
            
            // Audit log
            $this->audit(
                'admin.assign_role',
                'admin',
                $adminId,
                "Role '{$role->getName()}' diassign ke admin '{$admin->getUsername()}'"
            );
            
            // Clear cache
            $this->clearAdminPermissionCache($adminId);
            
            return true;
        }, 'assign_role_to_admin');
    }
    
    public function revokeRoleFromAdmin(int $adminId, int $roleId, ?string $reason = null): bool
    {
        return $this->transaction(function() use ($adminId, $roleId, $reason) {
            $admin = $this->adminRepository->findById($adminId);
            if (!$admin) {
                throw new NotFoundException("Admin tidak ditemukan");
            }
            
            // Cek jika role diassign
            if (!$this->roleRepository->isAdminAssigned($adminId, $roleId)) {
                return false;
            }
            
            // Revoke role
            $this->roleRepository->revokeFromAdmin($adminId, $roleId);
            
            // Audit log
            $role = $this->roleRepository->findById($roleId);
            $roleName = $role ? $role->getName() : $roleId;
            $this->audit(
                'admin.revoke_role',
                'admin',
                $adminId,
                "Role '{$roleName}' di-revoke dari admin '{$admin->getUsername()}'" . 
                ($reason ? ": {$reason}" : "")
            );
            
            // Clear cache
            $this->clearAdminPermissionCache($adminId);
            
            return true;
        }, 'revoke_role_from_admin');
    }
    
    public function assignMultipleRolesToAdmin(int $adminId, array $roleIds): array
    {
        return $this->transaction(function() use ($adminId, $roleIds) {
            $admin = $this->adminRepository->findById($adminId);
            if (!$admin) {
                throw new NotFoundException("Admin tidak ditemukan");
            }
            
            $results = [
                'assigned' => [],
                'already_assigned' => [],
                'failed' => []
            ];
            
            foreach ($roleIds as $roleId) {
                try {
                    if ($this->assignRoleToAdmin($adminId, $roleId)) {
                        $results['assigned'][] = $roleId;
                    } else {
                        $results['already_assigned'][] = $roleId;
                    }
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'role_id' => $roleId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (!empty($results['assigned'])) {
                // Audit log
                $this->audit(
                    'admin.assign_multiple_roles',
                    'admin',
                    $adminId,
                    count($results['assigned']) . " role diassign ke admin '{$admin->getUsername()}'"
                );
                
                // Clear cache
                $this->clearAdminPermissionCache($adminId);
            }
            
            return $results;
        }, 'assign_multiple_roles_to_admin');
    }
    
    public function revokeMultipleRolesFromAdmin(int $adminId, array $roleIds): array
    {
        return $this->transaction(function() use ($adminId, $roleIds) {
            $admin = $this->adminRepository->findById($adminId);
            if (!$admin) {
                throw new NotFoundException("Admin tidak ditemukan");
            }
            
            $results = [
                'revoked' => [],
                'not_assigned' => [],
                'failed' => []
            ];
            
            foreach ($roleIds as $roleId) {
                try {
                    if ($this->revokeRoleFromAdmin($adminId, $roleId)) {
                        $results['revoked'][] = $roleId;
                    } else {
                        $results['not_assigned'][] = $roleId;
                    }
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'role_id' => $roleId,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            if (!empty($results['revoked'])) {
                // Audit log
                $this->audit(
                    'admin.revoke_multiple_roles',
                    'admin',
                    $adminId,
                    count($results['revoked']) . " role di-revoke dari admin '{$admin->getUsername()}'"
                );
                
                // Clear cache
                $this->clearAdminPermissionCache($adminId);
            }
            
            return $results;
        }, 'revoke_multiple_roles_from_admin');
    }
    
    public function getAdminRoles(int $adminId): array
    {
        return $this->withCaching(
            "admin:{$adminId}:roles:v3",
            function() use ($adminId) {
                $roles = $this->roleRepository->getAdminRoles($adminId);
                
                $response = [];
                foreach ($roles as $role) {
                    $response[] = $this->buildRoleResponse($role);
                }
                
                return $response;
            },
            1800 // 30 minutes cache
        );
    }
    
    public function getRoleAdmins(int $roleId, PaginationQuery $pagination): array
    {
        return $this->roleRepository->getRoleAdmins($roleId, $pagination);
    }
    
    // ==================== PERMISSION CHECKING ====================
    
    public function hasPermission(CheckPermissionRequest $request): bool
    {
        return $this->withCaching(
            "admin:{$request->getAdminId()}:has_permission:{$request->getPermissionCode()}:v3",
            function() use ($request) {
                $adminId = $request->getAdminId();
                $permissionCode = $request->getPermissionCode();
                
                // Super admin memiliki semua permissions
                if ($this->isSuperAdmin($adminId)) {
                    return true;
                }
                
                // Cek permission di cache admin
                $adminPermissions = $this->getAdminPermissions($adminId);
                
                foreach ($adminPermissions as $permission) {
                    if ($permission->getCode() === $permissionCode) {
                        return true;
                    }
                }
                
                return false;
            },
            900 // 15 minutes cache
        );
    }
    
    public function authorizePermission(CheckPermissionRequest $request): void
    {
        if (!$this->hasPermission($request)) {
            throw new AuthorizationException(
                "Admin tidak memiliki permission: {$request->getPermissionCode()}"
            );
        }
    }
    
    public function hasAnyPermission(int $adminId, array $permissionCodes): bool
    {
        // Super admin memiliki semua permissions
        if ($this->isSuperAdmin($adminId)) {
            return true;
        }
        
        $adminPermissions = $this->getAdminPermissions($adminId);
        $adminPermissionCodes = array_map(function($permission) {
            return $permission->getCode();
        }, $adminPermissions);
        
        foreach ($permissionCodes as $code) {
            if (in_array($code, $adminPermissionCodes)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function hasAllPermissions(int $adminId, array $permissionCodes): bool
    {
        // Super admin memiliki semua permissions
        if ($this->isSuperAdmin($adminId)) {
            return true;
        }
        
        $adminPermissions = $this->getAdminPermissions($adminId);
        $adminPermissionCodes = array_map(function($permission) {
            return $permission->getCode();
        }, $adminPermissions);
        
        foreach ($permissionCodes as $code) {
            if (!in_array($code, $adminPermissionCodes)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function hasRole(int $adminId, string $roleName): bool
    {
        $adminRoles = $this->getAdminRoles($adminId);
        
        foreach ($adminRoles as $role) {
            if ($role->getName() === $roleName) {
                return true;
            }
        }
        
        return false;
    }
    
    public function hasAnyRole(int $adminId, array $roleNames): bool
    {
        $adminRoles = $this->getAdminRoles($adminId);
        $adminRoleNames = array_map(function($role) {
            return $role->getName();
        }, $adminRoles);
        
        foreach ($roleNames as $roleName) {
            if (in_array($roleName, $adminRoleNames)) {
                return true;
            }
        }
        
        return false;
    }
    
    public function hasAllRoles(int $adminId, array $roleNames): bool
    {
        $adminRoles = $this->getAdminRoles($adminId);
        $adminRoleNames = array_map(function($role) {
            return $role->getName();
        }, $adminRoles);
        
        foreach ($roleNames as $roleName) {
            if (!in_array($roleName, $adminRoleNames)) {
                return false;
            }
        }
        
        return true;
    }
    
    public function getAdminPermissions(int $adminId): array
    {
        return $this->withCaching(
            "admin:{$adminId}:permissions:v3",
            function() use ($adminId) {
                // Super admin - get all permissions
                if ($this->isSuperAdmin($adminId)) {
                    return $this->getAllPermissions();
                }
                
                // Get permissions dari semua role admin
                $adminRoles = $this->roleRepository->getAdminRoles($adminId);
                $permissions = [];
                
                foreach ($adminRoles as $role) {
                    $rolePermissions = $this->roleRepository->getPermissions($role->getId());
                    foreach ($rolePermissions as $permission) {
                        // Hindari duplicate
                        $key = $permission->getCode();
                        if (!isset($permissions[$key])) {
                            $permissions[$key] = $permission;
                        }
                    }
                }
                
                // Convert ke array values
                return array_values($permissions);
            },
            1800 // 30 minutes cache
        );
    }
    
    // ==================== BULK OPERATIONS ====================
    
    public function bulkAssignRoles(array $adminIds, array $roleIds, ?string $reason = null): BulkActionResult
    {
        return $this->transaction(function() use ($adminIds, $roleIds, $reason) {
            $result = new BulkActionResult();
            $statuses = [];
            
            foreach ($adminIds as $adminId) {
                $status = new BulkActionStatus();
                $status->setId($adminId);
                $status->setType('admin');
                
                try {
                    $admin = $this->adminRepository->findById($adminId);
                    if (!$admin) {
                        throw new NotFoundException("Admin {$adminId} tidak ditemukan");
                    }
                    
                    $assignedRoles = [];
                    foreach ($roleIds as $roleId) {
                        if ($this->assignRoleToAdmin($adminId, $roleId)) {
                            $assignedRoles[] = $roleId;
                        }
                    }
                    
                    if (!empty($assignedRoles)) {
                        $status->setStatus('success');
                        $status->setMessage(count($assignedRoles) . " role berhasil diassign");
                        $result->incrementSuccess();
                    } else {
                        $status->setStatus('skipped');
                        $status->setMessage("Semua role sudah diassign");
                        $result->incrementSkipped();
                    }
                } catch (\Exception $e) {
                    $status->setStatus('failed');
                    $status->setMessage($e->getMessage());
                    $result->incrementFailed();
                }
                
                $statuses[] = $status;
            }
            
            $result->setStatuses($statuses);
            
            // Audit log
            if ($result->getSuccessCount() > 0) {
                $this->audit(
                    'bulk.assign_roles',
                    'system',
                    0,
                    "Bulk assign roles: " . $result->getSuccessCount() . " berhasil, " . 
                    $result->getFailedCount() . " gagal, " . $result->getSkippedCount() . " skipped" .
                    ($reason ? " - {$reason}" : "")
                );
            }
            
            return $result;
        }, 'bulk_assign_roles');
    }
    
    public function bulkRevokeRoles(array $adminIds, array $roleIds, ?string $reason = null): BulkActionResult
    {
        return $this->transaction(function() use ($adminIds, $roleIds, $reason) {
            $result = new BulkActionResult();
            $statuses = [];
            
            foreach ($adminIds as $adminId) {
                $status = new BulkActionStatus();
                $status->setId($adminId);
                $status->setType('admin');
                
                try {
                    $admin = $this->adminRepository->findById($adminId);
                    if (!$admin) {
                        throw new NotFoundException("Admin {$adminId} tidak ditemukan");
                    }
                    
                    $revokedRoles = [];
                    foreach ($roleIds as $roleId) {
                        if ($this->revokeRoleFromAdmin($adminId, $roleId)) {
                            $revokedRoles[] = $roleId;
                        }
                    }
                    
                    if (!empty($revokedRoles)) {
                        $status->setStatus('success');
                        $status->setMessage(count($revokedRoles) . " role berhasil di-revoke");
                        $result->incrementSuccess();
                    } else {
                        $status->setStatus('skipped');
                        $status->setMessage("Tidak ada role yang di-revoke");
                        $result->incrementSkipped();
                    }
                } catch (\Exception $e) {
                    $status->setStatus('failed');
                    $status->setMessage($e->getMessage());
                    $result->incrementFailed();
                }
                
                $statuses[] = $status;
            }
            
            $result->setStatuses($statuses);
            
            // Audit log
            if ($result->getSuccessCount() > 0) {
                $this->audit(
                    'bulk.revoke_roles',
                    'system',
                    0,
                    "Bulk revoke roles: " . $result->getSuccessCount() . " berhasil, " . 
                    $result->getFailedCount() . " gagal, " . $result->getSkippedCount() . " skipped" .
                    ($reason ? " - {$reason}" : "")
                );
            }
            
            return $result;
        }, 'bulk_revoke_roles');
    }
    
    public function bulkActivateRoles(array $roleIds): BulkActionResult
    {
        return $this->batchOperation($roleIds, function($roleId) {
            return $this->activateRole($roleId);
        }, 'role', 'bulk_activate_roles');
    }
    
    public function bulkDeactivateRoles(array $roleIds, ?string $reason = null): BulkActionResult
    {
        return $this->batchOperation($roleIds, function($roleId) use ($reason) {
            return $this->deactivateRole($roleId, $reason);
        }, 'role', 'bulk_deactivate_roles');
    }
    
    // ==================== PERMISSION CACHING ====================
    
    public function clearAdminPermissionCache(int $adminId): bool
    {
        $cacheKeys = [
            "admin:{$adminId}:permissions:v3",
            "admin:{$adminId}:has_permission:*",
            "admin:{$adminId}:roles:v3"
        ];
        
        foreach ($cacheKeys as $key) {
            $this->cache->deleteMatching($key);
        }
        
        return true;
    }
    
    public function clearRolePermissionCache(int $roleId): bool
    {
        $cacheKeys = [
            "role:{$roleId}:permissions:v3",
            "role:{$roleId}:v3"
        ];
        
        foreach ($cacheKeys as $key) {
            $this->cache->deleteMatching($key);
        }
        
        return true;
    }
    
    public function clearAllPermissionCache(): bool
    {
        $cacheKeys = [
            'all_permissions:v3',
            'active_roles:v3',
            'admin:*:permissions:v3',
            'role:*:permissions:v3',
            'permission:*:roles:v3'
        ];
        
        foreach ($cacheKeys as $key) {
            $this->cache->deleteMatching($key);
        }
        
        return true;
    }
    
    // ==================== VALIDATION & UTILITIES ====================
    
    public function validatePermissionHierarchy(int $permissionId, int $parentPermissionId): array
    {
        // TODO: Implement permission hierarchy validation
        return [
            'valid' => true,
            'message' => 'Hierarchy validation not implemented'
        ];
    }
    
    public function getPermissionTree(): array
    {
        // TODO: Implement permission tree
        return [];
    }
    
    public function getRoleHierarchy(): array
    {
        // TODO: Implement role hierarchy
        return [];
    }
    
    public function checkPermissionInheritance(int $roleId, string $permissionCode): bool
    {
        // TODO: Implement permission inheritance check
        return false;
    }
    
    public function getEffectivePermissions(int $adminId): array
    {
        return $this->getAdminPermissions($adminId);
    }
    
    // ==================== SYSTEM & HEALTH ====================
    
    public function getAuthorizationStatistics(): array
    {
        return $this->withCaching(
            'authorization_statistics:v3',
            function() {
                return [
                    'total_roles' => $this->roleRepository->countAll(),
                    'total_permissions' => $this->permissionRepository->countAll(),
                    'active_roles' => $this->roleRepository->countByStatus(RoleStatus::ACTIVE),
                    'active_permissions' => $this->permissionRepository->countByStatus(PermissionStatus::ACTIVE),
                    'role_assignments' => $this->roleRepository->countAllAssignments(),
                    'permission_assignments' => $this->permissionRepository->countAllAssignments(),
                    'admins_with_roles' => $this->roleRepository->countAdminsWithRoles(),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
            },
            3600 // 1 hour cache
        );
    }
    
    public function getAuthorizationActivityTimeline(int $days = 30): array
    {
        $logs = $this->auditLogRepository->getLogsByActionType('authorization', $days);
        
        $timeline = [];
        foreach ($logs as $log) {
            $timeline[] = [
                'id' => $log->getId(),
                'action' => $log->getAction(),
                'entity_type' => $log->getEntityType(),
                'entity_id' => $log->getEntityId(),
                'admin_id' => $log->getAdminId(),
                'description' => $log->getDescription(),
                'created_at' => $log->getCreatedAt()->format('Y-m-d H:i:s')
            ];
        }
        
        return $timeline;
    }
    
    public function getPermissionUsageStatistics(): array
    {
        return $this->permissionRepository->getUsageStatistics();
    }
    
    public function getRoleUsageStatistics(): array
    {
        return $this->roleRepository->getUsageStatistics();
    }
    
    public function getUnusedPermissions(PaginationQuery $pagination): array
    {
        return $this->permissionRepository->findUnused($pagination);
    }
    
    public function getUnusedRoles(PaginationQuery $pagination): array
    {
        return $this->roleRepository->findUnused($pagination);
    }
    
    public function getAuthorizationHealthStatus(): array
    {
        $stats = $this->getAuthorizationStatistics();
        
        return [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'metrics' => $stats,
            'checks' => [
                'database_connection' => $this->db->connect() ? 'ok' : 'error',
                'cache_connection' => $this->cache->isSupported() ? 'ok' : 'warning',
                'repository_health' => 'ok',
                'configuration_valid' => $this->validateConfiguration()['valid'] ? 'ok' : 'error'
            ]
        ];
    }
    
    public function getAuthorizationConfiguration(): array
    {
        return $this->configuration;
    }
    
    public function updateAuthorizationConfiguration(array $config): array
    {
        $this->validateConfiguration($config);
        $this->configuration = array_merge($this->configuration, $config);
        $this->saveConfiguration($this->configuration);
        
        // Clear cache karena konfigurasi berubah
        $this->clearAllPermissionCache();
        
        return $this->configuration;
    }
    
    public function initializeDefaultAuthorization(): array
    {
        return $this->transaction(function() {
            // Buat default roles
            $defaultRoles = [
                [
                    'name' => 'Super Administrator',
                    'code' => 'super_admin',
                    'description' => 'System administrator dengan akses penuh',
                    'is_system' => true,
                    'permissions' => ['*'] // Semua permissions
                ],
                [
                    'name' => 'Administrator',
                    'code' => 'admin',
                    'description' => 'Administrator dengan akses terbatas',
                    'is_system' => true,
                    'permissions' => [
                        'dashboard.view',
                        'product.view',
                        'product.create',
                        'product.update',
                        'product.delete'
                    ]
                ],
                [
                    'name' => 'Content Manager',
                    'code' => 'content_manager',
                    'description' => 'Manager konten produk',
                    'is_system' => true,
                    'permissions' => [
                        'product.view',
                        'product.create',
                        'product.update',
                        'category.view',
                        'category.manage'
                    ]
                ]
            ];
            
            $results = [
                'roles_created' => 0,
                'permissions_created' => 0,
                'assignments' => 0
            ];
            
            foreach ($defaultRoles as $roleData) {
                // Cek jika role sudah ada
                $existingRole = $this->roleRepository->findByCode($roleData['code']);
                if (!$existingRole) {
                    // Buat role
                    $role = new Role();
                    $role->setName($roleData['name']);
                    $role->setCode($roleData['code']);
                    $role->setDescription($roleData['description']);
                    $role->setIsSystem($roleData['is_system']);
                    $role->setStatus(RoleStatus::ACTIVE);
                    $role->setCreatedAt(new Time('now'));
                    $role->setUpdatedAt(new Time('now'));
                    
                    $savedRole = $this->roleRepository->save($role);
                    $results['roles_created']++;
                    
                    // Assign permissions
                    if ($roleData['permissions'][0] === '*') {
                        // Super admin - assign semua permissions
                        $allPermissions = $this->permissionRepository->findAllActive();
                        foreach ($allPermissions as $permission) {
                            $this->roleRepository->assignPermission($savedRole->getId(), $permission->getId());
                            $results['assignments']++;
                        }
                    } else {
                        // Assign specific permissions
                        foreach ($roleData['permissions'] as $permissionCode) {
                            $permission = $this->permissionRepository->findByCode($permissionCode);
                            if ($permission) {
                                $this->roleRepository->assignPermission($savedRole->getId(), $permission->getId());
                                $results['assignments']++;
                            }
                        }
                    }
                }
            }
            
            // Clear cache
            $this->clearAllPermissionCache();
            
            return $results;
        }, 'initialize_default_authorization');
    }
    
    // ==================== ABSTRACT METHOD IMPLEMENTATIONS ====================
    
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        $errors = [];
        
        // TODO: Implement business rules validation
        // Contoh: validasi role name unik, permission hierarchy, dll
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    public function getServiceName(): string
    {
        return 'AuthorizationService';
    }
    
    public function getAuthorizationHealthStatus(): array
    {
        return $this->getHealthStatus();
    }
    
    // ==================== PRIVATE METHODS ====================
    
    private function buildRoleResponse(Role $role): RoleResponse
    {
        $response = new RoleResponse();
        $response->setId($role->getId());
        $response->setName($role->getName());
        $response->setCode($role->getCode());
        $response->setDescription($role->getDescription());
        $response->setStatus($role->getStatus()->value);
        $response->setIsSystem($role->isSystem());
        $response->setCreatedAt($role->getCreatedAt()->format('Y-m-d H:i:s'));
        $response->setUpdatedAt($role->getUpdatedAt()->format('Y-m-d H:i:s'));
        
        // Get permission count (cached)
        $permissionCount = $this->roleRepository->countPermissions($role->getId());
        $response->setPermissionCount($permissionCount);
        
        // Get admin count (cached)
        $adminCount = $this->roleRepository->countAdminAssignments($role->getId());
        $response->setAdminCount($adminCount);
        
        return $response;
    }
    
    private function buildPermissionResponse(Permission $permission): PermissionResponse
    {
        $response = new PermissionResponse();
        $response->setId($permission->getId());
        $response->setName($permission->getName());
        $response->setCode($permission->getCode());
        $response->setDescription($permission->getDescription());
        $response->setStatus($permission->getStatus()->value);
        $response->setCreatedAt($permission->getCreatedAt()->format('Y-m-d H:i:s'));
        $response->setUpdatedAt($permission->getUpdatedAt()->format('Y-m-d H:i:s'));
        
        // Get role count (cached)
        $roleCount = $this->permissionRepository->countRoleAssignments($permission->getId());
        $response->setRoleCount($roleCount);
        
        return $response;
    }
    
    private function loadConfiguration(): array
    {
        // TODO: Load configuration from database or config file
        return [
            'enable_permission_caching' => true,
            'cache_ttl' => 1800,
            'default_role' => 'admin',
            'super_admin_role' => 'super_admin',
            'enable_permission_inheritance' => false,
            'strict_mode' => true
        ];
    }
    
    private function saveConfiguration(array $config): void
    {
        // TODO: Save configuration to database
        // Simpan ke cache untuk sekarang
        $this->cache->save('authorization_config:v3', $config, 86400);
    }
    
    private function validateConfiguration(array $config): array
    {
        $errors = [];
        
        // Validasi required fields
        $required = ['enable_permission_caching', 'default_role', 'super_admin_role'];
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                $errors[] = "Configuration field '{$field}' is required";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    private function isSuperAdmin(int $adminId): bool
    {
        // Cek jika admin memiliki role super_admin
        $adminRoles = $this->getAdminRoles($adminId);
        foreach ($adminRoles as $role) {
            if ($role->getCode() === 'super_admin') {
                return true;
            }
        }
        
        return false;
    }
}