<?php

namespace App\DTOs\Responses;

use App\Entities\Product;
use App\Enums\ImageSourceType;
use App\Enums\ProductStatus;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Product Response DTO
 *
 * Immutable DTO for product listing responses.
 * Supports public and admin modes with appropriate field visibility.
 * Includes comprehensive formatting and URL generation.
 *
 * @package App\DTOs\Responses
 */
class ProductResponse
{
    // Core product properties
    private int $id;
    private string $name;
    private string $slug;
    private ?string $description = null;
    private string $marketPrice;
    private int $viewCount = 0;
    private ?string $imageUrl = null;
    private ?string $imageSourceType = null;
    private string $status;
    private ?string $statusLabel = null;
    private ?string $statusColorClass = null;

    // Category information
    private ?int $categoryId = null;
    private ?string $categoryName = null;
    private ?string $categorySlug = null;
    private ?string $categoryIcon = null;

    // Timestamps
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $publishedAt = null;
    private ?string $verifiedAt = null;

    // Admin-only properties
    private ?int $verifiedBy = null;
    private ?string $lastPriceCheck = null;
    private ?string $lastLinkCheck = null;
    private ?string $deletedAt = null;
    private ?string $imagePath = null;

    // Configuration
    private bool $adminMode = false;
    private bool $includeTrashed = false;
    private string $baseImageUrl = '';
    private string $currency = 'Rp';
    private bool $formatPrices = true;

    // Cache for formatted values
    private ?array $formattedCache = null;

    /**
     * Private constructor for immutability
     */
    private function __construct()
    {
    }

    /**
     * Create ProductResponse from Product entity
     *
     * @param array $config Configuration options
     */
    public static function fromEntity(Product $product, array $config = []): self
    {
        $response = new self();
        $response->applyConfiguration($config);
        $response->populateFromEntity($product);

        return $response;
    }

    /**
     * Create ProductResponse from database array
     */
    public static function fromArray(array $data, array $config = []): self
    {
        $response = new self();
        $response->applyConfiguration($config);
        $response->populateFromArray($data);

        return $response;
    }

    /**
     * Create multiple ProductResponses from array of entities/arrays
     *
     * @param array $items Array of Product entities or arrays
     */
    public static function collection(array $items, array $config = []): array
    {
        $responses = [];

        foreach ($items as $item) {
            if ($item instanceof Product) {
                $responses[] = self::fromEntity($item, $config);
            } elseif (is_array($item)) {
                $responses[] = self::fromArray($item, $config);
            } else {
                throw new InvalidArgumentException(
                    'Items must be Product entities or arrays'
                );
            }
        }

        return $responses;
    }

    /**
     * Apply configuration options
     */
    protected function applyConfiguration(array $config): void
    {
        $this->adminMode = $config['admin_mode'] ?? false;
        $this->includeTrashed = $config['include_trashed'] ?? false;
        $this->baseImageUrl = rtrim($config['base_image_url'] ?? '', '/');
        $this->currency = $config['currency'] ?? 'Rp';
        $this->formatPrices = $config['format_prices'] ?? true;

        // Set category info if provided
        if (isset($config['category'])) {
            $this->categoryId = $config['category']['id'] ?? null;
            $this->categoryName = $config['category']['name'] ?? null;
            $this->categorySlug = $config['category']['slug'] ?? null;
            $this->categoryIcon = $config['category']['icon'] ?? null;
        }
    }

    /**
     * Populate data from Product entity
     */
    protected function populateFromEntity(Product $product): void
    {
        $this->id = $product->getId();
        $this->name = $product->getName();
        $this->slug = $product->getSlug();
        $this->description = $product->getDescription();
        $this->marketPrice = $product->getMarketPrice();
        $this->viewCount = $product->getViewCount();
        $this->categoryId = $product->getCategoryId();

        // Handle image based on source type
        $this->imageSourceType = $product->getImageSourceType()->value;
        $this->imageUrl = $this->resolveImageUrl($product);
        $this->imagePath = $product->getImagePath();

        // Status with labels
        $this->status = $product->getStatus()->value;
        $this->statusLabel = $product->getStatusLabel();
        $this->statusColorClass = $product->getStatusColorClass();

        // Timestamps
        $this->createdAt = $this->formatTimestamp($product->getCreatedAt());
        $this->updatedAt = $this->formatTimestamp($product->getUpdatedAt());
        $this->publishedAt = $this->formatTimestamp($product->getPublishedAt());
        $this->verifiedAt = $this->formatTimestamp($product->getVerifiedAt());

        // Admin properties
        if ($this->adminMode) {
            $this->verifiedBy = $product->getVerifiedBy();
            $this->lastPriceCheck = $this->formatTimestamp($product->getLastPriceCheck());
            $this->lastLinkCheck = $this->formatTimestamp($product->getLastLinkCheck());

            if ($this->includeTrashed) {
                $this->deletedAt = $this->formatTimestamp($product->getDeletedAt());
            }
        }
    }

