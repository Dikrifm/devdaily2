<?php

namespace App\DTOs\Requests\Link;

/**
 * Create Link Request DTO
 * 
 * Handles validation and data transfer for link creation with commission rate.
 * Commission rate is TRANSIENT - used only for calculation, not persisted.
 * 
 * @package App\DTOs\Requests\Link
 */
class CreateLinkRequest
{
    /**
     * Product ID (required)
     * 
     * @var int
     */
    public int $product_id;

    /**
     * Marketplace ID (required)
     * 
     * @var int
     */
    public int $marketplace_id;

    /**
     * Store name (required)
     * 
     * @var string
     */
    public string $store_name;

    /**
     * Product price in Rupiah (required)
     * 
     * @var string
     */
    public string $price;

    /**
     * Product URL (required)
     * 
     * @var string
     */
    public string $url;

    /**
     * Product rating (optional, default 0.00)
     * 
     * @var string|null
     */
    public ?string $rating = null;

    /**
     * Active status (optional, default true)
     * 
     * @var bool|null
     */
    public ?bool $active = null;

    /**
     * Marketplace badge ID (optional)
     * 
     * @var int|null
     */
    public ?int $marketplace_badge_id = null;

    /**
     * Commission rate in percentage (TRANSIENT - optional)
     * 
     * Example: 5 for 5%, 2.5 for 2.5%
     * If null or empty, system will use default 2%
     * 
     * @var float|null
     */
    public ?float $commission_rate = null;

