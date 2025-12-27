<?php

namespace App\Contracts;

use App\DTOs\BaseDTO;
use App\Entities\MarketplaceBadge;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Closure;

/**
 * Marketplace Badge Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for marketplace badge business operations.
 * Defines the protocol for all marketplace badge-related business logic with strict type safety.
 *
 * @package App\Contracts
 */
interface MarketplaceBadgeInterface extends BaseInterface
{
    // ==================== CRUD OPERATIONS ====================

    /**
     * Create a new marketplace badge with business validation
     *
     * @param BaseDTO $requestDTO Create badge request data
     * @return MarketplaceBadge Created badge entity
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules are violated
     */
    public function createMarketplaceBadge(BaseDTO $requestDTO): MarketplaceBadge;

    /**
     * Update an existing marketplace badge with business validation
     *
     * @param int $badgeId Badge ID to update
     * @param BaseDTO $requestDTO Update badge request data
     * @return MarketplaceBadge Updated badge entity
     * @throws NotFoundException If badge not found
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules are violated
     */
    public function updateMarketplaceBadge(int $badgeId, BaseDTO $requestDTO): MarketplaceBadge;

    /**
     * Get marketplace badge by ID with proper authorization
     *
     * @param int $badgeId Badge ID
     * @param bool $withArchived Include archived badges
     * @return MarketplaceBadge Badge entity
     * @throws NotFoundException If badge not found
     * @throws AuthorizationException If not authorized
     */
    public function getMarketplaceBadge(int $badgeId, bool $withArchived = false): MarketplaceBadge;

    /**
     * Get marketplace badge by label (case-insensitive)
     *
     * @param string $label Badge label
     * @param bool $withArchived Include archived badges
     * @return MarketplaceBadge|null Badge entity or null
     */
    public function getMarketplaceBadgeByLabel(string $label, bool $withArchived = false): ?MarketplaceBadge;

    /**
     * Get marketplace badge by icon
     *
     * @param string $icon Icon name
     * @return array<MarketplaceBadge> Array of badges with specified icon
     */
    public function getMarketplaceBadgesByIcon(string $icon): array;

    /**
     * Delete marketplace badge with business validation
     *
     * @param int $badgeId Badge ID to delete
     * @param bool $force Force delete (bypass soft delete)
     * @return bool True if successful
     * @throws NotFoundException If badge not found
     * @throws DomainException If badge cannot be deleted (e.g., in use)
     * @throws AuthorizationException If not authorized
     */
    public function deleteMarketplaceBadge(int $badgeId, bool $force = false): bool;

    // ==================== BULK OPERATIONS ====================

    /**
     * Archive marketplace badge (soft delete with business rules)
     *
     * @param int $badgeId Badge ID to archive
     * @return bool True if successful
     * @throws NotFoundException If badge not found
     * @throws DomainException If badge cannot be archived
     */
    public function archiveMarketplaceBadge(int $badgeId): bool;

    /**
     * Restore archived marketplace badge
     *
     * @param int $badgeId Badge ID to restore
     * @return bool True if successful
     * @throws NotFoundException If badge not found
     */
    public function restoreMarketplaceBadge(int $badgeId): bool;

    /**
     * Bulk archive marketplace badges with validation
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @return int Number of successfully archived badges
     * @throws DomainException If any badge cannot be archived
     */
    public function bulkArchiveMarketplaceBadges(array $badgeIds): int;

    /**
     * Bulk restore marketplace badges
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @return int Number of successfully restored badges
     */
    public function bulkRestoreMarketplaceBadges(array $badgeIds): int;

    /**
     * Bulk update marketplace badge status
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @param string $status New status
     * @param array $context Additional context
     * @return int Number of successfully updated badges
     * @throws DomainException If status transition is invalid
     */
    public function bulkUpdateStatus(array $badgeIds, string $status, array $context = []): int;

    // ==================== QUERY OPERATIONS ====================

    /**
     * Get all marketplace badges with filtering and pagination
     *
     * @param array $filters Query filters
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: array<MarketplaceBadge>, pagination: array}
     */
    public function getAllMarketplaceBadges(array $filters = [], int $perPage = 25, int $page = 1): array;

