<?php

namespace App\Entities;

use DateTimeImmutable;

/**
 * Badge Entity
 *
 * Represents a product badge/tag for visual categorization and highlighting.
 * Examples: "Best Seller", "New Arrival", "Limited Edition", etc.
 *
 * @package App\Entities
 */
final class Badge extends BaseEntity
{
    /*
     * Badge label/name
     */
    private string $label;

    /**
     * Badge color in hex format
     */
    private ?string $color = null;

    /**
     * Badge constructor
     *
     * @param string $label Badge display label
     */
    public function __construct(string $label)
    {
        $this->label = $label;
        $this->initialize();
    }

    // ==================== GETTER METHODS ====================

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    // ==================== SETTER METHODS (Immutable pattern) ====================

    public function setLabel(string $label): self
    {
        if ($this->label === $label) {
            return $this;
        }

        $this->trackChange('label', $this->label, $label);
        $this->label = $label;
        $this->markAsUpdated();
        return $this;
    }

    public function setColor(?string $color): self
    {
        if ($color !== null && !preg_match('/^#[0-9A-F]{6}$/i', $color)) {
            throw new \InvalidArgumentException('Color must be a valid hex code (e.g., #ef4444) or null');
        }

        $normalizedColor = $color !== null ? strtoupper($color) : null;
        if ($this->color === $normalizedColor) {
            return $this;
        }

        $this->trackChange('color', $this->color, $normalizedColor);
        $this->color = $normalizedColor;
        $this->markAsUpdated();
        return $this;
    }

    // ==================== BUSINESS LOGIC METHODS ====================
    /**
     * Check if badge has a custom color
     */
    public function hasColor(): bool
    {
        return $this->color !== null;
    }

    /**
     * Get CSS color style for badge display
     * Returns inline style if color is set, empty string otherwise
     */
    public function getColorStyle(): string
    {
        if (!$this->hasColor()) {
            return '';
        }

        return sprintf('background-color: %s; color: white;', $this->color);
    }

    /**
     * Get Tailwind CSS classes for badge display
     * Based on business need for consistent styling
     */
    public function getTailwindClasses(): string
    {
        $baseClasses = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium';

        if ($this->hasColor()) {
            // For custom colors, we'll use inline style
            return $baseClasses;
        }

        // Default color scheme based on badge type
        $colorMap = [
            'Best Seller' => 'bg-red-100 text-red-800',
            'New Arrival' => 'bg-green-100 text-green-800',
            'Limited Edition' => 'bg-purple-100 text-purple-800',
            'Exclusive' => 'bg-yellow-100 text-yellow-800',
            'Trending' => 'bg-blue-100 text-blue-800',
            'Verified' => 'bg-emerald-100 text-emerald-800',
            'Discount' => 'bg-pink-100 text-pink-800',
            'Premium' => 'bg-amber-100 text-amber-800',
        ];

        return $baseClasses . ' ' . ($colorMap[$this->label] ?? 'bg-gray-100 text-gray-800');
    }

    /**
     * Get FontAwesome icon for badge type
     */
    public function getIcon(): string
    {
        $iconMap = [
            'Best Seller' => 'fas fa-trophy',
            'New Arrival' => 'fas fa-star',
            'Limited Edition' => 'fas fa-clock',
            'Exclusive' => 'fas fa-crown',
            'Trending' => 'fas fa-chart-line',
            'Verified' => 'fas fa-check-circle',
            'Discount' => 'fas fa-tag',
            'Premium' => 'fas fa-gem',
        ];

        return $iconMap[$this->label] ?? 'fas fa-tag';
    }

    /**
     * Check if badge is currently in use by any active product
     * Note: This check should be done at service level
     */
    public function isInUse(): bool
    {
        // This is a placeholder - actual check will be in service layer
        // Business rule: badge is in use if assigned to any active product
        return false;
    }

    /**
     * Check if badge can be archived
     * Business rule: badge in use cannot be archived
     */
    public function canBeArchived(): bool
    {
        if (!parent::canBeArchived()) {
            return false;
        }

        return !$this->isInUse();
    }

