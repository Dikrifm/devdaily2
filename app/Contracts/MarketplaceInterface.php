<?php

namespace App\Contracts;

use App\DTOs\BaseDTO;
use App\Entities\Marketplace;
use App\Entities\Link;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Closure;

/**
 * Marketplace Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for marketplace business operations.
 * Defines the protocol for all marketplace-related business logic with strict type safety.
 *
 * @package App\Contracts
 */
interface MarketplaceInterface extends BaseInterface
{
    // ==================== CORE CRUD OPERATIONS ====================

    /**
     * Create a new marketplace with business validation
     *
     * @param BaseDTO $requestDTO Create marketplace request data
     * @return Marketplace Created marketplace entity
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules are violated
     */
    public function createMarketplace(BaseDTO $requestDTO): Marketplace;

    /**
     * Update an existing marketplace with business validation
     *
     * @param int $marketplaceId Marketplace ID to update
     * @param BaseDTO $requestDTO Update marketplace request data
     * @return Marketplace Updated marketplace entity
     * @throws NotFoundException If marketplace not found
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules are violated
     */
    public function updateMarketplace(int $marketplaceId, BaseDTO $requestDTO): Marketplace;

    /**
     * Get marketplace by ID with proper authorization
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $withTrashed Include archived marketplaces
     * @return Marketplace Marketplace entity
     * @throws NotFoundException If marketplace not found
     * @throws AuthorizationException If not authorized
     */
    public function getMarketplace(int $marketplaceId, bool $withTrashed = false): Marketplace;

    /**
     * Get marketplace by slug
     *
     * @param string $slug Marketplace slug
     * @param bool $withTrashed Include archived marketplaces
     * @return Marketplace|null Marketplace entity or null
     */
    public function getMarketplaceBySlug(string $slug, bool $withTrashed = false): ?Marketplace;

    /**
     * Get marketplace by name
     *
     * @param string $name Marketplace name
     * @param bool $withTrashed Include archived marketplaces
     * @return Marketplace|null Marketplace entity or null
     */
    public function getMarketplaceByName(string $name, bool $withTrashed = false): ?Marketplace;

    /**
     * Delete marketplace with business validation
     *
     * @param int $marketplaceId Marketplace ID to delete
     * @param bool $force Force delete (bypass soft delete)
     * @param string|null $reason Reason for deletion
     * @return bool True if successful
     * @throws NotFoundException If marketplace not found
     * @throws DomainException If marketplace cannot be deleted
     * @throws AuthorizationException If not authorized
     */
    public function deleteMarketplace(int $marketplaceId, bool $force = false, ?string $reason = null): bool;

    // ==================== STATUS MANAGEMENT ====================

    /**
     * Activate marketplace
     *
     * @param int $marketplaceId Marketplace ID to activate
     * @return bool True if successful
     * @throws NotFoundException If marketplace not found
     * @throws DomainException If activation fails
     */
    public function activateMarketplace(int $marketplaceId): bool;

    /**
     * Deactivate marketplace
     *
     * @param int $marketplaceId Marketplace ID to deactivate
     * @param string|null $reason Reason for deactivation
     * @return bool True if successful
     * @throws NotFoundException If marketplace not found
     * @throws DomainException If deactivation fails
     */
    public function deactivateMarketplace(int $marketplaceId, ?string $reason = null): bool;

    /**
     * Archive marketplace (soft delete with business rules)
     *
     * @param int $marketplaceId Marketplace ID to archive
     * @param string|null $notes Archive notes
     * @return bool True if successful
     * @throws NotFoundException If marketplace not found
     * @throws DomainException If marketplace cannot be archived
     */
    public function archiveMarketplace(int $marketplaceId, ?string $notes = null): bool;

    /**
     * Restore archived marketplace
     *
     * @param int $marketplaceId Marketplace ID to restore
     * @return bool True if successful
     * @throws NotFoundException If marketplace not found
     */
    public function restoreMarketplace(int $marketplaceId): bool;

    /**
     * Check if marketplace is active
     *
     * @param int $marketplaceId Marketplace ID
     * @return bool True if marketplace is active
     * @throws NotFoundException If marketplace not found
     */
    public function isMarketplaceActive(int $marketplaceId): bool;

    // ==================== BULK OPERATIONS ====================

    /**
     * Bulk update marketplace status
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param string $status New status (active/inactive)
     * @param string|null $reason Reason for status change
     * @return int Number of successfully updated marketplaces
     * @throws DomainException If status transition is invalid
     */
    public function bulkUpdateStatus(array $marketplaceIds, string $status, ?string $reason = null): int;

