<?php

namespace App\Repositories\Interfaces;

use App\Entities\Marketplace;
use App\Exceptions\MarketplaceNotFoundException;

interface MarketplaceRepositoryInterface
{
    // ==================== BASIC CRUD OPERATIONS ====================
    
    /**
     * Find marketplace by ID
     *
     * @param int $id Marketplace ID
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return Marketplace|null
     */
    public function find(int $id, bool $withTrashed = false): ?Marketplace;
    
    /**
     * Find marketplace by slug
     *
     * @param string $slug Marketplace slug
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return Marketplace|null
     */
    public function findBySlug(string $slug, bool $withTrashed = false): ?Marketplace;
    
    /**
     * Find marketplace by name (case-insensitive)
     *
     * @param string $name Marketplace name
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return Marketplace|null
     */
    public function findByName(string $name, bool $withTrashed = false): ?Marketplace;
    
    /**
     * Find marketplace by ID or slug
     *
     * @param mixed $identifier ID or slug
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return Marketplace|null
     */
    public function findByIdOrSlug($identifier, bool $withTrashed = false): ?Marketplace;
    
    /**
     * Get all marketplaces with filtering
     *
     * @param array $filters [
     * 'active' => bool,
     * 'search' => string
     * ]
     * @param string $sortBy
     * @param string $sortDirection
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return array
     */
    public function findAll(
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        bool $withTrashed = false
    ): array;
    
    /**
     * Save marketplace (create or update)
     *
     * @param Marketplace $marketplace
     * @return Marketplace
     * @throws \RuntimeException
     */
    public function save(Marketplace $marketplace): Marketplace;
    
    /**
     * Delete marketplace
     *
     * @param int $id Marketplace ID
     * @param bool $force Permanent deletion
     * @return bool
     */
    public function delete(int $id, bool $force = false): bool;
    
    /**
     * Restore soft deleted marketplace
     *
     * @param int $id Marketplace ID
     * @return bool
     */
    public function restore(int $id): bool;
    
    /**
     * Check if marketplace exists
     *
     * @param int $id Marketplace ID
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return bool
     */
    public function exists(int $id, bool $withTrashed = false): bool;
    
    // ==================== STATUS & ACTIVATION MANAGEMENT ====================
    
    /**
     * Activate marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @return bool
     */
    public function activate(int $marketplaceId): bool;
    
    /**
     * Deactivate marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param string|null $reason Reason for deactivation
     * @return bool
     */
    public function deactivate(int $marketplaceId, ?string $reason = null): bool;
    
    /**
     * Archive marketplace (special deactivation)
     *
     * @param int $marketplaceId Marketplace ID
     * @param string|null $notes Archive notes
     * @return bool
     */
    public function archive(int $marketplaceId, ?string $notes = null): bool;
    
    /**
     * Check if marketplace is active
     *
     * @param int $marketplaceId Marketplace ID
     * @return bool
     */
    public function isActive(int $marketplaceId): bool;
    
    /**
     * Bulk update marketplace status
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @param string $status New status (active/inactive/archived)
     * @param string|null $reason Reason for status change
     * @return int Number of affected rows
     */
    public function bulkUpdateStatus(array $marketplaceIds, string $status, ?string $reason = null): int;
    
    // ==================== LINK & PRODUCT RELATIONS ====================
    
    /**
     * Get all links for a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active links
     * @param bool $withTrashed Include soft deleted links
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function getLinks(
        int $marketplaceId,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 100,
        int $offset = 0
    ): array;
    
    /**
     * Count links for a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active links
     * @return int
     */
    public function countLinks(int $marketplaceId, bool $activeOnly = true): int;
    
    /**
     * Count active links for a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @return int
     */
    public function countActiveLinks(int $marketplaceId): int;
    
    /**
     * Get products available on a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active products
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function getProducts(
        int $marketplaceId,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array;
    
    /**
     * Count products on a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active products
     * @return int
     */
    public function countProducts(int $marketplaceId, bool $activeOnly = true): int;
    
    /**
     * Get categories represented on a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Only active categories
     * @return array
     */
    public function getCategories(int $marketplaceId, bool $activeOnly = true): array;
    
    /**
     * Get top selling products on a marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param int $limit Result limit
     * @param string $period Time period (week, month, quarter, year, all)
     * @return array
     */
    public function getTopSellingProducts(int $marketplaceId, int $limit = 10, string $period = 'month'): array;
    
    /**
     * Get marketplace link statistics
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $period Time period (day, week, month, quarter, year)
     * @return array [total_links, active_links, total_clicks, total_revenue, conversion_rate]
     */
    public function getLinkStatistics(int $marketplaceId, string $period = 'month'): array;
    
    // ==================== SEARCH & FILTER ====================
    
