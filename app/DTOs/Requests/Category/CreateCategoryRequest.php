<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Category;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

/**
 * DTO for creating a new category
 * 
 * @package App\DTOs\Requests\Category
 */
final class CreateCategoryRequest extends BaseDTO
{
    private string $name;
    private string $slug;
    private ?string $description = null;
    private string $icon = 'fas fa-folder';
    private int $sortOrder = 0;
    private bool $active = true;
    private ?int $parentId = null;
    private ?float $commissionRate = null;
    private ?int $createdBy = null;
    private ?string $color = null;
    private ?string $metaTitle = null;
    private ?string $metaDescription = null;

    /**
     * Constructor is private, use factory methods
     */
    private function __construct() {}

    /**
     * Factory method from HTTP request data
     */
    public static function fromRequest(array $requestData, ?int $createdBy = null): self
    {
        $dto = new self();
        $dto->validateAndHydrate($requestData);
        $dto->createdBy = $createdBy;
        
        return $dto;
    }

    /**
     * Factory method from array data (implements BaseDTO abstract method)
     */
    public static function fromArray(array $data): static
    {
        $dto = new static();
        
        // Map array keys to properties (support both snake_case and camelCase)
        $normalizedData = self::normalizeArrayKeys($data);
        
        $dto->validateAndHydrate($normalizedData);
        
        // Set createdBy if provided in array
        if (isset($normalizedData['createdBy'])) {
            $dto->createdBy = (int) $normalizedData['createdBy'];
        }
        
        return $dto;
    }

    /**
     * Factory method with explicit parameters (for testing/CLI usage)
     */
    public static function create(
        string $name,
        ?string $slug = null,
        ?string $description = null,
        ?string $icon = 'fas fa-folder',
        int $sortOrder = 0,
        bool $active = true,
        ?int $parentId = null,
        ?float $commissionRate = null,
        ?int $createdBy = null,
        ?string $color = null,
        ?string $metaTitle = null,
        ?string $metaDescription = null
    ): self {
        $data = [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'icon' => $icon,
            'sort_order' => $sortOrder,
            'active' => $active,
            'parent_id' => $parentId,
            'commission_rate' => $commissionRate,
            'color' => $color,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'createdBy' => $createdBy,
        ];
        
        return self::fromArray($data);
    }

    /**
     * Validate and hydrate the DTO
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // Validate required fields
        $requiredFields = ['name'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                $errors[$field] = "Field {$field} is required";
            }
        }
        
        // Hydrate with validation
        if (empty($errors)) {
            $this->name = $this->sanitizeString($data['name'] ?? '');
            $this->slug = $this->generateSlug($data);
            $this->description = isset($data['description']) ? $this->sanitizeString($data['description']) : null;
            $this->icon = isset($data['icon']) ? $this->sanitizeString($data['icon']) : 'fas fa-folder';
            $this->sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;
            $this->active = isset($data['active']) ? filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) : true;
            $this->parentId = isset($data['parent_id']) ? (int)$data['parent_id'] : null;
            $this->commissionRate = isset($data['commission_rate']) ? $this->validateCommissionRate($data['commission_rate'], $errors) : null;
            $this->color = isset($data['color']) ? $this->validateColor($data['color'], $errors) : null;
            $this->metaTitle = isset($data['meta_title']) ? $this->sanitizeString($data['meta_title']) : null;
            $this->metaDescription = isset($data['meta_description']) ? $this->sanitizeString($data['meta_description']) : null;
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw new ValidationException('Category creation validation failed', $errors);
        }
    }

    /**
     * Generate slug from name or use provided slug
     */
    private function generateSlug(array $data): string
    {
        if (!empty($data['slug'])) {
            return SlugValidator::create()->normalize($data['slug']);
        }
        
        // Auto-generate from name
        return SlugValidator::create()->generate(
            $data['name'],
            ['entityType' => SlugValidator::ENTITY_CATEGORY]
        );
    }

    /**
     * Validate commission rate (0-100%)
     */
    private function validateCommissionRate(mixed $rate, array &$errors): ?float
    {
        if ($rate === '' || $rate === null) {
            return null;
        }
        
        $numericRate = (float)$rate;
        
        if ($numericRate < 0 || $numericRate > 100) {
            $errors['commission_rate'] = 'Commission rate must be between 0 and 100 percent';
            return null;
        }
        
        return round($numericRate, 2);
    }

