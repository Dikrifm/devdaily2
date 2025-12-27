<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Link;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;

/**
 * DTO for bulk updating multiple affiliate links
 * 
 * @package DevDaily\DTOs\Requests\Link
 */
final class BulkLinkUpdateRequest extends BaseDTO
{
    private array $linkIds;
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
    private string $updateReason = 'bulk_update';
    private bool $skipValidation = false;
    private int $batchSize = 50;
    
    /** @var array<string, mixed> Update data for database */
    private array $updateData = [];

    /**
     * Constructor is private, use factory method
     */
    private function __construct() {}

    /**
     * Create DTO from request data
     */
    public static function fromRequest(array $requestData, ?int $updatedBy = null): self
    {
        $dto = new self();
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
        
        // Validate required fields
        if (empty($data['link_ids']) || !is_array($data['link_ids'])) {
            $errors['link_ids'] = 'Link IDs are required and must be an array';
        } else {
            $this->linkIds = $this->validateLinkIds($data['link_ids'], $errors);
        }
        
        // Validate at least one update field is provided
        $updateFields = ['store_name', 'price', 'url', 'rating', 'active', 'marketplace_badge_id', 'commission_rate'];
        $hasUpdateField = false;
        
        foreach ($updateFields as $field) {
            if (array_key_exists($field, $data)) {
                $hasUpdateField = true;
                break;
            }
        }
        
        if (!$hasUpdateField) {
            $errors['update_fields'] = 'At least one update field must be provided';
        }
        
        // Hydrate update fields
        if (isset($data['store_name']) && $data['store_name'] !== '') {
            $this->storeName = $this->sanitizeString($data['store_name']);
            $this->updateData['store_name'] = $this->storeName;
        }
        
        if (isset($data['price']) && $data['price'] !== '') {
            $this->price = $this->validateAndFormatPrice($data['price'], $errors);
            $this->updateData['price'] = $this->price;
        }
        
        if (isset($data['url']) && $data['url'] !== '') {
            $this->url = $this->validateAndFormatUrl($data['url'], $errors);
            $this->updateData['url'] = $this->url;
        }
        
        if (array_key_exists('rating', $data)) {
            $this->rating = $data['rating'] !== '' ? $this->validateRating($data['rating'], $errors) : null;
            if ($this->rating !== null) {
                $this->updateData['rating'] = $this->rating;
            }
        }
        
        if (isset($data['active'])) {
            $this->active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
            $this->updateData['active'] = $this->active ? 1 : 0;
        }
        
        if (array_key_exists('marketplace_badge_id', $data)) {
            $this->marketplaceBadgeId = $data['marketplace_badge_id'] !== '' ? (int)$data['marketplace_badge_id'] : null;
            if ($this->marketplaceBadgeId !== null) {
                $this->updateData['marketplace_badge_id'] = $this->marketplaceBadgeId;
            }
        }
        
        if (array_key_exists('commission_rate', $data)) {
            $this->commissionRate = $data['commission_rate'] !== '' ? 
                $this->validateCommissionRate($data['commission_rate'], $errors) : null;
            if ($this->commissionRate !== null) {
                $this->updateData['commission_rate'] = $this->commissionRate;
            }
        }
        
        // Optional behavior flags
        if (isset($data['update_price_timestamp'])) {
            $this->updatePriceTimestamp = filter_var($data['update_price_timestamp'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (isset($data['mark_as_validated'])) {
            $this->markAsValidated = filter_var($data['mark_as_validated'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (isset($data['update_reason']) && $data['update_reason'] !== '') {
            $this->updateReason = $this->sanitizeString($data['update_reason']);
        }
        
        if (isset($data['skip_validation'])) {
            $this->skipValidation = filter_var($data['skip_validation'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (isset($data['batch_size']) && is_numeric($data['batch_size'])) {
            $this->batchSize = max(1, min(100, (int)$data['batch_size']));
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw new ValidationException('Bulk link update validation failed', $errors);
        }
    }

    /**
     * Validate and sanitize link IDs
     */
    private function validateLinkIds(array $linkIds, array &$errors): array
    {
        $validIds = [];
        
        foreach ($linkIds as $id) {
            $intId = (int)$id;
            if ($intId <= 0) {
                $errors['link_ids'] = 'All link IDs must be positive integers';
                return [];
            }
            $validIds[] = $intId;
        }
        
        // Remove duplicates
        $validIds = array_unique($validIds);
        
        // Check max batch size
        if (count($validIds) > 100) {
            $errors['link_ids'] = 'Cannot update more than 100 links at once';
            return [];
        }
        
        if (count($validIds) === 0) {
            $errors['link_ids'] = 'At least one valid link ID is required';
        }
        
        return $validIds;
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
        
        // Update reason validation
        if (strlen($this->updateReason) > 255) {
            $errors['update_reason'] = 'Update reason cannot exceed 255 characters';
        }
    }

    /**
     * Get validation rules for bulk updates
     */
    public static function rules(): array
    {
        return [
            'link_ids' => 'required|is_array',
            'link_ids.*' => 'required|integer|greater_than[0]',
            'store_name' => 'permit_empty|string|max:100',
            'price' => 'permit_empty|numeric|greater_than_equal_to[100]|less_than_equal_to[1000000000]',
            'url' => 'permit_empty|valid_url|max:500',
            'rating' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[5]',
            'active' => 'permit_empty|in_list[0,1,true,false]',
            'marketplace_badge_id' => 'permit_empty|integer|greater_than[0]',
            'commission_rate' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]',
            'update_price_timestamp' => 'permit_empty|in_list[0,1,true,false]',
            'mark_as_validated' => 'permit_empty|in_list[0,1,true,false]',
            'update_reason' => 'permit_empty|string|max:255',
            'skip_validation' => 'permit_empty|in_list[0,1,true,false]',
            'batch_size' => 'permit_empty|integer|greater_than[0]|less_than_equal_to[100]',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'link_ids' => [
                'required' => 'At least one link must be selected',
                'is_array' => 'Link IDs must be provided as an array',
            ],
            'link_ids.*' => [
                'integer' => 'All link IDs must be integers',
                'greater_than' => 'All link IDs must be valid',
            ],
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
            'batch_size' => [
                'greater_than' => 'Batch size must be at least 1',
                'less_than_equal_to' => 'Maximum batch size is 100',
            ],
        ];
    }

    // Getters
    public function getLinkIds(): array { return $this->linkIds; }
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
    public function getUpdateReason(): string { return $this->updateReason; }
    public function shouldSkipValidation(): bool { return $this->skipValidation; }
    public function getBatchSize(): int { return $this->batchSize; }
    public function getUpdateData(): array { return $this->updateData; }

    /**
     * Check if store name is being updated
     */
    public function isUpdatingStoreName(): bool
    {
        return $this->storeName !== null;
    }

    /**
     * Check if price is being updated
     */
    public function isUpdatingPrice(): bool
    {
        return $this->price !== null;
    }

    /**
     * Check if URL is being updated
     */
    public function isUpdatingUrl(): bool
    {
        return $this->url !== null;
    }

    /**
     * Check if rating is being updated
     */
    public function isUpdatingRating(): bool
    {
        return $this->rating !== null;
    }

    /**
     * Check if active status is being updated
     */
    public function isUpdatingActiveStatus(): bool
    {
        return $this->active !== null;
    }

    /**
     * Check if marketplace badge is being updated
     */
    public function isUpdatingMarketplaceBadge(): bool
    {
        return $this->marketplaceBadgeId !== null;
    }

    /**
     * Check if commission rate is being updated
     */
    public function isUpdatingCommissionRate(): bool
    {
        return $this->commissionRate !== null;
    }

    /**
     * Get the number of links to update
     */
    public function getLinkCount(): int
    {
        return count($this->linkIds);
    }

    /**
     * Check if this is a large batch (needs chunking)
     */
    public function isLargeBatch(): bool
    {
        return $this->getLinkCount() > $this->batchSize;
    }

    /**
     * Get chunks of link IDs for batch processing
     */
    public function getLinkIdChunks(): array
    {
        return array_chunk($this->linkIds, $this->batchSize);
    }

    /**
     * Get update summary for audit logging
     */
    public function getUpdateSummary(): string
    {
        $updates = [];
        
        if ($this->isUpdatingStoreName()) {
            $updates[] = 'Store Name';
        }
        
        if ($this->isUpdatingPrice()) {
            $updates[] = 'Price';
            if ($this->updatePriceTimestamp) {
                $updates[] = 'Price Timestamp';
            }
        }
        
        if ($this->isUpdatingUrl()) {
            $updates[] = 'URL';
        }
        
        if ($this->isUpdatingRating()) {
            $updates[] = 'Rating';
        }
        
        if ($this->isUpdatingActiveStatus()) {
            $status = $this->active ? 'Active' : 'Inactive';
            $updates[] = "Status to {$status}";
        }
        
        if ($this->isUpdatingMarketplaceBadge()) {
            $updates[] = 'Marketplace Badge';
        }
        
        if ($this->isUpdatingCommissionRate()) {
            $updates[] = 'Commission Rate';
        }
        
        if ($this->markAsValidated) {
            $updates[] = 'Validation Status';
        }
        
        if (empty($updates)) {
            return 'No updates specified';
        }
        
        $summary = sprintf(
            'Bulk update %d links: %s',
            $this->getLinkCount(),
            implode(', ', $updates)
        );
        
        if ($this->updateReason !== 'bulk_update') {
            $summary .= sprintf(' (Reason: %s)', $this->updateReason);
        }
        
        return $summary;
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
     * Convert to database array for individual updates
     */
    public function toDatabaseArray(): array
    {
        return $this->updateData;
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        $data = [
            'link_count' => $this->getLinkCount(),
            'is_large_batch' => $this->isLargeBatch(),
            'batch_size' => $this->batchSize,
            'update_price_timestamp' => $this->updatePriceTimestamp,
            'mark_as_validated' => $this->markAsValidated,
            'update_reason' => $this->updateReason,
            'skip_validation' => $this->skipValidation,
            'update_summary' => $this->getUpdateSummary(),
            'update_fields' => [],
        ];
        
        if ($this->storeName !== null) {
            $data['update_fields']['store_name'] = $this->storeName;
        }
        
        if ($this->price !== null) {
            $data['update_fields']['price'] = $this->price;
            $data['update_fields']['formatted_price'] = 'Rp ' . number_format((float)$this->price, 0, ',', '.');
        }
        
        if ($this->url !== null) {
            $data['update_fields']['url'] = $this->url;
        }
        
        if ($this->rating !== null) {
            $data['update_fields']['rating'] = $this->rating;
            $data['update_fields']['formatted_rating'] = sprintf('%s/5', $this->rating);
        }
        
        if ($this->active !== null) {
            $data['update_fields']['active'] = $this->active;
            $data['update_fields']['status_label'] = $this->active ? 'Active' : 'Inactive';
        }
        
        if ($this->marketplaceBadgeId !== null) {
            $data['update_fields']['marketplace_badge_id'] = $this->marketplaceBadgeId;
        }
        
        if ($this->commissionRate !== null) {
            $data['update_fields']['commission_rate'] = $this->commissionRate;
            $data['update_fields']['commission_rate_display'] = $this->getCommissionRateDisplay();
            $data['update_fields']['commission_rate_decimal'] = $this->getCommissionRateDecimal();
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