<?php

namespace App\Repositories\Interfaces;

use App\Repositories\BaseRepositoryInterface;
use App\Entities\Badge;
use App\DTOs\Queries\PaginationQuery;

/**
 * Badge Repository Interface
 *
 * Contract for Badge data operations with type safety and cache management.
 * Extends base repository interface with Badge-specific methods.
 *
 * @extends App\Repositories\BaseRepositoryInterface
 * @package App\Repositories
 */
interface BadgeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find badge by exact label (case-insensitive)
     *
     * @param string $label
     * @return Badge|null
     */
    public function findByLabel(string $label): ?Badge;

    /**
     * Find badges by color
     *
     * @param string $color Hex color code (e.g., #FF0000)
     * @return array<Badge>
     */
    public function findByColor(string $color): array;

    /**
     * Find badges without assigned color
     *
     * @return array<Badge>
     */
    public function findWithoutColor(): array;

    /**
     * Find badges with assigned color
     *
     * @return array<Badge>
     */
    public function findWithColor(): array;

    /**
     * Search badges by label (partial match)
     *
     * @param string $searchTerm
     * @param int $limit
     * @param int $offset
     * @return array<Badge>
     */
    public function searchByLabel(string $searchTerm, int $limit = 10, int $offset = 0): array;

    /**
     * Find all active badges with optional ordering
     *
     * @param string $orderDirection ASC or DESC
     * @return array<Badge>
     */
    public function findAllActive(string $orderDirection = 'ASC'): array;

    /**
     * Check if label already exists (for unique validation)
     *
     * @param string $label
     * @param int|string|null $excludeId
     * @return bool
     */
    public function labelExists(string $label, int|string|null $excludeId = null): bool;

    /**
     * Count badges grouped by color and active status
     *
     * @return array{
     *     with_color: int,
     *     without_color: int,
     *     active: int,
     *     archived: int
     * }
     */
    public function countByColorStatus(): array;

    /**
     * Find most used badges (by product associations)
     *
     * @param int $limit Maximum number of badges to return
     * @return array<Badge>
     */
    public function findMostUsed(int $limit = 10): array;

    /**
     * Find archived (soft-deleted) badges
     *
     * @param string $orderDirection ASC or DESC
     * @return array<Badge>
     */
    public function findArchived(string $orderDirection = 'ASC'): array;

    /**
     * Get badge usage statistics
     *
     * @return array{
     *     total_badges: int,
     *     active_badges: int,
     *     archived_badges: int,
     *     badges_with_color: int,
     *     badges_without_color: int,
     *     most_used_badge: array{id: int|null, label: string, usage_count: int}|null
     * }
     */
    public function getStatistics(): array;

    /**
     * Find common badge types (predefined badges)
     *
     * @return array<Badge>
     */
    public function findCommonBadges(): array;

    /**
     * Initialize common badges if they don't exist
     *
     * @return array{array{created: bool, badge: Badge}}
     */
    public function initializeCommonBadges(): array;

    /**
     * Find badges that are not assigned to any product
     *
     * @return array<Badge>
     */
    public function findUnassignedBadges(): array;

    /**
     * Check if badge can be safely archived
     * Business Rule: Badge cannot be archived if assigned to active products
     *
     * @param int|string $id
     * @return bool
     */
    public function canBeArchived(int|string $id): bool;

    /**
     * Archive badge with safety checks
     *
     * @param int|string $id
     * @return bool
     * @throws \App\Exceptions\DomainException If badge cannot be archived
     */
    public function archiveBadge(int|string $id): bool;

    /**
     * Restore archived badge
     *
     * @param int|string $id
     * @return bool
     */
    public function restoreBadge(int|string $id): bool;

    /**
     * Bulk archive badges with validation
     *
     * @param array<int|string> $ids
     * @return int Number of successfully archived badges
     */
    public function bulkArchive(array $ids): int;

    /**
     * Bulk restore archived badges
     *
     * @param array<int|string> $ids
     * @return int Number of successfully restored badges
     */
    public function bulkRestore(array $ids): int;

    /**
     * Find badges by multiple labels
     *
     * @param array<string> $labels
     * @return array<Badge>
     */
    public function findByLabels(array $labels): array;

    /**
     * Find badges with usage count (for admin dashboard)
     *
     * @param int|null $limit
     * @param int $offset
     * @return array<array{badge: Badge, usage_count: int}>
     */
    public function findWithUsageCount(?int $limit = null, int $offset = 0): array;

    /**
     * Paginate badges with advanced filters
     *
     * @param PaginationQuery $query
     * @param array{
     *     search?: string,
     *     has_color?: bool,
     *     is_active?: bool,
     *     min_usage?: int,
     *     max_usage?: int
     * } $filters
     * @return array{
     *     data: array<Badge>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         from: int,
     *         to: int
     *     }
     * }
     */
    public function paginateWithFilters(PaginationQuery $query, array $filters = []): array;
}