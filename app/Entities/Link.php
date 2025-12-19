<?php

namespace App\Entities;

use DateTimeImmutable;

/**
 * Link Entity with Smart Commission Logic
 *
 * Implements "Transient Input, Persistent Revenue" strategy
 * Commission rate (percentage) is transient, only revenue (Rupiah) is persisted
 *
 * @package App\Entities
 */
class Link extends BaseEntity
{
    public $created_at;
    public $updated_at;
    public $deleted_at;
    private int $product_id;
    private int $marketplace_id;
    private string $store_name;
    private string $price = '0.00';
    private ?string $url = null;
    private string $rating = '0.00';
    private bool $active = true;
    private int $sold_count = 0;
    private int $clicks = 0;
    private ?DateTimeImmutable $last_price_update = null;
    private ?DateTimeImmutable $last_validation = null;
    private string $affiliate_revenue = '0.00';
    private ?int $marketplace_badge_id = null;

    /**
     * Default commission rate (2%) as global fallback
     * Used when admin doesn't provide custom rate
     *
     * @const float
     */
    public const DEFAULT_COMMISSION_RATE = 0.02; // 2%
    /**
     * Constructor
     */
    public function __construct(int $product_id, int $marketplace_id, string $store_name)
    {
        $this->product_id = $product_id;
        $this->marketplace_id = $marketplace_id;
        $this->store_name = $store_name;
    }

    // ============================================
    // GETTER METHODS (EXISTING - KEEP AS IS)
    // ============================================

    public function getProductId(): int
    {
        return $this->product_id;
    }

    public function getMarketplaceId(): int
    {
        return $this->marketplace_id;
    }

