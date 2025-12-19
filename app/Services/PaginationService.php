<?php

namespace App\Services;

use App\DTOs\Queries\PaginationQuery;
use CodeIgniter\Pager\Pager;
use Config\Pager as PagerConfig;

class PaginationService
{
    /**
     * @var PagerConfig
     */
    protected $config;

    /**
     * @var Pager|null
     */
    protected $pager;

    /**
     * Constructor.
     *
     * @param PagerConfig $config
     */
    public function __construct(?PagerConfig $config = null)
    {
        $this->config = $config ?? config('Pager');
    }

    /**
     * Create pagination metadata from PaginationQuery and total items.
     *
     * @param PaginationQuery $query
     * @param int $totalItems
     * @return array
     */
    public function createPagination(PaginationQuery $query, int $totalItems): array
    {
        $page = $query->getPage();
        $perPage = $query->getPerPage();
        $totalPages = $this->calculateTotalPages($totalItems, $perPage);

        // Clamp page number to valid range
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }

        return [
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'current_page' => $page,
            'per_page' => $perPage,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages,
            'from' => $this->calculateFrom($page, $perPage, $totalItems),
            'to' => $this->calculateTo($page, $perPage, $totalItems),
            'offset' => $query->getOffset(),
            'limit' => $query->getLimit(),
        ];
    }

    /**
     * Generate pagination links for API responses.
     *
     * @param string $baseUrl
     * @param PaginationQuery $query
     * @param int $totalItems
     * @param array $additionalParams Additional query parameters
     * @return array
     */
    public function createLinks(
        string $baseUrl,
        PaginationQuery $query,
        int $totalItems,
        array $additionalParams = []
    ): array {
        $page = $query->getPage();
        $perPage = $query->getPerPage();
        $totalPages = $this->calculateTotalPages($totalItems, $perPage);

        $links = [
            'first' => null,
            'last' => null,
            'prev' => null,
            'next' => null,
            'self' => $this->buildUrl($baseUrl, $page, $perPage, $additionalParams),
        ];

        // First page
        if ($totalPages > 1) {
            $links['first'] = $this->buildUrl($baseUrl, 1, $perPage, $additionalParams);
        }

        // Last page
        if ($totalPages > 1) {
            $links['last'] = $this->buildUrl($baseUrl, $totalPages, $perPage, $additionalParams);
        }

        // Previous page
        if ($page > 1) {
            $links['prev'] = $this->buildUrl($baseUrl, $page - 1, $perPage, $additionalParams);
        }

        // Next page
        if ($page < $totalPages) {
            $links['next'] = $this->buildUrl($baseUrl, $page + 1, $perPage, $additionalParams);
        }

        return array_filter($links);
    }

    /**
     * Create CodeIgniter Pager instance for view rendering.
     *
     * @param string $group
     * @param int $totalItems
     * @param int $perPage
     * @param int|null $currentPage
     * @return Pager
     */
    public function createPager(
        string $group,
        int $totalItems,
        int $perPage,
        ?int $currentPage = null
    ): Pager {
        $pager = service('pager');

        return $pager->makeLinks(
            $currentPage ?? 1,
            $perPage,
            $totalItems,
            $group,
            2 // Number of links to show on each side
        );
    }

    /**
     * Validate and normalize pagination parameters from request.
     *
     * @param array $requestData
     * @param array $config
     * @return PaginationQuery
     */
    public function createFromRequest(array $requestData, array $config = []): PaginationQuery
    {
        return PaginationQuery::fromRequest($requestData, $config);
    }

    /**
     * Get default pagination configuration.
     *
     * @return PaginationQuery
     */
    public function getDefault(): PaginationQuery
    {
        return PaginationQuery::default();
    }

    /**
     * Calculate total pages.
     *
     * @param int $totalItems
     * @param int $perPage
     * @return int
     */
    protected function calculateTotalPages(int $totalItems, int $perPage): int
    {
        if ($perPage <= 0) {
            return 0;
        }

        return (int) ceil($totalItems / $perPage);
    }

    /**
     * Calculate "from" number (showing X-Y of Z).
     *
     * @param int $page
     * @param int $perPage
     * @param int $totalItems
     * @return int
     */
    protected function calculateFrom(int $page, int $perPage, int $totalItems): int
    {
        if ($totalItems === 0) {
            return 0;
        }

        return (($page - 1) * $perPage) + 1;
    }

    /**
     * Calculate "to" number (showing X-Y of Z).
     *
     * @param int $page
     * @param int $perPage
     * @param int $totalItems
     * @return int
     */
    protected function calculateTo(int $page, int $perPage, int $totalItems): int
    {
        if ($totalItems === 0) {
            return 0;
        }

        $to = $page * $perPage;
        return min($to, $totalItems);
    }

    /**
     * Build URL with pagination parameters.
     *
     * @param string $baseUrl
     * @param int $page
     * @param int $perPage
     * @param array $additionalParams
     * @return string
     */
    protected function buildUrl(
        string $baseUrl,
        int $page,
        int $perPage,
        array $additionalParams = []
    ): string {
        $params = array_merge($additionalParams, [
            'page' => $page,
            'per_page' => $perPage,
        ]);

        // Remove null values
        $params = array_filter($params, function ($value) {
            return $value !== null;
        });

        // Build query string
        $queryString = http_build_query($params);

        // Handle base URL with existing query string
        $separator = strpos($baseUrl, '?') === false ? '?' : '&';

        return $queryString ? $baseUrl . $separator . $queryString : $baseUrl;
    }

    /**
     * Generate cache key for paginated results.
     *
     * @param string $baseKey
     * @param PaginationQuery $query
     * @return string
     */
    public function generateCacheKey(string $baseKey, PaginationQuery $query): string
    {
        return $query->getCacheKey($baseKey);
    }

    /**
     * Generate cache pattern for paginated results (for clearing).
     *
     * @param string $baseKey
     * @param PaginationQuery $query
     * @return string
     */
    public function generateCachePattern(string $baseKey, PaginationQuery $query): string
    {
        return $query->getCachePattern($baseKey);
    }

    /**
     * Check if current page is within valid range.
     *
     * @param int $page
     * @param int $perPage
     * @param int $totalItems
     * @return bool
     */
    public function isValidPage(int $page, int $perPage, int $totalItems): bool
    {
        if ($totalItems === 0) {
            return $page === 1;
        }

        $totalPages = $this->calculateTotalPages($totalItems, $perPage);
        return $page >= 1 && $page <= $totalPages;
    }

    /**
     * Get recommended per_page values for UI dropdown.
     *
     * @return array
     */
    public function getPerPageOptions(): array
    {
        return [
            ['value' => 10, 'label' => '10 items'],
            ['value' => 20, 'label' => '20 items'],
            ['value' => 50, 'label' => '50 items'],
            ['value' => 100, 'label' => '100 items'],
        ];
    }

    /**
     * Get pagination summary for logging/monitoring.
     *
     * @param PaginationQuery $query
     * @param int $totalItems
     * @return array
     */
    public function getSummary(PaginationQuery $query, int $totalItems): array
    {
        $page = $query->getPage();
        $perPage = $query->getPerPage();
        $totalPages = $this->calculateTotalPages($totalItems, $perPage);

        return [
            'page' => $page,
            'per_page' => $perPage,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'offset' => $query->getOffset(),
            'is_first_page' => $page === 1,
            'is_last_page' => $page === $totalPages || $totalPages === 0,
            'has_pagination' => $totalPages > 1,
        ];
    }
}
