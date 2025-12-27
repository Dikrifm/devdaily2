<?php

namespace App\DTOs\Requests\Category;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

final class UpdateCategoryRequest extends BaseDTO
{
    private int $categoryId;
    private ?string $name = null;
    private ?string $slug = null;
    private ?string $description = null;
    private ?string $icon = null;
    private ?int $sortOrder = null;
    private ?bool $active = null;
    private ?int $parentId = null;
    private ?float $commissionRate = null;
    private ?int $updatedBy = null;
    private ?string $color = null;
    private ?string $metaTitle = null;
    private ?string $metaDescription = null;
    private bool $regenerateSlug = false;
    private array $changedFields = [];

    private function __construct(int $categoryId)
    {
        $this->categoryId = $categoryId;
    }

    public static function fromRequest(int $categoryId, array $requestData, ?int $updatedBy = null): self
    {
        $instance = new self($categoryId);
        $instance->validateAndHydrate($requestData, $updatedBy);
        return $instance;
    }

    public static function create(
        int $categoryId,
        array $updates,
        ?int $updatedBy = null
    ): self {
        $instance = new self($categoryId);
        $instance->validateAndHydrate($updates, $updatedBy);
        return $instance;
    }

    private function validateAndHydrate(array $data, ?int $updatedBy = null): void
    {
        $errors = [];
        $originalData = $this->getOriginalDataSnapshot();
        
        // Validate category ID
        if ($this->categoryId <= 0) {
            $errors['category_id'] = 'Category ID must be positive integer';
        }

        $this->updatedBy = $updatedBy;

        // Validate name (optional)
        if (isset($data['name'])) {
            $name = trim($data['name']);
            if (empty($name)) {
                $errors['name'] = 'Category name cannot be empty';
            } elseif (strlen($name) > 100) {
                $errors['name'] = 'Category name must not exceed 100 characters';
            } else {
                $this->name = $name;
                
                // Auto-regenerate slug if name changed and slug not provided
                if (!isset($data['slug']) && isset($originalData['name']) && $originalData['name'] !== $name) {
                    $this->regenerateSlug = true;
                }
            }
        }

        // Validate slug (optional, but if provided must be valid)
        if (isset($data['slug'])) {
            $slug = trim($data['slug']);
            if (empty($slug)) {
                $errors['slug'] = 'Slug cannot be empty';
            } elseif (strlen($slug) > 50) {
                $errors['slug'] = 'Slug must not exceed 50 characters';
            } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors['slug'] = 'Slug can only contain lowercase letters, numbers, and hyphens';
            } else {
                $this->slug = $slug;
            }
        }

        // Validate description (optional)
        if (isset($data['description'])) {
            $description = trim($data['description']);
            if (strlen($description) > 2000) {
                $errors['description'] = 'Description must not exceed 2000 characters';
            } else {
                $this->description = $description ?: null;
            }
        }

        // Validate icon (optional)
        if (isset($data['icon'])) {
            $icon = trim($data['icon']);
            if (!empty($icon) && strlen($icon) > 50) {
                $errors['icon'] = 'Icon must not exceed 50 characters';
            } else {
                $this->icon = $icon ?: null;
            }
        }

        // Validate sort order (optional)
        if (isset($data['sort_order'])) {
            $sortOrder = filter_var($data['sort_order'], FILTER_VALIDATE_INT);
            if ($sortOrder === false || $sortOrder < 0) {
                $errors['sort_order'] = 'Sort order must be a non-negative integer';
            } else {
                $this->sortOrder = $sortOrder;
            }
        }