    /**
     * Bulk activate marketplaces
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param string|null $reason Reason for activation
     * @return int Number of successfully activated marketplaces
     */
    public function bulkActivate(array $marketplaceIds, ?string $reason = null): int;

    /**
     * Bulk deactivate marketplaces
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param string|null $reason Reason for deactivation
     * @return int Number of successfully deactivated marketplaces
     */
    public function bulkDeactivate(array $marketplaceIds, ?string $reason = null): int;

    /**
     * Bulk archive marketplaces
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param bool $force Force deletion of dependent records
     * @return int Number of successfully archived marketplaces
     */
    public function bulkDelete(array $marketplaceIds, bool $force = false): int;

    /**
     * Bulk restore marketplaces
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @return int Number of successfully restored marketplaces
     */
    public function bulkRestore(array $marketplaceIds): int;

    /**
     * Bulk update marketplace data
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param array $updateData Data to update
     * @return int Number of successfully updated marketplaces
     * @throws ValidationException If update data is invalid
     */
    public function bulkUpdate(array $marketplaceIds, array $updateData): int;

    // ==================== QUERY & SEARCH OPERATIONS ====================

    /**
     * Get all marketplaces with filtering and pagination
     *
     * @param array $filters Query filters
     * @param int $perPage Items per page
     * @param int $page Current page
     * @param bool $withTrashed Include archived marketplaces
     * @return array{data: array<Marketplace>, pagination: array}
     */
    public function getAllMarketplaces(
        array $filters = [],
        int $perPage = 25,
        int $page = 1,
        bool $withTrashed = false
    ): array;

    /**
     * Get active marketplaces
     *
     * @param array $filters Additional filters
     * @return array<Marketplace> Array of active marketplaces
     */
    public function getActiveMarketplaces(array $filters = []): array;

    /**
     * Get inactive marketplaces
     *
     * @param array $filters Additional filters
     * @return array<Marketplace> Array of inactive marketplaces
     */
    public function getInactiveMarketplaces(array $filters = []): array;

    /**
     * Get archived marketplaces
     *
     * @param array $filters Additional filters
     * @return array<Marketplace> Array of archived marketplaces
     */
    public function getArchivedMarketplaces(array $filters = []): array;

    /**
     * Search marketplaces by name or slug
     *
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @param bool $activeOnly Return only active marketplaces
     * @return array<Marketplace> Array of matching marketplaces
     */
    public function searchMarketplaces(
        string $searchTerm,
        int $limit = 20,
        int $offset = 0,
        bool $activeOnly = true
    ): array;

    /**
     * Get marketplaces by IDs
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param bool $withTrashed Include archived marketplaces
     * @return array<Marketplace> Array of marketplaces
     */
    public function getMarketplacesByIds(array $marketplaceIds, bool $withTrashed = false): array;

    /**
     * Get marketplaces with active links
     *
     * @param int $minLinks Minimum number of active links
     * @param bool $activeOnly Return only active marketplaces
     * @param int $limit Result limit
     * @return array<Marketplace> Array of marketplaces with active links
     */
    public function getMarketplacesWithActiveLinks(
        int $minLinks = 1,
        bool $activeOnly = true,
        int $limit = 50
    ): array;

    /**
     * Get marketplaces without active links
     *
     * @param bool $activeOnly Return only active marketplaces
     * @param int $limit Result limit
     * @return array<Marketplace> Array of marketplaces without active links
     */
    public function getMarketplacesWithoutActiveLinks(bool $activeOnly = true, int $limit = 50): array;

    /**
     * Get default marketplaces (system defaults)
     *
     * @param bool $activeOnly Return only active marketplaces
     * @return array<Marketplace> Array of default marketplaces
     */
    public function getDefaultMarketplaces(bool $activeOnly = true): array;

    /**
     * Get marketplace suggestions based on query
     *
     * @param string|null $query Search query
     * @param bool $activeOnly Return only active marketplaces
     * @param int $limit Result limit
     * @return array<Marketplace> Array of suggested marketplaces
     */
    public function getMarketplaceSuggestions(?string $query = null, bool $activeOnly = true, int $limit = 20): array;

    // ==================== LINK MANAGEMENT ====================

