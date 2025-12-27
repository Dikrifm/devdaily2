<?php

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Enums\ProductStatus;
use App\Exceptions\ValidationException;

/**
 * Data Transfer Object for Product Quick Edit Request
 * 
 * Validates and encapsulates data for quick editing product via HTMX
 * Minimal validation - only format and required fields
 * Business rules checked in Service layer
 * 
 * @package DevDaily
 * @subpackage ProductDTOs
 */
class ProductQuickEditRequest extends BaseDTO
{
    /**
     * @var int Product ID (required)
     */
    private int $productId;
    
    /**
     * @var int User ID performing the action (required)
     */
    private int $userId;
    
    /**
     * @var string|null Product name (optional, but validated when provided)
     */
    private ?string $name = null;
    
    /**
     * @var string|null Product slug (auto-generated if not provided)
     */
    private ?string $slug = null;
    
    /**
     * @var string|null Product description (optional)
     */
    private ?string $description = null;
    
    /**
     * @var float|null Product price in IDR (optional)
     */
    private ?float $price = null;
    
    /**
     * @var ProductStatus|null Product status (optional)
     */
    private ?ProductStatus $status = null;
    
    /**
     * @var int|null Category ID (optional)
     */
    private ?int $categoryId = null;
    
    /**
     * @var \DateTimeImmutable Timestamp of request
     */
    private \DateTimeImmutable $requestedAt;
    
    /**
     * @var string IP address of requester
     */
    private string $ipAddress;
    
    /**
     * @var bool Whether to regenerate slug if name changes
     */
    private bool $regenerateSlug = true;
    
    /**
     * Private constructor - use fromArray() static method
     */
    private function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    
    /**
     * Create DTO from array input
     * 
     * @param array $data Input data
     * @return self
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->validateAndHydrate($data);
        return $instance;
    }
    
    /**
     * Validate input data and hydrate DTO properties
     * 
     * @param array $data
     * @throws ValidationException
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // 1. Validate product_id - required, positive integer
        if (!isset($data['product_id'])) {
            $errors['product_id'] = 'Product ID is required';
        } elseif (!is_numeric($data['product_id']) || (int)$data['product_id'] <= 0) {
            $errors['product_id'] = 'Product ID must be a positive integer';
        } else {
            $this->productId = (int)$data['product_id'];
        }
        
        // 2. Validate user_id - required, positive integer
        if (!isset($data['user_id'])) {
            $errors['user_id'] = 'User ID is required';
        } elseif (!is_numeric($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors['user_id'] = 'User ID must be a positive integer';
        } else {
            $this->userId = (int)$data['user_id'];
        }
        
        // 3. Validate name (if provided)
        if (isset($data['name'])) {
            if (!is_string($data['name'])) {
                $errors['name'] = 'Product name must be a string';
            } elseif (trim($data['name']) === '') {
                $errors['name'] = 'Product name cannot be empty';
            } elseif (mb_strlen($data['name']) > 255) {
                $errors['name'] = 'Product name cannot exceed 255 characters';
            } else {
                $this->name = trim($data['name']);
            }
        }
        
        // 4. Validate slug (if provided)
        if (isset($data['slug'])) {
            if (!is_string($data['slug'])) {
                $errors['slug'] = 'Product slug must be a string';
            } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $data['slug'])) {
                $errors['slug'] = 'Slug can only contain lowercase letters, numbers, and hyphens';
            } elseif (mb_strlen($data['slug']) > 255) {
                $errors['slug'] = 'Product slug cannot exceed 255 characters';
            } else {
                $this->slug = $data['slug'];
                $this->regenerateSlug = false; // User provided custom slug
            }
        }
        
        // 5. Validate description (if provided)
        if (isset($data['description'])) {
            if (!is_string($data['description'])) {
                $errors['description'] = 'Product description must be a string';
            } elseif (mb_strlen($data['description']) > 2000) {
                $errors['description'] = 'Product description cannot exceed 2000 characters';
            } else {
                $this->description = $data['description'];
            }
        }
        
        // 6. Validate price (if provided)
        if (isset($data['price'])) {
            if (!is_numeric($data['price'])) {
                $errors['price'] = 'Product price must be a number';
            } elseif ((float)$data['price'] < 0) {
                $errors['price'] = 'Product price cannot be negative';
            } elseif ((float)$data['price'] > 1000000000) { // 1 Miliar IDR
                $errors['price'] = 'Product price cannot exceed 1,000,000,000 IDR';
            } else {
                $this->price = round((float)$data['price'], 2);
            }
        }
        
        // 7. Validate status (if provided)
        if (isset($data['status'])) {
            try {
                $this->status = is_string($data['status']) 
                    ? ProductStatus::from($data['status'])
                    : ProductStatus::from((string)$data['status']);
            } catch (\ValueError $e) {
                $errors['status'] = 'Invalid status. Must be one of: ' . 
                    implode(', ', array_column(ProductStatus::cases(), 'value'));
            }
        }
        
        // 8. Validate category_id (if provided)
        if (isset($data['category_id'])) {
            if (!is_numeric($data['category_id']) || (int)$data['category_id'] < 0) {
                $errors['category_id'] = 'Category ID must be a non-negative integer';
            } else {
                $this->categoryId = (int)$data['category_id'];
            }
        }
        
        // 9. Set IP address
        $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // 10. Validate at least one field is being updated (besides IDs)
        $updatableFields = ['name', 'slug', 'description', 'price', 'status', 'category_id'];
        $hasUpdates = false;
        
        foreach ($updatableFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $hasUpdates = true;
                break;
            }
        }
        
        if (!$hasUpdates) {
            $errors['general'] = 'At least one field must be updated';
        }
        
        // 11. Throw ValidationException if any errors
        if (!empty($errors)) {
            throw new ValidationException('Product quick edit validation failed', $errors);
        }
    }
    
    /**
     * Get product ID
     * 
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }
    
    /**
     * Get user ID
     * 
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }
    
    /**
     * Get product name
     * 
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->name;
    }
    
    /**
     * Get product slug
     * 
     * @return string|null
     */
    public function getSlug(): ?string
    {
        return $this->slug;
    }
    
