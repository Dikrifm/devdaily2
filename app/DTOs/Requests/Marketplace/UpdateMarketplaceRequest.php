<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Marketplace;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

/**
 * DTO for updating an existing marketplace
 * 
 * @package DevDaily\DTOs\Requests\Marketplace
 */
final class UpdateMarketplaceRequest extends BaseDTO
{
    private int $marketplaceId;
    private ?string $name = null;
    private ?string $slug = null;
    private ?string $description = null;
    private ?string $icon = null;
    private ?string $color = null;
    private ?bool $active = null;
    private ?string $domain = null;
    private ?string $affiliateProgramUrl = null;
    private ?float $defaultCommissionRate = null;
    private ?string $apiKey = null;
    private ?string $apiSecret = null;
    private ?string $webhookUrl = null;
    private ?int $updatedBy = null;
    private bool $regenerateSlug = false;
    private bool $clearApiCredentials = false;
    
    /** @var array<string, mixed> Tracks changed fields */
    private array $changedFields = [];

    /**
     * Private constructor - use factory method
     */
    private function __construct(int $marketplaceId)
    {
        $this->marketplaceId = $marketplaceId;
    }

    /**
     * Create DTO from request data
     */
    public static function fromRequest(int $marketplaceId, array $requestData, ?int $updatedBy = null): self
    {
        $dto = new self($marketplaceId);
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
        if (isset($data['name']) && $data['name'] !== '') {
            $this->name = $this->sanitizeString($data['name']);
            $this->regenerateSlug = true; // Auto-regenerate slug if name changes
        }
        
        if (isset($data['slug']) && $data['slug'] !== '') {
            $this->slug = SlugValidator::create()->normalize($data['slug']);
            $this->regenerateSlug = false; // Manual slug provided
        }
        
        if (array_key_exists('description', $data)) {
            $this->description = $data['description'] !== '' ? $this->sanitizeString($data['description']) : null;
        }
        
        if (array_key_exists('icon', $data)) {
            $this->icon = $data['icon'] !== '' ? $this->sanitizeString($data['icon']) : null;
        }
        
        if (isset($data['color']) && $data['color'] !== '') {
            $this->color = $this->validateColor($data['color'], $errors);
        }
        
        if (isset($data['active'])) {
            $this->active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
        }
        
        if (isset($data['domain']) && $data['domain'] !== '') {
            $this->domain = $this->validateDomain($data['domain'], $errors);
        }
        
        if (array_key_exists('affiliate_program_url', $data)) {
            $this->affiliateProgramUrl = $data['affiliate_program_url'] !== '' ? 
                $this->validateUrl($data['affiliate_program_url'], 'affiliate_program_url', $errors) : null;
        }
        
        if (array_key_exists('default_commission_rate', $data)) {
            $this->defaultCommissionRate = $data['default_commission_rate'] !== '' ? 
                $this->validateCommissionRate($data['default_commission_rate'], $errors) : null;
        }
        
        if (isset($data['api_key']) && $data['api_key'] !== '') {
            $this->apiKey = $this->sanitizeString($data['api_key']);
        } elseif (isset($data['clear_api_credentials']) && filter_var($data['clear_api_credentials'], FILTER_VALIDATE_BOOLEAN)) {
            $this->clearApiCredentials = true;
            $this->apiKey = null;
            $this->apiSecret = null;
        }
        
        if (isset($data['api_secret']) && $data['api_secret'] !== '') {
            $this->apiSecret = $this->sanitizeString($data['api_secret']);
        }
        
        if (array_key_exists('webhook_url', $data)) {
            $this->webhookUrl = $data['webhook_url'] !== '' ? 
                $this->validateUrl($data['webhook_url'], 'webhook_url', $errors) : null;
        }
        
        if (isset($data['regenerate_slug']) && $data['regenerate_slug'] === '1') {
            $this->regenerateSlug = true;
        }
        
        // Generate slug if needed
        if ($this->regenerateSlug && $this->name !== null) {
            $this->slug = SlugValidator::create()->generate(
                $this->name,
                ['entityType' => SlugValidator::ENTITY_MARKETPLACE, 'entityId' => $this->marketplaceId]
            );
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        // Track changed fields
        $this->identifyChangedFields($originalData);
        
        if (!empty($errors)) {
            throw new ValidationException('Marketplace update validation failed', $errors);
        }
    }

    /**
     * Get snapshot of original data for comparison
     */
    private function getOriginalDataSnapshot(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'active' => $this->active,
            'domain' => $this->domain,
            'affiliate_program_url' => $this->affiliateProgramUrl,
            'default_commission_rate' => $this->defaultCommissionRate,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'webhook_url' => $this->webhookUrl,
        ];
    }

