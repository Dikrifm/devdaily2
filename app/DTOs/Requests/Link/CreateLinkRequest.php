<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Link;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;

/**
 * DTO for creating a new affiliate link
 * 
 * @package DevDaily\DTOs\Requests\Link
 */
final class CreateLinkRequest extends BaseDTO
{
    private int $productId;
    private int $marketplaceId;
    private string $storeName;
    private string $price;
    private string $url;
    private ?string $rating = null;
    private bool $active = true;
    private ?int $marketplaceBadgeId = null;
    private ?float $commissionRate = null;
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
        $requiredFields = ['product_id', 'marketplace_id', 'store_name', 'price', 'url'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $errors[$field] = "Field {$field} is required";
            }
        }
        
        // Hydrate with validation
        if (empty($errors)) {
            $this->productId = (int)$data['product_id'];
            $this->marketplaceId = (int)$data['marketplace_id'];
            $this->storeName = $this->sanitizeString($data['store_name']);
            $this->price = $this->validateAndFormatPrice($data['price'], $errors);
            $this->url = $this->validateAndFormatUrl($data['url'], $errors);
            $this->rating = isset($data['rating']) ? $this->validateRating($data['rating'], $errors) : null;
            $this->active = isset($data['active']) ? filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) : true;
            $this->marketplaceBadgeId = isset($data['marketplace_badge_id']) ? (int)$data['marketplace_badge_id'] : null;
            $this->commissionRate = isset($data['commission_rate']) ? $this->validateCommissionRate($data['commission_rate'], $errors) : null;
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw new ValidationException('Link creation validation failed', $errors);
        }
    }

    /**
     * Validate and format price
     */
    private function validateAndFormatPrice(string $price, array &$errors): string
    {
        // Remove currency symbols and thousand separators
        $cleanPrice = preg_replace('/[^0-9.]/', '', $price);
        
        if (!is_numeric($cleanPrice) || (float)$cleanPrice < 0) {
            $errors['price'] = 'Price must be a valid positive number';
            return '0.00';
        }
        
        // Format to 2 decimal places
        return number_format((float)$cleanPrice, 2, '.', '');
    }

    /**
     * Validate and format URL
     */
    private function validateAndFormatUrl(string $url, array &$errors): string
    {
        $url = trim($url);
        
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors['url'] = 'Invalid URL format';
            return $url;
        }
        
        // Ensure URL has proper scheme
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        
        // Check URL length
        if (strlen($url) > 500) {
            $errors['url'] = 'URL cannot exceed 500 characters';
        }
        
        return $url;
    }

    /**
     * Validate rating (0-5 with 1 decimal place)
     */
    private function validateRating(mixed $rating, array &$errors): ?string
    {
        if ($rating === '' || $rating === null) {
            return null;
        }
        
        $numericRating = (float)$rating;
        
        if ($numericRating < 0 || $numericRating > 5) {
            $errors['rating'] = 'Rating must be between 0 and 5';
            return null;
        }
        
        // Format to 1 decimal place
        return number_format($numericRating, 1, '.', '');
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
     * Validate business rules
     */
    private function validateBusinessRules(array &$errors): void
    {
        // Store name length validation
        if (strlen($this->storeName) > 100) {
            $errors['store_name'] = 'Store name cannot exceed 100 characters';
        }
        
        // Price validation (MVP: 100 to 1,000,000,000 IDR)
        $price = (float)$this->price;
        if ($price < 100) {
            $errors['price'] = 'Minimum price is 100 IDR';
        }
        
        if ($price > 1000000000) {
            $errors['price'] = 'Maximum price is 1,000,000,000 IDR';
        }
        
        // Rating format validation
        if ($this->rating !== null) {
            $rating = (float)$this->rating;
            if ($rating < 0 || $rating > 5) {
                $errors['rating'] = 'Rating must be between 0 and 5';
            }
        }
        
        // Product and marketplace ID validation
        if ($this->productId <= 0) {
            $errors['product_id'] = 'Invalid product ID';
        }
        
        if ($this->marketplaceId <= 0) {
            $errors['marketplace_id'] = 'Invalid marketplace ID';
        }
        
        // Marketplace badge ID validation
        if ($this->marketplaceBadgeId !== null && $this->marketplaceBadgeId <= 0) {
            $errors['marketplace_badge_id'] = 'Invalid marketplace badge ID';
        }
    }

    /**
     * Get validation rules for use in controllers
     */
    public static function rules(): array
    {
        return [
            'product_id' => 'required|integer|greater_than[0]',
            'marketplace_id' => 'required|integer|greater_than[0]',
            'store_name' => 'required|string|max:100',
            'price' => 'required|numeric|greater_than_equal_to[100]|less_than_equal_to[1000000000]',
            'url' => 'required|valid_url|max:500',
            'rating' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[5]',
            'active' => 'permit_empty|in_list[0,1,true,false]',
            'marketplace_badge_id' => 'permit_empty|integer|greater_than[0]',
            'commission_rate' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'product_id' => [
                'required' => 'Product selection is required',
                'greater_than' => 'Invalid product selection',
            ],
            'marketplace_id' => [
                'required' => 'Marketplace selection is required',
                'greater_than' => 'Invalid marketplace selection',
            ],
            'store_name' => [
                'required' => 'Store name is required',
                'max' => 'Store name cannot exceed 100 characters',
            ],
            'price' => [
                'required' => 'Price is required',
                'numeric' => 'Price must be a valid number',
                'greater_than_equal_to' => 'Minimum price is 100 IDR',
                'less_than_equal_to' => 'Maximum price is 1,000,000,000 IDR',
            ],
            'url' => [
                'required' => 'Product URL is required',
                'valid_url' => 'Please enter a valid URL',
                'max' => 'URL cannot exceed 500 characters',
            ],
            'rating' => [
                'numeric' => 'Rating must be a valid number',
                'greater_than_equal_to' => 'Rating cannot be negative',
                'less_than_equal_to' => 'Maximum rating is 5',
            ],
            'commission_rate' => [
                'numeric' => 'Commission rate must be a valid number',
                'greater_than_equal_to' => 'Commission rate cannot be negative',
                'less_than_equal_to' => 'Commission rate cannot exceed 100%',
            ],
        ];
    }

    // Getters
    public function getProductId(): int { return $this->productId; }
    public function getMarketplaceId(): int { return $this->marketplaceId; }
    public function getStoreName(): string { return $this->storeName; }
    public function getPrice(): string { return $this->price; }
    public function getUrl(): string { return $this->url; }
    public function getRating(): ?string { return $this->rating; }
    public function isActive(): bool { return $this->active; }
    public function getMarketplaceBadgeId(): ?int { return $this->marketplaceBadgeId; }
    public function getCommissionRate(): ?float { return $this->commissionRate; }
    public function getCreatedBy(): ?int { return $this->createdBy; }

    /**
     * Check if link has a rating
     */
    public function hasRating(): bool
    {
        return $this->rating !== null;
    }

    /**
     * Check if link has a marketplace badge
     */
    public function hasMarketplaceBadge(): bool
    {
        return $this->marketplaceBadgeId !== null && $this->marketplaceBadgeId > 0;
    }

    /**
     * Check if link has commission rate
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
     * Get commission rate as decimal (for calculations)
     */
    public function getCommissionRateDecimal(): ?float
    {
        if ($this->commissionRate === null) {
            return null;
        }
        
        return $this->commissionRate / 100;
    }

    /**
     * Convert to database array
     */
    public function toDatabaseArray(): array
    {
        return [
            'product_id' => $this->productId,
            'marketplace_id' => $this->marketplaceId,
            'store_name' => $this->storeName,
            'price' => $this->price,
            'url' => $this->url,
            'rating' => $this->rating,
            'active' => $this->active ? 1 : 0,
            'marketplace_badge_id' => $this->marketplaceBadgeId,
            'commission_rate' => $this->commissionRate,
        ];
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        $data = [
            'product_id' => $this->productId,
            'marketplace_id' => $this->marketplaceId,
            'store_name' => $this->storeName,
            'price' => $this->price,
            'formatted_price' => 'Rp ' . number_format((float)$this->price, 0, ',', '.'),
            'url' => $this->url,
            'active' => $this->active,
            'has_rating' => $this->hasRating(),
            'has_marketplace_badge' => $this->hasMarketplaceBadge(),
            'has_commission_rate' => $this->hasCommissionRate(),
        ];
        
        if ($this->rating !== null) {
            $data['rating'] = $this->rating;
            $data['formatted_rating'] = sprintf('%s/5', $this->rating);
        }
        
        if ($this->marketplaceBadgeId !== null) {
            $data['marketplace_badge_id'] = $this->marketplaceBadgeId;
        }
        
        if ($this->commissionRate !== null) {
            $data['commission_rate'] = $this->commissionRate;
            $data['commission_rate_display'] = $this->getCommissionRateDisplay();
            $data['commission_rate_decimal'] = $this->getCommissionRateDecimal();
        }
        
        return $data;
    }

    /**
     * Get validation errors (if any)
     */
    public function validate(): array
    {
        $errors = [];
        
        // Re-run business rule validations
        $this->validateBusinessRules($errors);
        
        return $errors;
    }
}