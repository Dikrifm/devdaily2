<?php

namespace App\DTOs\Requests\Authorization;

use App\DTOs\BaseDTO;

/**
 * DTO untuk membuat Custom Role (Persiapan Fase Scale).
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
        }

        if (empty($this->slug)) {
            $errors['slug'][] = 'Role slug is required';
        } elseif (!preg_match('/^[a-z0-9_]+$/', $this->slug)) {
            $errors['slug'][] = 'Slug must use lowercase letters and underscores only';
        }

        return $errors;
    }
}