    /**
     * Get links for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Return only active links
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array<Link> Array of links
     */
    public function getLinksForMarketplace(
        int $marketplaceId,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array;

    /**
     * Count links for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Count only active links
     * @return int Number of links
     */
    public function countLinksForMarketplace(int $marketplaceId, bool $activeOnly = true): int;

    /**
     * Count active links for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @return int Number of active links
     */
    public function countActiveLinksForMarketplace(int $marketplaceId): int;

    /**
     * Get products for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Return only active products
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array Array of products
     */
    public function getProductsForMarketplace(
        int $marketplaceId,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array;

    /**
     * Count products for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Count only active products
     * @return int Number of products
     */
    public function countProductsForMarketplace(int $marketplaceId, bool $activeOnly = true): int;

    /**
     * Get categories for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param bool $activeOnly Return only active categories
     * @return array<array> Array of categories
     */
    public function getCategoriesForMarketplace(int $marketplaceId, bool $activeOnly = true): array;

    /**
     * Get top-selling products for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param int $limit Result limit
     * @param string $period Time period (day, week, month, year)
     * @return array<array> Array of top-selling products
     */
    public function getTopSellingProductsForMarketplace(
        int $marketplaceId,
        int $limit = 10,
        string $period = 'month'
    ): array;

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * Get marketplace statistics
     *
     * @param int|null $marketplaceId Specific marketplace ID or null for global
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     archived: int,
     *     with_links: int,
     *     without_links: int,
     *     link_counts: array<int, int>
     * }
     */
    public function getMarketplaceStatistics(?int $marketplaceId = null): array;

    /**
     * Get link statistics for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $period Time period (day, week, month, year)
     * @return array{
     *     total_links: int,
     *     active_links: int,
     *     total_clicks: int,
     *     total_revenue: float,
     *     average_rating: float,
     *     period_comparison: array
     * }
     */
    public function getLinkStatisticsForMarketplace(int $marketplaceId, string $period = 'month'): array;

    /**
     * Get growth statistics for marketplace
     *
     * @param string $period Time period (day, week, month, year)
     * @return array{
     *     new_marketplaces: int,
     *     new_links: int,
     *     growth_rate: float,
     *     period_comparison: array
     * }
     */
    public function getGrowthStatistics(string $period = 'month'): array;

    /**
     * Get revenue ranking for marketplaces
     *
     * @param string $period Time period (day, week, month, year)
     * @param int $limit Result limit
     * @return array<array{marketplace: Marketplace, revenue: float, ranking: int}>
     */
    public function getRevenueRanking(string $period = 'month', int $limit = 10): array;

    /**
     * Get click ranking for marketplaces
     *
     * @param string $period Time period (day, week, month, year)
     * @param int $limit Result limit
     * @return array<array{marketplace: Marketplace, clicks: int, ranking: int}>
     */
    public function getClickRanking(string $period = 'month', int $limit = 10): array;

    /**
     * Get conversion ranking for marketplaces
     *
     * @param string $period Time period (day, week, month, year)
     * @param int $limit Result limit
     * @return array<array{marketplace: Marketplace, conversion_rate: float, ranking: int}>
     */
    public function getConversionRanking(string $period = 'month', int $limit = 10): array;

    /**
     * Get performance comparison for multiple marketplaces
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param string $period Time period (day, week, month, year)
     * @return array<array{
     *     marketplace_id: int,
     *     name: string,
     *     revenue: float,
     *     clicks: int,
     *     conversion_rate: float,
     *     ranking: int
     * }>
     */
    public function getPerformanceComparison(array $marketplaceIds, string $period = 'month'): array;

    /**
     * Get marketplace health status
     *
     * @param int $marketplaceId Marketplace ID
     * @return array{
     *     status: string,
     *     score: int,
     *     issues: array<string>,
     *     recommendations: array<string>
     * }
     */
    public function getMarketplaceHealthStatus(int $marketplaceId): array;

    // ==================== VALIDATION & CHECKS ====================

    /**
     * Check if marketplace can be deleted
     *
     * @param int $marketplaceId Marketplace ID
     * @return array{can_delete: bool, reasons: array<string>, dependent_records: array}
     */
    public function canDeleteMarketplace(int $marketplaceId): array;

    /**
     * Check if marketplace can be deactivated
     *
     * @param int $marketplaceId Marketplace ID
     * @return array{can_deactivate: bool, reasons: array<string>}
     */
    public function canDeactivateMarketplace(int $marketplaceId): array;

    /**
     * Check if slug is unique
     *
     * @param string $slug Slug to check
     * @param int|null $excludeId Marketplace ID to exclude (for updates)
     * @return bool True if slug is unique
     */
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool;

    /**
     * Check if name is unique
     *
     * @param string $name Name to check
     * @param int|null $excludeId Marketplace ID to exclude (for updates)
     * @return bool True if name is unique
     */
    public function isNameUnique(string $name, ?int $excludeId = null): bool;

    /**
     * Validate marketplace data
     *
     * @param array $data Marketplace data
     * @param string $context Validation context (create/update)
     * @return array{is_valid: bool, errors: array<string, string>}
     */
    public function validateMarketplaceData(array $data, string $context = 'create'): array;

    // ==================== SYSTEM & CONFIGURATION ====================

    /**
     * Create default marketplaces (system initialization)
     *
     * @return array{created: int, skipped: int, marketplaces: array<Marketplace>}
     */
    public function createDefaultMarketplaces(): array;

    /**
     * Get marketplace configuration
     *
     * @param int $marketplaceId Marketplace ID
     * @return array Configuration array
     */
    public function getMarketplaceConfiguration(int $marketplaceId): array;

    /**
     * Update marketplace configuration
     *
     * @param int $marketplaceId Marketplace ID
     * @param array $config Configuration data
     * @return bool True if successful
     * @throws ValidationException If configuration is invalid
     */
    public function updateMarketplaceConfiguration(int $marketplaceId, array $config): bool;

    /**
     * Get allowed domains for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @return array<string> Array of allowed domains
     */
    public function getAllowedDomains(int $marketplaceId): array;

    /**
     * Get icon URL for marketplace
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $size Icon size (small, medium, large)
     * @return string|null Icon URL or null
     */
    public function getMarketplaceIconUrl(int $marketplaceId, string $size = 'medium'): ?string;

    /**
     * Check if marketplace has affiliate program
     *
     * @param int $marketplaceId Marketplace ID
     * @return bool True if marketplace has affiliate program
     */
    public function hasAffiliateProgram(int $marketplaceId): bool;

    /**
     * Check if marketplace supports specific features
     *
     * @param int $marketplaceId Marketplace ID
     * @param array $features Array of features to check
     * @return array<string, bool> Feature support status
     */
    public function supportsFeatures(int $marketplaceId, array $features): array;

    /**
     * Check if marketplace is popular (based on threshold)
     *
     * @param int $marketplaceId Marketplace ID
     * @param int $threshold Popularity threshold (minimum links)
     * @return bool True if marketplace is popular
     */
    public function isPopularMarketplace(int $marketplaceId, int $threshold = 100): bool;

    /**
     * Get similar marketplaces
     *
     * @param int $marketplaceId Marketplace ID
     * @param int $limit Result limit
     * @return array<Marketplace> Array of similar marketplaces
     */
    public function getSimilarMarketplaces(int $marketplaceId, int $limit = 5): array;

    /**
     * Get marketplace summary
     *
     * @param int $marketplaceId Marketplace ID
     * @return array{
     *     basic_info: array,
     *     statistics: array,
     *     performance: array,
     *     health: array
     * }
     */
    public function getMarketplaceSummary(int $marketplaceId): array;

    // ==================== REPORT GENERATION ====================

    /**
     * Generate marketplace report
     *
     * @param int $marketplaceId Marketplace ID
     * @param string $period Time period (day, week, month, year)
     * @param string $format Report format (array, json, csv, pdf)
     * @return mixed Report data in specified format
     */
    public function generateMarketplaceReport(int $marketplaceId, string $period = 'month', string $format = 'array');

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all marketplace-related cache
     *
     * @param int|null $marketplaceId Specific marketplace ID or null for all
     * @return bool True if successful
     */
    public function clearMarketplaceCache(?int $marketplaceId = null): bool;

    /**
     * Get marketplace cache statistics
     *
     * @return array{
     *     total_keys: int,
     *     memory_usage: string,
     *     hit_rate: float,
     *     keys_by_type: array<string, int>
     * }
     */
    public function getMarketplaceCacheStats(): array;

    // ==================== BATCH PROCESSING ====================

    /**
     * Process batch marketplace operations with progress tracking
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs
     * @param Closure $operation Operation to perform on each marketplace
     * @param int $batchSize Batch size
     * @param callable|null $progressCallback Progress callback
     * @return array{processed: int, successful: int, failed: int, errors: array<string, string>}
     */
    public function processBatchMarketplaceOperations(
        array $marketplaceIds,
        Closure $operation,
        int $batchSize = 50,
        ?callable $progressCallback = null
    ): array;

    // ==================== IMPORT/EXPORT ====================

    /**
     * Import marketplaces from external source
     *
     * @param array $importData Array of marketplace data to import
     * @param array $options Import options
     * @return array{imported: int, skipped: int, errors: array<string, string>}
     * @throws DomainException If import fails
     */
    public function importMarketplaces(array $importData, array $options = []): array;

    /**
     * Export marketplaces to array format
     *
     * @param array<int> $marketplaceIds Array of marketplace IDs to export
     * @param array $options Export options
     * @return array<array> Array of exported marketplace data
     */
    public function exportMarketplaces(array $marketplaceIds, array $options = []): array;
}