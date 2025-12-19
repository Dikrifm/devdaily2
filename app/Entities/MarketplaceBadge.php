<?php

namespace App\Entities;

use DateTimeImmutable;

/**
 * MarketplaceBadge Entity
 *
 * Represents a badge specific to marketplace stores/sellers.
 * Examples: "Official Store", "Top Seller", "Verified Seller", "Fast Delivery"
 * These badges are displayed next to store names in product links.
 *
 * @package App\Entities
 */
class MarketplaceBadge extends BaseEntity
{
    /**
     * Badge label/name
     */
    private string $label;

    /**
     * Badge icon (FontAwesome class or custom icon)
     */
    private ?string $icon = null;

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

    public function getIcon(): ?string
    {
        return $this->icon;
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

    public function setIcon(?string $icon): self
    {
        if ($this->icon === $icon) {
            return $this;
        }

        $this->trackChange('icon', $this->icon, $icon);
        $this->icon = $icon;
        $this->markAsUpdated();
        return $this;
    }

    public function setColor(?string $color): self
    {
        if ($color !== null && !preg_match('/^#[0-9A-F]{6}$/i', $color)) {
            throw new \InvalidArgumentException('Color must be a valid hex code (e.g., #10B981) or null');
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
     * Check if badge has an icon
     */
    public function hasIcon(): bool
    {
        return $this->icon !== null && $this->icon !== '';
    }

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

        return sprintf('color: %s;', $this->color);
    }

    /**
     * Get HTML for badge display
     * Combines icon and label for consistent rendering
     */
    public function getDisplayHtml(): string
    {
        $iconHtml = '';
        $colorStyle = $this->getColorStyle();

        if ($this->hasIcon()) {
            $iconHtml = sprintf(
                '<i class="%s mr-1" style="%s"></i>',
                $this->icon,
                $colorStyle
            );
        }

        return sprintf(
            '<span class="inline-flex items-center text-xs font-medium" style="%s">%s%s</span>',
            $colorStyle,
            $iconHtml,
            htmlspecialchars($this->label)
        );
    }

    /**
     * Get Tailwind CSS classes for badge display
     * Based on badge type for consistent styling
     */
    public function getTailwindClasses(): string
    {
        $baseClasses = 'inline-flex items-center text-xs font-medium';

        if ($this->hasColor()) {
            // For custom colors, we'll use inline style
            return $baseClasses;
        }

        // Default color scheme based on badge type
        $colorMap = [
            'Official Store' => 'text-emerald-600',
            'Top Seller' => 'text-amber-600',
            'Verified Seller' => 'text-blue-600',
            'Fast Delivery' => 'text-purple-600',
            'Recommended' => 'text-rose-600',
            'Trusted' => 'text-green-600',
            'Choice' => 'text-indigo-600',
            'Premium Seller' => 'text-yellow-600',
        ];

        return $baseClasses . ' ' . ($colorMap[$this->label] ?? 'text-gray-600');
    }

    /**
     * Check if badge is currently assigned to any active link
     * Note: This check should be done at service level
     */
    public function isAssigned(): bool
    {
        // This is a placeholder - actual check will be in service layer
        // Business rule: badge is assigned if used by any active link
        return false;
    }

    /**
     * Check if badge can be archived
     * Business rule: badge assigned to active links cannot be archived
     */
    public function canBeArchived(): bool
    {
        if (!parent::canBeArchived()) {
            return false;
        }

        return !$this->isAssigned();
    }

    /**
     * Archive badge (soft delete) with assignment check
     *
     * @throws \LogicException If badge cannot be archived
     */
    public function archive(): self
    {
        if (!$this->canBeArchived()) {
            throw new \LogicException('Marketplace badge cannot be archived because it is still assigned to active links.');
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
            throw new \LogicException('Marketplace badge cannot be restored.');
        }

        $this->restore();
        return $this;
    }

    /**
     * Validate badge state
     * Override parent validation with marketplace badge-specific rules
     *
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $parentValidation = parent::validate();
        $errors = $parentValidation['errors'];

        // Marketplace badge-specific validation
        if ($this->label === '' || $this->label === '0') {
            $errors[] = 'Marketplace badge label cannot be empty';
        }

        if (strlen($this->label) > 100) {
            $errors[] = 'Marketplace badge label cannot exceed 100 characters';
        }

        if ($this->icon !== null && strlen($this->icon) > 100) {
            $errors[] = 'Marketplace badge icon cannot exceed 100 characters';
        }

        if ($this->color !== null && !preg_match('/^#[0-9A-F]{6}$/i', $this->color)) {
            $errors[] = 'Marketplace badge color must be a valid hex color code (e.g., #10B981) or null';
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
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'has_icon' => $this->hasIcon(),
            'has_color' => $this->hasColor(),
            'color_style' => $this->getColorStyle(),
            'display_html' => $this->getDisplayHtml(),
            'tailwind_classes' => $this->getTailwindClasses(),
            'is_assigned' => $this->isAssigned(),
            'can_be_archived' => $this->canBeArchived(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'deleted_at' => $this->getDeletedAt(),
            'is_deleted' => $this->isDeleted(),
        ];
    }

    public static function fromArray(array $data): static
    {
        $badge = new self($data['label'] ?? '');

        if (isset($data['id'])) {
            $badge->setId($data['id']);
        }

        if (isset($data['icon'])) {
            $badge->setIcon($data['icon']);
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
     * Create a common marketplace badge instance
     * Useful for default marketplace badges in the system
     *
     * @param string $type Type of common badge
     */
    public static function createCommon(string $type): ?static
    {
        $commonBadges = [
            'official_store' => [
                'label' => 'Official Store',
                'icon' => 'fas fa-check-circle',
                'color' => '#059669'
            ],
            'top_seller' => [
                'label' => 'Top Seller',
                'icon' => 'fas fa-crown',
                'color' => '#D97706'
            ],
            'verified_seller' => [
                'label' => 'Verified Seller',
                'icon' => 'fas fa-shield-check',
                'color' => '#2563EB'
            ],
            'fast_delivery' => [
                'label' => 'Fast Delivery',
                'icon' => 'fas fa-shipping-fast',
                'color' => '#7C3AED'
            ],
            'recommended' => [
                'label' => 'Recommended',
                'icon' => 'fas fa-thumbs-up',
                'color' => '#DC2626'
            ],
            'trusted' => [
                'label' => 'Trusted',
                'icon' => 'fas fa-award',
                'color' => '#059669'
            ],
            'choice' => [
                'label' => 'Choice',
                'icon' => 'fas fa-star',
                'color' => '#4F46E5'
            ],
            'premium_seller' => [
                'label' => 'Premium Seller',
                'icon' => 'fas fa-gem',
                'color' => '#F59E0B'
            ],
        ];

        if (!isset($commonBadges[$type])) {
            return null;
        }

        $badge = new self($commonBadges[$type]['label']);
        $badge->setIcon($commonBadges[$type]['icon']);
        $badge->setColor($commonBadges[$type]['color']);

        return $badge;
    }

    /**
     * Create all common marketplace badges for system initialization
     *
     * @return static[]
     */
    public static function createAllCommon(): array
    {
        $types = [
            'official_store',
            'top_seller',
            'verified_seller',
            'fast_delivery',
            'recommended',
            'trusted',
            'choice',
            'premium_seller',
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
     * Create a sample marketplace badge for testing/demo
     */
    public static function createSample(): static
    {
        return self::createCommon('official_store') ?? new self('Sample Marketplace Badge');
    }
}