    /**
     * Get active marketplace badges (not archived)
     *
     * @param string $orderDirection Sort direction
     * @return array<MarketplaceBadge> Array of active badges
     */
    public function getActiveMarketplaceBadges(string $orderDirection = 'ASC'): array;

    /**
     * Get archived marketplace badges
     *
     * @param string $orderDirection Sort direction
     * @return array<MarketplaceBadge> Array of archived badges
     */
    public function getArchivedMarketplaceBadges(string $orderDirection = 'ASC'): array;

    /**
     * Search marketplace badges by label with partial matching
     *
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array<MarketplaceBadge> Array of matching badges
     */
    public function searchMarketplaceBadges(string $searchTerm, int $limit = 10, int $offset = 0): array;

    /**
     * Get marketplace badges by icon prefix
     *
     * @param string $iconPrefix Icon prefix (e.g., 'fas fa-')
     * @return array<MarketplaceBadge> Array of badges with icon prefix
     */
    public function getMarketplaceBadgesByIconPrefix(string $iconPrefix): array;

    /**
     * Get marketplace badges with icons
     *
     * @return array<MarketplaceBadge> Array of badges with icons
     */
    public function getMarketplaceBadgesWithIcons(): array;

    /**
     * Get marketplace badges without icons
     *
     * @return array<MarketplaceBadge> Array of badges without icons
     */
    public function getMarketplaceBadgesWithoutIcons(): array;

    /**
     * Get marketplace badges by color
     *
     * @param string $color Color to filter by
     * @return array<MarketplaceBadge> Array of badges with specified color
     */
    public function getMarketplaceBadgesByColor(string $color): array;

    /**
     * Get common marketplace badges (predefined system badges)
     *
     * @return array<MarketplaceBadge> Array of common badges
     */
    public function getCommonMarketplaceBadges(): array;

    /**
     * Get unassigned marketplace badges (not used by any link)
     *
     * @return array<MarketplaceBadge> Array of unassigned badges
     */
    public function getUnassignedMarketplaceBadges(): array;

    /**
     * Get marketplace badges with FontAwesome icons
     *
     * @return array<MarketplaceBadge> Array of badges with FontAwesome icons
     */
    public function getMarketplaceBadgesWithFontAwesomeIcons(): array;

    // ==================== BUSINESS VALIDATION ====================

    /**
     * Check if marketplace badge can be archived
     *
     * @param int $badgeId Badge ID
     * @return array{can_archive: bool, reasons: array<string>}
     */
    public function canArchiveMarketplaceBadge(int $badgeId): array;

    /**
     * Check if marketplace badge can be deleted
     *
     * @param int $badgeId Badge ID
     * @return array{can_delete: bool, reasons: array<string>}
     */
    public function canDeleteMarketplaceBadge(int $badgeId): array;

    /**
     * Check if marketplace badge label is unique
     *
     * @param string $label Badge label
     * @param int|null $excludeId Badge ID to exclude (for updates)
     * @return bool True if label is unique
     */
    public function isMarketplaceBadgeLabelUnique(string $label, ?int $excludeId = null): bool;

    /**
     * Validate marketplace badge icon format
     *
     * @param string|null $icon Icon to validate
     * @return array{is_valid: bool, errors: array<string>}
     */
    public function validateMarketplaceBadgeIcon(?string $icon): array;

    /**
     * Validate marketplace badge color format
     *
     * @param string|null $color Color to validate
     * @return array{is_valid: bool, errors: array<string>}
     */
    public function validateMarketplaceBadgeColor(?string $color): array;

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * Get marketplace badge usage statistics
     *
     * @param int $limit Result limit
     * @return array<array{badge: MarketplaceBadge, usage_count: int}>
     */
    public function getMarketplaceBadgeUsageStatistics(int $limit = 20): array;

    /**
     * Get marketplace badge statistics
     *
     * @return array{
     *     total: int,
     *     active: int,
     *     archived: int,
     *     with_icons: int,
     *     without_icons: int,
     *     with_color: int,
     *     without_color: int
     * }
     */
    public function getMarketplaceBadgeStatistics(): array;