    /**
     * Get validation rules for create link
     * 
     * @return array
     */
    public static function rules(): array
    {
        return [
            'product_id'          => 'required|integer|is_natural_no_zero',
            'marketplace_id'      => 'required|integer|is_natural_no_zero',
            'store_name'          => 'required|string|min_length[2]|max_length[255]',
            'price'               => 'required|numeric|greater_than[0]',
            'url'                 => 'required|valid_url|max_length[500]',
            'rating'              => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[5]',
            'active'              => 'permit_empty|in_list[0,1]',
            'marketplace_badge_id' => 'permit_empty|integer|is_natural',
            
            // Commission rate validation
            'commission_rate'     => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]',
        ];
    }

    /**
     * Get validation error messages
     * 
     * @return array
     */
    public static function messages(): array
    {
        return [
            'product_id' => [
                'required' => 'Product ID is required',
                'integer'  => 'Product ID must be an integer',
                'is_natural_no_zero' => 'Product ID must be a positive number'
            ],
            'marketplace_id' => [
                'required' => 'Marketplace ID is required',
                'integer'  => 'Marketplace ID must be an integer',
                'is_natural_no_zero' => 'Marketplace ID must be a positive number'
            ],
            'store_name' => [
                'required'   => 'Store name is required',
                'min_length' => 'Store name must be at least 2 characters',
                'max_length' => 'Store name cannot exceed 255 characters'
            ],
            'price' => [
                'required'      => 'Price is required',
                'numeric'       => 'Price must be a number',
                'greater_than'  => 'Price must be greater than 0'
            ],
            'url' => [
                'required'   => 'URL is required',
                'valid_url'  => 'URL must be a valid URL',
                'max_length' => 'URL cannot exceed 500 characters'
            ],
            'rating' => [
                'numeric'                => 'Rating must be a number',
                'greater_than_equal_to'  => 'Rating cannot be less than 0',
                'less_than_equal_to'     => 'Rating cannot exceed 5'
            ],
            'active' => [
                'in_list' => 'Active must be 0 or 1'
            ],
            'marketplace_badge_id' => [
                'integer'       => 'Marketplace badge ID must be an integer',
                'is_natural'    => 'Marketplace badge ID must be a positive number or zero'
            ],
            'commission_rate' => [
                'numeric'                => 'Commission rate must be a number',
                'greater_than_equal_to'  => 'Commission rate cannot be negative',
                'less_than_equal_to'     => 'Commission rate cannot exceed 100%'
            ]
        ];
    }

    /**
     * Create DTO instance from request data
     * 
     * @param array $requestData
     * @return self
     */
    public static function fromRequest(array $requestData): self
    {
        $instance = new self();
        
        // Required fields
        $instance->product_id = (int) ($requestData['product_id'] ?? 0);
        $instance->marketplace_id = (int) ($requestData['marketplace_id'] ?? 0);
        $instance->store_name = (string) ($requestData['store_name'] ?? '');
        $instance->price = (string) ($requestData['price'] ?? '0');
        $instance->url = (string) ($requestData['url'] ?? '');
        
        // Optional fields with null coalescing
        if (isset($requestData['rating']) && $requestData['rating'] !== '') {
            $instance->rating = (string) $requestData['rating'];
        }
        
        if (isset($requestData['active'])) {
            $instance->active = (bool) $requestData['active'];
        }
        
        if (isset($requestData['marketplace_badge_id']) && $requestData['marketplace_badge_id'] !== '') {
            $instance->marketplace_badge_id = (int) $requestData['marketplace_badge_id'];
        }
        
        // Commission rate parsing (TRANSIENT FIELD)
        // Empty string or null will result in null (use default 2%)
        if (isset($requestData['commission_rate']) && $requestData['commission_rate'] !== '') {
            $instance->commission_rate = (float) $requestData['commission_rate'];
        }
        
        return $instance;
    }

    /**
     * Convert DTO to array for database insertion
     * EXCLUDES transient fields (commission_rate)
     * 
     * @return array
     */
    public function toDatabaseArray(): array
    {
        $data = [
            'product_id'      => $this->product_id,
            'marketplace_id'  => $this->marketplace_id,
            'store_name'      => $this->store_name,
            'price'           => $this->price,
            'url'             => $this->url,
        ];
        
        // Add optional fields only if they are not null
        if ($this->rating !== null) {
            $data['rating'] = $this->rating;
        }
        
        if ($this->active !== null) {
            $data['active'] = $this->active;
        }
        
        if ($this->marketplace_badge_id !== null) {
            $data['marketplace_badge_id'] = $this->marketplace_badge_id;
        }
        
        // NOTE: commission_rate is NOT included - it's transient
        
        return $data;
    }

    /**
     * Get commission rate as decimal (for calculation)
     * Returns null if not set (will use default 2%)
     * 
     * @return float|null Decimal rate (e.g., 0.05 for 5%)
     */
    public function getCommissionRateDecimal(): ?float
    {
        if ($this->commission_rate === null) {
            return null;
        }
        
        return $this->commission_rate / 100;
    }

    /**
     * Check if commission rate was provided in request
     * 
     * @return bool
     */
    public function hasCommissionRate(): bool
    {
        return $this->commission_rate !== null;
    }

    /**
     * Get commission rate display string
     * 
     * @return string Formatted percentage (e.g., "5.00%")
     */
    public function getCommissionRateDisplay(): string
    {
        if ($this->commission_rate === null) {
            return 'Default (2%)';
        }
        
        return number_format($this->commission_rate, 2) . '%';
    }

    /**
     * Validate the DTO data
     * 
     * @return array Array of validation errors, empty if valid
     */
    public function validate(): array
    {
        $errors = [];
        
        // Required field validations
        if ($this->product_id <= 0) {
            $errors['product_id'] = 'Product ID is required';
        }
        
        if ($this->marketplace_id <= 0) {
            $errors['marketplace_id'] = 'Marketplace ID is required';
        }
        
        if (empty(trim($this->store_name))) {
            $errors['store_name'] = 'Store name is required';
        } elseif (strlen($this->store_name) < 2) {
            $errors['store_name'] = 'Store name must be at least 2 characters';
        }
        
        if (empty($this->price) || (float) $this->price <= 0) {
            $errors['price'] = 'Valid price is required';
        }
        
        if (empty($this->url)) {
            $errors['url'] = 'URL is required';
        } elseif (!filter_var($this->url, FILTER_VALIDATE_URL)) {
            $errors['url'] = 'URL must be a valid URL';
        }
        
        // Optional field validations
        if ($this->rating !== null) {
            $rating = (float) $this->rating;
            if ($rating < 0 || $rating > 5) {
                $errors['rating'] = 'Rating must be between 0 and 5';
            }
        }
        
        if ($this->commission_rate !== null) {
            if ($this->commission_rate < 0) {
                $errors['commission_rate'] = 'Commission rate cannot be negative';
            } elseif ($this->commission_rate > 100) {
                $errors['commission_rate'] = 'Commission rate cannot exceed 100%';
            }
        }
        
        return $errors;
    }
}