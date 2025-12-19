<?php

namespace App\DTOs\Responses;

class LinkAnalyticsResponse
{
    public static function fromEntity($entity): self
    {
        return new self();
    }
    public static function fromTree(array $tree): self
    {
        return new self();
    }
    public static function create($param = null): self
    {
        return new self();
    }
}
