<?php

namespace App\DTOs\Responses;

class CategoryTreeResponse
{
    public static function fromEntity($entity): self
    {
        return new self();
    }
    public static function fromTree(array $tree, $param2 = null): self
    {
        return new self();
    }
    public static function create($param = null): self
    {
        return new self();
    }
}
