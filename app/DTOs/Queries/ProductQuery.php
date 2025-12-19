<?php

namespace App\DTOs\Queries;

use App\Enums\ProductStatus;

/**
 * Product Query DTO
 *
 * Immutable DTO for product listing and filtering parameters.
 * Enterprise-grade with comprehensive validation and sanitization.
 *
 * @package App\DTOs\Queries
 */
final class ProductQuery
{
    // Filter properties
    private ?array $status = null;
    private ?array $categoryIds = null;
    private ?string $search = null;
    private ?float $minPrice = null;
    private ?float $maxPrice = null;
    private ?int $marketplaceId = null;
    private ?array $badgeIds = null;
    private ?bool $hasActiveLinks = null;
    private ?bool $needsPriceUpdate = null;
    private ?bool $needsLinkValidation = null;
    private ?string $dateFrom = null;
    private ?string $dateTo = null;
    private ?string $dateField = null;

    // Sorting properties
    private string $sortBy = 'created_at';
    private string $sortDirection = 'DESC';

    // Scope properties
    private bool $includeTrashed = false;
    private bool $adminMode = false;
    private ?int $verifiedBy = null;

    // Validation constants
    private const ALLOWED_SORT_FIELDS = [
        'id', 'name', 'slug', 'market_price', 'view_count',
        'created_at', 'updated_at', 'published_at', 'verified_at'
    ];

    private const ALLOWED_DATE_FIELDS = [
        'created_at', 'updated_at', 'published_at', 'verified_at'
    ];

    private const MAX_SEARCH_LENGTH = 100;
    private const MAX_CATEGORIES = 10;

    /**
     * Private constructor for immutability
     */
    private function __construct()
    {
    }

    /**
     * Create ProductQuery from request array
     */
    public static function fromRequest(array $requestData, bool $adminMode = false): self
    {
        $query = new self();
        $query->adminMode = $adminMode;

        // Apply filters from request
        $query = $query->applyFilters($requestData);

        return $query;
    }

    /**
     * Create public product query (published only)
     */
    public static function forPublic(): self
    {
        $query = new self();
        $query->status = [ProductStatus::PUBLISHED->value];
        $query->hasActiveLinks = true;
        $query->adminMode = false;

        return $query;
    }

    /**
     * Create admin product query (all statuses)
     */
    public static function forAdmin(): self
    {
        $query = new self();
        $query->adminMode = true;
        $query->includeTrashed = false;

        return $query;
    }

