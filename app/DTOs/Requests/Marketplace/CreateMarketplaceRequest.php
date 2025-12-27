<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Marketplace;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

/**
 * DTO for creating a new marketplace
 * 
 * @package DevDaily\DTOs\Requests\Marketplace
 */
final class CreateMarketplaceRequest extends BaseDTO
{
    private string $name;
    private string $slug;
    private ?string $description = null;
    private ?string $icon = null;
    private string $color = '#64748b'; // Default slate-500
    private bool $active = true;
    private ?string $domain = null;
    private ?string $affiliateProgramUrl = null;
    private ?float $defaultCommissionRate = null;
    private ?string $apiKey = null;
    private ?string $apiSecret = null;
    private ?string $webhookUrl = null;
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
        $requiredFields = ['name'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || trim($data[$field]) === '') {
                $errors[$field] = "Field {$field} is required";
            }
        }
        
        // Hydrate with validation
        if (empty($errors)) {
            $this->name = $this->sanitizeString($data['name'] ?? '');
            $this->slug = $this->generateSlug($data);
            $this->description = isset($data['description']) ? $this->sanitizeString($data['description']) : null;
            $this->icon = isset($data['icon']) ? $this->sanitizeString($data['icon']) : null;
            $this->color = isset($data['color']) ? $this->validateColor($data['color'], $errors) : '#64748b';
            $this->active = isset($data['active']) ? filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) : true;
            $this->domain = isset($data['domain']) ? $this->validateDomain($data['domain'], $errors) : null;
            $this->affiliateProgramUrl = isset($data['affiliate_program_url']) ? $this->validateUrl($data['affiliate_program_url'], 'affiliate_program_url', $errors) : null;
            $this->defaultCommissionRate = isset($data['default_commission_rate']) ? $this->validateCommissionRate($data['default_commission_rate'], $errors) : null;
            $this->apiKey = isset($data['api_key']) ? $this->sanitizeString($data['api_key']) : null;
            $this->apiSecret = isset($data['api_secret']) ? $this->sanitizeString($data['api_secret']) : null;
            $this->webhookUrl = isset($data['webhook_url']) ? $this->validateUrl($data['webhook_url'], 'webhook_url', $errors) : null;
        }
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw new ValidationException('Marketplace creation validation failed', $errors);
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
            ['entityType' => SlugValidator::ENTITY_MARKETPLACE]
        );
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
        if (strlen($this->name) > 100) {
            $errors['name'] = 'Marketplace name cannot exceed 100 characters';
        }
        
        // Description length validation
        if ($this->description && strlen($this->description) > 1000) {
            $errors['description'] = 'Description cannot exceed 1000 characters';
        }
        
        // Icon length validation
        if ($this->icon && strlen($this->icon) > 50) {
            $errors['icon'] = 'Icon cannot exceed 50 characters';
        }
        
        // API key length validation
        if ($this->apiKey && strlen($this->apiKey) > 255) {
            $errors['api_key'] = 'API key cannot exceed 255 characters';
        }
        
        // API secret length validation
        if ($this->apiSecret && strlen($this->apiSecret) > 255) {
            $errors['api_secret'] = 'API secret cannot exceed 255 characters';
        }
        
        // Validate icon format (basic Font Awesome check)
        if ($this->icon && !preg_match('/^(fas|far|fal|fab|fad) fa-[a-z0-9-]+$|^[a-z0-9-]+$/i', $this->icon)) {
            // Accept Font Awesome or simple icon names
            $errors['icon'] = 'Icon must be a Font Awesome class or simple icon name';
        }
        
        // Validate domain is not a URL
        if ($this->domain && filter_var($this->domain, FILTER_VALIDATE_URL)) {
            $errors['domain'] = 'Domain should not include protocol (use example.com, not https://example.com)';
        }
    }

    /**
     * Get validation rules for use in controllers
     */
    public static function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
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
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'name' => [
                'required' => 'Marketplace name is required',
                'max' => 'Marketplace name cannot exceed 100 characters',
            ],
            'color' => [
                'regex_match' => 'Color must be a valid hex code (e.g., #336699 or #369)',
            ],
            'icon' => [
                'regex_match' => 'Icon must be a Font Awesome class (e.g., fas fa-store) or simple icon name',
            ],
            'domain' => [
                'regex_match' => 'Invalid domain format. Use: example.com',
            ],
            'default_commission_rate' => [
                'numeric' => 'Commission rate must be a valid number',
                'greater_than_equal_to' => 'Commission rate cannot be negative',
                'less_than_equal_to' => 'Commission rate cannot exceed 100%',
            ],
        ];
    }

    // Getters
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getDescription(): ?string { return $this->description; }
    public function getIcon(): ?string { return $this->icon; }
    public function getColor(): string { return $this->color; }
    public function isActive(): bool { return $this->active; }
    public function getDomain(): ?string { return $this->domain; }
    public function getAffiliateProgramUrl(): ?string { return $this->affiliateProgramUrl; }
    public function getDefaultCommissionRate(): ?float { return $this->defaultCommissionRate; }
    public function getApiKey(): ?string { return $this->apiKey; }
    public function getApiSecret(): ?string { return $this->apiSecret; }
    public function getWebhookUrl(): ?string { return $this->webhookUrl; }
    public function getCreatedBy(): ?int { return $this->createdBy; }

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
     * Convert to database array
     */
    public function toDatabaseArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'active' => $this->active ? 1 : 0,
            'domain' => $this->domain,
            'affiliate_program_url' => $this->affiliateProgramUrl,
            'default_commission_rate' => $this->defaultCommissionRate,
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'webhook_url' => $this->webhookUrl,
        ];
    }

    /**
     * Convert to array (for API response)
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'icon' => $this->icon,
            'color' => $this->color,
            'active' => $this->active,
            'has_domain' => $this->hasDomain(),
            'has_affiliate_program' => $this->hasAffiliateProgram(),
            'has_api_credentials' => $this->hasApiCredentials(),
            'has_webhook_url' => $this->hasWebhookUrl(),
            'has_default_commission_rate' => $this->hasDefaultCommissionRate(),
        ];
        
        if ($this->domain !== null) {
            $data['domain'] = $this->domain;
        }
        
        if ($this->affiliateProgramUrl !== null) {
            $data['affiliate_program_url'] = $this->affiliateProgramUrl;
        }
        
        if ($this->defaultCommissionRate !== null) {
            $data['default_commission_rate'] = $this->defaultCommissionRate;
            $data['default_commission_rate_display'] = $this->getDefaultCommissionRateDisplay();
            $data['default_commission_rate_decimal'] = $this->getDefaultCommissionRateDecimal();
        }
        
        if ($this->apiKey !== null) {
            $data['has_api_key'] = true;
        }
        
        if ($this->webhookUrl !== null) {
            $data['webhook_url'] = $this->webhookUrl;
        }
        
        return $data;
    }

    /**
     * Get a summary for logging/audit
     */
    public function toSummary(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'active' => $this->active,
            'has_affiliate_program' => $this->hasAffiliateProgram(),
            'has_api_credentials' => $this->hasApiCredentials(),
        ];
    }
}