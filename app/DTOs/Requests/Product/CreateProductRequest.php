<?php

namespace App\DTOs\Requests\Product;

use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;

/**
 * Create Product Request DTO
 * 
 * Data Transfer Object for product creation requests.
 * Contains validation rules and data transformation logic.
 * 
 * @package App\DTOs\Requests\Product
 */
class CreateProductRequest
{
    /**
     * Product name
     * 
     * @var string
     */
    public string $name;

    /**
     * URL-friendly slug
     * 
     * @var string
     */
    public string $slug;

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
     * @var string
     */
    public string $marketPrice = '0.00';

    /**
     * Image URL (for URL source type)
     * 
     * @var string|null
     */
    public ?string $image = null;

    /**
     * Image source type
     * 
     * @var ImageSourceType
     */
    public ImageSourceType $imageSourceType;

    /**
     * Initial product status
     * 
     * @var ProductStatus
     */
    public ProductStatus $status = ProductStatus::DRAFT;

    /**
     * CreateProductRequest constructor
     * 
     * @param array $data Request data
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $this->castValue($key, $value);
            }
        }
        
        // Set defaults
        if (!isset($this->imageSourceType)) {
            $this->imageSourceType = ImageSourceType::URL;
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
            case 'imageSourceType':
                return ImageSourceType::from($value);
                
            case 'status':
                return ProductStatus::from($value);
                
            case 'categoryId':
                return $value !== null ? (int) $value : null;
                
            case 'marketPrice':
                // Ensure decimal format with 2 places
                return number_format((float) $value, 2, '.', '');
                
            case 'name':
            case 'slug':
            case 'description':
            case 'image':
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
            'name' => 'required|min_length[3]|max_length[255]',
            'slug' => 'required|alpha_dash|max_length[255]',
            'description' => 'permit_empty|string|max_length[5000]',
            'categoryId' => 'permit_empty|integer|greater_than[0]',
            'marketPrice' => 'required|decimal|greater_than_equal_to[0]',
            'image' => 'permit_empty|string|max_length[2000]',
            'imageSourceType' => 'required|in_list[' . implode(',', ImageSourceType::all()) . ']',
            'status' => 'permit_empty|in_list[' . implode(',', ProductStatus::all()) . ']',
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
            'name.required' => 'Product name is required',
            'name.min_length' => 'Product name must be at least 3 characters',
            'name.max_length' => 'Product name cannot exceed 255 characters',
            'slug.required' => 'Product slug is required',
            'slug.alpha_dash' => 'Slug can only contain letters, numbers, dashes, and underscores',
            'slug.max_length' => 'Slug cannot exceed 255 characters',
            'marketPrice.required' => 'Market price is required',
            'marketPrice.decimal' => 'Market price must be a valid decimal number',
            'marketPrice.greater_than_equal_to' => 'Market price cannot be negative',
            'imageSourceType.required' => 'Image source type is required',
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
        $this->name = trim($this->name);
        $this->slug = strtolower(trim($this->slug));
        
        if ($this->description !== null) {
            $this->description = trim($this->description);
        }
        
        if ($this->image !== null) {
            $this->image = trim($this->image);
        }
        
        return $this;
    }

    /**
     * Convert to array for database insertion
     * 
     * @return array
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'market_price' => $this->marketPrice,
            'image' => $this->image,
            'image_source_type' => $this->imageSourceType->value,
            'status' => $this->status->value,
        ];

        // Remove null values
        return array_filter($data, fn($value) => $value !== null);
    }

    /**
     * Create from HTTP request
     * 
     * @param array $requestData
     * @return static
     */
    public static function fromRequest(array $requestData): self
    {
        $data = [
            'name' => $requestData['name'] ?? null,
            'slug' => $requestData['slug'] ?? null,
            'description' => $requestData['description'] ?? null,
            'categoryId' => $requestData['category_id'] ?? $requestData['categoryId'] ?? null,
            'marketPrice' => $requestData['market_price'] ?? $requestData['marketPrice'] ?? '0.00',
            'image' => $requestData['image'] ?? null,
            'imageSourceType' => $requestData['image_source_type'] ?? $requestData['imageSourceType'] ?? 'url',
            'status' => $requestData['status'] ?? 'draft',
        ];

        return new self(array_filter($data, fn($value) => $value !== null));
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
        
        // Convert enums to string values for validation
        $data['image_source_type'] = $this->imageSourceType->value;
        $data['status'] = $this->status->value;
        
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
        
        // Market price must be positive
        if ((float) $this->marketPrice < 0) {
            $errors[] = 'Market price cannot be negative';
        }
        
        // If image is provided for URL source type, validate URL
        if ($this->imageSourceType === ImageSourceType::URL && !empty($this->image)) {
            if (!filter_var($this->image, FILTER_VALIDATE_URL)) {
                $errors[] = 'Image must be a valid URL when using external source type';
            }
        }
        
        // Status must be DRAFT for creation (business rule)
        if ($this->status !== ProductStatus::DRAFT) {
            $errors[] = 'New products can only be created in DRAFT status';
        }
        
        return $errors;
    }

    /**
     * Get formatted market price
     * 
     * @return string
     */
    public function getFormattedMarketPrice(): string
    {
        return number_format((float) $this->marketPrice, 0, ',', '.');
    }

    /**
     * Check if request has image
     * 
     * @return bool
     */
    public function hasImage(): bool
    {
        return !empty($this->image);
    }

    /**
     * Check if request has category
     * 
     * @return bool
     */
    public function hasCategory(): bool
    {
        return $this->categoryId !== null && $this->categoryId > 0;
    }

    /**
     * Get the image source type label
     * 
     * @return string
     */
    public function getImageSourceTypeLabel(): string
    {
        return $this->imageSourceType->label();
    }

    /**
     * Get the status label
     * 
     * @return string
     */
    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    /**
     * Create a summary of the request for logging
     * 
     * @return array
     */
    public function toSummary(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'has_description' => !empty($this->description),
            'has_category' => $this->hasCategory(),
            'market_price' => $this->getFormattedMarketPrice(),
            'has_image' => $this->hasImage(),
            'image_source_type' => $this->getImageSourceTypeLabel(),
            'status' => $this->getStatusLabel(),
        ];
    }
}