    /**
     * Validate color hex code
     */
    private function validateColor(string $color, array &$errors): ?string
    {
        $color = strtolower(trim($color));
        
        // Check if it's a valid hex color
        if (!preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/', $color)) {
            $errors['color'] = 'Color must be a valid hex code (e.g., #336699)';
            return null;
        }
        
        return $color;
    }

    /**
     * Validate business rules
     */
    private function validateBusinessRules(array &$errors): void
    {
        // Name length validation
        if (strlen($this->name) > 100) {
            $errors['name'] = 'Category name cannot exceed 100 characters';
        }
        
        // Description length validation
        if ($this->description && strlen($this->description) > 500) {
            $errors['description'] = 'Description cannot exceed 500 characters';
        }
        
        // Icon length validation
        if (strlen($this->icon) > 50) {
            $errors['icon'] = 'Icon class cannot exceed 50 characters';
        }
        
        // Sort order validation
        if ($this->sortOrder < 0 || $this->sortOrder > 9999) {
            $errors['sort_order'] = 'Sort order must be between 0 and 9999';
        }
        
        // Meta title validation
        if ($this->metaTitle && strlen($this->metaTitle) > 70) {
            $errors['meta_title'] = 'Meta title cannot exceed 70 characters';
        }
        
        // Meta description validation
        if ($this->metaDescription && strlen($this->metaDescription) > 160) {
            $errors['meta_description'] = 'Meta description cannot exceed 160 characters';
        }
        
        // Validate parent ID is not same as this category (circular reference prevention)
        // Note: Actual circular reference check will be done in Service layer
        if ($this->parentId !== null && $this->parentId <= 0) {
            $errors['parent_id'] = 'Invalid parent category ID';
        }
        
        // Validate icon format (basic Font Awesome check)
        if (!preg_match('/^(fas|far|fal|fab|fad) fa-[a-z0-9-]+$/i', $this->icon)) {
            // Only warn, don't block - might be custom icon
            // This is just a basic validation
        }
    }

