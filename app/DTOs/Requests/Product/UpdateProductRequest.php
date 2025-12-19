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
     */
    public int $productId;

    /**
     * Product name
     */
    public ?string $name = null;

    /**
     * URL-friendly slug
     */
    public ?string $slug = null;

    /**
     * Product description
     */
    public ?string $description = null;

    /**
     * Category ID
     */
    public ?int $categoryId = null;

    /**
     * Market reference price
     */
    public ?string $marketPrice = null;

    /**
     * Image URL (for URL source type)
     */
    public ?string $image = null;

    /**
     * Image source type
     */
    public ?ImageSourceType $imageSourceType = null;

    /**
     * Product status
     */
    public ?ProductStatus $status = null;

    /**
     * Image path for uploaded images
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
            case 'categoryId':
                return (int) $value;

            case 'imageSourceType':
                return ImageSourceType::from($value);

            case 'status':
                return ProductStatus::from($value);

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

        if ($this->imageSourceType instanceof \App\Enums\ImageSourceType) {
            $data['image_source_type'] = $this->imageSourceType->value;
        }

        if ($this->status instanceof \App\Enums\ProductStatus) {
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

        return new self($productId, array_filter($data, fn ($value) => $value !== null));
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
        if ($this->imageSourceType instanceof \App\Enums\ImageSourceType) {
            $data['image_source_type'] = $this->imageSourceType->value;
        }

        if ($this->status instanceof \App\Enums\ProductStatus) {
            $data['status'] = $this->status->value;
        }

        $isValid = $validation->run($data);
        $errors = $isValid ? [] : $validation->getErrors();

        // Additional business validations
        $businessErrors = $this->validateBusinessRules();
        $errors = array_merge($errors, $businessErrors);

        return [
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    /**
     * Validate business rules
     */
    private function validateBusinessRules(): array
    {
        $errors = [];

        // Market price must be positive if provided
        if ($this->marketPrice !== null && (float) $this->marketPrice < 0) {
            $errors[] = 'Market price cannot be negative';
        }

        // If image is provided for URL source type, validate URL
        if ($this->imageSourceType === ImageSourceType::URL && !in_array($this->image, [null, '', '0'], true) && !filter_var($this->image, FILTER_VALIDATE_URL)) {
            $errors[] = 'Image must be a valid URL when using external source type';
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
     */
    public function hasChanges(): bool
    {
        return $this->toArray() !== [];
    }

    /**
     * Get list of fields being updated
     */
    public function getChangedFields(): array
    {
        $fields = [];

        if ($this->name !== null) {
            $fields[] = 'name';
        }
        if ($this->slug !== null) {
            $fields[] = 'slug';
        }
        if ($this->description !== null) {
            $fields[] = 'description';
        }
        if ($this->categoryId !== null) {
            $fields[] = 'category_id';
        }
        if ($this->marketPrice !== null) {
            $fields[] = 'market_price';
        }
        if ($this->image !== null) {
            $fields[] = 'image';
        }
        if ($this->imageSourceType instanceof \App\Enums\ImageSourceType) {
            $fields[] = 'image_source_type';
        }
        if ($this->status instanceof \App\Enums\ProductStatus) {
            $fields[] = 'status';
        }
        if ($this->imagePath !== null) {
            $fields[] = 'image_path';
        }

        return $fields;
    }

    /**
     * Check if specific field is being updated
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
            'image_source_type' => $this->imageSourceType instanceof \App\Enums\ImageSourceType,
            'status' => $this->status instanceof \App\Enums\ProductStatus,
            'image_path' => $this->imagePath !== null,
        ];

        return $fieldMap[$field] ?? false;
    }

    /**
     * Get formatted market price if provided
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
     */
    public function hasImageData(): bool
    {
        return $this->image !== null || $this->imagePath !== null || $this->imageSourceType instanceof \App\Enums\ImageSourceType;
    }

    /**
     * Get the image source type label if provided
     */
    public function getImageSourceTypeLabel(): ?string
    {
        return $this->imageSourceType?->label();
    }

    /**
     * Get the status label if provided
     */
    public function getStatusLabel(): ?string
    {
        return $this->status?->label();
    }

    /**
     * Create a summary of changes for logging
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

        if ($this->status instanceof \App\Enums\ProductStatus) {
            $summary['new_status'] = $this->getStatusLabel();
        }

        if ($this->imageSourceType instanceof \App\Enums\ImageSourceType) {
            $summary['new_image_source_type'] = $this->getImageSourceTypeLabel();
        }

        return $summary;
    }

    /**
     * Merge with another update request
     * Useful for combining partial updates
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