    /**
     * Get marketplace badge icon distribution
     *
     * @return array<array{icon_prefix: string, count: int, percentage: float}>
     */
    public function getMarketplaceBadgeIconDistribution(): array;

    /**
     * Get marketplace badge color distribution
     *
     * @return array<array{color: string, count: int, percentage: float}>
     */
    public function getMarketplaceBadgeColorDistribution(): array;

    // ==================== SYSTEM OPERATIONS ====================

    /**
     * Initialize common marketplace badges
     *
     * @return array{created: int, skipped: int, badges: array<MarketplaceBadge>}
     */
    public function initializeCommonMarketplaceBadges(): array;

    /**
     * Create a sample marketplace badge for testing
     *
     * @param array $overrides Optional property overrides
     * @return MarketplaceBadge Created sample badge
     */
    public function createSampleMarketplaceBadge(array $overrides = []): MarketplaceBadge;

    /**
     * Clean up unused marketplace badges
     *
     * @param int $daysUnused Minimum days of non-usage
     * @param bool $archiveFirst Archive before deletion
     * @return array{archived: int, deleted: int, errors: array<string>}
     */
    public function cleanupUnusedMarketplaceBadges(int $daysUnused = 90, bool $archiveFirst = true): array;

    // ==================== ASSIGNMENT OPERATIONS ====================

    /**
     * Assign marketplace badge to link
     *
     * @param int $badgeId Badge ID
     * @param int $linkId Link ID
     * @return bool True if successful
     * @throws NotFoundException If badge or link not found
     * @throws DomainException If assignment fails
     */
    public function assignBadgeToLink(int $badgeId, int $linkId): bool;

    /**
     * Remove marketplace badge from link
     *
     * @param int $badgeId Badge ID
     * @param int $linkId Link ID
     * @return bool True if successful
     * @throws NotFoundException If badge or link not found
     */
    public function removeBadgeFromLink(int $badgeId, int $linkId): bool;

    /**
     * Get links assigned to marketplace badge
     *
     * @param int $badgeId Badge ID
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array<array{link_id: int, product_id: int, store_name: string}>
     */
    public function getLinksForMarketplaceBadge(int $badgeId, int $limit = 50, int $offset = 0): array;

    /**
     * Get marketplace badges assigned to link
     *
     * @param int $linkId Link ID
     * @return array<MarketplaceBadge> Array of assigned badges
     */
    public function getMarketplaceBadgesForLink(int $linkId): array;

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all marketplace badge-related cache
     *
     * @return bool True if successful
     */
    public function clearMarketplaceBadgeCache(): bool;

    /**
     * Preload marketplace badge cache for performance
     *
     * @param array<int> $badgeIds Array of badge IDs to preload
     * @return int Number of badges preloaded
     */
    public function preloadMarketplaceBadgeCache(array $badgeIds): int;

    /**
     * Get marketplace badge cache statistics
     *
     * @return array{
     *     total_keys: int,
     *     memory_usage: string,
     *     hit_rate: float,
     *     keys_by_type: array<string, int>
     * }
     */
    public function getMarketplaceBadgeCacheStats(): array;

    // ==================== BATCH PROCESSING ====================

    /**
     * Process batch marketplace badge updates with progress tracking
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @param Closure $updateOperation Update operation for each badge
     * @param int $batchSize Batch size
     * @param callable|null $progressCallback Progress callback
     * @return array{processed: int, successful: int, failed: int, errors: array<string, string>}
     */
    public function processBatchMarketplaceBadgeUpdates(
        array $badgeIds,
        Closure $updateOperation,
        int $batchSize = 50,
        ?callable $progressCallback = null
    ): array;

    // ==================== IMPORT/EXPORT ====================

    /**
     * Import marketplace badges from external source
     *
     * @param array $importData Array of badge data to import
     * @param array $options Import options
     * @return array{imported: int, skipped: int, errors: array<string, string>}
     * @throws DomainException If import fails
     */
    public function importMarketplaceBadges(array $importData, array $options = []): array;

    /**
     * Export marketplace badges to array format
     *
     * @param array<int> $badgeIds Array of badge IDs to export
     * @param array $options Export options
     * @return array<array> Array of exported badge data
     */
    public function exportMarketplaceBadges(array $badgeIds, array $options = []): array;
}