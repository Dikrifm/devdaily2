<?php

namespace App\DTOs\Requests\Authorization;

use App\DTOs\BaseDTO;

/**
 * Request untuk memberikan Permission ke Role tertentu.
 */
class AssignPermissionRequest extends BaseDTO
{
    /**
     * Slug dari role yang akan diberi izin.
     * Input JSON: 'role_slug' -> Hydrated ke: $roleSlug
     */
    protected string $roleSlug;
    
    /**
     * Daftar izin yang akan diberikan.
     * @var string[]
     */
    protected array $permissions;

    public function validate(): array
    {
        $errors = [];

        if (empty($this->roleSlug)) {
            $errors['role_slug'][] = 'Role slug is required';
        }

        if (empty($this->permissions)) {
            $errors['permissions'][] = 'Permissions array cannot be empty';
        } elseif (!is_array($this->permissions)) {
            $errors['permissions'][] = 'Permissions must be an array of strings';
        } else {
            foreach ($this->permissions as $perm) {
                if (!is_string($perm)) {
                    $errors['permissions'][] = 'Each permission must be a string';
                    break;
                }
            }
        }

        return $errors;
    }
}
