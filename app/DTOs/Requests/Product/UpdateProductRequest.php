<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

/**
 * DTO for updating an existing product
 * 
 * @package DevDaily\DTOs\Requests\Product
 */
final class UpdateProductRequest extends BaseDTO
{
    private int $productId;
    private ?string $name = null;
    private ?string $slug = null;
    private ?string $description = null;
    private ?int $categoryId = null;
    private ?string $marketPrice = null;
    private ?string $image = null;
    private ?ImageSourceType $imageSourceType = null;
    private ?ProductStatus $status = null;
    private ?string $imagePath = null;
    private bool $regenerateSlug = false;
    private ?int $updatedBy = null;
    
    /** @var array<string, mixed> Tracks changed fields */
    private array $changedFields = [];

    /**
     * Private constructor - use factory method
     */
    private function __construct(int $productId)
    {
        $this->productId = $productId;
    }

    /**
     * Create DTO from request data
     */
    public static function fromRequest(int $productId, array $requestData, ?int $updatedBy = null): self
    {
        $dto = new self($productId);
        $dto->validateAndHydrate($requestData);
        $dto->updatedBy = $updatedBy;
        
        return $dto;
    }

    /**
     * Validate and hydrate the DTO
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // Track original data for comparison
        $originalData = $this->getOriginalDataSnapshot();
        
        // Hydrate only provided fields
        if (isset($data['name']) && $data['name'] !== '') {
            $this->name = $this->sanitizeString($data['name']);
            $this->regenerateSlug = true; // Auto-regenerate slug if name changes
        }
        
        if (isset($data['slug']) && $data['slug'] !== '') {
            $this->slug = SlugValidator::create()->normalize($data['slug']);
            $this->regenerateSlug = false; // Manual slug provided
        }
        
        if (array_key_exists('description', $data)) {
            $this->description = $data['description'] !== '' ? $this->sanitizeString($data['description']) : null;
        }
        
        if (array_key_exists('category_id', $data)) {
            $this->categoryId = $data['category_id'] !== '' ? (int)$data['category_id'] : null;
        }
        
        if (isset($data['market_price']) && $data['market_price'] !== '') {
            $this->marketPrice = $this->validateAndFormatPrice($data['market_price'], $errors);
        }
        
        if (array_key_exists('image', $data)) {
            $this->image = $data['image'] !== '' ? $this->sanitizeString($data['image']) : null;
        }
        
        if (isset($data['image_source_type']) && $data['image_source_type'] !== '') {
            $this->imageSourceType = $this->parseImageSourceType($data['image_source_type'], $errors);
        }
        
        if (isset($data['status']) && $data['status'] !== '') {
            $this->status = $this->parseProductStatus($data['status'], $errors);
        }
        
        if (array_key_exists('image_path', $data)) {
            $this->imagePath = $data['image_path'] !== '' ? $this->sanitizeString($data['image_path']) : null;
        }
        
        if (isset($data['regenerate_slug']) && $data['regenerate_slug'] === '1') {
            $this->regenerateSlug = true;
        }
        
        // Generate slug if needed
        if ($this->regenerateSlug && $this->name !== null) {
            $this->slug = SlugValidator::create()->generate(
                $this->name,
                ['entityType' => SlugValidator::ENTITY_PRODUCT, 'entityId' => $this->productId]
            );
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        // Track changed fields
        $this->identifyChangedFields($originalData);
        
        if (!empty($errors)) {
            throw new ValidationException('Product update validation failed', $errors);
        }
    }

    /**
     * Get snapshot of original data for comparison
     */
    private function getOriginalDataSnapshot(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'market_price' => $this->marketPrice,
            'image' => $this->image,
            'image_source_type' => $this->imageSourceType,
            'status' => $this->status,
            'image_path' => $this->imagePath,
        ];
    }

    /**
     * Identify which fields have changed
     */
    private function identifyChangedFields(array $originalData): void
    {
        $currentData = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'market_price' => $this->marketPrice,
            'image' => $this->image,
            'image_source_type' => $this->imageSourceType,
            'status' => $this->status,
            'image_path' => $this->imagePath,
        ];
        
        foreach ($currentData as $field => $value) {
            if ($value !== null && $value !== $originalData[$field]) {
                $this->changedFields[$field] = $value;
            }
        }
    }

    /**
     * Validate and format price
     */
    private function validateAndFormatPrice(string $price, array &$errors): string
    {
        $cleanPrice = preg_replace('/[^0-9.]/', '', $price);
        
        if (!is_numeric($cleanPrice) || (float)$cleanPrice < 0) {
            $errors['market_price'] = 'Market price must be a valid positive number';
            return '0.00';
        }
        
        return number_format((float)$cleanPrice, 2, '.', '');
    }

    /**
     * Parse image source type
     */
    private function parseImageSourceType(?string $type, array &$errors): ImageSourceType
    {
        try {
            return ImageSourceType::from($type);
        } catch (\ValueError $e) {
            $errors['image_source_type'] = 'Invalid image source type';
            return ImageSourceType::URL;
        }
    }

    /**
     * Parse product status
     */
    private function parseProductStatus(?string $status, array &$errors): ProductStatus
    {
        try {
            return ProductStatus::from($status);
        } catch (\ValueError $e) {
            $errors['status'] = 'Invalid product status';
            return ProductStatus::DRAFT;
        }
    }

    /**
     * Validate business rules
     */
    private function validateBusinessRules(array &$errors): void
    {
        // Name length validation
        if ($this->name !== null && strlen($this->name) > 255) {
            $errors['name'] = 'Product name cannot exceed 255 characters';
        }
        
        // Description length validation
        if ($this->description !== null && strlen($this->description) > 2000) {
            $errors['description'] = 'Description cannot exceed 2000 characters';
        }
        
        // Price range validation
        if ($this->marketPrice !== null) {
            $price = (float)$this->marketPrice;
            if ($price < 100) {
                $errors['market_price'] = 'Minimum price is 100 IDR';
            }
            
            if ($price > 1000000000) {
                $errors['market_price'] = 'Maximum price is 1,000,000,000 IDR';
            }
        }
        
        // Image URL validation if provided
        if ($this->image !== null && $this->imageSourceType === ImageSourceType::URL) {
            if (!filter_var($this->image, FILTER_VALIDATE_URL)) {
                $errors['image'] = 'Invalid image URL';
            }
            
            if (strlen($this->image) > 500) {
                $errors['image'] = 'Image URL cannot exceed 500 characters';
            }
        }
        
        // Slug validation if provided
        if ($this->slug !== null && strlen($this->slug) > 100) {
            $errors['slug'] = 'Slug cannot exceed 100 characters';
        }
    }

    /**
     * Get validation rules for partial updates
     */
    public static function rules(): array
    {
        return [
            'name' => 'permit_empty|string|max:255',
            'slug' => 'permit_empty|string|max:100',
            'description' => 'permit_empty|string|max:2000',
            'category_id' => 'permit_empty|integer',
            'market_price' => 'permit_empty|numeric|greater_than_equal_to[100]|less_than_equal_to[1000000000]',
            'image' => 'permit_empty|string|max:500',
            'image_source_type' => 'permit_empty|string|in_list[url,upload,external]',
            'status' => 'permit_empty|string|in_list[draft,published,archived,pending_verification]',
            'image_path' => 'permit_empty|string|max:500',
            'regenerate_slug' => 'permit_empty|in_list[0,1]',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'name.max' => 'Product name cannot exceed 255 characters',
            'market_price.numeric' => 'Market price must be a valid number',
            'market_price.greater_than_equal_to' => 'Minimum price is 100 IDR',
            'market_price.less_than_equal_to' => 'Maximum price is 1,000,000,000 IDR',
        ];
    }

    // Getters
    public function getProductId(): int { return $this->productId; }
    public function getName(): ?string { return $this->name; }
    public function getSlug(): ?string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getCategoryId(): ?int { return $this->categoryId; }
    public function getMarketPrice(): ?string { return $this->marketPrice; }
    public function getImage(): ?string { return $this->image; }
    public function getImageSourceType(): ?ImageSourceType { return $this->imageSourceType; }
    public function getStatus(): ?ProductStatus { return $this->status; }
    public function getImagePath(): ?string { return $this->imagePath; }
    public function shouldRegenerateSlug(): bool { return $this->regenerateSlug; }
    public function getUpdatedBy(): ?int { return $this->updatedBy; }
    
    /**
     * Get all changed fields
     */
    public function getChangedFields(): array
    {
        return $this->changedFields;
    }
    
    /**
     * Check if any field has changed
     */
    public function hasChanges(): bool
    {
        return !empty($this->changedFields);
    }
    
    /**
     * Check if specific field has changed
     */
    public function isFieldChanged(string $field): bool
    {
        return isset($this->changedFields[$field]);
    }
    
    /**
     * Get summary of changes for audit logging
     */
    public function getChangeSummary(): string
    {
        if (!$this->hasChanges()) {
            return 'No changes';
        }
        
        $changes = [];
        foreach ($this->changedFields as $field => $value) {
            $changes[] = sprintf('%s: %s', $field, $this->formatChangeValue($value));
        }
        
        return implode(', ', $changes);
    }
    
    /**
     * Format value for change summary
     */
    private function formatChangeValue($value): string
    {
        if ($value === null) {
            return '[null]';
        }
        
        if ($value instanceof ImageSourceType || $value instanceof ProductStatus) {
            return $value->value;
        }
        
        if (is_string($value) && strlen($value) > 50) {
            return substr($value, 0, 47) . '...';
        }
        
        return (string)$value;
    }

    /**
     * Convert changed fields to database array
     */
    public function toDatabaseArray(): array
    {
        $data = [];
        
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        
        if ($this->slug !== null) {
            $data['slug'] = $this->slug;
        }
        
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        
        if ($this->categoryId !== null) {
            $data['category_id'] = $this->categoryId;
        }
        
        if ($this->marketPrice !== null) {
            $data['market_price'] = $this->marketPrice;
        }
        
        if ($this->image !== null) {
            $data['image'] = $this->image;
        }
        
        if ($this->imageSourceType !== null) {
            $data['image_source_type'] = $this->imageSourceType->value;
        }
        
        if ($this->status !== null) {
            $data['status'] = $this->status->value;
        }
        
        if ($this->imagePath !== null) {
            $data['image_path'] = $this->imagePath;
        }
        
        return $data;
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        $data = [
            'product_id' => $this->productId,
            'has_changes' => $this->hasChanges(),
        ];
        
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        
        if ($this->slug !== null) {
            $data['slug'] = $this->slug;
        }
        
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        
        if ($this->categoryId !== null) {
            $data['category_id'] = $this->categoryId;
        }
        
        if ($this->marketPrice !== null) {
            $data['market_price'] = $this->marketPrice;
            $data['formatted_market_price'] = 'Rp ' . number_format((float)$this->marketPrice, 0, ',', '.');
        }
        
        if ($this->image !== null) {
            $data['image'] = $this->image;
        }
        
        if ($this->imageSourceType !== null) {
            $data['image_source_type'] = $this->imageSourceType->value;
        }
        
        if ($this->status !== null) {
            $data['status'] = $this->status->value;
            $data['status_label'] = $this->status->label();
        }
        
        if ($this->changedFields) {
            $data['changed_fields'] = $this->changedFields;
            $data['change_summary'] = $this->getChangeSummary();
        }
        
        return $data;
    }
}