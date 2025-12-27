<?php

namespace App\Contracts;

use App\DTOs\BaseDTO;
use App\Entities\Badge;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Closure;

/**
 * Badge Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for badge business operations.
 * Defines the protocol for all badge-related business logic with strict type safety.
 *
 * @package App\Contracts
 */
interface BadgeInterface extends BaseInterface
{
    // ==================== CRUD OPERATIONS ====================

    /**
     * Create a new badge with business validation
     *
     * @param BaseDTO $requestDTO Create badge request data
     * @return Badge Created badge entity
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules are violated
     */
    public function createBadge(BaseDTO $requestDTO): Badge;

    /**
     * Update an existing badge with business validation
     *
     * @param int $badgeId Badge ID to update
     * @param BaseDTO $requestDTO Update badge request data
     * @return Badge Updated badge entity
     * @throws NotFoundException If badge not found
     * @throws ValidationException If validation fails
     * @throws DomainException If business rules are violated
     */
    public function updateBadge(int $badgeId, BaseDTO $requestDTO): Badge;

    /**
     * Get badge by ID with proper authorization
     *
     * @param int $badgeId Badge ID
     * @param bool $withArchived Include archived badges
     * @return Badge Badge entity
     * @throws NotFoundException If badge not found
     * @throws AuthorizationException If not authorized
     */
    public function getBadge(int $badgeId, bool $withArchived = false): Badge;

    /**
     * Get badge by label (case-insensitive)
     *
     * @param string $label Badge label
     * @param bool $withArchived Include archived badges
     * @return Badge|null Badge entity or null
     */
    public function getBadgeByLabel(string $label, bool $withArchived = false): ?Badge;

    /**
     * Delete badge with business validation
     *
     * @param int $badgeId Badge ID to delete
     * @param bool $force Force delete (bypass soft delete)
     * @return bool True if successful
     * @throws NotFoundException If badge not found
     * @throws DomainException If badge cannot be deleted (e.g., in use)
     * @throws AuthorizationException If not authorized
     */
    public function deleteBadge(int $badgeId, bool $force = false): bool;

    // ==================== BULK OPERATIONS ====================

    /**
     * Archive badge (soft delete with business rules)
     *
     * @param int $badgeId Badge ID to archive
     * @return bool True if successful
     * @throws NotFoundException If badge not found
     * @throws DomainException If badge cannot be archived
     */
    public function archiveBadge(int $badgeId): bool;

    /**
     * Restore archived badge
     *
     * @param int $badgeId Badge ID to restore
     * @return bool True if successful
     * @throws NotFoundException If badge not found
     */
    public function restoreBadge(int $badgeId): bool;

    /**
     * Bulk archive badges with validation
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @return int Number of successfully archived badges
     * @throws DomainException If any badge cannot be archived
     */
    public function bulkArchiveBadges(array $badgeIds): int;

    /**
     * Bulk restore badges
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @return int Number of successfully restored badges
     */
    public function bulkRestoreBadges(array $badgeIds): int;

    /**
     * Bulk update badge status
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
     * Get all badges with filtering and pagination
     *
     * @param array $filters Query filters
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: array<Badge>, pagination: array}
     */
    public function getAllBadges(array $filters = [], int $perPage = 25, int $page = 1): array;

    /**
     * Get active badges (not archived)
     *
     * @param string $orderDirection Sort direction
     * @return array<Badge> Array of active badges
     */
    public function getActiveBadges(string $orderDirection = 'ASC'): array;

    /**
     * Get archived badges
     *
     * @param string $orderDirection Sort direction
     * @return array<Badge> Array of archived badges
     */
    public function getArchivedBadges(string $orderDirection = 'ASC'): array;

    /**
     * Search badges by label with partial matching
     *
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array<Badge> Array of matching badges
     */
    public function searchBadges(string $searchTerm, int $limit = 10, int $offset = 0): array;

    /**
     * Get badges by color
     *
     * @param string $color Color to filter by
     * @return array<Badge> Array of badges with specified color
     */
    public function getBadgesByColor(string $color): array;

    /**
     * Get badges without assigned color
     *
     * @return array<Badge> Array of badges without color
     */
    public function getBadgesWithoutColor(): array;

    /**
     * Get badges with assigned color
     *
     * @return array<Badge> Array of badges with color
     */
    public function getBadgesWithColor(): array;

    /**
     * Get common badges (predefined system badges)
     *
     * @return array<Badge> Array of common badges
     */
    public function getCommonBadges(): array;

