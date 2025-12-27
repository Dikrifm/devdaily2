<?php

namespace App\DTOs\Responses;

use App\Entities\Admin;
use App\Entities\Badge;
use App\Entities\Category;
use App\Entities\Link;
use App\Entities\Marketplace;
use App\Entities\MarketplaceBadge;
use App\Entities\Product;

/* 
 * Product Detail Response DTO
 *
 * Extended DTO for detailed product responses with all relations.
 * Supports multiple levels of relation loading with comprehensive formatting.
 *
 * @package App\DTOs\Responses
 */
class ProductDetailResponse extends ProductResponse
{
    public $currency;
    public $verifiedAt;
    public $verifiedBy;
    public $adminMode;
    public $id;
    public $slug;
    public $baseImageUrl;
    // Level 1: Category full object
    private ?array $category = null;

    // Level 2: Links with basic marketplace info
    private array $links = [];

    // Level 3: Full marketplace objects
    private array $marketplaces = [];

    // Level 3: Product badges
    private array $badges = [];

    // Level 3: Marketplace badges for links
    private array $marketplaceBadges = [];

    // Level 4: Verification admin info
    private ?array $verifiedByAdmin = null;

    // Level 4: Statistics
    private array $statistics = [];

    // Level 4: Audit trail (recent actions)
    private array $recentActions = [];

    // Configuration for relation loading
    private array $loadedRelations = [];

    // Relation loading flags
    private bool $loadCategory = false;
    private bool $loadLinks = false;
    private bool $loadMarketplaces = false;
    private bool $loadBadges = false;
    private bool $loadMarketplaceBadges = false;
    private bool $loadVerificationInfo = false;
    private bool $loadStatistics = false;
    private bool $loadRecentActions = false;

    // Cache for formatted relations
    private ?array $formattedRelations = null;

    /**
     * Private constructor for immutability
     */
    private function __construct()
    {
    }

    /**
     * Create ProductDetailResponse from Product entity with relations
     *
     * @param array $relations Array of related entities
     * @param array $config Configuration options
     */
    public static function fromEntityWithRelations(Product $product, array $relations = [], array $config = []): self
    {
        // First create basic product response
        $response = new self();
        $response->applyConfiguration($config);
        $response->populateFromEntity($product);

        // Apply relation loading configuration
        $response->applyRelationConfig($config);

        // Load relations if provided
        $response->loadRelations($relations);

        return $response;
    }

    /**
     * Create ProductDetailResponse with all relations loaded
     *
     * @param array $allRelations Complete relations array
     */
    public static function withAllRelations(Product $product, array $allRelations, array $config = []): self
    {
        $response = new self();
        $response->applyConfiguration($config);
        $response->populateFromEntity($product);

        // Enable all relation loading
        $response->loadCategory = true;
        $response->loadLinks = true;
        $response->loadMarketplaces = true;
        $response->loadBadges = true;
        $response->loadMarketplaceBadges = true;
        $response->loadVerificationInfo = true;
        $response->loadStatistics = true;
        $response->loadRecentActions = true;

        // Load all relations
        $response->loadAllRelations($allRelations);

        return $response;
    }

    /**
     * Apply relation loading configuration
     */
    private function applyRelationConfig(array $config): void
    {
        $this->loadCategory = $config['load_category'] ?? false;
        $this->loadLinks = $config['load_links'] ?? false;
        $this->loadMarketplaces = $config['load_marketplaces'] ?? false;
        $this->loadBadges = $config['load_badges'] ?? false;
        $this->loadMarketplaceBadges = $config['load_marketplace_badges'] ?? false;
        $this->loadVerificationInfo = $config['load_verification_info'] ?? false;
        $this->loadStatistics = $config['load_statistics'] ?? false;
        $this->loadRecentActions = $config['load_recent_actions'] ?? false;
    }