    /**
     * Populate data from array
     */
    private function populateFromArray(array $data): void
    {
        $this->id = (int) ($data['id'] ?? 0);
        $this->name = (string) ($data['name'] ?? '');
        $this->slug = (string) ($data['slug'] ?? '');
        $this->description = $data['description'] ?? null;
        $this->marketPrice = (string) ($data['market_price'] ?? '0.00');
        $this->viewCount = (int) ($data['view_count'] ?? 0);
        $this->categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;

        // Image handling
        $this->imageSourceType = $data['image_source_type'] ?? ImageSourceType::URL->value;
        $this->imageUrl = $this->resolveImageUrlFromArray($data);
        $this->imagePath = $data['image_path'] ?? null;

        // Status
        $this->status = $data['status'] ?? ProductStatus::DRAFT->value;

        // Try to get status info from ProductStatus enum if available
        try {
            $statusEnum = ProductStatus::from($this->status);
            $this->statusLabel = $statusEnum->label();
            $this->statusColorClass = $statusEnum->colorClass();
        } catch (\ValueError $e) {
            $this->statusLabel = ucfirst(str_replace('_', ' ', $this->status));
            $this->statusColorClass = 'secondary';
        }

        // Timestamps
        $this->createdAt = $this->formatTimestampFromString($data['created_at'] ?? null);
        $this->updatedAt = $this->formatTimestampFromString($data['updated_at'] ?? null);
        $this->publishedAt = $this->formatTimestampFromString($data['published_at'] ?? null);
        $this->verifiedAt = $this->formatTimestampFromString($data['verified_at'] ?? null);

        // Admin properties
        if ($this->adminMode) {
            $this->verifiedBy = isset($data['verified_by']) ? (int) $data['verified_by'] : null;
            $this->lastPriceCheck = $this->formatTimestampFromString($data['last_price_check'] ?? null);
            $this->lastLinkCheck = $this->formatTimestampFromString($data['last_link_check'] ?? null);

            if ($this->includeTrashed) {
                $this->deletedAt = $this->formatTimestampFromString($data['deleted_at'] ?? null);
            }
        }

        // Category info from array if provided
        if (isset($data['category'])) {
            $category = is_array($data['category']) ? $data['category'] : null;
            $this->categoryId = $category['id'] ?? $this->categoryId;
            $this->categoryName = $category['name'] ?? null;
            $this->categorySlug = $category['slug'] ?? null;
            $this->categoryIcon = $category['icon'] ?? null;
        }
    }

    /**
     * Resolve image URL from Product entity
     */
    private function resolveImageUrl(Product $product): ?string
    {
        $imageSourceType = $product->getImageSourceType();

        if ($imageSourceType === ImageSourceType::URL) {
            return $product->getImage();
        }
        $imagePath = $product->getImagePath();
        if ($imagePath && $this->baseImageUrl) {
            return $this->baseImageUrl . '/' . ltrim($imagePath, '/');
        }
        return $imagePath;
    }

    /**
     * Resolve image URL from array data
     */
    private function resolveImageUrlFromArray(array $data): ?string
    {
        $imageSourceType = $data['image_source_type'] ?? ImageSourceType::URL->value;

        if ($imageSourceType === ImageSourceType::URL->value) {
            return $data['image'] ?? null;
        }

        if ($imageSourceType === ImageSourceType::UPLOAD->value) {
            $imagePath = $data['image_path'] ?? null;
            if ($imagePath && $this->baseImageUrl) {
                return $this->baseImageUrl . '/' . ltrim((string) $imagePath, '/');
            }
            return $imagePath;
        }

        return null;
    }

