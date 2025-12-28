<?php

namespace App\DTOs\Responses\Authorization;

use App\DTOs\BaseDTO;

/**
 * Response standard untuk objek Permission.
 */
class PermissionResponse extends BaseDTO
{
    protected string $slug;
    protected string $description;
    protected ?string $group = null; // e.g., 'Product Management'

    public function validate(): array
    {
        return []; // Response DTO tidak butuh validasi input
    }
}
