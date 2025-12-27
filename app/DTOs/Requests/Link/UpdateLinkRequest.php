<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Link;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;

/**
 * DTO for updating an existing affiliate link
 * 
 * @package DevDaily\DTOs\Requests\Link
 */
final class UpdateLinkRequest extends BaseDTO
{
    private int $linkId;
    private ?string $storeName = null;
    private ?string $price = null;
    private ?string $url = null;
    private ?string $rating = null;
    private ?bool $active = null;
    private ?int $marketplaceBadgeId = null;
    private ?float $commissionRate = null;
    private ?int $updatedBy = null;
    private bool $updatePriceTimestamp = true;
    private bool $markAsValidated = false;
    
    /** @var array<string, mixed> Tracks changed fields */
    private array $changedFields = [];

    /**
     * Private constructor - use factory method
     */
    private function __construct(int $linkId)
    {
        $this->linkId = $linkId;
    }

    /**
     * Create DTO from request data
     */
    public static function fromRequest(int $linkId, array $requestData, ?int $updatedBy = null): self
    {
        $dto = new self($linkId);
        $dto->validateAndHydrate($requestData);
        $dto->updatedBy = $updatedBy;
        
        return $dto;
    }

    /**
     * Validate and hydrate the DTO for partial update
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // Track original data for comparison
        $originalData = $this->getOriginalDataSnapshot();
        
        // Hydrate only provided fields
        if (isset($data['store_name']) && $data['store_name'] !== '') {
            $this->storeName = $this->sanitizeString($data['store_name']);
        }
        
        if (isset($data['price']) && $data['price'] !== '') {
            $this->price = $this->validateAndFormatPrice($data['price'], $errors);
            $this->updatePriceTimestamp = true; // Default to update timestamp when price changes
        }
        
        if (isset($data['url']) && $data['url'] !== '') {
            $this->url = $this->validateAndFormatUrl($data['url'], $errors);
        }
        
        if (array_key_exists('rating', $data)) {
            $this->rating = $data['rating'] !== '' ? $this->validateRating($data['rating'], $errors) : null;
        }
        
        if (isset($data['active'])) {
            $this->active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (array_key_exists('marketplace_badge_id', $data)) {
            $this->marketplaceBadgeId = $data['marketplace_badge_id'] !== '' ? (int)$data['marketplace_badge_id'] : null;
        }
        
        if (array_key_exists('commission_rate', $data)) {
            $this->commissionRate = $data['commission_rate'] !== '' ? 
                $this->validateCommissionRate($data['commission_rate'], $errors) : null;
        }
        
        // Optional flags for update behavior
        if (isset($data['update_price_timestamp'])) {
            $this->updatePriceTimestamp = filter_var($data['update_price_timestamp'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (isset($data['mark_as_validated'])) {
            $this->markAsValidated = filter_var($data['mark_as_validated'], FILTER_VALIDATE_BOOLEAN);
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        // Track changed fields
        $this->identifyChangedFields($originalData);
        
        if (!empty($errors)) {
            throw new ValidationException('Link update validation failed', $errors);
        }
    }

    /**
     * Get snapshot of original data for comparison
     */
    private function getOriginalDataSnapshot(): array
    {
        return [
            'store_name' => $this->storeName,
            'price' => $this->price,
            'url' => $this->url,
            'rating' => $this->rating,
            'active' => $this->active,
            'marketplace_badge_id' => $this->marketplaceBadgeId,
            'commission_rate' => $this->commissionRate,
        ];
    }

