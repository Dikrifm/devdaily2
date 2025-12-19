<?php

namespace App\Exceptions;

use RuntimeException;

class LinkNotFoundException extends RuntimeException
{
    public static function forId(int $id): self
    {
        return new self("Entity with ID $id not found.");
    }
}