    /**
     * Load relations from provided data
     */
    private function loadRelations(array $relations): void
    {
        if ($this->loadCategory && isset($relations['category'])) {
            $this->loadCategoryRelation($relations['category']);
        }

        if ($this->loadLinks && isset($relations['links'])) {
            $this->loadLinksRelation($relations['links']);
        }

        if ($this->loadMarketplaces && isset($relations['marketplaces'])) {
            $this->loadMarketplacesRelation($relations['marketplaces']);
        }

        if ($this->loadBadges && isset($relations['badges'])) {
            $this->loadBadgesRelation($relations['badges']);
        }

        if ($this->loadMarketplaceBadges && isset($relations['marketplace_badges'])) {
            $this->loadMarketplaceBadgesRelation($relations['marketplace_badges']);
        }

        if ($this->loadVerificationInfo && isset($relations['verified_by_admin'])) {
            $this->loadVerificationInfo($relations['verified_by_admin']);
        }

        if ($this->loadStatistics && isset($relations['statistics'])) {
            $this->loadStatistics($relations['statistics']);
        }

        if ($this->loadRecentActions && isset($relations['recent_actions'])) {
            $this->loadRecentActions($relations['recent_actions']);
        }
    }

    /**
     * Load all relations at once
     */
    private function loadAllRelations(array $allRelations): void
    {
        // Track loaded relations
        $this->loadedRelations = [];

        // Category
        if (isset($allRelations['category'])) {
            $this->loadCategoryRelation($allRelations['category']);
            $this->loadedRelations[] = 'category';
        }

        // Links
        if (isset($allRelations['links'])) {
            $this->loadLinksRelation($allRelations['links']);
            $this->loadedRelations[] = 'links';
        }

        // Marketplaces
        if (isset($allRelations['marketplaces'])) {
            $this->loadMarketplacesRelation($allRelations['marketplaces']);
            $this->loadedRelations[] = 'marketplaces';
        }

        // Badges
        if (isset($allRelations['badges'])) {
            $this->loadBadgesRelation($allRelations['badges']);
            $this->loadedRelations[] = 'badges';
        }

        // Marketplace badges
        if (isset($allRelations['marketplace_badges'])) {
            $this->loadMarketplaceBadgesRelation($allRelations['marketplace_badges']);
            $this->loadedRelations[] = 'marketplace_badges';
        }

        // Verification info
        if (isset($allRelations['verified_by_admin'])) {
            $this->loadVerificationInfo($allRelations['verified_by_admin']);
            $this->loadedRelations[] = 'verified_by_admin';
        }

        // Statistics
        if (isset($allRelations['statistics'])) {
            $this->loadStatistics($allRelations['statistics']);
            $this->loadedRelations[] = 'statistics';
        }

        // Recent actions
        if (isset($allRelations['recent_actions'])) {
            $this->loadRecentActions($allRelations['recent_actions']);
            $this->loadedRelations[] = 'recent_actions';
        }
    }

    /**
     * Load category relation
     *
     * @param mixed $categoryData
     */
    private function loadCategoryRelation($categoryData): void
    {
        if ($categoryData instanceof Category) {
            $this->category = $categoryData->toArray();
        } elseif (is_array($categoryData)) {
            $this->category = $categoryData;
        }
    }

    /**
     * Load links relation
     */
    private function loadLinksRelation(array $linksData): void
    {
        foreach ($linksData as $link) {
            if ($link instanceof Link) {
                $this->links[] = $link->toArray();
            } elseif (is_array($link)) {
                $this->links[] = $link;
            }
        }
    }

    /**
     * Load marketplaces relation
     */
    private function loadMarketplacesRelation(array $marketplacesData): void
    {
        foreach ($marketplacesData as $marketplace) {
            if ($marketplace instanceof Marketplace) {
                $this->marketplaces[] = $marketplace->toArray();
            } elseif (is_array($marketplace)) {
                $this->marketplaces[] = $marketplace;
            }
        }
    }

    /**
     * Load badges relation
     */
    private function loadBadgesRelation(array $badgesData): void
    {
        foreach ($badgesData as $badge) {
            if ($badge instanceof Badge) {
                $this->badges[] = $badge->toArray();
            } elseif (is_array($badge)) {
                $this->badges[] = $badge;
            }
        }
    }