    /**
     * Archive badge (soft delete) with usage check
     *
     * @throws \LogicException If badge cannot be archived
     */
    public function archive(): self
    {
        if (!$this->canBeArchived()) {
            throw new \LogicException('Badge cannot be archived because it is still in use by active products.');
        }

        $this->softDelete();
        return $this;
    }

    /**
     * Restore badge from archive
     */
    public function restore(): self
    {
        if (!$this->canBeRestored()) {
            throw new \LogicException('Badge cannot be restored.');
        }

        parent::restore();
        return $this;
    }

    /**
     * Validate badge state
     * Override parent validation with badge-specific rules
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $parentValidation = parent::validate();
        $errors = $parentValidation['errors'];

        // Badge-specific validation
        if ($this->label === '' || $this->label === '0') {
            $errors[] = 'Badge label cannot be empty';
        }

        if (strlen($this->label) > 100) {
            $errors[] = 'Badge label cannot exceed 100 characters';
        }

        if ($this->color !== null && !preg_match('/^#[0-9A-F]{6}$/i', $this->color)) {
            $errors[] = 'Badge color must be a valid hex color code (e.g., #ef4444) or null';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    // ==================== SERIALIZATION METHODS ====================

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'label' => $this->getLabel(),
            'color' => $this->getColor(),
            'has_color' => $this->hasColor(),
            'color_style' => $this->getColorStyle(),
            'tailwind_classes' => $this->getTailwindClasses(),
            'icon' => $this->getIcon(),
            'is_in_use' => $this->isInUse(),
            'can_be_archived' => $this->canBeArchived(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'deleted_at' => $this->getDeletedAt(),
            'is_deleted' => $this->isDeleted(),
        ];
    }

    public static function fromArray(array $data): static
    {
        $badge = new static($data['label'] ?? '');

        if (isset($data['id'])) {
            $badge->setId($data['id']);
        }

        if (isset($data['color'])) {
            $badge->setColor($data['color']);
        }

        if (isset($data['created_at']) && $data['created_at'] instanceof DateTimeImmutable) {
            $badge->setCreatedAt($data['created_at']);
        }

        if (isset($data['updated_at']) && $data['updated_at'] instanceof DateTimeImmutable) {
            $badge->setUpdatedAt($data['updated_at']);
        }

        if (isset($data['deleted_at']) && $data['deleted_at'] instanceof DateTimeImmutable) {
            $badge->setDeletedAt($data['deleted_at']);
        }

        return $badge;
    }

    /**
     * Create a common badge instance
     * Useful for default badges in the system
     *
     * @param string $type Type of common badge
     */
    public static function createCommon(string $type): ?static
    {
        $commonBadges = [
            'best_seller' => ['label' => 'Best Seller', 'color' => '#EF4444'],
            'new_arrival' => ['label' => 'New Arrival', 'color' => '#10B981'],
            'limited' => ['label' => 'Limited Edition', 'color' => '#8B5CF6'],
            'exclusive' => ['label' => 'Exclusive', 'color' => '#F59E0B'],
            'trending' => ['label' => 'Trending', 'color' => '#3B82F6'],
            'verified' => ['label' => 'Verified', 'color' => '#059669'],
            'discount' => ['label' => 'Discount', 'color' => '#EC4899'],
            'premium' => ['label' => 'Premium', 'color' => '#D97706'],
        ];

        if (!isset($commonBadges[$type])) {
            return null;
        }

        $badge = new self($commonBadges[$type]['label']);
        $badge->setColor($commonBadges[$type]['color']);

        return $badge;
    }

    /**
     * Create all common badges for system initialization
     *
     * @return static[]
     */
    public static function createAllCommon(): array
    {
        $types = [
            'best_seller',
            'new_arrival',
            'limited',
            'exclusive',
            'trending',
            'verified',
            'discount',
            'premium',
        ];

        $badges = [];
        foreach ($types as $type) {
            $badge = self::createCommon($type);
            if ($badge !== null) {
                $badges[] = $badge;
            }
        }

        return $badges;
    }

    /**
     * Create a sample badge for testing/demo
     */
    public static function createSample(): static
    {
        return self::createCommon('best_seller') ?? new self('Sample Badge');
    }
}