    /**
     * Get unassigned badges (not used by any product)
     *
     * @return array<Badge> Array of unassigned badges
     */
    public function getUnassignedBadges(): array;

    // ==================== BUSINESS VALIDATION ====================

    /**
     * Check if badge can be archived
     *
     * @param int $badgeId Badge ID
     * @return array{can_archive: bool, reasons: array<string>}
     */
    public function canArchiveBadge(int $badgeId): array;

    /**
     * Check if badge can be deleted
     *
     * @param int $badgeId Badge ID
     * @return array{can_delete: bool, reasons: array<string>}
     */
    public function canDeleteBadge(int $badgeId): array;

    /**
     * Check if badge label is unique
     *
     * @param string $label Badge label
     * @param int|null $excludeId Badge ID to exclude (for updates)
     * @return bool True if label is unique
     */
    public function isLabelUnique(string $label, ?int $excludeId = null): bool;

    /**
     * Validate badge color format
     *
     * @param string|null $color Color to validate
     * @return array{is_valid: bool, errors: array<string>}
     */
    public function validateBadgeColor(?string $color): array;

    // ==================== STATISTICS & ANALYTICS ====================

    /**
     * Get badge usage statistics
     *
     * @return array{
     *     total: int,
     *     active: int,
     *     archived: int,
     *     with_color: int,
     *     without_color: int,
     *     most_used: array<array{id: int, label: string, usage_count: int}>
     * }
     */
    public function getBadgeStatistics(): array;

    /**
     * Get badge usage count by badge
     *
     * @param int|null $limit Result limit
     * @param int $offset Result offset
     * @return array<array{id: int, label: string, color: ?string, usage_count: int}>
     */
    public function getBadgeUsageCounts(?int $limit = null, int $offset = 0): array;

    /**
     * Get color distribution statistics
     *
     * @return array<array{color: string, count: int, percentage: float}>
     */
    public function getColorDistribution(): array;

    // ==================== SYSTEM OPERATIONS ====================

    /**
     * Initialize common system badges
     *
     * @return array{created: int, skipped: int, badges: array<Badge>}
     */
    public function initializeCommonBadges(): array;

    /**
     * Create a sample badge for testing
     *
     * @param array $overrides Optional property overrides
     * @return Badge Created sample badge
     */
    public function createSampleBadge(array $overrides = []): Badge;

    /**
     * Clean up unused badges
     *
     * @param int $daysUnused Minimum days of non-usage
     * @param bool $archiveFirst Archive before deletion
     * @return array{archived: int, deleted: int, errors: array<string>}
     */
    public function cleanupUnusedBadges(int $daysUnused = 90, bool $archiveFirst = true): array;

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear all badge-related cache
     *
     * @return bool True if successful
     */
    public function clearBadgeCache(): bool;

    /**
     * Preload badge cache for performance
     *
     * @param array<int> $badgeIds Array of badge IDs to preload
     * @return int Number of badges preloaded
     */
    public function preloadBadgeCache(array $badgeIds): int;

    /**
     * Get badge cache statistics
     *
     * @return array{
     *     total_keys: int,
     *     memory_usage: string,
     *     hit_rate: float,
     *     keys_by_type: array<string, int>
     * }
     */
    public function getBadgeCacheStats(): array;

    // ==================== BATCH PROCESSING ====================

    /**
     * Process batch badge updates with progress tracking
     *
     * @param array<int> $badgeIds Array of badge IDs
     * @param Closure $updateOperation Update operation for each badge
     * @param int $batchSize Batch size
     * @param callable|null $progressCallback Progress callback
     * @return array{processed: int, successful: int, failed: int, errors: array<string, string>}
     */
    public function processBatchBadgeUpdates(
        array $badgeIds,
        Closure $updateOperation,
        int $batchSize = 50,
        ?callable $progressCallback = null
    ): array;

    // ==================== IMPORT/EXPORT ====================

    /**
     * Import badges from external source
     *
     * @param array $importData Array of badge data to import
     * @param array $options Import options
     * @return array{imported: int, skipped: int, errors: array<string, string>}
     * @throws DomainException If import fails
     */
    public function importBadges(array $importData, array $options = []): array;

    /**
     * Export badges to array format
     *
     * @param array<int> $badgeIds Array of badge IDs to export
     * @param array $options Export options
     * @return array<array> Array of exported badge data
     */
    public function exportBadges(array $badgeIds, array $options = []): array;
}