    /**
     * Format DateTimeImmutable timestamp
     */
    private function formatTimestamp(?DateTimeImmutable $timestamp): ?string
    {
        if (!$timestamp instanceof \DateTimeImmutable) {
            return null;
        }

        return $timestamp->format('Y-m-d H:i:s');
    }

    /**
     * Format timestamp from string
     */
    private function formatTimestampFromString(?string $timestamp): ?string
    {
        if ($timestamp === null || trim($timestamp) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($timestamp);
            return $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $timestamp;
        }
    }

    /**
     * Get formatted market price
     */
    public function getFormattedMarketPrice(): string
    {
        if (!$this->formatPrices) {
            return $this->marketPrice;
        }

        $price = (float) $this->marketPrice;

        if ($price == 0) {
            return $this->currency . ' 0';
        }

        // Format with thousands separators
        $formatted = number_format($price, 0, ',', '.');
        return $this->currency . ' ' . $formatted;
    }

    /**
     * Get numeric market price
     */
    public function getNumericMarketPrice(): float
    {
        return (float) $this->marketPrice;
    }

    /**
     * Get view count formatted with thousands separator
     */
    public function getFormattedViewCount(): string
    {
        return number_format($this->viewCount, 0, ',', '.');
    }

    /**
     * Check if product is published
     */
    public function isPublished(): bool
    {
        return $this->status === ProductStatus::PUBLISHED->value;
    }

    /**
     * Check if product has image
     */
    public function hasImage(): bool
    {
        return !in_array($this->imageUrl, [null, '', '0'], true);
    }

    /**
     * Check if product has category
     */
    public function hasCategory(): bool
    {
        return $this->categoryId !== null && $this->categoryId > 0;
    }

    /**
     * Get category info as array
     */
    public function getCategoryInfo(): ?array
    {
        if (!$this->hasCategory()) {
            return null;
        }

        return [
            'id' => $this->categoryId,
            'name' => $this->categoryName,
            'slug' => $this->categorySlug,
            'icon' => $this->categoryIcon,
        ];
    }

    /**
     * Get status info as array
     */
    public function getStatusInfo(): array
    {
        return [
            'value' => $this->status,
            'label' => $this->statusLabel,
            'color_class' => $this->statusColorClass,
            'is_published' => $this->isPublished(),
            'is_draft' => $this->status === ProductStatus::DRAFT->value,
            'is_verified' => $this->status === ProductStatus::VERIFIED->value,
            'is_archived' => $this->status === ProductStatus::ARCHIVED->value,
        ];
    }

    /**
     * Get image info as array
     */
    public function getImageInfo(): array
    {
        return [
            'url' => $this->imageUrl,
            'source_type' => $this->imageSourceType,
            'path' => $this->imagePath,
            'has_image' => $this->hasImage(),
            'is_external' => $this->imageSourceType === ImageSourceType::URL->value,
            'is_uploaded' => $this->imageSourceType === ImageSourceType::UPLOAD->value,
        ];
    }

    /**
     * Convert to array for public API response
     */
    public function toPublicArray(): array
    {
        if ($this->formattedCache === null) {
            $this->formattedCache = [
                'id' => $this->id,
                'name' => $this->name,
                'slug' => $this->slug,
                'image' => $this->imageUrl,
                'market_price' => [
                    'raw' => $this->marketPrice,
                    'formatted' => $this->getFormattedMarketPrice(),
                    'numeric' => $this->getNumericMarketPrice(),
                ],
                'status' => $this->getStatusInfo(),
                'view_count' => [
                    'raw' => $this->viewCount,
                    'formatted' => $this->getFormattedViewCount(),
                ],
                'has_category' => $this->hasCategory(),
                'has_image' => $this->hasImage(),
                'is_published' => $this->isPublished(),
                'published_at' => $this->publishedAt,
                'created_at' => $this->createdAt,
            ];

            // Add category if available
            if ($this->hasCategory() && $this->categoryName) {
                $this->formattedCache['category'] = $this->getCategoryInfo();
            }

            // Add image info
            $this->formattedCache['image_info'] = $this->getImageInfo();
        }

        return $this->formattedCache;
    }

