<?php

namespace App\Entities;

use DateTimeImmutable;

/**
 * Category Entity
 * 
 * Represents a product category in the system.
 * Limited to 15 categories as per business constraints.
 * 
 * @package App\Entities
 */
class Category extends BaseEntity
{
    /**
     * Category name
     * 
     * @var string
     */
    private string $name;

    /**
     * URL-friendly slug (unique)
     * 
     * @var string
     */
    private string $slug;

    /**
     * FontAwesome or custom icon class
     * 
     * @var string
     */
    private string $icon = 'fas fa-folder';

    /**
     * Manual sorting order
     * 
     * @var int
     */
    private int $sort_order = 0;

    /**
     * Whether category is active and visible
     * 
     * @var bool
     */
    private bool $active = true;

    /**
     * Category constructor
     * 
     * @param string $name Category name
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

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getSortOrder(): int
    {
        return $this->sort_order;
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

    public function setIcon(string $icon): self
    {
        if ($this->icon === $icon) {
            return $this;
        }
        
        $this->trackChange('icon', $this->icon, $icon);
        $this->icon = $icon;
        $this->markAsUpdated();
        return $this;
    }

    public function setSortOrder(int $sort_order): self
    {
        if ($this->sort_order === $sort_order) {
            return $this;
        }
        
        $this->trackChange('sort_order', $this->sort_order, $sort_order);
        $this->sort_order = $sort_order;
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
     * Check if category can be deleted
     * Based on business rule: category should not be deleted if it has products
     * Note: This check should be done at service level with product count
     * 
     * @return bool
     */
    public function canBeDeleted(): bool
    {
        // This is a placeholder - actual check will be in service layer
        // Business rule: categories with products cannot be deleted
        // Return true for now, service layer will enforce business rules
        return true;
    }

    /**
     * Check if category can be archived
     * Business rule: categories with active products cannot be archived
     * 
     * @return bool
     */
    public function canBeArchived(): bool
    {
        // Override parent method to add business logic
        if (!parent::canBeArchived()) {
            return false;
        }
        
        // Additional check: cannot archive if active (must deactivate first)
        if ($this->active) {
            return false;
        }
        
        return $this->canBeDeleted(); // Same rules as deletion
    }

    /**
     * Archive category (soft delete)
     * Override to add custom logic with validation
     * 
     * @return self
     * @throws \LogicException If category cannot be archived
     */
    public function archive(): self
    {
        if (!$this->canBeArchived()) {
            throw new \LogicException('Category cannot be archived. It may have active products or is already archived.');
        }
        
        $this->softDelete();
        $this->deactivate();
        return $this;
    }

    /**
     * Restore category from archive
     * 
     * @return self
     */
    public function restore(): self
    {
        if (!$this->canBeRestored()) {
            throw new \LogicException('Category cannot be restored.');
        }
        
        $this->restoreFromDelete();
        $this->activate();
        return $this;
    }

    /**
     * Check if category is currently in use
     * Business rule: category is in use if it has any published products
     * Note: This check should be done at service level
     * 
     * @return bool
     */
    public function isInUse(): bool
    {
        // This is a placeholder - actual check will be in service layer
        // Business rule: category is in use if it has published products
        return false;
    }

    /**
     * Validate category state
     * Override parent validation with category-specific rules
     * 
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(): array
    {
        $parentValidation = parent::validate();
        $errors = $parentValidation['errors'];
        
        // Category-specific validation
        if (empty($this->name)) {
            $errors[] = 'Category name cannot be empty';
        }
        
        if (empty($this->slug)) {
            $errors[] = 'Category slug cannot be empty';
        }
        
        if (!preg_match('/^[a-z0-9\-]+$/', $this->slug)) {
            $errors[] = 'Category slug can only contain lowercase letters, numbers, and hyphens';
        }
        
        if ($this->sort_order < 0) {
            $errors[] = 'Sort order cannot be negative';
        }
        
        // Business rule: Maximum 15 categories (enforced at service level, not here)
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Get display icon HTML
     * 
     * @return string
     */
    public function getIconHtml(): string
    {
        return sprintf('<i class="%s"></i>', htmlspecialchars($this->icon));
    }

    /**
     * Get Tailwind CSS classes for category display
     * 
     * @return string
     */
    public function getDisplayClasses(): string
    {
        $baseClasses = 'category-item';
        
        if (!$this->active) {
            $baseClasses .= ' opacity-50';
        }
        
        if ($this->isDeleted()) {
            $baseClasses .= ' line-through';
        }
        
        return $baseClasses;
    }

    // ==================== SERIALIZATION METHODS ====================

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'slug' => $this->getSlug(),
            'icon' => $this->getIcon(),
            'icon_html' => $this->getIconHtml(),
            'sort_order' => $this->getSortOrder(),
            'active' => $this->isActive(),
            'is_in_use' => $this->isInUse(),
            'can_be_deleted' => $this->canBeDeleted(),
            'can_be_archived' => $this->canBeArchived(),
            'display_classes' => $this->getDisplayClasses(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'deleted_at' => $this->getDeletedAt(),
            'is_deleted' => $this->isDeleted(),
        ];
    }

    public static function fromArray(array $data): static
    {
        $category = new self(
            $data['name'] ?? '',
            $data['slug'] ?? ''
        );

        if (isset($data['id'])) {
            $category->setId($data['id']);
        }

        if (isset($data['icon'])) {
            $category->setIcon($data['icon']);
        }

        if (isset($data['sort_order'])) {
            $category->setSortOrder((int) $data['sort_order']);
        }

        if (isset($data['active'])) {
            $category->setActive((bool) $data['active']);
        }

        if (isset($data['created_at']) && $data['created_at'] instanceof DateTimeImmutable) {
            $category->setCreatedAt($data['created_at']);
        }

        if (isset($data['updated_at']) && $data['updated_at'] instanceof DateTimeImmutable) {
            $category->setUpdatedAt($data['updated_at']);
        }

        if (isset($data['deleted_at']) && $data['deleted_at'] instanceof DateTimeImmutable) {
            $category->setDeletedAt($data['deleted_at']);
        }

        return $category;
    }

    /**
     * Create a sample category for testing/demo
     * 
     * @return static
     */
    public static function createSample(): static
    {
        $category = new self('Electronics', 'electronics');
        $category->setIcon('fas fa-laptop');
        $category->setSortOrder(1);
        return $category;
    }
}