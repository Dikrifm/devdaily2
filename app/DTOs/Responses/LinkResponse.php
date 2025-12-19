<?php

namespace App\DTOs\Responses;

use App\Entities\Link;

/**
 * Link Response DTO
 *
 * Response format for Link data with reverse commission calculation.
 * Includes display-only fields like commission_rate_display derived from stored revenue.
 *
 * @package App\DTOs\Responses
 */
class LinkResponse
{
    /**
     * Link ID
     */
    public int $id;

    /**
     * Product ID
     */
    public int $product_id;

    /**
     * Marketplace ID
     */
    public int $marketplace_id;

    /**
     * Store name
     */
    public string $store_name;

    /**
     * Product price (raw decimal)
     */
    public string $price;

    /**
     * Formatted price for display
     */
    public string $formatted_price;

    /**
     * Product URL
     */
    public ?string $url;

    /**
     * Product rating (0.00 - 5.00)
     */
    public string $rating;

    /**
     * Formatted rating for display
     */
    public string $formatted_rating;

    /**
     * Star rating HTML
     */
    public string $star_rating_html;

    /**
     * Active status
     */
    public bool $active;

    /**
     * Sold count
     */
    public int $sold_count;

    /**
     * Formatted sold count for display
     */
    public string $formatted_sold_count;

    /**
     * Click count
     */
    public int $clicks;

    /**
     * Last price update timestamp
     */
    public ?string $last_price_update;

    /**
     * Last validation timestamp
     */
    public ?string $last_validation;

    /**
     * Affiliate revenue (raw decimal)
     */
    public string $affiliate_revenue;

    /**
     * Formatted revenue for display
     */
    public string $formatted_revenue;

    /**
     * Marketplace badge ID
     */
    public ?int $marketplace_badge_id;

    /**
     * Created at timestamp
     */
    public ?string $created_at;

    /**
     * Updated at timestamp
     */
    public ?string $updated_at;

    /**
     * DISPLAY ONLY: Commission rate percentage derived from revenue
     * Example: "5.00" for 5%
     * This is calculated from stored revenue, not persisted
     */
    public string $commission_rate_display;

    /**
     * DISPLAY ONLY: Commission rate with percent symbol
     * Example: "5.00%"
     */
    public string $commission_rate_percent;

    /**
     * DISPLAY ONLY: Whether commission rate is using default (2%)
     */
    public bool $is_default_commission;

    /**
     * DISPLAY ONLY: Revenue per click
     */
    public string $revenue_per_click;

    /**
     * Create LinkResponse from Link entity
     */
    public static function fromEntity(Link $link): self
    {
        $response = new self();

        // Basic properties
        $response->id = $link->getId() ?? 0;
        $response->product_id = $link->getProductId();
        $response->marketplace_id = $link->getMarketplaceId();
        $response->store_name = $link->getStoreName();
        $response->price = $link->getPrice();
        $response->formatted_price = $link->getFormattedPrice();
        $response->url = $link->getUrl();
        $response->rating = $link->getRating();
        $response->formatted_rating = $link->getFormattedRating();
        $response->star_rating_html = $link->getStarRatingHtml();
        $response->active = $link->isActive();
        $response->sold_count = $link->getSoldCount();
        $response->formatted_sold_count = $link->getFormattedSoldCount();
        $response->clicks = $link->getClicks();

        // Timestamps
        $lastPriceUpdate = $link->getLastPriceUpdate();
        $response->last_price_update = $lastPriceUpdate instanceof \DateTimeImmutable ? $lastPriceUpdate->format('Y-m-d H:i:s') : null;

        $lastValidation = $link->getLastValidation();
        $response->last_validation = $lastValidation instanceof \DateTimeImmutable ? $lastValidation->format('Y-m-d H:i:s') : null;

        // Revenue data
        $response->affiliate_revenue = $link->getAffiliateRevenue();
        $response->formatted_revenue = $link->getFormattedAffiliateRevenue();

        // Marketplace badge
        $response->marketplace_badge_id = $link->getMarketplaceBadgeId();

        // Timestamps from BaseEntity
        $createdAt = $link->getCreatedAt();
        $response->created_at = $createdAt instanceof \DateTimeImmutable ? $createdAt->format('Y-m-d H:i:s') : null;

        $updatedAt = $link->getUpdatedAt();
        $response->updated_at = $updatedAt instanceof \DateTimeImmutable ? $updatedAt->format('Y-m-d H:i:s') : null;

        // ============================================
        // REVERSE CALCULATION FOR COMMISSION DISPLAY
        // ============================================

        // Get implied commission rate from entity
        $impliedRate = $link->getImpliedCommissionRate();

        // Format for display (2 decimal places)
        $response->commission_rate_display = number_format($impliedRate, 2, '.', '');
        $response->commission_rate_percent = number_format($impliedRate, 2) . '%';

        // Check if using default commission (2%)
        $response->is_default_commission = abs($impliedRate - 2.0) < 0.01;

        // Calculate revenue per click
        $response->revenue_per_click = $link->getRevenuePerClick();

        return $response;
    }

    /**
     * Create collection of LinkResponse from array of Link entities
     *
     * @param array $links Array of Link entities
     * @return array Array of LinkResponse objects
     */
    public static function collection(array $links): array
    {
        $collection = [];

        foreach ($links as $link) {
            $collection[] = self::fromEntity($link);
        }

        return $collection;
    }

