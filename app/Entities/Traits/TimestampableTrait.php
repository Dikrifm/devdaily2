<?php

namespace App\Entities\Traits;

use DateTimeImmutable;

/**
 * Timestampable Trait
 *
 * Provides created_at and updated_at timestamps for entities.
 * Using DateTimeImmutable for immutable date objects.
 *
 * @package App\Entities\Traits
 */
trait TimestampableTrait
{
    /**
     * Creation timestamp
     */
    protected ?DateTimeImmutable $created_at = null;

    /**
     * Last update timestamp
     */
    protected ?DateTimeImmutable $updated_at = null;

    /**
     * Get creation timestamp
     */
    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->created_at;
    }

    /**
     * Set creation timestamp
     */
    public function setCreatedAt(?DateTimeImmutable $created_at): void
    {
        $this->created_at = $created_at;
    }

    /**
     * Get last update timestamp
     */
    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updated_at;
    }

    /**
     * Set last update timestamp
     */
    public function setUpdatedAt(?DateTimeImmutable $updated_at): void
    {
        $this->updated_at = $updated_at;
    }

    /**
     * Update the updated_at timestamp to current time
     */
    public function touch(): void
    {
        $this->updated_at = new DateTimeImmutable();
    }

    /**
     * Initialize timestamps on creation
     * Should be called in constructor or factory method
     */
    public function initializeTimestamps(): void
    {
        $now = new DateTimeImmutable();
        $this->created_at = $now;
        $this->updated_at = $now;
    }

    /**
     * Check if entity is older than specified days
     * Useful for cache invalidation and maintenance checks
     */
    public function isOlderThanDays(int $days): bool
    {
        if ($this->updated_at === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $interval = $now->diff($this->updated_at);

        return $interval->days > $days;
    }
}
