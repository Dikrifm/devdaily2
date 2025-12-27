<?php

namespace App\Entities;

use DateTimeImmutable;

/** 
 * ProductBadge Entity
 *
 * Represents a many-to-many relationship between Products and Badges.
 * This is a junction table entity without timestamps or soft delete.
 *
 * @package App\Entities
 */
final class ProductBadge
{
    /**
     * Product ID (foreign key, part of composite primary key)
     */
    private int $product_id;

    /**
     * Badge ID (foreign key, part of composite primary key)
     */
    private int $badge_id;


    private ?DateTimeImmutable $assigned_at = null;

    private ?int $assigned_by = null;

    public function getAssignedBy(): ?int 
    { 
        return $this->assigned_by; 
    }
    
    public function setAssignedBy(?int $assigned_by): self 
    {
        $this->assigned_by = $assigned_by;
        return $this;
    }

    public function getAssignedAt(): ?DateTimeImmutable
    {
        return $this->assigned_at;
    }

    public function setAssignedAt(?DateTimeImmutable $date): self
    {
        $this->assigned_at = $date;
        return $this;
    }


    /**
     * ProductBadge constructor
     */
    public function __construct(int $product_id, int $badge_id)
    {
        $this->product_id = $product_id;
        $this->badge_id = $badge_id;
    }

    /**
     * Get product ID
     */
    public function getProductId(): int
    {
        return $this->product_id;
    }

    /**
     * Set product ID
     */
    public function setProductId(int $product_id): void
    {
        $this->product_id = $product_id;
    }

    /**
     * Get badge ID
     */
    public function getBadgeId(): int
    {
        return $this->badge_id;
    }

    /**
     * Set badge ID
     */
    public function setBadgeId(int $badge_id): void
    {
        $this->badge_id = $badge_id;
    }

    /**
     * Check if this association is for a specific product
     */
    public function isForProduct(int $product_id): bool
    {
        return $this->product_id === $product_id;
    }

    /**
     * Check if this association is for a specific badge
     */
    public function isForBadge(int $badge_id): bool
    {
        return $this->badge_id === $badge_id;
    }

    /**
     * Get the composite key as an array
     * Useful for database operations
     */
    public function getCompositeKey(): array
    {
        return [
            'product_id' => $this->product_id,
            'badge_id' => $this->badge_id
        ];
    }

    /**
     * Check if another ProductBadge is equal to this one
     */
    public function equals(ProductBadge $other): bool
    {
        return $this->product_id === $other->getProductId()
            && $this->badge_id === $other->getBadgeId();
    }

    /**
     * Convert entity to array representation
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->getProductId(),
            'badge_id' => $this->getBadgeId(),
            'composite_key' => $this->getCompositeKey(),
        ];
    }

    /**
     * Create ProductBadge from array data
     */
    public static function fromArray(array $data): static
    {
        $entity = new static(
            (int) ($data['product_id'] ?? 0),
            (int) ($data['badge_id'] ?? 0)
        );

        if (isset($data['assigned_by'])) {
            $entity->setAssignedBy((int) $data['assigned_by']);
        }

        if (isset($data['assigned_at'])) {
             // Handle string from DB or DateTime object
             $entity->setAssignedAt(
                 is_string($data['assigned_at']) 
                 ? new DateTimeImmutable($data['assigned_at']) 
                 : $data['assigned_at']
             );
        }
         
        return $entity;
    }

    /**
     * Create a sample ProductBadge for testing/demo
     */
    public static function createSample(): static
    {
        return new static(1, 1); // Assuming product ID 1 and badge ID 1
    }
}