    /**
     * Get product description
     * 
     * @return string|null
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }
    
    /**
     * Get product price
     * 
     * @return float|null
     */
    public function getPrice(): ?float
    {
        return $this->price;
    }
    
    /**
     * Get product status
     * 
     * @return ProductStatus|null
     */
    public function getStatus(): ?ProductStatus
    {
        return $this->status;
    }
    
    /**
     * Get category ID
     * 
     * @return int|null
     */
    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }
    
    /**
     * Get requested at timestamp
     * 
     * @return \DateTimeImmutable
     */
    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }
    
    /**
     * Get IP address
     * 
     * @return string
     */
    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }
    
    /**
     * Should regenerate slug?
     * 
     * @return bool
     */
    public function shouldRegenerateSlug(): bool
    {
        return $this->regenerateSlug;
    }
    
    /**
     * Get changed fields as array
     * Useful for partial updates
     * 
     * @return array
     */
    public function getChangedFields(): array
    {
        $changed = [];
        
        if ($this->name !== null) {
            $changed['name'] = $this->name;
        }
        
        if ($this->slug !== null) {
            $changed['slug'] = $this->slug;
        }
        
        if ($this->description !== null) {
            $changed['description'] = $this->description;
        }
        
        if ($this->price !== null) {
            $changed['price'] = $this->price;
        }
        
        if ($this->status !== null) {
            $changed['status'] = $this->status;
        }
        
        if ($this->categoryId !== null) {
            $changed['category_id'] = $this->categoryId;
        }
        
        return $changed;
    }
    
    /**
     * Check if specific field is being updated
     * 
     * @param string $fieldName
     * @return bool
     */
    public function hasField(string $fieldName): bool
    {
        $changedFields = $this->getChangedFields();
        return array_key_exists($fieldName, $changedFields);
    }
    
    /**
     * Convert DTO to array for logging/API response
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'user_id' => $this->userId,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => $this->price,
            'status' => $this->status?->value,
            'category_id' => $this->categoryId,
            'requested_at' => $this->requestedAt->format('c'),
            'ip_address' => $this->ipAddress,
            'regenerate_slug' => $this->regenerateSlug,
            'changed_fields' => $this->getChangedFields()
        ];
    }
    
    /**
     * Convert DTO to JSON string
     * 
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
}