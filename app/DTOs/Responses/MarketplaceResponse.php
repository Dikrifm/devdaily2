<?php

namespace App\DTOs\Responses;

use App\Entities\Marketplace;
use DateTimeImmutable;
use InvalidArgumentException;

class MarketplaceResponse
{
    private int $id;
    private string $name;
    private string $slug;
    private ?string $description = null;
    private ?string $icon = null;
    private string $color;
    private bool $active;
    private ?string $domain = null;
    private ?string $affiliateProgramUrl = null;
    private ?float $defaultCommissionRate = null;
    private ?string $apiKey = null;
    private ?string $apiSecret = null;
    private ?string $webhookUrl = null;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $deletedAt = null;
    
    // Optional fields (only included when requested)
    private ?int $linksCount = null;
    private ?int $activeLinksCount = null;
    private ?int $productsCount = null;
    private ?array $statistics = null;
    private ?array $configuration = null;
    private ?array $topProducts = null;
    private ?array $recentActivity = null;
    private ?string $healthStatus = null;
    
    // Calculated fields
    private ?string $statusLabel = null;
    private ?string $statusColorClass = null;
    private ?string $iconHtml = null;
    private ?string $colorStyle = null;
    private ?string $cssClasses = null;
    private ?string $commissionRateDisplay = null;
    
    private bool $includeSensitive = false;
    private bool $includeDetails = false;
    private bool $includeConfiguration = false;
    private string $dateFormat = 'Y-m-d H:i:s';
    
    private function __construct()
    {
        // Private constructor to enforce use of factory methods
    }
    
    public static function fromEntity(Marketplace $marketplace, array $options = []): self
    {
        $response = new self();
        $response->applyConfiguration($options);
        $response->populateFromEntity($marketplace);
        return $response;
    }
    
    public static function fromArray(array $data, array $options = []): self
    {
        $response = new self();
        $response->applyConfiguration($options);
        $response->populateFromArray($data);
        return $response;
    }
    
    public static function collection(array $marketplaces, array $options = []): array
    {
        $collection = [];
        foreach ($marketplaces as $marketplace) {
            if ($marketplace instanceof Marketplace) {
                $collection[] = self::fromEntity($marketplace, $options);
            } elseif (is_array($marketplace)) {
                $collection[] = self::fromArray($marketplace, $options);
            } else {
                throw new InvalidArgumentException(
                    'Each item must be an instance of Marketplace or an array'
                );
            }
        }
        return $collection;
    }
    
    private function applyConfiguration(array $config): void
    {
        $this->includeSensitive = $config['include_sensitive'] ?? false;
        $this->includeDetails = $config['include_details'] ?? false;
        $this->includeConfiguration = $config['include_configuration'] ?? false;
        $this->dateFormat = $config['date_format'] ?? 'Y-m-d H:i:s';
        
        // If including configuration, also include details
        if ($this->includeConfiguration) {
            $this->includeDetails = true;
        }
    }
    
    private function populateFromEntity(Marketplace $marketplace): void
    {
        $this->id = $marketplace->getId();
        $this->name = $marketplace->getName();
        $this->slug = $marketplace->getSlug();
        $this->icon = $marketplace->getIcon();
        $this->color = $marketplace->getColor();
        $this->active = $marketplace->isActive();
        
        // Format timestamps
        $this->createdAt = $this->formatTimestamp($marketplace->getCreatedAt());
        $this->updatedAt = $this->formatTimestamp($marketplace->getUpdatedAt());
        $this->deletedAt = $this->formatTimestamp($marketplace->getDeletedAt());
        
        // Calculated fields from entity methods
        $this->cssClasses = $marketplace->getCssClasses();
        $this->colorStyle = $marketplace->getColorStyle();
        $this->iconHtml = $marketplace->hasIcon() ? 
            '<i class="' . $this->icon . '"></i>' : null;
        
        // Status information
        $this->statusLabel = $marketplace->isActive() ? 'Active' : 'Inactive';
        $this->statusColorClass = $marketplace->isActive() ? 
            'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
        
        // NOTE: Marketplace entity currently doesn't have these fields
        // They might be stored in a separate configuration table
        // We'll leave them as null for now - they can be populated via withAdditionalData()
    }
    
