<?php

namespace App\Entities;

use DateTimeImmutable;

/*b
 * Marketplace Entity
 * * Represents an e-commerce marketplace where products are sold.
 * Examples: Tokopedia, Shopee, Lazada, etc.
 * * @package App\Entities
 */
final class Marketplace extends BaseEntity
{
    /**
     * Marketplace name
     * * @var string
     */
    private string $name;

    /**
     * URL-friendly slug (unique)
     * * @var string
     */
    private string $slug;

    /**
     * Marketplace icon (FontAwesome or custom)
     * * @var string|null
     */
    private ?string $icon = null;

    /**
     * Brand color in hex format
     * * @var string
     */
    private string $color = '#64748b'; // Default slate-500

    /**
     * Whether marketplace is active in the system
     * * @var bool
     */
    private bool $active = true;

    /**
     * Marketplace constructor
     * * @param string $name Marketplace name
     * @param string $slug URL slug
     */
    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->initialize();
    }

    // ==================== GETTER METHODS ====================

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    // ==================== SETTER METHODS (Immutable pattern) ====================

    public function setName(string $name): self
    {
        if ($this->name === $name) {
            return $this;
        }

        $this->trackChange('name', $this->name, $name);
        $this->name = $name;
        $this->markAsUpdated();
        return $this;
    }

    public function setSlug(string $slug): self
    {
        if ($this->slug === $slug) {
            return $this;
        }

        $this->trackChange('slug', $this->slug, $slug);
        $this->slug = $slug;
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

    public function setColor(string $color): self
    {
        if (!preg_match('/^#[0-9A-F]{6}$/i', $color)) {
            throw new \InvalidArgumentException('Color must be a valid hex code (e.g., #3b82f6)');
        }

        $normalizedColor = strtoupper($color);
        if ($this->color === $normalizedColor) {
            return $this;
        }

        $this->trackChange('color', $this->color, $normalizedColor);
        $this->color = $normalizedColor;
        $this->markAsUpdated();
        return $this;
    }

    public function setActive(bool $active): self
    {
        if ($this->active === $active) {
            return $this;
        }

        $this->trackChange('active', $this->active, $active);
        $this->active = $active;
        $this->markAsUpdated();
        return $this;
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    public function activate(): self
    {
        return $this->setActive(true);
    }

    public function deactivate(): self
    {
        return $this->setActive(false);
    }

    /**
     * Check if marketplace has an icon
     * * @return bool
     */
    public function hasIcon(): bool
    {
        return $this->icon !== null && $this->icon !== '';
    }

    /**
     * Get CSS classes for marketplace display
     * Useful for frontend styling
     * * @return string
     */
    public function getCssClasses(): string
    {
        $classes = sprintf('marketplace marketplace-%s', $this->slug);

        if (!$this->active) {
            $classes .= ' marketplace-inactive';
        }

        return $classes;
    }

    /**
     * Get inline style for marketplace color
     * * @return string
     */
    public function getColorStyle(): string
    {
        return sprintf('color: %s;', $this->color);
    }

    /**
     * Get background style for marketplace color
     * * @return string
     */
    public function getBackgroundStyle(): string
    {
        return sprintf('background-color: %s;', $this->color);
    }

    /**
     * Check if marketplace is currently in use
     * Business rule: marketplace is in use if it has at least 1 active link
     * Note: This check should be done at service level
     * * @return bool
     */
    public function isInUse(): bool
    {
        // This is a placeholder - actual check will be in service layer
        // Business rule: marketplace is in use if it has at least 1 active link
        return false;
    }

    /**
     * Check if marketplace can be archived
     * Business rule: marketplace with active links cannot be archived
     * * @return bool
     */
    public function canBeArchived(): bool
    {
        if (!parent::canBeArchived()) {
            return false;
        }

        // Additional check: cannot archive if active (must deactivate first)
        if ($this->active) {
            return false;
        }

        return !$this->isInUse();
    }

    /**
     * Archive marketplace (soft delete)
     * Override to add custom logic with validation
     * * @return self
     * @throws \LogicException If marketplace cannot be archived
     */
    public function archive(): self
    {
        if (!$this->canBeArchived()) {
            throw new \LogicException('Marketplace cannot be archived. It may have active links or is already archived.');
        }

        $this->softDelete();
        $this->deactivate();
        return $this;
    }

    /**
     * Restore marketplace from archive
     * * @return self
     */
    public function restore(): self
    {
        if (!$this->canBeRestored()) {
            throw new \LogicException('Marketplace cannot be restored.');
        }

        parent::restore();
        $this->activate();
        return $this;
    }

    /**
     * Validate marketplace state
     * Override parent validation with marketplace-specific rules
     * * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $parentValidation = parent::validate();
        $errors = $parentValidation['errors'];

        // Marketplace-specific validation
        if ($this->name === '' || $this->name === '0') {
            $errors[] = 'Marketplace name cannot be empty';
        }

        if ($this->slug === '' || $this->slug === '0') {
            $errors[] = 'Marketplace slug cannot be empty';
        }

        if (!preg_match('/^[a-z0-9\-]+$/', $this->slug)) {
            $errors[] = 'Marketplace slug can only contain lowercase letters, numbers, and hyphens';
        }

        if (!preg_match('/^#[0-9A-F]{6}$/i', $this->color)) {
            $errors[] = 'Marketplace color must be a valid hex color code (e.g., #3b82f6)';
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
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'active' => $this->isActive(),
            'has_icon' => $this->hasIcon(),
            'css_classes' => $this->getCssClasses(),
            'color_style' => $this->getColorStyle(),
            'background_style' => $this->getBackgroundStyle(),
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
        $marketplace = new static(
            $data['name'] ?? '',
            $data['slug'] ?? ''
        );

        if (isset($data['id'])) {
            $marketplace->setId($data['id']);
        }

        if (isset($data['icon'])) {
            $marketplace->setIcon($data['icon']);
        }

        if (isset($data['color'])) {
            $marketplace->setColor($data['color']);
        }

        if (isset($data['active'])) {
            $marketplace->setActive((bool) $data['active']);
        }

        if (isset($data['created_at']) && $data['created_at'] instanceof DateTimeImmutable) {
            $marketplace->setCreatedAt($data['created_at']);
        }

        if (isset($data['updated_at']) && $data['updated_at'] instanceof DateTimeImmutable) {
            $marketplace->setUpdatedAt($data['updated_at']);
        }

        if (isset($data['deleted_at']) && $data['deleted_at'] instanceof DateTimeImmutable) {
            $marketplace->setDeletedAt($data['deleted_at']);
        }

        return $marketplace;
    }

    /**
     * Create a default marketplace instance
     * Useful for testing or fallback
     * * @return static
     */
    public static function createDefault(): static
    {
        return new self('Unknown Marketplace', 'unknown');
    }

    /**
     * Create sample marketplaces for testing/demo
     * * @return static[]
     */
    public static function createSamples(): array
    {
        return [
            (new self('Tokopedia', 'tokopedia'))
                ->setIcon('fas fa-store')
                ->setColor('#42B549'),

            (new self('Shopee', 'shopee'))
                ->setIcon('fas fa-shopping-cart')
                ->setColor('#FF5316'),

            (new self('Lazada', 'lazada'))
                ->setIcon('fas fa-bolt')
                ->setColor('#0F146C'),

            (new self('Blibli', 'blibli'))
                ->setIcon('fas fa-box')
                ->setColor('#E60012'),
        ];
    }
}