    /**
     * Identify which fields have changed
     */
    private function identifyChangedFields(array $originalData): void
    {
        $currentData = [
            'store_name' => $this->storeName,
            'price' => $this->price,
            'url' => $this->url,
            'rating' => $this->rating,
            'active' => $this->active,
            'marketplace_badge_id' => $this->marketplaceBadgeId,
            'commission_rate' => $this->commissionRate,
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
            $errors['price'] = 'Price must be a valid positive number';
            return '0.00';
        }
        
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
        if ($this->storeName !== null && strlen($this->storeName) > 100) {
            $errors['store_name'] = 'Store name cannot exceed 100 characters';
        }
        
        // Price validation (MVP: 100 to 1,000,000,000 IDR)
        if ($this->price !== null) {
            $price = (float)$this->price;
            if ($price < 100) {
                $errors['price'] = 'Minimum price is 100 IDR';
            }
            
            if ($price > 1000000000) {
                $errors['price'] = 'Maximum price is 1,000,000,000 IDR';
            }
        }
        
        // Rating format validation
        if ($this->rating !== null) {
            $rating = (float)$this->rating;
            if ($rating < 0 || $rating > 5) {
                $errors['rating'] = 'Rating must be between 0 and 5';
            }
        }
        
        // Marketplace badge ID validation
        if ($this->marketplaceBadgeId !== null && $this->marketplaceBadgeId <= 0) {
            $errors['marketplace_badge_id'] = 'Invalid marketplace badge ID';
        }
        
        // Link ID validation
        if ($this->linkId <= 0) {
            $errors['link_id'] = 'Invalid link ID';
        }
    }

