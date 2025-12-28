<?php

namespace App\DTOs\Requests\Authorization;

use App\DTOs\BaseDTO;

/**
 * Request untuk mengupdate Role yang sudah ada.
 */
class UpdateRoleRequest extends BaseDTO
{
    /**
     * Input JSON: 'role_slug' (Identifier role lama)
     */
    protected string $roleSlug;
    
    protected ?string $name = null;
    protected ?string $description = null;
    
    /**
     * Optional: Update permission sekaligus
     */
    protected ?array $permissions = null;

    public function validate(): array
    {
        $errors = [];

        if (empty($this->roleSlug)) {
            $errors['role_slug'][] = 'Target role slug is required';
        }

        if ($this->name !== null && empty($this->name)) {
             $errors['name'][] = 'Role name cannot be empty';
        }

        return $errors;
    }
}