        // Validate active status (optional)
        if (isset($data['active'])) {
            $active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                $errors['active'] = 'Active must be a boolean value';
            } else {
                $this->active = $active;
            }
        }

        // Validate parent ID (optional)
        if (isset($data['parent_id'])) {
            $parentId = filter_var($data['parent_id'], FILTER_VALIDATE_INT);
            if ($parentId === false) {
                $errors['parent_id'] = 'Parent ID must be an integer';
            } elseif ($parentId < 0) {
                $errors['parent_id'] = 'Parent ID must be non-negative';
            } elseif ($parentId === $this->categoryId) {
                $errors['parent_id'] = 'Category cannot be its own parent';
            } else {
                $this->parentId = $parentId ?: null;
            }
        }

        // Validate commission rate (optional)
        if (isset($data['commission_rate'])) {
            $commissionRate = filter_var($data['commission_rate'], FILTER_VALIDATE_FLOAT);
            if ($commissionRate === false) {
                $errors['commission_rate'] = 'Commission rate must be a valid number';
            } elseif ($commissionRate < 0 || $commissionRate > 1) {
                $errors['commission_rate'] = 'Commission rate must be between 0 and 1';
            } else {
                $this->commissionRate = $commissionRate ?: null;
            }
        }

        // Validate color (optional)
        if (isset($data['color'])) {
            $color = trim($data['color']);
            if (!empty($color)) {
                if (!preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
                    $errors['color'] = 'Color must be a valid hex color code';
                } elseif (strlen($color) > 20) {
                    $errors['color'] = 'Color must not exceed 20 characters';
                } else {
                    $this->color = $color;
                }
            } else {
                $this->color = null;
            }
        }

        // Validate meta title (optional)
        if (isset($data['meta_title'])) {
            $metaTitle = trim($data['meta_title']);
            if (strlen($metaTitle) > 255) {
                $errors['meta_title'] = 'Meta title must not exceed 255 characters';
            } else {
                $this->metaTitle = $metaTitle ?: null;
            }
        }

        // Validate meta description (optional)
        if (isset($data['meta_description'])) {
            $metaDescription = trim($data['meta_description']);
            if (strlen($metaDescription) > 500) {
                $errors['meta_description'] = 'Meta description must not exceed 500 characters';
            } else {
                $this->metaDescription = $metaDescription ?: null;
            }
        }

        // Validate regenerate slug flag (optional)
        if (isset($data['regenerate_slug'])) {
            $regenerateSlug = filter_var($data['regenerate_slug'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($regenerateSlug === null) {
                $errors['regenerate_slug'] = 'Regenerate slug must be a boolean value';
            } else {
                $this->regenerateSlug = $regenerateSlug;
            }
        }

        // Validate business rules
        $this->validateBusinessRules($errors);
        
        // Identify changed fields
        $this->identifyChangedFields($originalData);

        if (!empty($errors)) {
            throw ValidationException::forField('category_update', 'Validation failed', $errors);
        }
    }

    private function getOriginalDataSnapshot(): array
    {
        // This would typically fetch from repository
        // For DTO purposes, return empty array
        return [];
    }

    private function identifyChangedFields(array $originalData): void
    {
        $this->changedFields = [];

        $fieldsToCheck = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sortOrder' => $this->sortOrder,
            'active' => $this->active,
            'parentId' => $this->parentId,
            'commissionRate' => $this->commissionRate,
            'color' => $this->color,
            'metaTitle' => $this->metaTitle,
            'metaDescription' => $this->metaDescription,
        ];

        foreach ($fieldsToCheck as $field => $newValue) {
            $originalValue = $originalData[$field] ?? null;
            
            // Special handling for float comparison
            if ($field === 'commissionRate' && $newValue !== null && $originalValue !== null) {
                if (abs((float)$newValue - (float)$originalValue) > 0.00001) {
                    $this->changedFields[] = $field;
                }
            }
            // Special handling for boolean
            elseif ($field === 'active' && $newValue !== null) {
                if ((bool)$newValue !== (bool)$originalValue) {
                    $this->changedFields[] = $field;
                }
            }
            // For other fields
            elseif ($newValue !== null && $newValue !== $originalValue) {
                $this->changedFields[] = $field;
            }
        }
    }

    private function validateBusinessRules(array &$errors): void
    {
        // Business rule: Cannot set parent to a descendant
        if ($this->parentId !== null) {
            // This check requires repository access, so it's handled in Service
            // We'll just note it here
        }

        // Business rule: Commission rate consistency
        if ($this->commissionRate !== null && $this->commissionRate < 0) {
            $errors['commission_rate'] = 'Commission rate cannot be negative';
        }
    }

    public static function rules(): array
    {
        return [
            'name' => 'nullable|string|min:1|max:100',
            'slug' => 'nullable|string|min:1|max:50|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'description' => 'nullable|string|max:2000',
            'icon' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'active' => 'nullable|boolean',
            'parent_id' => 'nullable|integer|min:0',
            'commission_rate' => 'nullable|numeric|min:0|max:1',
            'color' => 'nullable|string|regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/|max:20',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:500',
            'regenerate_slug' => 'nullable|boolean',
        ];
    }

    public static function messages(): array
    {
        return [
            'name.min' => 'Category name is required',
            'name.max' => 'Category name must not exceed 100 characters',
            'slug.regex' => 'Slug can only contain lowercase letters, numbers, and hyphens',
            'description.max' => 'Description must not exceed 2000 characters',
            'commission_rate.min' => 'Commission rate cannot be negative',
            'commission_rate.max' => 'Commission rate cannot exceed 1',
            'color.regex' => 'Color must be a valid hex color code',
        ];
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function getParentId(): ?int
    {
        return $this->parentId;
    }

    public function getCommissionRate(): ?float
    {
        return $this->commissionRate;
    }

    public function getUpdatedBy(): ?int
    {
        return $this->updatedBy;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function shouldRegenerateSlug(): bool
    {
        return $this->regenerateSlug;
    }

    public function getChangedFields(): array
    {
        return $this->changedFields;
    }

    public function hasChanges(): bool
    {
        return !empty($this->changedFields);
    }

    public function isFieldChanged(string $field): bool
    {
        return in_array($field, $this->changedFields, true);
    }

    public function getChangeSummary(): string
    {
        if (!$this->hasChanges()) {
            return 'No changes';
        }

        $changes = [];
        foreach ($this->changedFields as $field) {
            $getter = 'get' . ucfirst($field);
            if (method_exists($this, $getter)) {
                $value = $this->$getter();
                $changes[] = $field . ': ' . $this->formatChangeValue($value);
            } else {
                $changes[] = $field;
            }
        }

        return implode(', ', $changes);
    }

    private function formatChangeValue($value): string
    {
        if ($value === null) {
            return '(null)';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_float($value)) {
            return number_format($value * 100, 1) . '%';
        }
        return (string) $value;
    }

    public function toDatabaseArray(): array
    {
        $data = [];
        
        if ($this->name !== null) $data['name'] = $this->name;
        if ($this->slug !== null) $data['slug'] = $this->slug;
        if ($this->description !== null) $data['description'] = $this->description;
        if ($this->icon !== null) $data['icon'] = $this->icon;
        if ($this->sortOrder !== null) $data['sort_order'] = $this->sortOrder;
        if ($this->active !== null) $data['active'] = $this->active;
        if ($this->parentId !== null) $data['parent_id'] = $this->parentId;
        if ($this->commissionRate !== null) $data['commission_rate'] = $this->commissionRate;
        if ($this->color !== null) $data['color'] = $this->color;
        if ($this->metaTitle !== null) $data['meta_title'] = $this->metaTitle;
        if ($this->metaDescription !== null) $data['meta_description'] = $this->metaDescription;
        if ($this->updatedBy !== null) $data['updated_by'] = $this->updatedBy;

        return $data;
    }

    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sortOrder,
            'active' => $this->active,
            'parent_id' => $this->parentId,
            'commission_rate' => $this->commissionRate,
            'updated_by' => $this->updatedBy,
            'color' => $this->color,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'regenerate_slug' => $this->regenerateSlug,
            'changed_fields' => $this->changedFields,
            'has_changes' => $this->hasChanges(),
        ];
    }

    public function toSummary(): array
    {
        return [
            'category_id' => $this->categoryId,
            'changes' => $this->getChangeSummary(),
            'updated_by' => $this->updatedBy,
        ];
    }
}