    /**
     * Get validation rules for partial updates
     */
    public static function rules(): array
    {
        return [
            'store_name' => 'permit_empty|string|max:100',
            'price' => 'permit_empty|numeric|greater_than_equal_to[100]|less_than_equal_to[1000000000]',
            'url' => 'permit_empty|valid_url|max:500',
            'rating' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[5]',
            'active' => 'permit_empty|in_list[0,1,true,false]',
            'marketplace_badge_id' => 'permit_empty|integer|greater_than[0]',
            'commission_rate' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]',
            'update_price_timestamp' => 'permit_empty|in_list[0,1,true,false]',
            'mark_as_validated' => 'permit_empty|in_list[0,1,true,false]',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'store_name' => [
                'max' => 'Store name cannot exceed 100 characters',
            ],
            'price' => [
                'numeric' => 'Price must be a valid number',
                'greater_than_equal_to' => 'Minimum price is 100 IDR',
                'less_than_equal_to' => 'Maximum price is 1,000,000,000 IDR',
            ],
            'url' => [
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
    public function getLinkId(): int { return $this->linkId; }
    public function getStoreName(): ?string { return $this->storeName; }
    public function getPrice(): ?string { return $this->price; }
    public function getUrl(): ?string { return $this->url; }
    public function getRating(): ?string { return $this->rating; }
    public function isActive(): ?bool { return $this->active; }
    public function getMarketplaceBadgeId(): ?int { return $this->marketplaceBadgeId; }
    public function getCommissionRate(): ?float { return $this->commissionRate; }
    public function getUpdatedBy(): ?int { return $this->updatedBy; }
    public function shouldUpdatePriceTimestamp(): bool { return $this->updatePriceTimestamp; }
    public function shouldMarkAsValidated(): bool { return $this->markAsValidated; }
    
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
     * Check if price has changed
     */
    public function isPriceChanged(): bool
    {
        return isset($this->changedFields['price']);
    }
    
    /**
     * Check if commission rate has changed
     */
    public function isCommissionRateChanged(): bool
    {
        return isset($this->changedFields['commission_rate']);
    }
    
    /**
     * Check if status (active) has changed
     */
    public function isStatusChanged(): bool
    {
        return isset($this->changedFields['active']);
    }
    
    /**
     * Get update scenario based on changed fields
     */
    public function getUpdateScenario(): string
    {
        if (!$this->hasChanges()) {
            return 'no_changes';
        }
        
        if ($this->isPriceChanged()) {
            return 'price_update';
        }
        
        if ($this->isStatusChanged()) {
            return 'status_change';
        }
        
        if ($this->isCommissionRateChanged()) {
            return 'commission_update';
        }
        
        return 'general_update';
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
     * Get summary of changes for audit logging
     */
    public function getChangesSummary(): string
    {
        if (!$this->hasChanges()) {
            return 'No changes';
        }
        
        $changes = [];
        foreach ($this->changedFields as $field => $value) {
            $formattedValue = $this->formatChangeValue($value, $field);
            $changes[] = sprintf('%s: %s', $this->formatFieldName($field), $formattedValue);
        }
        
        return implode(', ', $changes);
    }
    
    /**
     * Format value for change summary
     */
    private function formatChangeValue($value, string $field): string
    {
        if ($value === null) {
            return '[null]';
        }
        
        if (is_bool($value)) {
            return $value ? 'Active' : 'Inactive';
        }
        
        if ($field === 'commission_rate') {
            return sprintf('%s%%', number_format($value, 2));
        }
        
        if ($field === 'price') {
            return 'Rp ' . number_format((float)$value, 0, ',', '.');
        }
        
        if ($field === 'rating') {
            return sprintf('%s/5', $value);
        }
        
        if (is_string($value) && strlen($value) > 50) {
            return substr($value, 0, 47) . '...';
        }
        
        return (string)$value;
    }
    
    /**
     * Format field name for display
     */
    private function formatFieldName(string $field): string
    {
        $fieldMap = [
            'store_name' => 'Store Name',
            'price' => 'Price',
            'url' => 'URL',
            'rating' => 'Rating',
            'active' => 'Status',
            'marketplace_badge_id' => 'Marketplace Badge',
            'commission_rate' => 'Commission Rate',
        ];
        
        return $fieldMap[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Convert changed fields to database array
     */
    public function toDatabaseArray(): array
    {
        $data = [];
        
        if ($this->storeName !== null) {
            $data['store_name'] = $this->storeName;
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
            $data['active'] = $this->active ? 1 : 0;
        }
        
        if ($this->marketplaceBadgeId !== null) {
            $data['marketplace_badge_id'] = $this->marketplaceBadgeId;
        }
        
        if ($this->commissionRate !== null) {
            $data['commission_rate'] = $this->commissionRate;
        }
        
        return $data;
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        $data = [
            'link_id' => $this->linkId,
            'has_changes' => $this->hasChanges(),
            'update_scenario' => $this->getUpdateScenario(),
            'update_price_timestamp' => $this->updatePriceTimestamp,
            'mark_as_validated' => $this->markAsValidated,
        ];
        
        if ($this->storeName !== null) {
            $data['store_name'] = $this->storeName;
        }
        
        if ($this->price !== null) {
            $data['price'] = $this->price;
            $data['formatted_price'] = 'Rp ' . number_format((float)$this->price, 0, ',', '.');
        }
        
        if ($this->url !== null) {
            $data['url'] = $this->url;
        }
        
        if ($this->rating !== null) {
            $data['rating'] = $this->rating;
            $data['formatted_rating'] = sprintf('%s/5', $this->rating);
            $data['has_rating'] = true;
        } else {
            $data['has_rating'] = false;
        }
        
        if ($this->active !== null) {
            $data['active'] = $this->active;
            $data['status_label'] = $this->active ? 'Active' : 'Inactive';
        }
        
        if ($this->marketplaceBadgeId !== null) {
            $data['marketplace_badge_id'] = $this->marketplaceBadgeId;
            $data['has_marketplace_badge'] = true;
        } else {
            $data['has_marketplace_badge'] = false;
        }
        
        if ($this->commissionRate !== null) {
            $data['commission_rate'] = $this->commissionRate;
            $data['commission_rate_display'] = $this->getCommissionRateDisplay();
            $data['commission_rate_decimal'] = $this->getCommissionRateDecimal();
            $data['has_commission_rate'] = true;
        } else {
            $data['has_commission_rate'] = false;
        }
        
        if ($this->changedFields) {
            $data['changed_fields'] = $this->changedFields;
            $data['changes_summary'] = $this->getChangesSummary();
            $data['is_price_changed'] = $this->isPriceChanged();
            $data['is_status_changed'] = $this->isStatusChanged();
            $data['is_commission_rate_changed'] = $this->isCommissionRateChanged();
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