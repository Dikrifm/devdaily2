<?php

namespace App\Enums;

/**
 * Product Status Enumeration
 *
 * Defines all possible states in the product workflow lifecycle.
 * Business Rule: Two-level verification process (input → verify → publish)
 *
 * @package App\Enums
 */
enum ProductStatus: string
{
    /**
     * Initial state after admin input
     * Product data entered but not yet submitted for verification
     */
    case DRAFT = 'draft';

    /**
     * Submitted for verification by input admin
     * Awaiting second admin review and approval
     */
    case PENDING_VERIFICATION = 'pending_verification';

    /**
     * Verified by second admin
     * All data validated, ready for publishing
     */
    case VERIFIED = 'verified';

    /**
     * Live and visible to public users
     * Actively generating affiliate revenue
     */
    case PUBLISHED = 'published';

    /**
     * Soft-deleted or archived
     * Not visible, but kept for historical records
     */
    case ARCHIVED = 'archived';

    /**
     * Get all status values as array
     * Useful for validation and UI dropdowns
     *
     * @return array
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }


    public static function valuesString(): string
    {
        return implode(', ', array_map(
            fn ($case) => $case->value,
            self::cases()
        ));
    }

    /**
     * Get statuses that are considered "active" in the system
     * Business Rule: Only PUBLISHED products are publicly visible
     *
     * @return array
     */
    public static function activeStatuses(): array
    {
        return [self::PUBLISHED->value];
    }

    /**
     * Get statuses that require admin attention
     * Business Rule: PENDING_VERIFICATION needs second admin review
     *
     * @return array
     */
    public static function pendingActionStatuses(): array
    {
        return [self::PENDING_VERIFICATION->value];
    }

    /**
     * Get statuses that allow editing
     * Business Rule: Can edit DRAFT and PENDING_VERIFICATION
     *
     * @return array
     */
    public static function editableStatuses(): array
    {
        return [self::DRAFT->value, self::PENDING_VERIFICATION->value];
    }

    /**
     * Check if status allows publication
     * Business Rule: Only VERIFIED products can be published
     *
     * @return bool
     */
    public function canBePublished(): bool
    {
        return in_array($this, [self::VERIFIED, self::PUBLISHED]);
    }

    /**
     * Check if status is considered "live"
     * Business Rule: PUBLISHED = visible to users
     *
     * @return bool
     */
    public function isLive(): bool
    {
        return $this === self::PUBLISHED;
    }

    /**
     * Get next logical status in workflow
     * Business Rule: DRAFT → PENDING → VERIFIED → PUBLISHED
     *
     * @return ProductStatus|null
     */
    public function nextStatus(): ?ProductStatus
    {
        return match ($this) {
            self::DRAFT => self::PENDING_VERIFICATION,
            self::PENDING_VERIFICATION => self::VERIFIED,
            self::VERIFIED => self::PUBLISHED,
            self::PUBLISHED => self::ARCHIVED,
            self::ARCHIVED => null,
        };
    }

    /**
     * Get previous logical status in workflow
     *
     * @return ProductStatus|null
     */
    public function previousStatus(): ?ProductStatus
    {
        return match ($this) {
            self::PENDING_VERIFICATION => self::DRAFT,
            self::VERIFIED => self::PENDING_VERIFICATION,
            self::PUBLISHED => self::VERIFIED,
            self::ARCHIVED => self::PUBLISHED,
            self::DRAFT => null,
        };
    }

    /**
     * Get display label for UI
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_VERIFICATION => 'Pending Verification',
            self::VERIFIED => 'Verified',
            self::PUBLISHED => 'Published',
            self::ARCHIVED => 'Archived',
        };
    }

    /**
     * Get Tailwind CSS color class for status badges
     *
     * @return string
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::DRAFT => 'bg-gray-100 text-gray-800',
            self::PENDING_VERIFICATION => 'bg-yellow-100 text-yellow-800',
            self::VERIFIED => 'bg-blue-100 text-blue-800',
            self::PUBLISHED => 'bg-green-100 text-green-800',
            self::ARCHIVED => 'bg-red-100 text-red-800',
        };
    }

    /**
     * Get FontAwesome icon for status display
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::DRAFT => 'fas fa-edit',
            self::PENDING_VERIFICATION => 'fas fa-clock',
            self::VERIFIED => 'fas fa-check-circle',
            self::PUBLISHED => 'fas fa-globe',
            self::ARCHIVED => 'fas fa-archive',
        };
    }

    /**
     * Validate if transition to target status is allowed
     * Business Rule: Strict workflow progression required
     *
     * @param ProductStatus $targetStatus
     * @return bool
     */
    public function canTransitionTo(ProductStatus $targetStatus): bool
    {
        // Special case: can always archive from any state
        if ($targetStatus === self::ARCHIVED) {
            return true;
        }

        // Special case: can restore from archived to published
        if ($this === self::ARCHIVED && $targetStatus === self::PUBLISHED) {
            return true;
        }

        // Normal workflow progression
        $allowedTransitions = [
            self::DRAFT->value => [self::PENDING_VERIFICATION->value],
            self::PENDING_VERIFICATION->value => [self::VERIFIED->value, self::DRAFT->value],
            self::VERIFIED->value => [self::PUBLISHED->value, self::PENDING_VERIFICATION->value],
            self::PUBLISHED->value => [self::VERIFIED->value],
        ];

        return in_array(
            $targetStatus->value,
            $allowedTransitions[$this->value] ?? []
        );
    }

    /**
     * Get all allowed transitions from current status
     *
     * @return array<ProductStatus>
     */
    public function allowedTransitions(): array
    {
        $transitions = [];

        foreach (self::cases() as $status) {
            if ($this->canTransitionTo($status)) {
                $transitions[] = $status;
            }
        }

        return $transitions;
    }
}
