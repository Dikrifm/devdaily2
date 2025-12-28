<?php

namespace App\DTOs\Requests\Authorization;

use App\DTOs\BaseDTO;

/**
 * Request untuk memeriksa apakah User/Role memiliki izin tertentu.
 */
class CheckPermissionRequest extends BaseDTO
{
    /**
     * Input JSON: 'user_id' -> Hydrated ke: $userId
     */
    protected int $userId;
    
    /**
     * Input JSON: 'permission' (Slug permission, e.g. 'product.create')
     */
    protected string $permission;
    
    protected ?array $context = null;

    public function validate(): array
    {
        $errors = [];

        if (empty($this->userId)) {
            $errors['user_id'][] = 'User ID is required';
        }

        if (empty($this->permission)) {
            $errors['permission'][] = 'Permission slug is required';
        }

        return $errors;
    }
}