    /**
     * Apply filters from request data
     */
    private function applyFilters(array $filters): self
    {
        // Clone for immutability
        $clone = clone $this;

        // Status filter
        if (isset($filters['status'])) {
            $clone->status = $this->parseStatusFilter($filters['status']);
        }

        // Category filter
        if (isset($filters['category_ids']) || isset($filters['category_id'])) {
            $clone->categoryIds = $this->parseIdFilter($filters['category_ids'] ?? $filters['category_id'] ?? null);
        }

        // Search query
        if (isset($filters['search']) && is_string($filters['search'])) {
            $clone->search = $this->sanitizeSearch($filters['search']);
        }

        // Price range
        if (isset($filters['min_price'])) {
            $clone->minPrice = $this->parsePrice($filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $clone->maxPrice = $this->parsePrice($filters['max_price']);
        }

        // Marketplace filter
        if (isset($filters['marketplace_id'])) {
            $clone->marketplaceId = (int) $filters['marketplace_id'];
        }

        // Badge filter
        if (isset($filters['badge_ids']) || isset($filters['badge_id'])) {
            $clone->badgeIds = $this->parseIdFilter($filters['badge_ids'] ?? $filters['badge_id'] ?? null);
        }

        // Boolean filters
        if (isset($filters['has_active_links'])) {
            $clone->hasActiveLinks = (bool) $filters['has_active_links'];
        }
        if (isset($filters['needs_price_update'])) {
            $clone->needsPriceUpdate = (bool) $filters['needs_price_update'];
        }
        if (isset($filters['needs_link_validation'])) {
            $clone->needsLinkValidation = (bool) $filters['needs_link_validation'];
        }
        if (isset($filters['include_trashed'])) {
            $clone->includeTrashed = (bool) $filters['include_trashed'];
        }

        // Date range
        if (isset($filters['date_from']) || isset($filters['date_to'])) {
            $clone->dateFrom = $filters['date_from'] ?? null;
            $clone->dateTo = $filters['date_to'] ?? null;
            $clone->dateField = $filters['date_field'] ?? 'created_at';
        }

        // Verified by filter
        if (isset($filters['verified_by'])) {
            $clone->verifiedBy = (int) $filters['verified_by'];
        }

        // Sorting
        if (isset($filters['sort_by'])) {
            $clone->sortBy = $this->validateSortField($filters['sort_by']);
        }
        if (isset($filters['sort_direction'])) {
            $clone->sortDirection = $this->validateSortDirection($filters['sort_direction']);
        }

        return $clone;
    }

    /**
     * Parse status filter
     *
     * @param mixed $statusInput
     */
    private function parseStatusFilter($statusInput): ?array
    {
        if (is_array($statusInput)) {
            return array_filter(array_map(strval(...), $statusInput));
        }

        if (is_string($statusInput)) {
            return array_map(trim(...), explode(',', $statusInput));
        }

        return null;
    }

    /**
     * Parse ID filter (single ID or comma-separated)
     *
     * @param mixed $idInput
     */
    private function parseIdFilter($idInput): ?array
    {
        if (is_array($idInput)) {
            $ids = array_filter($idInput, is_numeric(...));
            return array_map(intval(...), array_slice($ids, 0, self::MAX_CATEGORIES));
        }

        if (is_string($idInput)) {
            $ids = array_filter(explode(',', $idInput), is_numeric(...));
            return array_map(intval(...), array_slice($ids, 0, self::MAX_CATEGORIES));
        }

        if (is_numeric($idInput)) {
            return [(int) $idInput];
        }

        return null;
    }

    /**
     * Sanitize search query
     */
    private function sanitizeSearch(string $search): string
    {
        $search = trim($search);
        $search = substr($search, 0, self::MAX_SEARCH_LENGTH);

        return preg_replace('/[^\p{L}\p{N}\s\-_.,]/u', '', $search);
    }

    /**
     * Parse price value
     *
     * @param mixed $price
     */
    private function parsePrice($price): ?float
    {
        if ($price === null || $price === '') {
            return null;
        }

        $price = (float) $price;
        return $price >= 0 ? $price : null;
    }

    /**
     * Validate sort field
     */
    private function validateSortField(string $field): string
    {
        $field = strtolower(trim($field));

        if (in_array($field, self::ALLOWED_SORT_FIELDS, true)) {
            return $field;
        }

        // Default to created_at if invalid
        return 'created_at';
    }

    /**
     * Validate sort direction
     */
    private function validateSortDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));

        return in_array($direction, ['ASC', 'DESC'], true)
            ? $direction
            : 'DESC';
    }

    /**
     * Validate date field
     */
    private function validateDateField(string $field): string
    {
        $field = strtolower(trim($field));

        return in_array($field, self::ALLOWED_DATE_FIELDS, true)
            ? $field
            : 'created_at';
    }

    /**
     * Convert to repository filter array
     */
    public function toRepositoryFilters(): array
    {
        $filters = [];

        if ($this->status !== null) {
            $filters['status'] = $this->status;
        }

        if ($this->categoryIds !== null) {
            $filters['category_id'] = $this->categoryIds;
        }

        if ($this->search !== null) {
            $filters['search'] = $this->search;
        }

        if ($this->minPrice !== null || $this->maxPrice !== null) {
            $filters['price_range'] = [
                'min' => $this->minPrice,
                'max' => $this->maxPrice
            ];
        }

        if ($this->marketplaceId !== null) {
            $filters['marketplace_id'] = $this->marketplaceId;
        }

        if ($this->badgeIds !== null) {
            $filters['badge_ids'] = $this->badgeIds;
        }

        if ($this->hasActiveLinks !== null) {
            $filters['has_active_links'] = $this->hasActiveLinks;
        }

        if ($this->needsPriceUpdate !== null) {
            $filters['needs_price_update'] = $this->needsPriceUpdate;
        }

        if ($this->needsLinkValidation !== null) {
            $filters['needs_link_validation'] = $this->needsLinkValidation;
        }

        if ($this->dateFrom !== null || $this->dateTo !== null) {
            $filters['date_range'] = [
                'from' => $this->dateFrom,
                'to' => $this->dateTo,
                'field' => $this->validateDateField($this->dateField)
            ];
        }

        if ($this->verifiedBy !== null) {
            $filters['verified_by'] = $this->verifiedBy;
        }

        return $filters;
    }

    /**
     * Get sort configuration
     */
    public function getSortString(): string
    {
        return $this->sortBy . ' ' . $this->sortDirection;
    }

    /**
     * Get cache key for this query
     */
    public function getCacheKey(string $prefix = 'product_query_'): string
    {
        $components = [
            'status' => $this->status ? implode(',', $this->status) : 'all',
            'categories' => $this->categoryIds ? implode(',', $this->categoryIds) : 'all',
            'search' => $this->search ?: 'none',
            'min_price' => $this->minPrice ?? 'none',
            'max_price' => $this->maxPrice ?? 'none',
            'marketplace' => $this->marketplaceId ?? 'none',
            'badges' => $this->badgeIds ? implode(',', $this->badgeIds) : 'none',
            'has_active_links' => $this->hasActiveLinks ? '1' : '0',
            'sort' => $this->sortBy . '_' . $this->sortDirection,
            'admin_mode' => $this->adminMode ? '1' : '0',
            'trashed' => $this->includeTrashed ? '1' : '0'
        ];

        return $prefix . md5(serialize($components));
    }

    /**
     * Validate the query parameters
     *
     * @return array [valid: bool, errors: array]
     */
    public function validate(): array
    {
        $errors = [];

        // Validate status values
        if ($this->status !== null) {
            $validStatuses = ProductStatus::all();
            foreach ($this->status as $status) {
                if (!in_array($status, $validStatuses, true)) {
                    $errors[] = "Invalid status value: {$status}";
                }
            }
        }

        // Validate price range
        if ($this->minPrice !== null && $this->maxPrice !== null && $this->minPrice > $this->maxPrice) {
            $errors[] = 'Minimum price cannot be greater than maximum price';
        }

        // Validate dates
        if ($this->dateFrom !== null && !strtotime($this->dateFrom)) {
            $errors[] = 'Invalid date_from format';
        }
        if ($this->dateTo !== null && !strtotime($this->dateTo)) {
            $errors[] = 'Invalid date_to format';
        }

        // Validate category IDs
        if ($this->categoryIds !== null) {
            foreach ($this->categoryIds as $categoryId) {
                if ($categoryId <= 0) {
                    $errors[] = 'Invalid category ID';
                    break;
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors
        ];
    }

    /**
     * Check if this is a public query
     */
    public function isPublicQuery(): bool
    {
        return !$this->adminMode &&
               $this->status === [ProductStatus::PUBLISHED->value] &&
               $this->hasActiveLinks === true;
    }

    /**
     * Check if this is an admin query
     */
    public function isAdminQuery(): bool
    {
        return $this->adminMode;
    }

    /**
     * Check if query has any filters applied
     */
    public function hasFilters(): bool
    {
        return $this->status !== null ||
               $this->categoryIds !== null ||
               $this->search !== null ||
               $this->minPrice !== null ||
               $this->maxPrice !== null ||
               $this->marketplaceId !== null ||
               $this->badgeIds !== null ||
               $this->hasActiveLinks !== null ||
               $this->needsPriceUpdate !== null ||
               $this->needsLinkValidation !== null ||
               $this->dateFrom !== null ||
               $this->dateTo !== null ||
               $this->verifiedBy !== null;
    }

    /**
     * Get filter summary for logging
     */
    public function toFilterSummary(): array
    {
        return [
            'status' => $this->status,
            'category_count' => $this->categoryIds ? count($this->categoryIds) : 0,
            'has_search' => $this->search !== null,
            'price_range' => $this->minPrice !== null || $this->maxPrice !== null,
            'marketplace_filter' => $this->marketplaceId !== null,
            'badge_count' => $this->badgeIds ? count($this->badgeIds) : 0,
            'date_range' => $this->dateFrom !== null || $this->dateTo !== null,
            'sort' => $this->getSortString(),
            'mode' => $this->adminMode ? 'admin' : 'public',
            'include_trashed' => $this->includeTrashed
        ];
    }

    // Getters for all properties (immutable)

    public function getStatus(): ?array
    {
        return $this->status;
    }
    public function getCategoryIds(): ?array
    {
        return $this->categoryIds;
    }
    public function getSearch(): ?string
    {
        return $this->search;
    }
    public function getMinPrice(): ?float
    {
        return $this->minPrice;
    }
    public function getMaxPrice(): ?float
    {
        return $this->maxPrice;
    }
    public function getMarketplaceId(): ?int
    {
        return $this->marketplaceId;
    }
    public function getBadgeIds(): ?array
    {
        return $this->badgeIds;
    }
    public function getHasActiveLinks(): ?bool
    {
        return $this->hasActiveLinks;
    }
    public function getNeedsPriceUpdate(): ?bool
    {
        return $this->needsPriceUpdate;
    }
    public function getNeedsLinkValidation(): ?bool
    {
        return $this->needsLinkValidation;
    }
    public function getDateFrom(): ?string
    {
        return $this->dateFrom;
    }
    public function getDateTo(): ?string
    {
        return $this->dateTo;
    }
    public function getDateField(): ?string
    {
        return $this->dateField;
    }
    public function getSortBy(): string
    {
        return $this->sortBy;
    }
    public function getSortDirection(): string
    {
        return $this->sortDirection;
    }
    public function getIncludeTrashed(): bool
    {
        return $this->includeTrashed;
    }
    public function getAdminMode(): bool
    {
        return $this->adminMode;
    }
    public function getVerifiedBy(): ?int
    {
        return $this->verifiedBy;
    }

    /**
     * Create a copy with modified properties
     */
    public function with(array $changes): self
    {
        $clone = clone $this;

        foreach ($changes as $key => $value) {
            if (property_exists($clone, $key)) {
                $clone->$key = $value;
            }
        }

        return $clone;
    }

    /**
     * Convert to array for debugging/logging
     */
    public function toArray(): array
    {
        return [
            'filters' => $this->toRepositoryFilters(),
            'sort' => $this->getSortString(),
            'admin_mode' => $this->adminMode,
            'include_trashed' => $this->includeTrashed,
            'cache_key' => $this->getCacheKey()
        ];
    }
}
