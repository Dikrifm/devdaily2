<?php

namespace App\DTOs\Requests\Authorization;

use App\DTOs\BaseDTO;

/**
 * DTO untuk mengangkat Admin menjadi Role tertentu (Super Admin / Admin).
 */
class AssignRoleRequest extends BaseDTO
{
    protected int $adminId;
    protected string $role; // 'admin' atau 'super_admin'

    public function validate(): array
    {
        $errors = [];

        if (empty($this->adminId)) {
            $errors['admin_id'][] = 'Admin ID is required';
        }

        $validRoles = ['admin', 'super_admin'];
        if (empty($this->role)) {
            $errors['role'][] = 'Role is required';
        } elseif (!in_array($this->role, $validRoles, true)) {
            $errors['role'][] = 'Invalid role. Allowed: ' . implode(', ', $validRoles);
        }

        return $errors;
    }
}
