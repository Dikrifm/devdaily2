<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

/**
 * DTO for creating a new product
 * 
 * @package DevDaily\DTOs\Requests\Product
 */
final class CreateProductRequest extends BaseDTO
{
    private string $name;
    private string $slug;
    private ?string $description = null;
    private ?int $categoryId = null;
    private string $marketPrice;
    private ?string $image = null;
    private ImageSourceType $imageSourceType;
    private ProductStatus $status = ProductStatus::DRAFT;
    private ?string $imagePath = null;
    private ?int $createdBy = null;

    /**
     * Constructor is private, use factory method
     */
    private function __construct() {}

    /**
     * Create DTO from request data
     */
    public static function fromRequest(array $requestData, ?int $createdBy = null): self
    {
        $dto = new self();
        $dto->validateAndHydrate($requestData);
        $dto->createdBy = $createdBy;
        
        return $dto;
    }

    /**
     * Validate and hydrate the DTO
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // Validate required fields
        $requiredFields = ['name', 'market_price'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[$field] = "Field {$field} is required";
            }
        }
        
        // Hydrate with validation
        if (empty($errors)) {
            $this->name = $this->sanitizeString($data['name'] ?? '');
            $this->slug = $this->generateSlug($data);
            $this->description = isset($data['description']) ? $this->sanitizeString($data['description']) : null;
            $this->categoryId = isset($data['category_id']) ? (int)$data['category_id'] : null;
            $this->marketPrice = $this->validateAndFormatPrice($data['market_price'] ?? '', $errors);
            $this->image = isset($data['image']) ? $this->sanitizeString($data['image']) : null;
            $this->imageSourceType = $this->parseImageSourceType($data['image_source_type'] ?? null, $errors);
            $this->status = $this->parseProductStatus($data['status'] ?? null, $errors);
            $this->imagePath = isset($data['image_path']) ? $this->sanitizeString($data['image_path']) : null;
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw new ValidationException('Product creation validation failed', $errors);
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
            ['entityType' => SlugValidator::ENTITY_PRODUCT]
        );
    }

    /**
     * Validate and format price
     */
    private function validateAndFormatPrice(string $price, array &$errors): string
    {
        // Remove currency symbols and thousand separators
        $cleanPrice = preg_replace('/[^0-9.]/', '', $price);
        
        if (!is_numeric($cleanPrice) || (float)$cleanPrice < 0) {
            $errors['market_price'] = 'Market price must be a valid positive number';
            return '0.00';
        }
        
        // Format to 2 decimal places
        return number_format((float)$cleanPrice, 2, '.', '');
    }

    /**
     * Parse image source type
     */
    private function parseImageSourceType(?string $type, array &$errors): ImageSourceType
    {
        if (empty($type)) {
            return ImageSourceType::URL;
        }
        
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
        if (empty($status)) {
            return ProductStatus::DRAFT;
        }
        
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
        if (strlen($this->name) > 255) {
            $errors['name'] = 'Product name cannot exceed 255 characters';
        }
        
        // Description length validation
        if ($this->description && strlen($this->description) > 2000) {
            $errors['description'] = 'Description cannot exceed 2000 characters';
        }
        
        // Price range validation (MVP: 100 to 1,000,000,000 IDR)
        $price = (float)$this->marketPrice;
        if ($price < 100) {
            $errors['market_price'] = 'Minimum price is 100 IDR';
        }
        
        if ($price > 1000000000) {
            $errors['market_price'] = 'Maximum price is 1,000,000,000 IDR';
        }
        
        // Image URL validation if provided
        if ($this->image && $this->imageSourceType === ImageSourceType::URL) {
            if (!filter_var($this->image, FILTER_VALIDATE_URL)) {
                $errors['image'] = 'Invalid image URL';
            }
            
            if (strlen($this->image) > 500) {
                $errors['image'] = 'Image URL cannot exceed 500 characters';
            }
        }
    }

    /**
     * Get validation rules for use in controllers
     */
    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'permit_empty|string|max:100',
            'description' => 'permit_empty|string|max:2000',
            'category_id' => 'permit_empty|integer',
            'market_price' => 'required|numeric|greater_than_equal_to[100]|less_than_equal_to[1000000000]',
            'image' => 'permit_empty|string|max:500',
            'image_source_type' => 'permit_empty|string|in_list[url,upload,external]',
            'status' => 'permit_empty|string|in_list[draft,published,archived,pending_verification]',
            'image_path' => 'permit_empty|string|max:500',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'name' => [
                'required' => 'Product name is required',
                'max' => 'Product name cannot exceed 255 characters',
            ],
            'market_price' => [
                'required' => 'Market price is required',
                'numeric' => 'Market price must be a valid number',
                'greater_than_equal_to' => 'Minimum price is 100 IDR',
                'less_than_equal_to' => 'Maximum price is 1,000,000,000 IDR',
            ],
        ];
    }

    // Getters
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getCategoryId(): ?int { return $this->categoryId; }
    public function getMarketPrice(): string { return $this->marketPrice; }
    public function getImage(): ?string { return $this->image; }
    public function getImageSourceType(): ImageSourceType { return $this->imageSourceType; }
    public function getStatus(): ProductStatus { return $this->status; }
    public function getImagePath(): ?string { return $this->imagePath; }
    public function getCreatedBy(): ?int { return $this->createdBy; }

    /**
     * Convert to database array
     */
    public function toDatabaseArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'market_price' => $this->marketPrice,
            'image' => $this->image,
            'image_source_type' => $this->imageSourceType->value,
            'status' => $this->status->value,
            'image_path' => $this->imagePath,
        ];
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'category_id' => $this->categoryId,
            'market_price' => $this->marketPrice,
            'formatted_market_price' => 'Rp ' . number_format((float)$this->marketPrice, 0, ',', '.'),
            'image' => $this->image,
            'image_source_type' => $this->imageSourceType->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
        ];
    }
}