    /**
     * Get validation rules for use in controllers
     */
    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => 'permit_empty|string|max:100',
            'description' => 'permit_empty|string|max:500',
            'icon' => 'permit_empty|string|max:50',
            'sort_order' => 'permit_empty|integer|greater_than_equal_to[0]|less_than_equal_to[9999]',
            'active' => 'permit_empty|in_list[0,1,true,false]',
            'parent_id' => 'permit_empty|integer',
            'commission_rate' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]',
            'color' => 'permit_empty|string|max:7|regex_match[/^#([a-f0-9]{3}|[a-f0-9]{6})$/i]',
            'meta_title' => 'permit_empty|string|max:70',
            'meta_description' => 'permit_empty|string|max:160',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'name' => [
                'required' => 'Category name is required',
                'max' => 'Category name cannot exceed 100 characters',
            ],
            'commission_rate' => [
                'numeric' => 'Commission rate must be a valid number',
                'greater_than_equal_to' => 'Commission rate cannot be negative',
                'less_than_equal_to' => 'Commission rate cannot exceed 100%',
            ],
            'color' => [
                'regex_match' => 'Color must be a valid hex code (e.g., #336699 or #369)',
            ],
            'sort_order' => [
                'greater_than_equal_to' => 'Sort order cannot be negative',
                'less_than_equal_to' => 'Sort order cannot exceed 9999',
            ],
        ];
    }

    /**
     * Run validation and return errors (without throwing exception)
     */
    public function validate(): array
    {
        $errors = [];
        
        // Name validation
        if (empty($this->name)) {
            $errors['name'] = 'Category name is required';
        } elseif (strlen($this->name) > 100) {
            $errors['name'] = 'Category name cannot exceed 100 characters';
        }
        
        // Description validation
        if ($this->description && strlen($this->description) > 500) {
            $errors['description'] = 'Description cannot exceed 500 characters';
        }
        
        // Icon validation
        if (strlen($this->icon) > 50) {
            $errors['icon'] = 'Icon class cannot exceed 50 characters';
        }
        
        // Sort order validation
        if ($this->sortOrder < 0 || $this->sortOrder > 9999) {
            $errors['sort_order'] = 'Sort order must be between 0 and 9999';
        }
        
        // Parent ID validation
        if ($this->parentId !== null && $this->parentId <= 0) {
            $errors['parent_id'] = 'Invalid parent category ID';
        }
        
        // Commission rate validation
        if ($this->commissionRate !== null) {
            if ($this->commissionRate < 0 || $this->commissionRate > 100) {
                $errors['commission_rate'] = 'Commission rate must be between 0 and 100 percent';
            }
        }
        
        // Color validation
        if ($this->color !== null && !preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $this->color)) {
            $errors['color'] = 'Color must be a valid hex code';
        }
        
        // Meta title validation
        if ($this->metaTitle && strlen($this->metaTitle) > 70) {
            $errors['meta_title'] = 'Meta title cannot exceed 70 characters';
        }
        
        // Meta description validation
        if ($this->metaDescription && strlen($this->metaDescription) > 160) {
            $errors['meta_description'] = 'Meta description cannot exceed 160 characters';
        }
        
        return $errors;
    }

    /**
     * Check if DTO is valid
     */
    public function isValid(): bool
    {
        return empty($this->validate());
    }

    // Getters
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getIcon(): string { return $this->icon; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function isActive(): bool { return $this->active; }
    public function getParentId(): ?int { return $this->parentId; }
    public function getCommissionRate(): ?float { return $this->commissionRate; }
    public function getCreatedBy(): ?int { return $this->createdBy; }
    public function getColor(): ?string { return $this->color; }
    public function getMetaTitle(): ?string { return $this->metaTitle; }
    public function getMetaDescription(): ?string { return $this->metaDescription; }

    /**
     * Check if category has a parent
     */
    public function hasParent(): bool
    {
        return $this->parentId !== null && $this->parentId > 0;
    }

    /**
     * Check if category has commission rate
     */
    public function hasCommissionRate(): bool
    {
        return $this->commissionRate !== null;
    }

    /**
     * Get commission rate as percentage string
     */
    public function getCommissionRateDisplay(): string
    {
        if ($this->commissionRate === null) {
            return 'Not set';
        }
        
        return sprintf('%s%%', number_format($this->commissionRate, 2));
    }

    /**
     * Convert to database array
     */
    public function toDatabaseArray(): array
    {
        $data = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sortOrder,
            'active' => $this->active ? 1 : 0,
            'parent_id' => $this->parentId,
            'commission_rate' => $this->commissionRate,
            'color' => $this->color,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
        ];
        
        if ($this->createdBy !== null) {
            $data['created_by'] = $this->createdBy;
        }
        
        return $data;
    }

    /**
     * Convert to array (implements BaseDTO abstract method)
     * Includes all properties for complete serialization
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'sort_order' => $this->sortOrder,
            'active' => $this->active,
            'parent_id' => $this->parentId,
            'commission_rate' => $this->commissionRate,
            'color' => $this->color,
            'meta_title' => $this->metaTitle,
            'meta_description' => $this->metaDescription,
            'created_by' => $this->createdBy,
            'has_parent' => $this->hasParent(),
            'has_commission_rate' => $this->hasCommissionRate(),
            'commission_rate_display' => $this->getCommissionRateDisplay(),
        ];
        
        // Remove null values for cleaner output
        return array_filter($data, function ($value) {
            return $value !== null;
        });
    }
    
    /**
     * Get a summary for logging/audit
     */
    public function toSummary(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parentId,
            'active' => $this->active,
            'has_commission' => $this->hasCommissionRate(),
            'commission_rate' => $this->commissionRate,
        ];
    }

    /**
     * String representation of the DTO
     */
    public function __toString(): string
    {
        return sprintf(
            'CreateCategoryRequest[name="%s", slug="%s", active=%s]',
            $this->name,
            $this->slug,
            $this->active ? 'true' : 'false'
        );
    }

    /**
     * Normalize array keys (snake_case to camelCase)
     */
    private static function normalizeArrayKeys(array $data): array
    {
        $normalized = [];
        
        foreach ($data as $key => $value) {
            // Convert snake_case to camelCase for internal use
            if (strpos($key, '_') !== false) {
                $camelKey = lcfirst(str_replace('_', '', ucwords($key, '_')));
                $normalized[$camelKey] = $value;
                
                // Also keep original for backward compatibility
                if (!isset($normalized[$key])) {
                    $normalized[$key] = $value;
                }
            } else {
                $normalized[$key] = $value;
            }
        }
        
        return $normalized;
    }
}