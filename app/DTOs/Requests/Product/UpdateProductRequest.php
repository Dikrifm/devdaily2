<?php

namespace App\DTOs\Requests\Product;

use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;

/**
 * Update Product Request DTO
 * 
 * Data Transfer Object for product update requests.
 * Supports partial updates with change detection.
 * 
 * @package App\DTOs\Requests\Product
 */
class UpdateProductRequest
{
    /**
     * Product ID to update
     * 
     * @var int
     */
    public int $productId;

    /**
     * Product name
     * 
     * @var string|null
     */
    public ?string $name = null;

    /**
     * URL-friendly slug
     * 
     * @var string|null
     */
    public ?string $slug = null;

    /**
     * Product description
     * 
     * @var string|null
     */
    public ?string $description = null;

    /**
     * Category ID
     * 
     * @var int|null
     */
    public ?int $categoryId = null;

    /**
     * Market reference price
     * 
     * @var string|null
     */
    public ?string $marketPrice = null;

    /**
     * Image URL (for URL source type)
     * 
     * @var string|null
     */
    public ?string $image = null;

    /**
     * Image source type
     * 
     * @var ImageSourceType|null
     */
    public ?ImageSourceType $imageSourceType = null;

    /**
     * Product status
     * 
     * @var ProductStatus|null
     */
    public ?ProductStatus $status = null;

    /**
     * Image path for uploaded images
     * 
     * @var string|null
     */
    public ?string $imagePath = null;