    private function populateFromArray(array $data): void
    {
        $this->id = (int) ($data['id'] ?? 0);
        $this->name = (string) ($data['name'] ?? '');
        $this->slug = (string) ($data['slug'] ?? '');
        $this->description = isset($data['description']) ? (string) $data['description'] : null;
        $this->icon = isset($data['icon']) ? (string) $data['icon'] : null;
        $this->color = (string) ($data['color'] ?? '#64748b');
        $this->active = (bool) ($data['active'] ?? true);
        $this->domain = isset($data['domain']) ? (string) $data['domain'] : null;
        $this->affiliateProgramUrl = isset($data['affiliate_program_url']) ? 
            (string) $data['affiliate_program_url'] : null;
        $this->defaultCommissionRate = isset($data['default_commission_rate']) ? 
            (float) $data['default_commission_rate'] : null;
        $this->apiKey = isset($data['api_key']) ? (string) $data['api_key'] : null;
        $this->apiSecret = isset($data['api_secret']) ? (string) $data['api_secret'] : null;
        $this->webhookUrl = isset($data['webhook_url']) ? (string) $data['webhook_url'] : null;
        
        // Format timestamps
        $this->createdAt = $this->formatTimestampFromString($data['created_at'] ?? null);
        $this->updatedAt = $this->formatTimestampFromString($data['updated_at'] ?? null);
        $this->deletedAt = $this->formatTimestampFromString($data['deleted_at'] ?? null);
        
        // Optional fields from data
        if (isset($data['links_count'])) {
            $this->linksCount = (int) $data['links_count'];
        }
        if (isset($data['active_links_count'])) {
            $this->activeLinksCount = (int) $data['active_links_count'];
        }
        if (isset($data['products_count'])) {
            $this->productsCount = (int) $data['products_count'];
        }
        if (isset($data['statistics'])) {
            $this->statistics = is_array($data['statistics']) ? $data['statistics'] : null;
        }
        if (isset($data['configuration'])) {
            $this->configuration = is_array($data['configuration']) ? $data['configuration'] : null;
        }
        if (isset($data['top_products'])) {
            $this->topProducts = is_array($data['top_products']) ? $data['top_products'] : null;
        }
        if (isset($data['recent_activity'])) {
            $this->recentActivity = is_array($data['recent_activity']) ? $data['recent_activity'] : null;
        }
        if (isset($data['health_status'])) {
            $this->healthStatus = (string) $data['health_status'];
        }
        
        // Calculate fields if not provided
        if (empty($this->cssClasses)) {
            $this->cssClasses = $this->generateCssClasses($this->color);
        }
        
        if (empty($this->colorStyle)) {
            $this->colorStyle = "color: {$this->color};";
        }
        
        if (empty($this->iconHtml) && $this->icon) {
            $this->iconHtml = '<i class="' . $this->icon . '"></i>';
        }
        
        if (empty($this->statusLabel)) {
            $this->statusLabel = $this->active ? 'Active' : 'Inactive';
        }
        
        if (empty($this->statusColorClass)) {
            $this->statusColorClass = $this->active ? 
                'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
        }
        
        if ($this->defaultCommissionRate !== null && empty($this->commissionRateDisplay)) {
            $this->commissionRateDisplay = ($this->defaultCommissionRate * 100) . '%';
        }
    }
    
    private function formatTimestamp(?DateTimeImmutable $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        return $timestamp->format($this->dateFormat);
    }
    
    private function formatTimestampFromString(?string $timestamp): ?string
    {
        if (!$timestamp) {
            return null;
        }
        
        try {
            $dateTime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp);
            if ($dateTime) {
                return $dateTime->format($this->dateFormat);
            }
        } catch (\Exception $e) {
            // If format is different, try generic parsing
            try {
                $dateTime = new DateTimeImmutable($timestamp);
                return $dateTime->format($this->dateFormat);
            } catch (\Exception $e) {
                return $timestamp;
            }
        }
        