    /**
     * Load marketplace badges relation
     */
    private function loadMarketplaceBadgesRelation(array $badgesData): void
    {
        foreach ($badgesData as $badge) {
            if ($badge instanceof MarketplaceBadge) {
                $this->marketplaceBadges[] = $badge->toArray();
            } elseif (is_array($badge)) {
                $this->marketplaceBadges[] = $badge;
            }
        }
    }

    /**
     * Load verification info
     *
     * @param mixed $adminData
     */
    private function loadVerificationInfo($adminData): void
    {
        if ($adminData instanceof Admin) {
            $this->verifiedByAdmin = $adminData->toArray();
        } elseif (is_array($adminData)) {
            $this->verifiedByAdmin = $adminData;
        }
    }

    /**
     * Load statistics
     */
    private function loadStatistics(array $statsData): void
    {
        $this->statistics = $statsData;
    }

    /**
     * Load recent actions
     */
    private function loadRecentActions(array $actionsData): void
    {
        $this->recentActions = $actionsData;
    }

    /**
     * Get formatted category data
     */
    private function getFormattedCategory(): ?array
    {
        if (!$this->category) {
            return null;
        }

        return [
            'id' => $this->category['id'] ?? null,
            'name' => $this->category['name'] ?? null,
            'slug' => $this->category['slug'] ?? null,
            'icon' => $this->category['icon'] ?? 'fas fa-folder',
            'sort_order' => $this->category['sort_order'] ?? 0,
            'active' => $this->category['active'] ?? true,
            'created_at' => $this->category['created_at'] ?? null,
            'updated_at' => $this->category['updated_at'] ?? null,
        ];
    }

    /**
     * Get formatted links data
     */
    private function getFormattedLinks(): array
    {
        $formattedLinks = [];

        foreach ($this->links as $link) {
            $formattedLink = [
                'id' => $link['id'] ?? null,
                'marketplace_id' => $link['marketplace_id'] ?? null,
                'store_name' => $link['store_name'] ?? '',
                'price' => [
                    'raw' => $link['price'] ?? '0.00',
                    'formatted' => $this->formatLinkPrice($link['price'] ?? '0.00'),
                ],
                'url' => $link['url'] ?? null,
                'rating' => [
                    'raw' => $link['rating'] ?? '0.00',
                    'formatted' => number_format((float)($link['rating'] ?? 0), 2),
                    'stars' => $this->generateStarRating($link['rating'] ?? '0.00'),
                ],
                'active' => $link['active'] ?? true,
                'sold_count' => $link['sold_count'] ?? 0,
                'clicks' => $link['clicks'] ?? 0,
                'affiliate_revenue' => $link['affiliate_revenue'] ?? '0.00',
                'marketplace_badge_id' => $link['marketplace_badge_id'] ?? null,
                'last_price_update' => $link['last_price_update'] ?? null,
                'last_validation' => $link['last_validation'] ?? null,
                'created_at' => $link['created_at'] ?? null,
                'updated_at' => $link['updated_at'] ?? null,
            ];

            // Add marketplace info if available
            if (isset($link['marketplace'])) {
                $formattedLink['marketplace'] = $this->formatMarketplaceInfo($link['marketplace']);
            }

            // Add marketplace badge if available
            if (isset($link['marketplace_badge'])) {
                $formattedLink['marketplace_badge'] = $this->formatMarketplaceBadgeInfo($link['marketplace_badge']);
            }

            $formattedLinks[] = $formattedLink;
        }

        return $formattedLinks;
    }

    /**
     * Format link price
     */
    private function formatLinkPrice(string $price): string
    {
        $price = (float) $price;

        if ($price == 0) {
            return $this->currency . ' 0';
        }

        return $this->currency . ' ' . number_format($price, 0, ',', '.');
    }