    /**
     * UpdateProductRequest constructor
     * 
     * @param int $productId Product ID to update
     * @param array $data Request data
     */
    public function __construct(int $productId, array $data = [])
    {
        $this->productId = $productId;
        
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $this->castValue($key, $value);
            }
        }
    }

    /**
     * Cast value to appropriate type
     * 
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    private function castValue(string $key, $value)
    {
        if ($value === null) {
            return null;
        }

        switch ($key) {
            case 'productId':
                return (int) $value;
                
            case 'imageSourceType':
                return ImageSourceType::from($value);
                
            case 'status':
                return ProductStatus::from($value);
                
            case 'categoryId':
                return (int) $value;
                
            case 'marketPrice':
                // Ensure decimal format with 2 places
                return number_format((float) $value, 2, '.', '');
                
            case 'name':
            case 'slug':
            case 'description':
            case 'image':
            case 'imagePath':
                return (string) $value;
                
            default:
                return $value;
        }
    }

    /**
     * Get validation rules for this request
     * 
     * @return array
     */
    public static function rules(): array
    {
        return [
            'productId' => 'required|integer|greater_than[0]',
            'name' => 'permit_empty|min_length[3]|max_length[255]',
            'slug' => 'permit_empty|alpha_dash|max_length[255]',
            'description' => 'permit_empty|string|max_length[5000]',
            'categoryId' => 'permit_empty|integer|greater_than[0]',
            'marketPrice' => 'permit_empty|decimal|greater_than_equal_to[0]',
            'image' => 'permit_empty|string|max_length[2000]',
            'imageSourceType' => 'permit_empty|in_list[' . implode(',', ImageSourceType::all()) . ']',
            'status' => 'permit_empty|in_list[' . implode(',', ProductStatus::all()) . ']',
            'imagePath' => 'permit_empty|string|max_length[500]',
        ];
    }

    /**
     * Get validation messages
     * 
     * @return array
     */
    public static function messages(): array
    {
        return [
            'productId.required' => 'Product ID is required',
            'productId.integer' => 'Product ID must be an integer',
            'productId.greater_than' => 'Product ID must be positive',
            'name.min_length' => 'Product name must be at least 3 characters',
            'name.max_length' => 'Product name cannot exceed 255 characters',
            'slug.alpha_dash' => 'Slug can only contain letters, numbers, dashes, and underscores',
            'slug.max_length' => 'Slug cannot exceed 255 characters',
            'marketPrice.decimal' => 'Market price must be a valid decimal number',
            'marketPrice.greater_than_equal_to' => 'Market price cannot be negative',
            'imageSourceType.in_list' => 'Invalid image source type',
            'status.in_list' => 'Invalid product status',
        ];
    }

    /**
     * Sanitize the request data
     * 
     * @return self
     */
    public function sanitize(): self
    {
        if ($this->name !== null) {
            $this->name = trim($this->name);
        }
        
        if ($this->slug !== null) {
            $this->slug = strtolower(trim($this->slug));
        }
        
        if ($this->description !== null) {
            $this->description = trim($this->description);
        }
        
        if ($this->image !== null) {
            $this->image = trim($this->image);
        }
        
        if ($this->imagePath !== null) {
            $this->imagePath = trim($this->imagePath);
        }
        
        return $this;
    }

    /**
     * Convert to array for database update
     * Only includes fields that are actually being updated
     * 
     * @return array
     */
    public function toArray(): array
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
     * Create from HTTP request
     * 
     * @param int $productId
     * @param array $requestData
     * @return static
     */
    public static function fromRequest(int $productId, array $requestData): self
    {
        $data = [
            'name' => $requestData['name'] ?? null,
            'slug' => $requestData['slug'] ?? null,
            'description' => $requestData['description'] ?? null,
            'categoryId' => $requestData['category_id'] ?? $requestData['categoryId'] ?? null,
            'marketPrice' => $requestData['market_price'] ?? $requestData['marketPrice'] ?? null,
            'image' => $requestData['image'] ?? null,
            'imageSourceType' => $requestData['image_source_type'] ?? $requestData['imageSourceType'] ?? null,
            'status' => $requestData['status'] ?? null,
            'imagePath' => $requestData['image_path'] ?? $requestData['imagePath'] ?? null,
        ];

        return new self($productId, array_filter($data, fn($value) => $value !== null));
    }

    /**
     * Validate the request data
     * 
     * @return array [valid: bool, errors: array]
     */
    public function validate(): array
    {
        $validation = \Config\Services::validation();
        $validation->setRules(self::rules(), self::messages());
        
        $data = $this->toArray();
        $data['productId'] = $this->productId;
        
        // Convert enums to string values for validation
        if ($this->imageSourceType !== null) {
            $data['image_source_type'] = $this->imageSourceType->value;
        }
        
        if ($this->status !== null) {
            $data['status'] = $this->status->value;
        }
        
        $isValid = $validation->run($data);
        $errors = $isValid ? [] : $validation->getErrors();
        
        // Additional business validations
        $businessErrors = $this->validateBusinessRules();
        $errors = array_merge($errors, $businessErrors);
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate business rules
     * 
     * @return array
     */
    private function validateBusinessRules(): array
    {
        $errors = [];
        
        // Market price must be positive if provided
        if ($this->marketPrice !== null && (float) $this->marketPrice < 0) {
            $errors[] = 'Market price cannot be negative';
        }
        
        // If image is provided for URL source type, validate URL
        if ($this->imageSourceType === ImageSourceType::URL && !empty($this->image)) {
            if (!filter_var($this->image, FILTER_VALIDATE_URL)) {
                $errors[] = 'Image must be a valid URL when using external source type';
            }
        }
        
        // If both image and imagePath are provided, they must be compatible
        if ($this->image !== null && $this->imagePath !== null && $this->imageSourceType === ImageSourceType::UPLOAD) {
            // For uploads, image should be null and imagePath should contain the path
            $errors[] = 'For uploaded images, provide only imagePath, not image URL';
        }
        
        return $errors;
    }

    /**
     * Check if this request has any changes
     * 
     * @return bool
     */
    public function hasChanges(): bool
    {
        return !empty($this->toArray());
    }

    /**
     * Get list of fields being updated
     * 
     * @return array
     */
    public function getChangedFields(): array
    {
        $fields = [];
        
        if ($this->name !== null) $fields[] = 'name';
        if ($this->slug !== null) $fields[] = 'slug';
        if ($this->description !== null) $fields[] = 'description';
        if ($this->categoryId !== null) $fields[] = 'category_id';
        if ($this->marketPrice !== null) $fields[] = 'market_price';
        if ($this->image !== null) $fields[] = 'image';
        if ($this->imageSourceType !== null) $fields[] = 'image_source_type';
        if ($this->status !== null) $fields[] = 'status';
        if ($this->imagePath !== null) $fields[] = 'image_path';
        
        return $fields;
    }

    /**
     * Check if specific field is being updated
     * 
     * @param string $field
     * @return bool
     */
    public function isFieldChanged(string $field): bool
    {
        $fieldMap = [
            'name' => $this->name !== null,
            'slug' => $this->slug !== null,
            'description' => $this->description !== null,
            'category_id' => $this->categoryId !== null,
            'market_price' => $this->marketPrice !== null,
            'image' => $this->image !== null,
            'image_source_type' => $this->imageSourceType !== null,
            'status' => $this->status !== null,
            'image_path' => $this->imagePath !== null,
        ];
        
        return $fieldMap[$field] ?? false;
    }

    /**
     * Get formatted market price if provided
     * 
     * @return string|null
     */
    public function getFormattedMarketPrice(): ?string
    {
        if ($this->marketPrice === null) {
            return null;
        }
        
        return number_format((float) $this->marketPrice, 0, ',', '.');
    }

    /**
     * Check if request has image data
     * 
     * @return bool
     */
    public function hasImageData(): bool
    {
        return $this->image !== null || $this->imagePath !== null || $this->imageSourceType !== null;
    }

    /**
     * Get the image source type label if provided
     * 
     * @return string|null
     */
    public function getImageSourceTypeLabel(): ?string
    {
        return $this->imageSourceType?->label();
    }

    /**
     * Get the status label if provided
     * 
     * @return string|null
     */
    public function getStatusLabel(): ?string
    {
        return $this->status?->label();
    }

    /**
     * Create a summary of changes for logging
     * 
     * @return array
     */
    public function toChangeSummary(): array
    {
        $summary = [
            'product_id' => $this->productId,
            'changed_fields' => $this->getChangedFields(),
            'field_count' => count($this->getChangedFields()),
        ];
        
        if ($this->name !== null) {
            $summary['new_name'] = $this->name;
        }
        
        if ($this->slug !== null) {
            $summary['new_slug'] = $this->slug;
        }
        
        if ($this->marketPrice !== null) {
            $summary['new_market_price'] = $this->getFormattedMarketPrice();
        }
        
        if ($this->status !== null) {
            $summary['new_status'] = $this->getStatusLabel();
        }
        
        if ($this->imageSourceType !== null) {
            $summary['new_image_source_type'] = $this->getImageSourceTypeLabel();
        }
        
        return $summary;
    }

    /**
     * Merge with another update request
     * Useful for combining partial updates
     * 
     * @param UpdateProductRequest $other
     * @return self
     */
    public function merge(self $other): self
    {
        if ($this->productId !== $other->productId) {
            throw new \InvalidArgumentException('Cannot merge requests for different products');
        }
        
        $mergedData = $this->toArray();
        $otherData = $other->toArray();
        
        // Merge, with other request taking precedence
        $mergedData = array_merge($mergedData, $otherData);
        
        // Recreate request with merged data
        return new self($this->productId, $mergedData);
    }
}