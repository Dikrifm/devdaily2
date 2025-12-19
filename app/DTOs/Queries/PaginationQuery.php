<?php

namespace App\DTOs\Queries;

use InvalidArgumentException;

/**
 * Pagination Query DTO
 *
 * Immutable DTO for pagination parameters with comprehensive validation.
 * Supports page-based pagination with offset calculation and cache integration.
 *
 * @package App\DTOs\Queries
 */
final class PaginationQuery
{
    // Pagination properties
    private int $page = 1;
    private int $perPage = 20;
    private int $maxPerPage = 100;
    private int $defaultPerPage = 20;

    // Cache properties
    private string $cachePrefix = 'page_';
    private ?int $cacheTtl = null;

    // Validation constants
    public const MIN_PAGE = 1;
    public const MIN_PER_PAGE = 1;
    public const MAX_PER_PAGE = 200; // Absolute maximum

    // Sort direction constants
    public const SORT_ASC = 'ASC';
    public const SORT_DESC = 'DESC';

    // Page calculation properties
    private ?int $totalItems = null;
    private ?int $totalPages = null;

    /**
     * Private constructor for immutability
     */
    private function __construct()
    {
    }

    /**
     * Create PaginationQuery from request array
     *
     * @param array $config Configuration overrides
     */
    public static function fromRequest(array $requestData, array $config = []): self
    {
        $query = new self();

        // Apply configuration
        if (isset($config['default_per_page'])) {
            $query->defaultPerPage = max(self::MIN_PER_PAGE, (int) $config['default_per_page']);
        }

        if (isset($config['max_per_page'])) {
            $query->maxPerPage = max($query->defaultPerPage, (int) $config['max_per_page']);
        }

        if (isset($config['cache_prefix'])) {
            $query->cachePrefix = (string) $config['cache_prefix'];
        }

        if (isset($config['cache_ttl'])) {
            $query->cacheTtl = (int) $config['cache_ttl'];
        }

        // Apply request parameters
        $query = $query->applyRequestParameters($requestData);

        return $query;
    }

    /**
     * Create default pagination query
     */
    public static function default(array $config = []): self
    {
        $query = new self();

        if (isset($config['default_per_page'])) {
            $query->defaultPerPage = max(self::MIN_PER_PAGE, (int) $config['default_per_page']);
        }

        if (isset($config['max_per_page'])) {
            $query->maxPerPage = max($query->defaultPerPage, (int) $config['max_per_page']);
        }

        $query->perPage = $query->defaultPerPage;

        return $query;
    }

    /**
     * Apply request parameters
     */
    private function applyRequestParameters(array $requestData): self
    {
        $clone = clone $this;

        // Parse page parameter
        if (isset($requestData['page'])) {
            $clone->page = $this->parsePage($requestData['page']);
        }

        // Parse per_page parameter (with aliases)
        $perPageKeys = ['per_page', 'perPage', 'limit', 'page_size'];
        foreach ($perPageKeys as $key) {
            if (isset($requestData[$key])) {
                $clone->perPage = $this->parsePerPage($requestData[$key], $clone->maxPerPage);
                break;
            }
        }

        return $clone;
    }

    /**
     * Parse and validate page number
     *
     * @param mixed $page
     */
    private function parsePage($page): int
    {
        $page = (int) $page;

        if ($page < self::MIN_PAGE) {
            return self::MIN_PAGE;
        }

        // Prevent excessively large page numbers
        if ($page > 1000000) {
            return 1000000;
        }

        return $page;
    }

    /**
     * Parse and validate per_page value
     *
     * @param mixed $perPage
     */
    private function parsePerPage($perPage, int $maxPerPage): int
    {
        $perPage = (int) $perPage;

        if ($perPage < self::MIN_PER_PAGE) {
            return self::MIN_PER_PAGE;
        }

        if ($perPage > self::MAX_PER_PAGE) {
            return self::MAX_PER_PAGE;
        }

        if ($perPage > $maxPerPage) {
            return $maxPerPage;
        }

        return $perPage;
    }

    /**
     * Calculate offset for database query
     */
    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * Get limit for database query
     */
    public function getLimit(): int
    {
        return $this->perPage;
    }

    /**
     * Check if this is the first page
     */
    public function isFirstPage(): bool
    {
        return $this->page === 1;
    }