    /**
     * Search marketplaces by name or slug
     *
     * @param string $keyword Search term
     * @param bool $activeOnly Only active marketplaces
     * @param bool $withTrashed Include soft deleted marketplaces
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array
     */
    public function search(
        string $keyword,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 50,
        int $offset = 0
    ): array;
    
    /**
     * Find marketplaces by IDs
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @param bool $activeOnly Only active marketplaces
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return array
     */
    public function findByIds(
        array $marketplaceIds,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array;
    
    /**
     * Find marketplaces with active links
     *
     * @param int $minLinks Minimum number of active links
     * @param bool $activeOnly Only active marketplaces
     * @param int $limit Result limit
     * @return array
     */
    public function findWithActiveLinks(int $minLinks = 1, bool $activeOnly = true, int $limit = 50): array;
    
    /**
     * Find marketplaces without active links
     *
     * @param bool $activeOnly Only active marketplaces
     * @param int $limit Result limit
     * @return array
     */
    public function findWithoutActiveLinks(bool $activeOnly = true, int $limit = 50): array;
    
    /**
     * Get marketplaces sorted by performance
     *
     * @param string $metric clicks|revenue|conversion|links
     * @param string $period Time period (week, month, quarter, year, all)
     * @param bool $activeOnly Only active marketplaces
     * @param int $limit Result limit
     * @return array
     */
    public function getByPerformance(
        string $metric = 'revenue',
        string $period = 'month',
        bool $activeOnly = true,
        int $limit = 10
    ): array;
    
    // ==================== STATISTICS & ANALYTICS ====================
    
    /**
     * Get marketplace statistics
     *
     * @param int|null $marketplaceId Marketplace ID (null for system-wide)
     * @return array
     */
    public function getStatistics(?int $marketplaceId = null): array;
    
    /**
     * Count marketplaces by status
     *
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return array [active => int, inactive => int, archived => int]
     */
    public function countByStatus(bool $withTrashed = false): array;
    
    /**
     * Count total marketplaces
     *
     * @param bool $withTrashed Include soft deleted marketplaces
     * @return int
     */
    public function countAll(bool $withTrashed = false): int;
    
    /**
     * Count active marketplaces
     *
     * @return int
     */
    public function countActive(): int;
    
    /**
     * Get marketplace growth statistics
     *
     * @param string $period Time period (week, month, quarter, year)
     * @return array [new_marketplaces, deactivated_marketplaces, growth_rate]
     */
    public function getGrowthStatistics(string $period = 'month'): array;
    
    /**
     * Get revenue statistics by marketplace
     *
     * @param string $period Time period (week, month, quarter, year)
     * @param int $limit Top N marketplaces
     * @return array
     */
    public function getRevenueRanking(string $period = 'month', int $limit = 10): array;
    
    /**
     * Get click statistics by marketplace
     *
     * @param string $period Time period (week, month, quarter, year)
     * @param int $limit Top N marketplaces
     * @return array
     */
    public function getClickRanking(string $period = 'month', int $limit = 10): array;
    
    /**
     * Get conversion rate statistics by marketplace
     *
     * @param string $period Time period (week, month, quarter, year)
     * @param int $limit Top N marketplaces
     * @return array
     */
    public function getConversionRanking(string $period = 'month', int $limit = 10): array;
    
    /**
     * Get performance comparison between marketplaces
     *
     * @param array $marketplaceIds Array of marketplace IDs to compare
     * @param string $period Time period
     * @return array [marketplace_id => [revenue, clicks, conversion]]
     */
    public function getPerformanceComparison(array $marketplaceIds, string $period = 'month'): array;
    
    // ==================== BATCH & BULK OPERATIONS ====================
    
    /**
     * Bulk update marketplaces
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @param array $updateData Data to update
     * @return int Number of affected rows
     */
    public function bulkUpdate(array $marketplaceIds, array $updateData): int;
    
    /**
     * Bulk activate marketplaces
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @param string|null $reason Reason for activation
     * @return int Number of activated marketplaces
     */
    public function bulkActivate(array $marketplaceIds, ?string $reason = null): int;
    
    /**
     * Bulk deactivate marketplaces
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @param string|null $reason Reason for deactivation
     * @return int Number of deactivated marketplaces
     */
    public function bulkDeactivate(array $marketplaceIds, ?string $reason = null): int;
    
    /**
     * Bulk delete marketplaces
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @param bool $force Permanent deletion
     * @return int Number of deleted marketplaces
     */
    public function bulkDelete(array $marketplaceIds, bool $force = false): int;
    
    /**
     * Bulk restore marketplaces
     *
     * @param array $marketplaceIds Array of marketplace IDs
     * @return int Number of restored marketplaces
     */
    public function bulkRestore(array $marketplaceIds): int;
    
    // ==================== VALIDATION & BUSINESS RULES ====================
    
    /**
     * Check if marketplace can be deleted
     *
     * @param int $marketplaceId Marketplace ID
     * @return array [can_delete => bool, reasons => string[], active_links => int]
     */
    public function canDelete(int $marketplaceId): array;
    
    /**
     * Check if marketplace can be deactivated
     *
     * @param int $marketplaceId Marketplace ID
     * @return array [can_deactivate => bool, reasons => string[], active_links => int]
     */
    public function canDeactivate(int $marketplaceId): array;
    
    /**
     * Check if slug is unique
     *
     * @param string $slug Slug to check
     * @param int|null $excludeId Marketplace ID to exclude (for updates)
     * @return bool
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool;
    
    /**
     * Check if name is unique
     *
     * @param string $name Name to check
     * @param int|null $excludeId Marketplace ID to exclude (for updates)
     * @return bool
     */
    public function isNameUnique(string $name, ?int $excludeId = null): bool;
    
    /**
     * Validate marketplace business rules
     *
     * @param Marketplace $marketplace
     * @return array [is_valid => bool, errors => string[]]
     */
    public function validate(Marketplace $marketplace): array;
    
    /**
     * Check if marketplace has active affiliate program
     *
     * @param int $marketplaceId Marketplace ID
     * @return bool
     */
    public function hasAffiliateProgram(int $marketplaceId): bool;
    
    /**
     * Check if marketplace supports specific features
     *
     * @param int $marketplaceId Marketplace ID
     * @param array $features Features to check
     * @return array [feature => bool]
     */
    public function supportsFeatures(int $marketplaceId, array $features): array;
    
    // ==================== CACHE MANAGEMENT ====================
    
    /**
     * Clear marketplace caches
     *
     * @param int|null $marketplaceId Specific marketplace ID (null for all)
     * @return void
     */
    public function clearCache(?int $marketplaceId = null): void;
    
    /**
     * Get cache TTL setting
     *
     * @return int Cache TTL in seconds
     */
    public function getCacheTtl(): int;
    
    /**
     * Set cache TTL
     *
     * @param int $ttl Cache TTL in seconds
     * @return self
     */
    public function setCacheTtl(int $ttl): self;
    
    // ==================== UTILITY & HELPER METHODS ====================
    
    /**
     * Get default marketplaces (commonly used)
     *
     * @param bool $activeOnly Only active marketplaces
     * @return array
     */
    public function getDefaults(bool $activeOnly = true): array;
    
    /**
     * Create default marketplaces if none exist
     *
     * @return array Created marketplaces
     */
    public function createDefaultMarketplaces(): array;
    
    /**
     * Get marketplace suggestions for dropdowns
     *
     * @param string|null $query Search query
     * @param bool $activeOnly Only active marketplaces
     * @param int $limit Result limit
     * @return array [id => name, ...]
     */
    public function getSuggestions(?string $query = null, bool $activeOnly = true, int $limit = 20): array;
    
    /**
     * Get marketplace domains (for validation)
     *
     * @param int $marketplaceId Marketplace ID
     * @return array Allowed domains for this marketplace
     */
    public function getAllowedDomains(int $marketplaceId): array;
    
    /**
     * Get marketplace configuration
     *
     * @param int $marketplaceId Marketplace ID
     * @return array Configuration array
     */
    public function getConfiguration(int $marketplaceId): array;
    
    /**
     * Update marketplace configuration
     *
     * @param int $marketplaceId Marketplace ID
     * @param array $config Configuration data
     * @return bool
     */
    public function updateConfiguration(int $marketplaceId, array $config): bool;
    
    /**
     * Get marketplace icon URL
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $size Icon size (small, medium, large)
     * @return string|null
     */
    public function getIconUrl(int $marketplaceId, string $size = 'medium'): ?string;
    
    /**
     * Generate marketplace report
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $period Time period
     * @param string $format Report format (array, json, csv)
     * @return mixed
     */
    public function generateReport(int $marketplaceId, string $period = 'month', string $format = 'array');
    
    /**
     * Check if marketplace is popular (by link count)
     *
     * @param int $marketplaceId Marketplace ID
     * @param int $threshold Minimum links to be considered popular
     * @return bool
     */
    public function isPopular(int $marketplaceId, int $threshold = 100): bool;
    
    /**
     * Get marketplace health status
     *
     * @param int $marketplaceId Marketplace ID
     * @return array [status => string, issues => array, last_activity => string]
     */
    public function getHealthStatus(int $marketplaceId): array;
    
    /**
     * Find similar marketplaces
     *
     * @param int $marketplaceId Marketplace ID
     * @param int $limit Result limit
     * @return array
     */
    public function findSimilar(int $marketplaceId, int $limit = 5): array;
    
    /**
     * Get marketplace summary for quick views
     *
     * @param int $marketplaceId Marketplace ID
     * @return array
     */
    public function getSummary(int $marketplaceId): array;
}