    /**
     * Identify which fields have changed
     */
    private function identifyChangedFields(array $originalData): void
    {
        $currentData = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'active' => $this->active,
            'domain' => $this->domain,
            'affiliate_program_url' => $this->affiliateProgramUrl,
            'default_commission_rate' => $this->defaultCommissionRate,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'webhook_url' => $this->webhookUrl,
        ];
        
        foreach ($currentData as $field => $value) {
            if ($value !== null && $value !== $originalData[$field]) {
                $this->changedFields[$field] = $value;
            }
        }
        
        // Special handling for API credentials clearance
        if ($this->clearApiCredentials) {
            $this->changedFields['api_key'] = null;
            $this->changedFields['api_secret'] = null;
        }
    }

    /**
     * Validate color hex code
     */
    private function validateColor(string $color, array &$errors): string
    {
        $color = strtolower(trim($color));
        
        // Check if it's a valid hex color
        if (!preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/', $color)) {
            $errors['color'] = 'Color must be a valid hex code (e.g., #336699 or #369)';
            return '#64748b';
        }
        
        return $color;
    }

    /**
     * Validate domain format
     */
    private function validateDomain(?string $domain, array &$errors): ?string
    {
        if ($domain === null || $domain === '') {
            return null;
        }
        
        $domain = strtolower(trim($domain));
        
        // Remove protocol and www if present
        $domain = preg_replace('/^(https?:\/\/)?(www\.)?/', '', $domain);
        
        // Basic domain validation
        if (!preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/', $domain)) {
            $errors['domain'] = 'Invalid domain format';
            return null;
        }
        
        // Domain length validation
        if (strlen($domain) > 100) {
            $errors['domain'] = 'Domain cannot exceed 100 characters';
            return null;
        }
        
        return $domain;
    }

    /**
     * Validate URL
     */
    private function validateUrl(string $url, string $fieldName, array &$errors): ?string
    {
        $url = trim($url);
        
        if ($url === '') {
            return null;
        }
        
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[$fieldName] = 'Invalid URL format';
            return null;
        }
        
        // Ensure URL has proper scheme
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }
        
        // Check URL length
        if (strlen($url) > 500) {
            $errors[$fieldName] = 'URL cannot exceed 500 characters';
            return null;
        }
        
        return $url;
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
            $errors['default_commission_rate'] = 'Commission rate must be between 0 and 100 percent';
            return null;
        }
        
        return round($numericRate, 2);
    }

    /**
     * Validate business rules
     */
    private function validateBusinessRules(array &$errors): void
    {
        // Name length validation
        if ($this->name !== null && strlen($this->name) > 100) {
            $errors['name'] = 'Marketplace name cannot exceed 100 characters';
        }
        
        // Description length validation
        if ($this->description !== null && strlen($this->description) > 1000) {
            $errors['description'] = 'Description cannot exceed 1000 characters';
        }
        
        // Icon length validation
        if ($this->icon !== null && strlen($this->icon) > 50) {
            $errors['icon'] = 'Icon cannot exceed 50 characters';
        }
        
        // API key length validation
        if ($this->apiKey !== null && strlen($this->apiKey) > 255) {
            $errors['api_key'] = 'API key cannot exceed 255 characters';
        }
        
        // API secret length validation
        if ($this->apiSecret !== null && strlen($this->apiSecret) > 255) {
            $errors['api_secret'] = 'API secret cannot exceed 255 characters';
        }
        
        // Validate icon format (basic Font Awesome check)
        if ($this->icon !== null && !preg_match('/^(fas|far|fal|fab|fad) fa-[a-z0-9-]+$|^[a-z0-9-]+$/i', $this->icon)) {
            $errors['icon'] = 'Icon must be a Font Awesome class or simple icon name';
        }
        
        // Validate domain is not a URL
        if ($this->domain !== null && filter_var($this->domain, FILTER_VALIDATE_URL)) {
            $errors['domain'] = 'Domain should not include protocol (use example.com, not https://example.com)';
        }
        
        // Slug validation if provided
        if ($this->slug !== null && strlen($this->slug) > 100) {
            $errors['slug'] = 'Slug cannot exceed 100 characters';
        }
        
        // Marketplace ID validation
        if ($this->marketplaceId <= 0) {
            $errors['marketplace_id'] = 'Invalid marketplace ID';
        }
    }

    /**
     * Get validation rules for partial updates
     */
    public static function rules(): array
    {
        return [
            'name' => 'permit_empty|string|max:100',
            'slug' => 'permit_empty|string|max:100',
            'description' => 'permit_empty|string|max:1000',
            'icon' => 'permit_empty|string|max:50|regex_match[/^(fas|far|fal|fab|fad) fa-[a-z0-9-]+$|^[a-z0-9-]+$/i]',
            'color' => 'permit_empty|string|max:7|regex_match[/^#([a-f0-9]{3}|[a-f0-9]{6})$/i]',
            'active' => 'permit_empty|in_list[0,1,true,false]',
            'domain' => 'permit_empty|string|max:100|regex_match[/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/]',
            'affiliate_program_url' => 'permit_empty|valid_url|max:500',
            'default_commission_rate' => 'permit_empty|numeric|greater_than_equal_to[0]|less_than_equal_to[100]',
            'api_key' => 'permit_empty|string|max:255',
            'api_secret' => 'permit_empty|string|max:255',
            'webhook_url' => 'permit_empty|valid_url|max:500',
            'regenerate_slug' => 'permit_empty|in_list[0,1]',
            'clear_api_credentials' => 'permit_empty|in_list[0,1,true,false]',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'name.max' => 'Marketplace name cannot exceed 100 characters',
            'color.regex_match' => 'Color must be a valid hex code (e.g., #336699 or #369)',
            'icon.regex_match' => 'Icon must be a Font Awesome class (e.g., fas fa-store) or simple icon name',
            'domain.regex_match' => 'Invalid domain format. Use: example.com',
            'default_commission_rate.numeric' => 'Commission rate must be a valid number',
            'default_commission_rate.greater_than_equal_to' => 'Commission rate cannot be negative',
            'default_commission_rate.less_than_equal_to' => 'Commission rate cannot exceed 100%',
        ];
    }

    // Getters
    public function getMarketplaceId(): int { return $this->marketplaceId; }
    public function getName(): ?string { return $this->name; }
    public function getSlug(): ?string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getIcon(): ?string { return $this->icon; }
    public function getColor(): ?string { return $this->color; }
    public function isActive(): ?bool { return $this->active; }
    public function getDomain(): ?string { return $this->domain; }
    public function getAffiliateProgramUrl(): ?string { return $this->affiliateProgramUrl; }
    public function getDefaultCommissionRate(): ?float { return $this->defaultCommissionRate; }
    public function getApiKey(): ?string { return $this->apiKey; }
    public function getApiSecret(): ?string { return $this->apiSecret; }
    public function getWebhookUrl(): ?string { return $this->webhookUrl; }
    public function getUpdatedBy(): ?int { return $this->updatedBy; }
    public function shouldRegenerateSlug(): bool { return $this->regenerateSlug; }
    public function shouldClearApiCredentials(): bool { return $this->clearApiCredentials; }
    
    /**
     * Check if marketplace has a domain
     */
    public function hasDomain(): bool
    {
        return $this->domain !== null;
    }
    
    /**
     * Check if marketplace has an affiliate program
     */
    public function hasAffiliateProgram(): bool
    {
        return $this->affiliateProgramUrl !== null;
    }
    
    /**
     * Check if marketplace has API credentials
     */
    public function hasApiCredentials(): bool
    {
        return $this->apiKey !== null && $this->apiSecret !== null;
    }
    
    /**
     * Check if marketplace has webhook URL
     */
    public function hasWebhookUrl(): bool
    {
        return $this->webhookUrl !== null;
    }
    
    /**
     * Check if marketplace has default commission rate
     */
    public function hasDefaultCommissionRate(): bool
    {
        return $this->defaultCommissionRate !== null;
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
     * Check if status (active) has changed
     */
    public function isStatusChanged(): bool
    {
        return isset($this->changedFields['active']);
    }
    
    /**
     * Check if domain has changed
     */
    public function isDomainChanged(): bool
    {
        return isset($this->changedFields['domain']);
    }
    
    /**
     * Check if commission rate has changed
     */
    public function isCommissionRateChanged(): bool
    {
        return isset($this->changedFields['default_commission_rate']);
    }
    
    /**
     * Check if API credentials have changed
     */
    public function isApiCredentialsChanged(): bool
    {
        return isset($this->changedFields['api_key']) || isset($this->changedFields['api_secret']) || $this->clearApiCredentials;
    }
    
    /**
     * Get update scenario based on changed fields
     */
    public function getUpdateScenario(): string
    {
        if (!$this->hasChanges()) {
            return 'no_changes';
        }
        
        if ($this->isStatusChanged()) {
            return 'status_change';
        }
        
        if ($this->isDomainChanged()) {
            return 'domain_update';
        }
        
        if ($this->isCommissionRateChanged()) {
            return 'commission_update';
        }
        
        if ($this->isApiCredentialsChanged()) {
            return $this->clearApiCredentials ? 'api_credentials_clear' : 'api_credentials_update';
        }
        
        return 'general_update';
    }
    
    /**
     * Get default commission rate as percentage string
     */
    public function getDefaultCommissionRateDisplay(): string
    {
        if ($this->defaultCommissionRate === null) {
            return 'Not set';
        }
        
        return sprintf('%s%%', number_format($this->defaultCommissionRate, 2));
    }
    
    /**
     * Get default commission rate as decimal (for calculations)
     */
    public function getDefaultCommissionRateDecimal(): ?float
    {
        if ($this->defaultCommissionRate === null) {
            return null;
        }
        
        return $this->defaultCommissionRate / 100;
    }
    
    /**
     * Get summary of changes for audit logging
     */
    public function getChangeSummary(): string
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
        
        if ($field === 'default_commission_rate') {
            return sprintf('%s%%', number_format($value, 2));
        }
        
        if ($field === 'api_key' || $field === 'api_secret') {
            return '***' . substr((string)$value, -4); // Show last 4 chars for audit
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
            'name' => 'Name',
            'slug' => 'Slug',
            'description' => 'Description',
            'icon' => 'Icon',
            'color' => 'Color',
            'active' => 'Status',
            'domain' => 'Domain',
            'affiliate_program_url' => 'Affiliate Program URL',
            'default_commission_rate' => 'Default Commission Rate',
            'api_key' => 'API Key',
            'api_secret' => 'API Secret',
            'webhook_url' => 'Webhook URL',
        ];
        
        return $fieldMap[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Convert changed fields to database array
     */
    public function toDatabaseArray(): array
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
        
        if ($this->icon !== null) {
            $data['icon'] = $this->icon;
        }
        
        if ($this->color !== null) {
            $data['color'] = $this->color;
        }
        
        if ($this->active !== null) {
            $data['active'] = $this->active ? 1 : 0;
        }
        
        if ($this->domain !== null) {
            $data['domain'] = $this->domain;
        }
        
        if ($this->affiliateProgramUrl !== null) {
            $data['affiliate_program_url'] = $this->affiliateProgramUrl;
        }
        
        if ($this->defaultCommissionRate !== null) {
            $data['default_commission_rate'] = $this->defaultCommissionRate;
        }
        
        if ($this->apiKey !== null) {
            $data['api_key'] = $this->apiKey;
        }
        
        if ($this->apiSecret !== null) {
            $data['api_secret'] = $this->apiSecret;
        }
        
        if ($this->webhookUrl !== null) {
            $data['webhook_url'] = $this->webhookUrl;
        }
        
        // Handle API credentials clearance
        if ($this->clearApiCredentials) {
            $data['api_key'] = null;
            $data['api_secret'] = null;
        }
        
        return $data;
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        $data = [
            'marketplace_id' => $this->marketplaceId,
            'has_changes' => $this->hasChanges(),
            'update_scenario' => $this->getUpdateScenario(),
            'regenerate_slug' => $this->regenerateSlug,
            'clear_api_credentials' => $this->clearApiCredentials,
        ];
        
        if ($this->name !== null) {
            $data['name'] = $this->name;
        }
        
        if ($this->slug !== null) {
            $data['slug'] = $this->slug;
        }
        
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        
        if ($this->icon !== null) {
            $data['icon'] = $this->icon;
        }
        
        if ($this->color !== null) {
            $data['color'] = $this->color;
        }
        
        if ($this->active !== null) {
            $data['active'] = $this->active;
            $data['status_label'] = $this->active ? 'Active' : 'Inactive';
        }
        
        if ($this->domain !== null) {
            $data['domain'] = $this->domain;
            $data['has_domain'] = true;
        } else {
            $data['has_domain'] = false;
        }
        
        if ($this->affiliateProgramUrl !== null) {
            $data['affiliate_program_url'] = $this->affiliateProgramUrl;
            $data['has_affiliate_program'] = true;
        } else {
            $data['has_affiliate_program'] = false;
        }
        
        if ($this->defaultCommissionRate !== null) {
            $data['default_commission_rate'] = $this->defaultCommissionRate;
            $data['default_commission_rate_display'] = $this->getDefaultCommissionRateDisplay();
            $data['default_commission_rate_decimal'] = $this->getDefaultCommissionRateDecimal();
            $data['has_default_commission_rate'] = true;
        } else {
            $data['has_default_commission_rate'] = false;
        }
        
        if ($this->apiKey !== null) {
            $data['has_api_key'] = true;
        } else {
            $data['has_api_key'] = false;
        }
        
        if ($this->apiSecret !== null) {
            $data['has_api_secret'] = true;
        } else {
            $data['has_api_secret'] = false;
        }
        
        if ($this->webhookUrl !== null) {
            $data['webhook_url'] = $this->webhookUrl;
            $data['has_webhook_url'] = true;
        } else {
            $data['has_webhook_url'] = false;
        }
        
        if ($this->changedFields) {
            $data['changed_fields'] = $this->changedFields;
            $data['change_summary'] = $this->getChangeSummary();
            $data['is_status_changed'] = $this->isStatusChanged();
            $data['is_domain_changed'] = $this->isDomainChanged();
            $data['is_commission_rate_changed'] = $this->isCommissionRateChanged();
            $data['is_api_credentials_changed'] = $this->isApiCredentialsChanged();
        }
        
        return $data;
    }
    
    /**
     * Get a summary for logging/audit
     */
    public function toSummary(): array
    {
        return [
            'marketplace_id' => $this->marketplaceId,
            'has_changes' => $this->hasChanges(),
            'changed_fields_count' => count($this->changedFields),
            'is_status_changed' => $this->isStatusChanged(),
            'is_domain_changed' => $this->isDomainChanged(),
            'is_api_credentials_changed' => $this->isApiCredentialsChanged(),
            'clear_api_credentials' => $this->clearApiCredentials,
        ];
    }
}