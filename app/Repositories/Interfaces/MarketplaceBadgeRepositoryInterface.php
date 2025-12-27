<?php

namespace App\Repositories\Interfaces;

use App\Repositories\BaseRepositoryInterface;
use App\Entities\MarketplaceBadge;
use App\DTOs\Queries\PaginationQuery;

/**
 * MarketplaceBadge Repository Interface
 * 
 * Layer 3: Data Orchestrator Contract for MarketplaceBadge
 * 
 * @extends BaseRepositoryInterface<MarketplaceBadge>
 */
interface MarketplaceBadgeRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find marketplace badge by label
     * 
     * @param string $label
     * @return MarketplaceBadge|null
     */
    public function findByLabel(string $label): ?MarketplaceBadge;

    /**
     * Find marketplace badges by icon
     * 
     * @param string $icon
     * @return array<MarketplaceBadge>
     */
    public function findByIcon(string $icon): array;

    /**
     * Find marketplace badges with icons
     * 
     * @return array<MarketplaceBadge>
     */
    public function findWithIcons(): array;

    /**
     * Find marketplace badges without icons
     * 
     * @return array<MarketplaceBadge>
     */
    public function findWithoutIcons(): array;

    /**
     * Find marketplace badges by color
     * 
     * @param string $color
     * @return array<MarketplaceBadge>
     */
    public function findByColor(string $color): array;

    /**
     * Search marketplace badges by label
     * 
     * @param string $searchTerm
     * @param int $limit
     * @param int $offset
     * @return array<MarketplaceBadge>
     */
    public function searchByLabel(string $searchTerm, int $limit = 10, int $offset = 0): array;

    /**
     * Find all active marketplace badges
     * 
     * @param string $orderDirection
     * @return array<MarketplaceBadge>
     */
    public function findAllActive(string $orderDirection = 'ASC'): array;

    /**
     * Paginate active marketplace badges
     * 
     * @param int $perPage
     * @param int $page
     * @return array{data: array<MarketplaceBadge>, pagination: array}
     */
    public function paginateActive(int $perPage = 20, int $page = 1): array;

    /**
     * Find marketplace badges by IDs
     * 
     * @param array<int> $ids
     * @return array<MarketplaceBadge>
     */
    public function findByIds(array $ids): array;

    /**
     * Check if label exists
     * 
     * @param string $label
     * @param int|string|null $excludeId
     * @return bool
     */
    public function labelExists(string $label, int|string|null $excludeId = null): bool;

    /**
     * Find common badges (pre-defined system badges)
     * 
     * @return array<MarketplaceBadge>
     */
    public function findCommonBadges(): array;

    /**
     * Find marketplace badges by icon prefix
     * 
     * @param string $iconPrefix
     * @return array<MarketplaceBadge>
     */
    public function findByIconPrefix(string $iconPrefix): array;

    /**
     * Get usage statistics for badges
     * 
     * @param int $limit
     * @return array
     */
    public function findUsageStatistics(int $limit = 20): array;

    /**
     * Find unassigned badges (not linked to any marketplace)
     * 
     * @return array<MarketplaceBadge>
     */
    public function findUnassignedBadges(): array;

    /**
     * Find badges with FontAwesome icons
     * 
     * @return array<MarketplaceBadge>
     */
    public function findWithFontAwesomeIcons(): array;

    /**
     * Find archived badges
     * 
     * @param string $orderDirection
     * @return array<MarketplaceBadge>
     */
    public function findArchived(string $orderDirection = 'ASC'): array;

    /**
     * Get statistics for marketplace badges
     * 
     * @return array{
     *     total: int,
     *     active: int,
     *     archived: int,
     *     with_icons: int,
     *     with_colors: int
     * }
     */
    public function getStatistics(): array;

    /**
     * Bulk archive badges
     * 
     * @param array<int|string> $ids
     * @return int Number of archived badges
     */
    public function bulkArchive(array $ids): int;

    /**
     * Bulk restore badges
     * 
     * @param array<int|string> $ids
     * @return int Number of restored badges
     */
    public function bulkRestore(array $ids): int;

    /**
     * Initialize common badges (system badges)
     * 
     * @return array<MarketplaceBadge> Created badges
     */
    public function initializeCommonBadges(): array;
}