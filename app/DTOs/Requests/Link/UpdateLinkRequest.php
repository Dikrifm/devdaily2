<?php

namespace App\DTOs\Requests\Link;

/**
 * Update Link Request DTO
 * 
 * Handles validation and data transfer for link updates with smart commission logic.
 * All fields are optional - only provided fields will be updated.
 * Commission rate is TRANSIENT - used only for calculation, not persisted.
 * 
 * @package App\DTOs\Requests\Link
 */
class UpdateLinkRequest
{
    /**
     * Link ID to update (required)
     * 
     * @var int
     */
    public int $link_id;

    /**
     * Store name (optional)
     * 
     * @var string|null
     */
    public ?string $store_name = null;

    /**
     * Product price in Rupiah (optional)
     * 
     * @var string|null
     */
    public ?string $price = null;

    /**
     * Product URL (optional)
     * 
     * @var string|null
     */
    public ?string $url = null;

    /**
     * Product rating (optional)
     * 
     * @var string|null
     */
    public ?string $rating = null;

    /**
     * Active status (optional)
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
     * If provided, overrides the current implied rate
     * If null, maintains current rate (for price updates) or uses default
     * 
     * @var float|null
     */
    public ?float $commission_rate = null;

    /**
     * Constructor with required link ID
     * 
     * @param int $linkId
     */
    public function __construct(int $linkId)
    {
        $this->link_id = $linkId;
    }

    /**
     * Get validation rules for update link
     * 
     * @return array
     */
    public static function rules(): array
    {
        return [
            'store_name'          => 'permit_empty|string|min_length[2]|max_length[255]',
            'price'               => 'permit_empty|numeric|greater_than[0]',
            'url'                 => 'permit_empty|valid_url|max_length[500]',
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
            'store_name' => [
                'string'      => 'Store name must be text',
                'min_length'  => 'Store name must be at least 2 characters',
                'max_length'  => 'Store name cannot exceed 255 characters'
            ],
            'price' => [
                'numeric'       => 'Price must be a number',
                'greater_than'  => 'Price must be greater than 0'
            ],
            'url' => [
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
     * @param int $linkId
     * @param array $requestData
     * @return self
     */
    public static function fromRequest(int $linkId, array $requestData): self
    {
        $instance = new self($linkId);
        
        // Optional fields - only set if provided and not empty string
        if (isset($requestData['store_name']) && $requestData['store_name'] !== '') {
            $instance->store_name = (string) $requestData['store_name'];
        }
        
        if (isset($requestData['price']) && $requestData['price'] !== '') {
            $instance->price = (string) $requestData['price'];
        }
        
        if (isset($requestData['url']) && $requestData['url'] !== '') {
            $instance->url = (string) $requestData['url'];
        }
        
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
        // Empty string or null will result in null (maintain current implied rate)
        if (isset($requestData['commission_rate']) && $requestData['commission_rate'] !== '') {
            $instance->commission_rate = (float) $requestData['commission_rate'];
        }
        
        return $instance;
    }

    /**
     * Get array of fields that have been set (non-null)
     * Useful for partial updates
     * 
     * @return array
     */
    public function getChangedFields(): array
    {
        $fields = [];
        
        // Check each property (excluding link_id which is always set)
        $properties = ['store_name', 'price', 'url', 'rating', 'active', 'marketplace_badge_id'];
        
        foreach ($properties as $property) {
            if ($this->{$property} !== null) {
                $fields[] = $property;
            }
        }
        
        // Commission rate is special - it's transient but affects revenue
        if ($this->commission_rate !== null) {
            $fields[] = 'commission_rate';
        }
        
        return $fields;
    }

    /**
     * Check if any field has been changed
     * 
     * @return bool
     */
    public function hasChanges(): bool
    {
        return !empty($this->getChangedFields());
    }

    /**
     * Check if price is being updated
     * 
     * @return bool
     */
    public function isPriceChanged(): bool
    {
        return $this->price !== null;
    }

    /**
     * Check if commission rate is being updated
     * 
     * @return bool
     */
    public function isCommissionRateChanged(): bool
    {
        return $this->commission_rate !== null;
    }

    /**
     * Get commission rate as decimal (for calculation)
     * Returns null if not set (will maintain current implied rate)
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
     * Get update scenario type
     * Helps determine business logic in Service layer
     * 
     * @return string One of: 'price_only', 'rate_only', 'both', 'neither', 'other'
     */
    public function getUpdateScenario(): string
    {
        $priceChanged = $this->isPriceChanged();
        $rateChanged = $this->isCommissionRateChanged();
        
        if ($priceChanged && $rateChanged) {
            return 'both';
        }
        
        if ($priceChanged) {
            return 'price_only';
        }
        
        if ($rateChanged) {
            return 'rate_only';
        }
        
        // Check if any other fields changed
        $otherFields = array_diff($this->getChangedFields(), ['commission_rate']);
        if (!empty($otherFields)) {
            return 'other';
        }
        
        return 'neither';
    }

    /**
     * Convert DTO to array for database update
     * EXCLUDES transient fields (commission_rate)
     * Includes only non-null fields for partial update
     * 
     * @return array
     */
    public function toDatabaseArray(): array
    {
        $data = [];
        
        // Only include fields that are explicitly set (not null)
        if ($this->store_name !== null) {
            $data['store_name'] = $this->store_name;
        }
        
        if ($this->price !== null) {
            $data['price'] = $this->price;
        }
        
        if ($this->url !== null) {
            $data['url'] = $this->url;
        }
        
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
        // Revenue will be calculated separately in Service layer
        
        return $data;
    }

    /**
     * Validate the DTO data
     * 
     * @return array Array of validation errors, empty if valid
     */
    public function validate(): array
    {
        $errors = [];
        
        // Link ID validation
        if ($this->link_id <= 0) {
            $errors['link_id'] = 'Valid link ID is required';
        }
        
        // Optional field validations (only if provided)
        if ($this->store_name !== null && strlen(trim($this->store_name)) < 2) {
            $errors['store_name'] = 'Store name must be at least 2 characters';
        }
        
        if ($this->price !== null) {
            $price = (float) $this->price;
            if ($price <= 0) {
                $errors['price'] = 'Price must be greater than 0';
            }
        }
        
        if ($this->url !== null && !filter_var($this->url, FILTER_VALIDATE_URL)) {
            $errors['url'] = 'URL must be a valid URL';
        }
        
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

    /**
     * Get summary of changes for audit logging
     * 
     * @return string
     */
    public function getChangesSummary(): string
    {
        $changes = [];
        
        if ($this->store_name !== null) {
            $changes[] = 'store name';
        }
        
        if ($this->price !== null) {
            $changes[] = 'price';
        }
        
        if ($this->url !== null) {
            $changes[] = 'url';
        }
        
        if ($this->rating !== null) {
            $changes[] = 'rating';
        }
        
        if ($this->active !== null) {
            $changes[] = 'active status';
        }
        
        if ($this->marketplace_badge_id !== null) {
            $changes[] = 'marketplace badge';
        }
        
        if ($this->commission_rate !== null) {
            $changes[] = 'commission rate';
        }
        
        if (empty($changes)) {
            return 'No changes';
        }
        
        return 'Updated: ' . implode(', ', $changes);
    }
}