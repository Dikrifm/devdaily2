<?php

namespace App\DTOs\Responses\Authorization;

use App\DTOs\BaseDTO;

/**
 * Response standard untuk objek Role.
 */
class RoleResponse extends BaseDTO
{
    protected string $slug;
    protected string $name;
    protected ?string $description = null;
    
    /**
     * @var string[]|PermissionResponse[]
     */
    protected array $permissions = [];
    
    /**
     * Penanda role bawaan sistem (tidak bisa dihapus)
     * Output JSON: 'is_system'
     */
    protected bool $isSystem = false;

    public function validate(): array
    {
        return [];
    }
}