    /**
     * Check if pagination parameters are default
     */
    public function isDefault(): bool
    {
        return $this->page === 1 && $this->perPage === $this->defaultPerPage;
    }

    /**
     * Validate pagination parameters
     *
     * @return array [valid: bool, errors: array, warnings: array]
     */
    public function validate(): array
    {
        $errors = [];
        $warnings = [];

        // Validate page
        if ($this->page < self::MIN_PAGE) {
            $errors[] = sprintf('Page must be at least %d', self::MIN_PAGE);
        }

        // Validate per_page
        if ($this->perPage < self::MIN_PER_PAGE) {
            $errors[] = sprintf('Per page must be at least %d', self::MIN_PER_PAGE);
        }

        if ($this->perPage > $this->maxPerPage) {
            $warnings[] = sprintf(
                'Per page value %d exceeds configured maximum %d, using maximum',
                $this->perPage,
                $this->maxPerPage
            );
            $this->perPage = $this->maxPerPage;
        }

        if ($this->perPage > self::MAX_PER_PAGE) {
            $errors[] = sprintf('Per page cannot exceed %d', self::MAX_PER_PAGE);
        }

        // Calculate potential performance issues
        if ($this->getOffset() > 10000) {
            $warnings[] = 'Large offset detected, consider using keyset pagination for better performance';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Generate cache key for this pagination query
     *
     * @param string|null $prefix Override default prefix
     */
    public function getCacheKey(?string $prefix = null): string
    {
        $prefix = $prefix ?? $this->cachePrefix;

        return sprintf('%s%d_%d', $prefix, $this->page, $this->perPage);
    }

    /**
     * Generate cache key pattern for all pages with same per_page
     * Useful for cache invalidation
     */
    public function getCachePattern(?string $prefix = null): string
    {
        $prefix = $prefix ?? $this->cachePrefix;
        return $prefix . '*_' . $this->perPage;
    }

    /**
     * Generate cache key for page data with total items
     */
    public function getCacheKeyWithTotal(int $totalItems, ?string $prefix = null): string
    {
        $prefix = $prefix ?? $this->cachePrefix;

        return sprintf('%s%d_%d_%d', $prefix, $this->page, $this->perPage, $totalItems);
    }

    /**
     * Calculate and set total pages
     */
    public function withTotalItems(int $totalItems): self
    {
        $clone = clone $this;
        $clone->totalItems = $totalItems;
        $clone->totalPages = (int) ceil($totalItems / $this->perPage);

        return $clone;
    }

    /**
     * Generate pagination metadata for API response
     *
     * @param int|null $totalItems If null, uses internal totalItems
     */
    public function generateMetadata(?int $totalItems = null): array
    {
        $total = $totalItems ?? $this->totalItems;

        if ($total === null) {
            throw new InvalidArgumentException('Total items must be provided or set via withTotalItems()');
        }

        $totalPages = (int) ceil($total / $this->perPage);
        $currentPage = min($this->page, $totalPages > 0 ? $totalPages : 1);
        $hasMorePages = $currentPage < $totalPages;

        return [
            'pagination' => [
                'total' => $total,
                'per_page' => $this->perPage,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'has_more_pages' => $hasMorePages,
                'first_page' => 1,
                'last_page' => $totalPages > 0 ? $totalPages : 1,
                'from' => ($currentPage - 1) * $this->perPage + 1,
                'to' => min($currentPage * $this->perPage, $total),
                'has_previous' => $currentPage > 1,
                'has_next' => $hasMorePages,
                'previous_page' => $currentPage > 1 ? $currentPage - 1 : null,
                'next_page' => $hasMorePages ? $currentPage + 1 : null,
            ],
            'query' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'offset' => $this->getOffset(),
                'limit' => $this->getLimit(),
            ]
        ];
    }

    /**
     * Generate links for API response (HATEOAS style)
     *
     * @param string $baseUrl Base URL for links
     * @param array $queryParams Additional query parameters to preserve
     */
    public function generateLinks(string $baseUrl, array $queryParams = [], ?int $totalItems = null): array
    {
        $total = $totalItems ?? $this->totalItems;

        if ($total === null) {
            throw new InvalidArgumentException('Total items must be provided or set via withTotalItems()');
        }

        $totalPages = (int) ceil($total / $this->perPage);
        $currentPage = min($this->page, $totalPages > 0 ? $totalPages : 1);

        // Build base query parameters
        $baseParams = array_merge($queryParams, ['per_page' => $this->perPage]);

        $links = [
            'self' => $this->buildUrl($baseUrl, $baseParams, $currentPage),
            'first' => $this->buildUrl($baseUrl, $baseParams, 1),
            'last' => $totalPages > 0 ? $this->buildUrl($baseUrl, $baseParams, $totalPages) : null,
        ];

        if ($currentPage > 1) {
            $links['prev'] = $this->buildUrl($baseUrl, $baseParams, $currentPage - 1);
        }

        if ($currentPage < $totalPages) {
            $links['next'] = $this->buildUrl($baseUrl, $baseParams, $currentPage + 1);
        }

        return array_filter($links);
    }

    /**
     * Build URL with query parameters
     */
    private function buildUrl(string $baseUrl, array $params, int $page): string
    {
        $params['page'] = $page;
        $queryString = http_build_query($params);

        return $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . $queryString;
    }

    /**
     * Check if requested page exists
     */
    public function pageExists(int $totalItems): bool
    {
        if ($totalItems <= 0) {
            return $this->page === 1;
        }

        $totalPages = (int) ceil($totalItems / $this->perPage);
        return $this->page >= 1 && $this->page <= $totalPages;
    }

    /**
     * Get the actual page to use (clamped to valid range)
     */
    public function getClampedPage(int $totalItems): int
    {
        if ($totalItems <= 0) {
            return 1;
        }

        $totalPages = (int) ceil($totalItems / $this->perPage);
        return min(max(1, $this->page), $totalPages);
    }

    /**
     * Create a copy for next page
     */
    public function nextPage(): self
    {
        $clone = clone $this;
        $clone->page++;

        return $clone;
    }

    /**
     * Create a copy for previous page
     */
    public function previousPage(): self
    {
        $clone = clone $this;
        $clone->page = max(1, $this->page - 1);

        return $clone;
    }

    /**
     * Create a copy with specific page
     */
    public function withPage(int $page): self
    {
        $clone = clone $this;
        $clone->page = $this->parsePage($page);

        return $clone;
    }

    /**
     * Create a copy with specific per_page
     */
    public function withPerPage(int $perPage): self
    {
        $clone = clone $this;
        $clone->perPage = $this->parsePerPage($perPage, $this->maxPerPage);

        // Reset page to 1 if per_page changes
        $clone->page = 1;

        return $clone;
    }

    /**
     * Create a copy with cache configuration
     */
    public function withCacheConfig(string $prefix, ?int $ttl = null): self
    {
        $clone = clone $this;
        $clone->cachePrefix = $prefix;
        $clone->cacheTtl = $ttl;

        return $clone;
    }

    // Getters

    public function getPage(): int
    {
        return $this->page;
    }
    public function getPerPage(): int
    {
        return $this->perPage;
    }
    public function getMaxPerPage(): int
    {
        return $this->maxPerPage;
    }
    public function getDefaultPerPage(): int
    {
        return $this->defaultPerPage;
    }
    public function getCachePrefix(): string
    {
        return $this->cachePrefix;
    }
    public function getCacheTtl(): ?int
    {
        return $this->cacheTtl;
    }
    public function getTotalItems(): ?int
    {
        return $this->totalItems;
    }
    public function getTotalPages(): ?int
    {
        return $this->totalPages;
    }

    /**
     * Convert to array for debugging/logging
     */
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'per_page' => $this->perPage,
            'offset' => $this->getOffset(),
            'limit' => $this->getLimit(),
            'max_per_page' => $this->maxPerPage,
            'default_per_page' => $this->defaultPerPage,
            'cache_prefix' => $this->cachePrefix,
            'cache_ttl' => $this->cacheTtl,
            'total_items' => $this->totalItems,
            'total_pages' => $this->totalPages,
            'cache_key' => $this->getCacheKey(),
        ];
    }

    /**
     * Get string representation
     */
    public function toString(): string
    {
        return sprintf(
            'Page %d of %d (per page: %d)',
            $this->page,
            $this->totalPages ?? '?',
            $this->perPage
        );
    }
}
