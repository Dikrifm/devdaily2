<?php

namespace App\Services;

use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Exceptions\AuthorizationException;

class AuthorizationService
{
    private AdminRepositoryInterface $adminRepository;
    
    /**
     * Permission map untuk MVP
     * Format: ['role' => ['permission1', 'permission2', ...]]
     */
    private const PERMISSION_MAP = [
        'super_admin' => [
            '*', // Wildcard - access to everything
        ],
        'admin' => [
            'product.create',
            'product.edit',
            'product.delete',
            'product.publish',
            'product.archive',
            'category.create',
            'category.edit',
            'category.delete',
            'link.create',
            'link.edit',
            'link.delete',
            'marketplace.create',
            'marketplace.edit',
            'marketplace.delete',
            'dashboard.view',
            'audit.view',
        ],
        'editor' => [
            'product.create',
            'product.edit',
            'category.edit',
            'link.edit',
            'dashboard.view',
        ],
    ];

    /**
     * Resource-specific rules untuk ownership checking
     * Format: ['permission' => 'ownership_field']
     */
    private const OWNERSHIP_RULES = [
        'product.edit.own' => 'created_by',
        'product.delete.own' => 'created_by',
        'link.edit.own' => 'created_by',
        'link.delete.own' => 'created_by',
    ];

    public function __construct(AdminRepositoryInterface $adminRepository)
    {
        $this->adminRepository = $adminRepository;
    }

    /**
     * Check if user has permission (MVP version)
     * 
     * @param int $userId
     * @param string $permission
     * @param mixed $resource Optional resource for ownership check
     * @return bool
     */
    public function can(int $userId, string $permission, $resource = null): bool
    {
        // 1. Get user role
        $admin = $this->adminRepository->find($userId);
        if (!$admin || !$admin->isActive()) {
            return false;
        }

        $role = $admin->getRole();

        // 2. Check if role exists in permission map
        if (!isset(self::PERMISSION_MAP[$role])) {
            return false;
        }

        $userPermissions = self::PERMISSION_MAP[$role];

        // 3. Check for wildcard permission
        if (in_array('*', $userPermissions)) {
            return true;
        }

        // 4. Check exact permission
        if (in_array($permission, $userPermissions)) {
            return true;
        }

        // 5. Check for ownership-based permission
        if ($resource && $this->checkOwnershipPermission($userId, $permission, $resource, $userPermissions)) {
            return true;
        }

        return false;
    }

    /**
     * Authorize or throw exception
     * 
     * @param int $userId
     * @param string $permission
     * @param mixed $resource
     * @throws AuthorizationException
     */
    public function authorize(int $userId, string $permission, $resource = null): void
    {
        if (!$this->can($userId, $permission, $resource)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    /**
     * Get all permissions for a user
     */
    public function getPermissions(int $userId): array
    {
        $admin = $this->adminRepository->find($userId);
        if (!$admin || !$admin->isActive()) {
            return [];
        }

        $role = $admin->getRole();
        return self::PERMISSION_MAP[$role] ?? [];
    }

    /**
     * Check if user has any of the given permissions
     */
    public function canAny(int $userId, array $permissions, $resource = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($userId, $permission, $resource)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has all of the given permissions
     */
    public function canAll(int $userId, array $permissions, $resource = null): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($userId, $permission, $resource)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get user role
     */
    public function getRole(int $userId): ?string
    {
        $admin = $this->adminRepository->find($userId);
        return $admin?->getRole();
    }

    /**
     * Check ownership-based permissions
     */
    private function checkOwnershipPermission(int $userId, string $permission, $resource, array $userPermissions): bool
    {
        // Check for .own permission variant
        $ownPermission = $permission . '.own';
        
        if (!in_array($ownPermission, $userPermissions)) {
            return false;
        }

        // Get ownership field from rules
        $ownershipField = self::OWNERSHIP_RULES[$ownPermission] ?? null;
        if (!$ownershipField) {
            return false;
        }

        // Check if resource has ownership field that matches user
        if (is_array($resource)) {
            $ownerId = $resource[$ownershipField] ?? null;
        } elseif (is_object($resource) && method_exists($resource, 'get' . ucfirst($ownershipField))) {
            $method = 'get' . ucfirst($ownershipField);
            $ownerId = $resource->$method();
        } elseif (is_object($resource) && isset($resource->$ownershipField)) {
            $ownerId = $resource->$ownershipField;
        } else {
            return false;
        }

        return $ownerId === $userId;
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin(int $userId): bool
    {
        return $this->getRole($userId) === 'super_admin';
    }

    /**
     * Check if user is admin or higher
     */
    public function isAdmin(int $userId): bool
    {
        $role = $this->getRole($userId);
        return in_array($role, ['super_admin', 'admin']);
    }

    /**
     * Check if user is editor or higher
     */
    public function isEditor(int $userId): bool
    {
        $role = $this->getRole($userId);
        return in_array($role, ['super_admin', 'admin', 'editor']);
    }

    /**
     * Validate permission string format
     */
    public function isValidPermission(string $permission): bool
    {
        // Basic validation: entity.action format
        return (bool) preg_match('/^[a-z_]+\.[a-z_]+(\.[a-z_]+)?$/', $permission);
    }
}