        return $timestamp;
    }
    
    private function generateCssClasses(string $color): string
    {
        // Map color to Tailwind classes
        $colorMap = [
            '#64748b' => 'bg-slate-500 text-white',
            '#3b82f6' => 'bg-blue-500 text-white',
            '#10b981' => 'bg-emerald-500 text-white',
            '#f59e0b' => 'bg-amber-500 text-white',
            '#ef4444' => 'bg-red-500 text-white',
            '#8b5cf6' => 'bg-violet-500 text-white',
            '#ec4899' => 'bg-pink-500 text-white',
        ];
        
        return $colorMap[$color] ?? 'bg-gray-500 text-white';
    }
    
    public function hasAffiliateProgram(): bool
    {
        return !empty($this->affiliateProgramUrl);
    }
    
    public function hasApiConfiguration(): bool
    {
        return !empty($this->apiKey) || !empty($this->apiSecret) || !empty($this->webhookUrl);
    }
    
    public function hasDefaultCommissionRate(): bool
    {
        return $this->defaultCommissionRate !== null;
    }
    
    public function getDefaultCommissionRateDecimal(): ?float
    {
        return $this->defaultCommissionRate;
    }
    
    public function getDefaultCommissionRatePercentage(): ?string
    {
        if ($this->defaultCommissionRate === null) {
            return null;
        }
        return number_format($this->defaultCommissionRate * 100, 2) . '%';
    }
    
    public function isPopular(int $threshold = 100): bool
    {
        return ($this->activeLinksCount ?? 0) >= $threshold;
    }
    
    public function toPublicArray(): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'color' => $this->color,
            'active' => $this->active,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'status_label' => $this->statusLabel,
            'status_color_class' => $this->statusColorClass,
            'icon_html' => $this->iconHtml,
            'color_style' => $this->colorStyle,
            'css_classes' => $this->cssClasses,
        ];
        
        // Add optional public fields
        if ($this->description !== null) {
            $data['description'] = $this->description;
        }
        
        if ($this->domain !== null) {
            $data['domain'] = $this->domain;
        }
        
        if ($this->affiliateProgramUrl !== null) {
            $data['affiliate_program_url'] = $this->affiliateProgramUrl;
        }
        
        if ($this->defaultCommissionRate !== null) {
            $data['default_commission_rate'] = $this->defaultCommissionRate;
            $data['commission_rate_display'] = $this->getDefaultCommissionRatePercentage();
        }
        
        return $data;
    }
    
    public function toAdminArray(): array
    {
        $data = $this->toPublicArray();
        
        // Add admin-only fields
        if ($this->includeSensitive) {
            if ($this->apiKey !== null) {
                $data['api_key'] = $this->apiKey;
            }
            if ($this->apiSecret !== null) {
                $data['api_secret'] = $this->apiSecret;
            }
            if ($this->webhookUrl !== null) {
                $data['webhook_url'] = $this->webhookUrl;
            }
        }
        
        // Add detail fields
        if ($this->includeDetails) {
            $data['deleted_at'] = $this->deletedAt;
            $data['links_count'] = $this->linksCount;
            $data['active_links_count'] = $this->activeLinksCount;
            $data['products_count'] = $this->productsCount;
            $data['has_affiliate_program'] = $this->hasAffiliateProgram();
            $data['has_api_configuration'] = $this->hasApiConfiguration();
            $data['is_popular'] = $this->isPopular();
            
            if ($this->statistics !== null) {
                $data['statistics'] = $this->statistics;
            }
            
            if ($this->topProducts !== null) {
                $data['top_products'] = $this->topProducts;
            }
            
            if ($this->recentActivity !== null) {
                $data['recent_activity'] = $this->recentActivity;
            }
            
            if ($this->healthStatus !== null) {
                $data['health_status'] = $this->healthStatus;
            }
        }
        
        // Add configuration if requested
        if ($this->includeConfiguration && $this->configuration !== null) {
            $data['configuration'] = $this->configuration;
        }
        
        return $data;
    }
    
    public function toArray(): array
    {
        return $this->toAdminArray();
    }
    
    public function getCacheKey(string $prefix = 'marketplace_response_'): string
    {
        $parts = [
            $prefix,
            $this->id,
            $this->includeSensitive ? 'sensitive' : 'public',
            $this->includeDetails ? 'detailed' : 'basic',
            $this->includeConfiguration ? 'config' : 'noconfig',
            substr(md5($this->updatedAt ?? ''), 0, 8),
        ];
        
        return implode('_', array_filter($parts));
    }
    
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'active' => $this->active,
            'icon' => $this->icon,
            'color' => $this->color,
            'links_count' => $this->linksCount ?? 0,
            'has_affiliate_program' => $this->hasAffiliateProgram(),
        ];
    }
    
    // Getters for individual properties
    public function getId(): int { return $this->id; }
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
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function getDeletedAt(): ?string { return $this->deletedAt; }
    public function getLinksCount(): ?int { return $this->linksCount; }
    public function getActiveLinksCount(): ?int { return $this->activeLinksCount; }
    public function getProductsCount(): ?int { return $this->productsCount; }
    public function getStatistics(): ?array { return $this->statistics; }
    public function getConfiguration(): ?array { return $this->configuration; }
    public function getTopProducts(): ?array { return $this->topProducts; }
    public function getRecentActivity(): ?array { return $this->recentActivity; }
    public function getHealthStatus(): ?string { return $this->healthStatus; }
    public function getStatusLabel(): ?string { return $this->statusLabel; }
    public function getStatusColorClass(): ?string { return $this->statusColorClass; }
    public function getIconHtml(): ?string { return $this->iconHtml; }
    public function getColorStyle(): ?string { return $this->colorStyle; }
    public function getCssClasses(): ?string { return $this->cssClasses; }
    public function getCommissionRateDisplay(): ?string { return $this->commissionRateDisplay; }
    
    public function withConfig(array $config): self
    {
        $clone = clone $this;
        $clone->applyConfiguration($config);
        return $clone;
    }
    
    public function withAdditionalData(array $additionalData): self
    {
        $clone = clone $this;
        
        foreach ($additionalData as $key => $value) {
            if (property_exists($clone, $key)) {
                $clone->$key = $value;
            }
        }
        
        // Recalculate derived fields if needed
        if (isset($additionalData['default_commission_rate'])) {
            $clone->commissionRateDisplay = ($clone->defaultCommissionRate * 100) . '%';
        }
        
        return $clone;
    }
    
    public function toJson(bool $pretty = false): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }
        
        return json_encode($this->toArray(), $options);
    }
}