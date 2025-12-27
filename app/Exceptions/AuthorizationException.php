<?php

namespace App\Exceptions;

use Throwable;

class AuthorizationException extends DomainException
{
    protected const ERROR_CODE = 'AUTHORIZATION_FAILED';
    protected int $httpStatusCode = 403;
    
    private ?string $permission = null;
    private ?int $userId = null;
    private ?string $resourceType = null;
    private ?int $resourceId = null;
    private ?string $requiredRole = null;

    public function __construct(
        string $message = 'You are not authorized to perform this action',
        ?string $permission = null,
        ?int $userId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        ?string $requiredRole = null,
        array $details = [],
        ?Throwable $previous = null
    ) {
        $this->permission = $permission;
        $this->userId = $userId;
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->requiredRole = $requiredRole;
        
        // Build context for parent exception
        $context = array_merge($details, [
            'permission' => $permission,
            'user_id' => $userId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'required_role' => $requiredRole,
        ]);
        
        parent::__construct($message, $context, $previous);
    }

    /**
     * Factory method for permission denied
     */
    public static function forPermission(
        string $permission,
        ?int $userId = null,
        ?string $resourceType = null,
        ?int $resourceId = null,
        array $additionalDetails = []
    ): self {
        $message = sprintf(
            'Permission denied: %s%s%s',
            $permission,
            $resourceType ? " on {$resourceType}" : '',
            $resourceId ? " #{$resourceId}" : ''
        );
        
        return new self(
            $message,
            $permission,
            $userId,
            $resourceType,
            $resourceId,
            null,
            $additionalDetails
        );
    }

    /**
     * Factory method for role requirement
     */
    public static function forRole(
        string $requiredRole,
        ?string $currentRole = null,
        ?int $userId = null,
        array $additionalDetails = []
    ): self {
        $message = sprintf(
            'Role required: %s%s',
            $requiredRole,
            $currentRole ? " (current role: {$currentRole})" : ''
        );
        
        return new self(
            $message,
            null,
            $userId,
            null,
            null,
            $requiredRole,
            array_merge($additionalDetails, ['current_role' => $currentRole])
        );
    }

    /**
     * Factory method for ownership requirement
     */
    public static function forOwnership(
        string $resourceType,
        int $resourceId,
        ?int $userId = null,
        array $additionalDetails = []
    ): self {
        $message = sprintf(
            'Ownership required for %s #%d',
            $resourceType,
            $resourceId
        );
        
        return new self(
            $message,
            null,
            $userId,
            $resourceType,
            $resourceId,
            null,
            $additionalDetails
        );
    }

    /**
     * Factory method for admin access requirement
     */
    public static function forAdminAccess(?int $userId = null): self
    {
        return self::forRole('admin', null, $userId, [
            'reason' => 'admin_access_required'
        ]);
    }

    /**
     * Factory method for super admin access requirement
     */
    public static function forSuperAdminAccess(?int $userId = null): self
    {
        return self::forRole('super_admin', null, $userId, [
            'reason' => 'super_admin_access_required'
        ]);
    }

    /**
     * Get the permission that was denied
     */
    public function getPermission(): ?string
    {
        return $this->permission;
    }

    /**
     * Get the user ID that attempted the action
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Get the resource type (if any)
     */
    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    /**
     * Get the resource ID (if any)
     */
    public function getResourceId(): ?int
    {
        return $this->resourceId;
    }

    /**
     * Get the required role (if any)
     */
    public function getRequiredRole(): ?string
    {
        return $this->requiredRole;
    }

    /**
     * Check if this is a permission-based denial
     */
    public function isPermissionDenied(): bool
    {
        return $this->permission !== null;
    }

    /**
     * Check if this is a role-based denial
     */
    public function isRoleDenied(): bool
    {
        return $this->requiredRole !== null;
    }

    /**
     * Check if this is an ownership-based denial
     */
    public function isOwnershipDenied(): bool
    {
        return $this->resourceType !== null && $this->resourceId !== null;
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        $baseArray = parent::toArray();
        
        return array_merge($baseArray, [
            'authorization_info' => [
                'permission' => $this->permission,
                'user_id' => $this->userId,
                'resource_type' => $this->resourceType,
                'resource_id' => $this->resourceId,
                'required_role' => $this->requiredRole,
            ]
        ]);
    }

    /**
     * Convert to log context
     */
    public function toLogContext(): array
    {
        return array_merge(parent::toLogContext(), [
            'authorization_failure' => [
                'type' => $this->getFailureType(),
                'permission' => $this->permission,
                'user_id' => $this->userId,
                'resource' => $this->resourceType ? [
                    'type' => $this->resourceType,
                    'id' => $this->resourceId,
                ] : null,
                'required_role' => $this->requiredRole,
            ]
        ]);
    }

    /**
     * Determine the type of authorization failure
     */
    private function getFailureType(): string
    {
        if ($this->isPermissionDenied()) {
            return 'permission_denied';
        }
        
        if ($this->isRoleDenied()) {
            return 'role_denied';
        }
        
        if ($this->isOwnershipDenied()) {
            return 'ownership_denied';
        }
        
        return 'general_authorization_failure';
    }

    /**
     * Get user-friendly message
     */
    public function getUserMessage(): string
    {
        if ($this->isPermissionDenied()) {
            $resource = $this->resourceType ? 
                "this {$this->resourceType}" : 
                'this resource';
            
            return "You don't have permission to perform this action on {$resource}.";
        }
        
        if ($this->isRoleDenied()) {
            $roleMap = [
                'super_admin' => 'Super Administrator',
                'admin' => 'Administrator',
                'editor' => 'Editor',
            ];
            
            $required = $roleMap[$this->requiredRole] ?? $this->requiredRole;
            return "This action requires {$required} role.";
        }
        
        if ($this->isOwnershipDenied()) {
            return "You can only perform this action on your own resources.";
        }
        
        return $this->getMessage();
    }
}