    /**
     * Convert to array for admin API response
     */
    public function toAdminArray(): array
    {
        $data = $this->toPublicArray();

        // Add admin-only fields
        $data['description'] = $this->description;
        $data['category_id'] = $this->categoryId;
        $data['image_source_type'] = $this->imageSourceType;
        $data['image_path'] = $this->imagePath;
        $data['verified_by'] = $this->verifiedBy;
        $data['verified_at'] = $this->verifiedAt;
        $data['last_price_check'] = $this->lastPriceCheck;
        $data['last_link_check'] = $this->lastLinkCheck;
        $data['updated_at'] = $this->updatedAt;

        if ($this->includeTrashed) {
            $data['deleted_at'] = $this->deletedAt;
            $data['is_deleted'] = !in_array($this->deletedAt, [null, '', '0'], true);
        }

        // Add timestamps info
        $data['timestamps'] = [
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'published_at' => $this->publishedAt,
            'verified_at' => $this->verifiedAt,
        ];

        return $data;
    }

    /**
     * Convert to array based on mode
     */
    public function toArray(): array
    {
        return $this->adminMode ? $this->toAdminArray() : $this->toPublicArray();
    }

    /**
     * Get cache key for this response
     */
    public function getCacheKey(string $prefix = 'product_response_'): string
    {
        $components = [
            'id' => $this->id,
            'slug' => $this->slug,
            'admin_mode' => $this->adminMode ? '1' : '0',
            'trashed' => $this->includeTrashed ? '1' : '0',
            'image_base' => md5($this->baseImageUrl),
        ];

        return $prefix . md5(serialize($components));
    }

    /**
     * Get response summary for logging
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status,
            'price' => $this->getFormattedMarketPrice(),
            'has_image' => $this->hasImage(),
            'has_category' => $this->hasCategory(),
            'view_count' => $this->viewCount,
            'admin_mode' => $this->adminMode,
        ];
    }

    // Getters for all properties

    public function getId(): int
    {
        return $this->id;
    }
    public function getName(): string
    {
        return $this->name;
    }
    public function getSlug(): string
    {
        return $this->slug;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function getMarketPrice(): string
    {
        return $this->marketPrice;
    }
    public function getViewCount(): int
    {
        return $this->viewCount;
    }
    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }
    public function getImageSourceType(): ?string
    {
        return $this->imageSourceType;
    }
    public function getStatus(): string
    {
        return $this->status;
    }
    public function getStatusLabel(): ?string
    {
        return $this->statusLabel;
    }
    public function getStatusColorClass(): ?string
    {
        return $this->statusColorClass;
    }
    public function getCategoryId(): ?int
    {
        return $this->categoryId;
    }
    public function getCategoryName(): ?string
    {
        return $this->categoryName;
    }
    public function getCategorySlug(): ?string
    {
        return $this->categorySlug;
    }
    public function getCategoryIcon(): ?string
    {
        return $this->categoryIcon;
    }
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }
    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }
    public function getVerifiedAt(): ?string
    {
        return $this->verifiedAt;
    }
    public function getVerifiedBy(): ?int
    {
        return $this->verifiedBy;
    }
    public function getLastPriceCheck(): ?string
    {
        return $this->lastPriceCheck;
    }
    public function getLastLinkCheck(): ?string
    {
        return $this->lastLinkCheck;
    }
    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }
    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }
    public function isAdminMode(): bool
    {
        return $this->adminMode;
    }
    public function getBaseImageUrl(): string
    {
        return $this->baseImageUrl;
    }
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Create a copy with modified configuration
     */
    public function withConfig(array $config): self
    {
        $clone = clone $this;
        $clone->applyConfiguration($config);
        $clone->formattedCache = null; // Clear cache

        return $clone;
    }

    /**
     * Create a copy with category info
     */
    public function withCategory(array $category): self
    {
        $clone = clone $this;
        $clone->categoryId = $category['id'] ?? null;
        $clone->categoryName = $category['name'] ?? null;
        $clone->categorySlug = $category['slug'] ?? null;
        $clone->categoryIcon = $category['icon'] ?? null;
        $clone->formattedCache = null;

        return $clone;
    }

    /**
     * Create JSON string representation
     */
    public function toJson(bool $pretty = false): string
    {
        $options = $pretty ? JSON_PRETTY_PRINT : 0;
        return json_encode($this->toArray(), $options);
    }
}
