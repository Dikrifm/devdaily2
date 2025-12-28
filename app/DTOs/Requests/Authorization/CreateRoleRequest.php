<?php

namespace App\DTOs\Requests\Authorization;

use App\DTOs\BaseDTO;

/**
 * Request untuk membuat Role baru.
 */
class CreateRoleRequest extends BaseDTO
{
    protected string $name;
    protected string $slug;
    protected ?string $description = null;
    protected array $permissions = [];

    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors['name'][] = 'Role name is required';
        } elseif (strlen($this->name) > 50) {
            $errors['name'][] = 'Role name cannot exceed 50 characters';
        }

        if (empty($this->slug)) {
            $errors['slug'][] = 'Role slug is required';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $this->slug)) {
            $errors['slug'][] = 'Slug must contain only lowercase letters, numbers, and underscores';
        }

        return $errors;
    }
}