    /**
     * Generate star rating HTML/emoji representation
     */
    private function generateStarRating(string $rating): string
    {
        $numericRating = (float) $rating;
        $fullStars = floor($numericRating);
        $halfStar = ($numericRating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        $stars = str_repeat('★', $fullStars);
        $stars .= $halfStar ? '½' : '';

        return $stars . str_repeat('☆', $emptyStars);
    }

    /**
     * Format marketplace info
     *
     * @param mixed $marketplace
     */
    private function formatMarketplaceInfo($marketplace): ?array
    {
        if (is_array($marketplace)) {
            return [
                'id' => $marketplace['id'] ?? null,
                'name' => $marketplace['name'] ?? null,
                'slug' => $marketplace['slug'] ?? null,
                'icon' => $marketplace['icon'] ?? null,
                'color' => $marketplace['color'] ?? '#64748b',
                'active' => $marketplace['active'] ?? true,
            ];
        }

        return null;
    }

    /**
     * Format marketplace badge info
     *
     * @param mixed $badge
     */
    private function formatMarketplaceBadgeInfo($badge): ?array
    {
        if (is_array($badge)) {
            return [
                'id' => $badge['id'] ?? null,
                'label' => $badge['label'] ?? null,
                'icon' => $badge['icon'] ?? null,
                'color' => $badge['color'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Get formatted badges data
     */
    private function getFormattedBadges(): array
    {
        $formattedBadges = [];

        foreach ($this->badges as $badge) {
            $formattedBadges[] = [
                'id' => $badge['id'] ?? null,
                'label' => $badge['label'] ?? '',
                'color' => $badge['color'] ?? null,
                'color_style' => $badge['color'] ? "color: {$badge['color']}" : null,
                'created_at' => $badge['created_at'] ?? null,
                'updated_at' => $badge['updated_at'] ?? null,
            ];
        }

        return $formattedBadges;
    }

    /**
     * Get formatted marketplaces data
     */
    private function getFormattedMarketplaces(): array
    {
        $formattedMarketplaces = [];

        foreach ($this->marketplaces as $marketplace) {
            $formattedMarketplaces[] = [
                'id' => $marketplace['id'] ?? null,
                'name' => $marketplace['name'] ?? '',
                'slug' => $marketplace['slug'] ?? '',
                'icon' => $marketplace['icon'] ?? null,
                'color' => $marketplace['color'] ?? '#64748b',
                'active' => $marketplace['active'] ?? true,
                'created_at' => $marketplace['created_at'] ?? null,
                'updated_at' => $marketplace['updated_at'] ?? null,
            ];
        }

        return $formattedMarketplaces;
    }

    /**
     * Get formatted verification info
     */
    private function getFormattedVerificationInfo(): ?array
    {
        if (!$this->verifiedByAdmin) {
            return null;
        }

        return [
            'admin' => [
                'id' => $this->verifiedByAdmin['id'] ?? null,
                'username' => $this->verifiedByAdmin['username'] ?? null,
                'name' => $this->verifiedByAdmin['name'] ?? null,
                'email' => $this->verifiedByAdmin['email'] ?? null,
                'role' => $this->verifiedByAdmin['role'] ?? null,
            ],
            'verified_at' => $this->verifiedAt,
            'verified_by' => $this->verifiedBy,
        ];
    }

    /**
     * Get formatted statistics
     */
    private function getFormattedStatistics(): array
    {
        $defaultStats = [
            'total_links' => count($this->links),
            'active_links' => count(array_filter($this->links, fn ($link) => ($link['active'] ?? true))),
            'total_clicks' => array_sum(array_column($this->links, 'clicks')),
            'total_sold' => array_sum(array_column($this->links, 'sold_count')),
            'total_revenue' => array_sum(array_map(fn ($link) => (float)($link['affiliate_revenue'] ?? 0), $this->links)),
            'average_rating' => $this->calculateAverageRating(),
            'lowest_price' => $this->findLowestPrice(),
            'highest_price' => $this->findHighestPrice(),
        ];

        return array_merge($defaultStats, $this->statistics);
    }

    /**
     * Calculate average rating from links
     */
    private function calculateAverageRating(): float
    {
        if ($this->links === []) {
            return 0.0;
        }

        $ratings = array_filter(array_column($this->links, 'rating'));
        if ($ratings === []) {
            return 0.0;
        }

        $sum = array_sum(array_map(floatval(...), $ratings));
        return round($sum / count($ratings), 2);
    }

    /**
     * Find lowest price from active links
     */
    private function findLowestPrice(): ?array
    {
        $activeLinks = array_filter($this->links, fn ($link) => ($link['active'] ?? true));

        if ($activeLinks === []) {
            return null;
        }

        usort($activeLinks, fn ($a, $b) => (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0));
        $lowest = reset($activeLinks);

        return [
            'price' => $lowest['price'] ?? '0.00',
            'formatted' => $this->formatLinkPrice($lowest['price'] ?? '0.00'),
            'marketplace_id' => $lowest['marketplace_id'] ?? null,
            'store_name' => $lowest['store_name'] ?? '',
            'url' => $lowest['url'] ?? null,
        ];
    }

    /**
     * Find highest price from active links
     */
    private function findHighestPrice(): ?array
    {
        $activeLinks = array_filter($this->links, fn ($link) => ($link['active'] ?? true));

        if ($activeLinks === []) {
            return null;
        }

        usort($activeLinks, fn ($a, $b) => (float)($b['price'] ?? 0) <=> (float)($a['price'] ?? 0));
        $highest = reset($activeLinks);

        return [
            'price' => $highest['price'] ?? '0.00',
            'formatted' => $this->formatLinkPrice($highest['price'] ?? '0.00'),
            'marketplace_id' => $highest['marketplace_id'] ?? null,
            'store_name' => $highest['store_name'] ?? '',
            'url' => $highest['url'] ?? null,
        ];
    }

    /**
     * Get formatted recent actions
     */
    private function getFormattedRecentActions(): array
    {
        $formattedActions = [];

        foreach ($this->recentActions as $action) {
            $formattedActions[] = [
                'id' => $action['id'] ?? null,
                'action_type' => $action['action_type'] ?? null,
                'entity_type' => $action['entity_type'] ?? null,
                'entity_id' => $action['entity_id'] ?? null,
                'changes_summary' => $action['changes_summary'] ?? null,
                'performed_at' => $action['performed_at'] ?? null,
                'admin' => $action['admin'] ?? null,
                'ip_address' => $action['ip_address'] ?? null,
                'user_agent' => $action['user_agent'] ?? null,
            ];
        }

        return $formattedActions;
    }

    /**
     * Get all formatted relations
     */
    private function getAllFormattedRelations(): array
    {
        if ($this->formattedRelations === null) {
            $this->formattedRelations = [
                'category' => $this->getFormattedCategory(),
                'links' => $this->getFormattedLinks(),
                'badges' => $this->getFormattedBadges(),
                'marketplaces' => $this->getFormattedMarketplaces(),
                'marketplace_badges' => $this->marketplaceBadges,
                'statistics' => $this->getFormattedStatistics(),
                'loaded_relations' => $this->loadedRelations,
            ];

            // Add verification info if admin mode
            if ($this->adminMode) {
                $this->formattedRelations['verification_info'] = $this->getFormattedVerificationInfo();
                $this->formattedRelations['recent_actions'] = $this->getFormattedRecentActions();
            }
        }

        return $this->formattedRelations;
    }

    /**
     * Convert to detailed array for API response
     */
    public function toDetailArray(): array
    {
        // Get base product data
        $baseData = $this->toArray();

        // Add relations
        $relations = $this->getAllFormattedRelations();

        return array_merge($baseData, [
            'relations' => $relations,
            'has_category' => !empty($relations['category']),
            'has_links' => !empty($relations['links']),
            'has_badges' => !empty($relations['badges']),
            'total_links' => count($relations['links']),
            'active_links' => $relations['statistics']['active_links'] ?? 0,
            'price_range' => [
                'lowest' => $relations['statistics']['lowest_price'] ?? null,
                'highest' => $relations['statistics']['highest_price'] ?? null,
            ],
        ]);
    }

    /**
     * Override toArray to include relations when in admin mode
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        // Always include basic relation info
        $data['has_relations'] = $this->loadedRelations !== [];
        $data['loaded_relations'] = $this->loadedRelations;

        // Include category if loaded
        if ($this->category) {
            $data['category'] = $this->getFormattedCategory();
        }

        // Include badges if loaded
        if ($this->badges !== []) {
            $data['badges'] = $this->getFormattedBadges();
        }

        // In admin mode, include more details
        if ($this->adminMode) {
            if ($this->links !== []) {
                $data['links'] = $this->getFormattedLinks();
            }

            if ($this->verifiedByAdmin) {
                $data['verification_info'] = $this->getFormattedVerificationInfo();
            }

            if ($this->statistics !== []) {
                $data['statistics'] = $this->getFormattedStatistics();
            }
        }

        return $data;
    }

    /**
     * Check if specific relation is loaded
     */
    public function hasRelation(string $relation): bool
    {
        return in_array($relation, $this->loadedRelations, true);
    }

    /**
     * Get loaded relations count
     */
    public function getLoadedRelationsCount(): int
    {
        return count($this->loadedRelations);
    }

    /**
     * Get cache key for detailed response
     */
    public function getDetailCacheKey(string $prefix = 'product_detail_'): string
    {
        $components = [
            'id' => $this->id,
            'slug' => $this->slug,
            'admin_mode' => $this->adminMode ? '1' : '0',
            'relations' => implode(',', $this->loadedRelations),
            'image_base' => md5($this->baseImageUrl),
        ];

        return $prefix . md5(serialize($components));
    }

    /**
     * Get response summary including relations
     */
    public function getDetailSummary(): array
    {
        $summary = parent::getSummary();

        $summary['relations_loaded'] = $this->loadedRelations;
        $summary['links_count'] = count($this->links);
        $summary['badges_count'] = count($this->badges);
        $summary['has_category'] = $this->category !== null && $this->category !== [];
        $summary['has_verification_info'] = $this->verifiedByAdmin !== null && $this->verifiedByAdmin !== [];

        return $summary;
    }

    // Getters for relation data

    public function getCategory(): ?array
    {
        return $this->category;
    }
    public function getLinks(): array
    {
        return $this->links;
    }
    public function getMarketplaces(): array
    {
        return $this->marketplaces;
    }
    public function getBadges(): array
    {
        return $this->badges;
    }
    public function getMarketplaceBadges(): array
    {
        return $this->marketplaceBadges;
    }
    public function getVerifiedByAdmin(): ?array
    {
        return $this->verifiedByAdmin;
    }
    public function getStatistics(): array
    {
        return $this->statistics;
    }
    public function getRecentActions(): array
    {
        return $this->recentActions;
    }
    public function getLoadedRelations(): array
    {
        return $this->loadedRelations;
    }
    public function isLoadCategory(): bool
    {
        return $this->loadCategory;
    }
    public function isLoadLinks(): bool
    {
        return $this->loadLinks;
    }
    public function isLoadMarketplaces(): bool
    {
        return $this->loadMarketplaces;
    }
    public function isLoadBadges(): bool
    {
        return $this->loadBadges;
    }
    public function isLoadMarketplaceBadges(): bool
    {
        return $this->loadMarketplaceBadges;
    }
    public function isLoadVerificationInfo(): bool
    {
        return $this->loadVerificationInfo;
    }
    public function isLoadStatistics(): bool
    {
        return $this->loadStatistics;
    }
    public function isLoadRecentActions(): bool
    {
        return $this->loadRecentActions;
    }

    /**
     * Create a copy with additional relations
     */
    public function withRelations(array $newRelations): self
    {
        $clone = clone $this;
        $clone->loadAllRelations($newRelations);
        $clone->formattedRelations = null;

        return $clone;
    }

    /**
     * Create a copy with relation loading configuration
     */
    public function withRelationConfig(array $relationConfig): self
    {
        $clone = clone $this;
        $clone->applyRelationConfig($relationConfig);
        $clone->formattedRelations = null;

        return $clone;
    }

    /**
     * Create JSON string for detailed response
     */
    public function toDetailJson(bool $pretty = false): string
    {
        $options = $pretty ? JSON_PRETTY_PRINT : 0;
        return json_encode($this->toDetailArray(), $options);
    }
}