    /**
     * Convert response to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'marketplace_id' => $this->marketplace_id,
            'store_name' => $this->store_name,
            'price' => $this->price,
            'formatted_price' => $this->formatted_price,
            'url' => $this->url,
            'rating' => $this->rating,
            'formatted_rating' => $this->formatted_rating,
            'star_rating_html' => $this->star_rating_html,
            'active' => $this->active,
            'sold_count' => $this->sold_count,
            'formatted_sold_count' => $this->formatted_sold_count,
            'clicks' => $this->clicks,
            'last_price_update' => $this->last_price_update,
            'last_validation' => $this->last_validation,
            'affiliate_revenue' => $this->affiliate_revenue,
            'formatted_revenue' => $this->formatted_revenue,
            'marketplace_badge_id' => $this->marketplace_badge_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'commission_rate_display' => $this->commission_rate_display,
            'commission_rate_percent' => $this->commission_rate_percent,
            'is_default_commission' => $this->is_default_commission,
            'revenue_per_click' => $this->revenue_per_click,
        ];
    }

    /**
     * Convert response to JSON
     *
     * @param bool $pretty Whether to format JSON for readability
     */
    public function toJson(bool $pretty = false): string
    {
        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($pretty) {
            $options |= JSON_PRETTY_PRINT;
        }

        return json_encode($this->toArray(), $options);
    }

    /**
     * Get simplified summary for listing views
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'formatted_price' => $this->formatted_price,
            'formatted_revenue' => $this->formatted_revenue,
            'commission_rate_percent' => $this->commission_rate_percent,
            'active' => $this->active,
            'clicks' => $this->clicks,
            'sold_count' => $this->sold_count,
            'rating' => $this->formatted_rating,
        ];
    }

    /**
     * Get detailed view for single item display
     */
    public function toDetail(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'marketplace_id' => $this->marketplace_id,
            'store_name' => $this->store_name,
            'price_info' => [
                'raw' => $this->price,
                'formatted' => $this->formatted_price,
                'last_updated' => $this->last_price_update,
            ],
            'url' => $this->url,
            'rating_info' => [
                'raw' => $this->rating,
                'formatted' => $this->formatted_rating,
                'stars_html' => $this->star_rating_html,
            ],
            'status' => [
                'active' => $this->active,
                'sold_count' => $this->sold_count,
                'formatted_sold_count' => $this->formatted_sold_count,
                'clicks' => $this->clicks,
                'last_validation' => $this->last_validation,
            ],
            'commission_info' => [
                'revenue_raw' => $this->affiliate_revenue,
                'revenue_formatted' => $this->formatted_revenue,
                'rate_display' => $this->commission_rate_display,
                'rate_percent' => $this->commission_rate_percent,
                'is_default' => $this->is_default_commission,
                'revenue_per_click' => $this->revenue_per_click,
            ],
            'timestamps' => [
                'created_at' => $this->created_at,
                'updated_at' => $this->updated_at,
            ],
            'marketplace_badge_id' => $this->marketplace_badge_id,
        ];
    }

    /**
     * Check if link needs price update
     */
    public function needsPriceUpdate(): bool
    {
        if (!$this->last_price_update) {
            return true;
        }

        $lastUpdate = strtotime($this->last_price_update);
        $now = time();
        $hoursSinceUpdate = ($now - $lastUpdate) / 3600;

        return $hoursSinceUpdate > 24;
    }

    /**
     * Check if link needs validation
     */
    public function needsValidation(): bool
    {
        if (!$this->last_validation) {
            return true;
        }

        $lastValidation = strtotime($this->last_validation);
        $now = time();
        $hoursSinceValidation = ($now - $lastValidation) / 3600;

        return $hoursSinceValidation > 48;
    }

    /**
     * Get click-through rate if total product views provided
     */
    public function getClickThroughRate(float $totalProductViews = 0): float
    {
        if ($totalProductViews <= 0 || $this->clicks <= 0) {
            return 0.0;
        }

        return round(($this->clicks / $totalProductViews) * 100, 2);
    }

    /**
     * Get commission info for UI display
     */
    public function getCommissionInfo(): array
    {
        return [
            'rate' => $this->commission_rate_display,
            'percent' => $this->commission_rate_percent,
            'revenue' => $this->formatted_revenue,
            'is_default' => $this->is_default_commission,
            'tooltip' => $this->is_default_commission
                ? 'Using default 2% commission rate'
                : 'Custom commission rate applied',
        ];
    }

    /**
     * Get status badge info for UI
     */
    public function getStatusBadge(): array
    {
        if (!$this->active) {
            return [
                'text' => 'Inactive',
                'color' => 'bg-gray-100 text-gray-800',
                'icon' => 'fas fa-pause-circle',
            ];
        }

        if ($this->needsValidation()) {
            return [
                'text' => 'Needs Validation',
                'color' => 'bg-yellow-100 text-yellow-800',
                'icon' => 'fas fa-exclamation-triangle',
            ];
        }

        if ($this->needsPriceUpdate()) {
            return [
                'text' => 'Price Update Needed',
                'color' => 'bg-orange-100 text-orange-800',
                'icon' => 'fas fa-sync-alt',
            ];
        }

        return [
            'text' => 'Active',
            'color' => 'bg-green-100 text-green-800',
            'icon' => 'fas fa-check-circle',
        ];
    }
}
