<?php

namespace App\Entities\Traits;

use DateTimeImmutable;

/**
 * SoftDeletable Trait
 *
 * Provides soft delete functionality for entities.
 * Instead of physical deletion, sets a deletion timestamp.
 *
 * @package App\Entities\Traits
 */
trait SoftDeletableTrait
{
    /**
     * Deletion timestamp
     */
    private ?DateTimeImmutable $deleted_at = null;

    /**
     * Get deletion timestamp
     */
    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deleted_at;
    }

    /**
     * Set deletion timestamp
     */
    public function setDeletedAt(?DateTimeImmutable $deleted_at): void
    {
        $this->deleted_at = $deleted_at;
    }

    /**
     * Check if entity is soft-deleted
     */
    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Soft delete the entity
     * Sets deleted_at to current time
     */
    public function softDelete(?string $param = null): void
    {
        $this->deleted_at = new DateTimeImmutable();
    }

    /**
     * Restore a soft-deleted entity
     * Sets deleted_at to null
     */
    public function restore(): void
    {
        $this->deleted_at = null;
    }

    /**
     * Check if entity was deleted within specific days
     * Useful for cleanup or permanent deletion workflows
     */
    public function wasDeletedWithinDays(int $days): bool
    {
        if ($this->deleted_at === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $interval = $now->diff($this->deleted_at);

        return $interval->days <= $days;
    }

    /**
     * Get days since deletion
     * Returns null if not deleted
     */
    public function getDaysSinceDeletion(): ?int
    {
        if ($this->deleted_at === null) {
            return null;
        }

        $now = new DateTimeImmutable();
        $interval = $now->diff($this->deleted_at);

        return $interval->days;
    }
}