    public function getStoreName(): string
    {
        return $this->store_name;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getRating(): string
    {
        return $this->rating;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getSoldCount(): int
    {
        return $this->sold_count;
    }

    public function getClicks(): int
    {
        return $this->clicks;
    }

    public function getLastPriceUpdate(): ?DateTimeImmutable
    {
        return $this->last_price_update;
    }

    public function getLastValidation(): ?DateTimeImmutable
    {
        return $this->last_validation;
    }

    public function getAffiliateRevenue(): string
    {
        return $this->affiliate_revenue;
    }

    public function getMarketplaceBadgeId(): ?int
    {
        return $this->marketplace_badge_id;
    }

    // ============================================
    // SETTER METHODS (EXISTING - KEEP AS IS)
    // ============================================

    public function setProductId(int $product_id): self
    {
        $this->product_id = $product_id;
        return $this;
    }

    public function setMarketplaceId(int $marketplace_id): self
    {
        $this->marketplace_id = $marketplace_id;
        return $this;
    }

    public function setStoreName(string $store_name): self
    {
        $this->store_name = $store_name;
        return $this;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;
        return $this;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function setRating(string $rating): self
    {
        $this->rating = $rating;
        return $this;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    public function setSoldCount(int $sold_count): self
    {
        $this->sold_count = $sold_count;
        return $this;
    }

    public function setClicks(int $clicks): self
    {
        $this->clicks = $clicks;
        return $this;
    }

    public function setLastPriceUpdate(?DateTimeImmutable $last_price_update): self
    {
        $this->last_price_update = $last_price_update;
        return $this;
    }

    public function setLastValidation(?DateTimeImmutable $last_validation): self
    {
        $this->last_validation = $last_validation;
        return $this;
    }

    /**
     * Set affiliate revenue (Rupiah)
     * Only Rupiah value is persisted, not percentage
     */
    public function setAffiliateRevenue(string $affiliate_revenue): self
    {
        $this->affiliate_revenue = $affiliate_revenue;
        return $this;
    }

    public function setMarketplaceBadgeId(?int $marketplace_badge_id): self
    {
        $this->marketplace_badge_id = $marketplace_badge_id;
        return $this;
    }

    // ============================================
    // BUSINESS LOGIC METHODS (EXISTING - KEEP AS IS)
    // ============================================

    public function activate(): self
    {
        $this->active = true;
        return $this;
    }

    public function deactivate(): self
    {
        $this->active = false;
        return $this;
    }

    public function incrementClicks(): self
    {
        $this->clicks++;
        return $this;
    }

    public function incrementSoldCount(int $increment = 1): self
    {
        $this->sold_count += $increment;
        return $this;
    }

    public function addAffiliateRevenue(string $amount): self
    {
        $current = (float) $this->affiliate_revenue;
        $add = (float) $amount;
        $this->affiliate_revenue = number_format($current + $add, 2, '.', '');
        return $this;
    }

    public function updatePrice(string $newPrice, bool $autoUpdateTimestamp = true): self
    {
        $this->price = $newPrice;
        if ($autoUpdateTimestamp) {
            $this->last_price_update = new DateTimeImmutable();
        }
        return $this;
    }

    public function markAsValidated(): self
    {
        $this->last_validation = new DateTimeImmutable();
        return $this;
    }

    public function markAsInvalid(): self
    {
        $this->last_validation = null;
        return $this;
    }

    public function hasMarketplaceBadge(): bool
    {
        return $this->marketplace_badge_id !== null;
    }

    public function needsPriceUpdate(): bool
    {
        if (!$this->last_price_update instanceof \DateTimeImmutable) {
            return true;
        }

        $now = new DateTimeImmutable();
        $interval = $now->getTimestamp() - $this->last_price_update->getTimestamp();
        return $interval > 86400; // 24 hours
    }

    public function needsValidation(): bool
    {
        if (!$this->last_validation instanceof \DateTimeImmutable) {
            return true;
        }

        $now = new DateTimeImmutable();
        $interval = $now->getTimestamp() - $this->last_validation->getTimestamp();
        return $interval > 172800; // 48 hours
    }

    public function isValid(): bool
    {
        return $this->active && $this->url !== null;
    }

    public function isAffiliateActive(): bool
    {
        return $this->active && (float) $this->affiliate_revenue > 0;
    }

    public function getFormattedPrice(): string
    {
        $price = (float) $this->price;
        return 'Rp ' . number_format($price, 0, ',', '.');
    }

    public function getFormattedRating(): string
    {
        $rating = (float) $this->rating;
        return number_format($rating, 1);
    }

    public function getFormattedAffiliateRevenue(): string
    {
        $revenue = (float) $this->affiliate_revenue;
        return 'Rp ' . number_format($revenue, 0, ',', '.');
    }

    public function getStarRatingHtml(): string
    {
        $rating = (float) $this->rating;
        $stars = round($rating);
        $html = '';

        for ($i = 1; $i <= 5; $i++) {
            if ($i <= $stars) {
                $html .= '<i class="fas fa-star text-yellow-500"></i>';
            } else {
                $html .= '<i class="far fa-star text-gray-300"></i>';
            }
        }

        return $html;
    }

    public function getFormattedSoldCount(): string
    {
        return number_format($this->sold_count, 0, ',', '.');
    }

    public function getClickThroughRate(float $totalProductViews = 0): float
    {
        if ($totalProductViews <= 0) {
            return 0.0;
        }

        return ($this->clicks / $totalProductViews) * 100;
    }

    public function getRevenuePerClick(): string
    {
        if ($this->clicks <= 0) {
            return '0.00';
        }

        $revenue = (float) $this->affiliate_revenue;
        $perClick = $revenue / $this->clicks;
        return number_format($perClick, 2, '.', '');
    }

    public function archive(): self
    {
        $this->active = false;
        $this->softdelete();
        return $this;
    }

    // ============================================
    // NEW COMMISSION LOGIC METHODS
    // ============================================

    /**
     * Calculate estimated revenue based on price and commission rate
     * Forward calculation: percentage → Rupiah
     *
     * @param float|null $customRate Decimal rate (e.g., 0.05 for 5%). Null uses default 2%
     * @return string Rupiah value formatted to 2 decimal places
     */
    public function calculateRevenue(?float $customRate = null): string
    {
        // 1. Determine rate: custom input or default 2%
        $rate = $customRate ?? self::DEFAULT_COMMISSION_RATE;

        // 2. Get current price
        $price = (float) $this->price;

        // 3. Calculate: Price × Rate
        $estimated = $price * $rate;

        // Format with 2 decimal places, no thousands separator
        return number_format($estimated, 2, '.', '');
    }

    /**
     * Reverse calculation: Derive commission rate from stored revenue
     * Used to display percentage in edit forms
     * Formula: (Revenue / Price) × 100
     *
     * @return float Percentage (e.g., 5.0 for 5%)
     */
    public function getImpliedCommissionRate(): float
    {
        $price = (float) $this->price;
        $revenue = (float) $this->affiliate_revenue;

        // Prevent division by zero
        if ($price <= 0) {
            return 0.0;
        }

        // Calculate ratio
        $ratio = $revenue / $price;

        // Convert to percentage and round to 2 decimals
        return round($ratio * 100, 2);
    }

    // ============================================
    // TO ARRAY AND STATIC METHODS (KEEP AS IS)
    // ============================================

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'marketplace_id' => $this->marketplace_id,
            'store_name' => $this->store_name,
            'price' => $this->price,
            'url' => $this->url,
            'rating' => $this->rating,
            'active' => $this->active,
            'sold_count' => $this->sold_count,
            'clicks' => $this->clicks,
            'last_price_update' => $this->last_price_update instanceof \DateTimeImmutable ? $this->last_price_update->format('Y-m-d H:i:s') : null,
            'last_validation' => $this->last_validation instanceof \DateTimeImmutable ? $this->last_validation->format('Y-m-d H:i:s') : null,
            'affiliate_revenue' => $this->affiliate_revenue,
            'marketplace_badge_id' => $this->marketplace_badge_id,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            'deleted_at' => $this->deleted_at ? $this->deleted_at->format('Y-m-d H:i:s') : null,
        ];
    }

    public static function fromArray(array $data): static
    {
        $link = new Link(
            $data['product_id'] ?? 0,
            $data['marketplace_id'] ?? 0,
            $data['store_name'] ?? ''
        );

        if (isset($data['id'])) {
            $link->setId($data['id']);
        }
        if (isset($data['price'])) {
            $link->setPrice($data['price']);
        }
        if (isset($data['url'])) {
            $link->setUrl($data['url']);
        }
        if (isset($data['rating'])) {
            $link->setRating($data['rating']);
        }
        if (isset($data['active'])) {
            $link->setActive((bool) $data['active']);
        }
        if (isset($data['sold_count'])) {
            $link->setSoldCount((int) $data['sold_count']);
        }
        if (isset($data['clicks'])) {
            $link->setClicks((int) $data['clicks']);
        }

        if (isset($data['last_price_update']) && $data['last_price_update']) {
            $link->setLastPriceUpdate(new DateTimeImmutable($data['last_price_update']));
        }

        if (isset($data['last_validation']) && $data['last_validation']) {
            $link->setLastValidation(new DateTimeImmutable($data['last_validation']));
        }

        if (isset($data['affiliate_revenue'])) {
            $link->setAffiliateRevenue($data['affiliate_revenue']);
        }

        if (isset($data['marketplace_badge_id'])) {
            $link->setMarketplaceBadgeId($data['marketplace_badge_id']);
        }

        if (isset($data['created_at']) && $data['created_at']) {
            $link->setCreatedAt(new DateTimeImmutable($data['created_at']));
        }

        if (isset($data['updated_at']) && $data['updated_at']) {
            $link->setUpdatedAt(new DateTimeImmutable($data['updated_at']));
        }

        if (isset($data['deleted_at']) && $data['deleted_at']) {
            $link->setDeletedAt(new DateTimeImmutable($data['deleted_at']));
        }

        return $link;
    }

    public static function createSample(int $productId = 1, int $marketplaceId = 1): static
    {
        $link = new Link($productId, $marketplaceId, 'Sample Store');
        $link->setPrice('100000.00');
        $link->setUrl('https://example.com/product');
        $link->setRating('4.5');
        $link->setAffiliateRevenue('2000.00'); // 2% of 100,000
        return $link;
    }
}
