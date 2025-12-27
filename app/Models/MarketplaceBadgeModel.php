<?php

namespace App\Models;

use App\Entities\MarketplaceBadge;
use CodeIgniter\Database\BaseResult;
use RuntimeException;

/**
 * MarketplaceBadge Model
 * 
 * Layer 2: SQL Encapsulator for MarketplaceBadge entities.
 * 0% Business Logic - Pure data access layer for marketplace badges.
 * 
 * @package App\Models
 */
final class MarketplaceBadgeModel extends BaseModel
{
    /**
     * Database table name
     * 
     * @var string
     */
    protected $table = 'marketplace_badges';

    /**
     * Primary key column
     * 
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Entity class for hydration
     * 
     * @var class-string<MarketplaceBadge>
     */
    protected $returnType = MarketplaceBadge::class;

    /**
     * Fields allowed for mass assignment
     * 
     * @var array<string>
     */
    protected $allowedFields = [
        'label',
        'icon',
        'color',
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Validation rules for insert/update
     * 
     * @var array<string, array<string, string>>
     */
    protected $validationRules = [
        'label' => [
            'label' => 'Marketplace Badge Label',
            'rules' => 'required|max_length[100]|is_unique[marketplace_badges.label,id,{id}]',
            'errors' => [
                'required' => 'Marketplace badge label is required',
                'max_length' => 'Marketplace badge label cannot exceed 100 characters',
                'is_unique' => 'This marketplace badge label already exists'
            ]
        ],
        'icon' => [
            'label' => 'Badge Icon',
            'rules' => 'permit_empty|max_length[100]',
            'errors' => [
                'max_length' => 'Icon class cannot exceed 100 characters'
            ]
        ],
        'color' => [
            'label' => 'Badge Color',
            'rules' => 'permit_empty|regex_match[/^#[0-9A-F]{6}$/i]',
            'errors' => [
                'regex_match' => 'Color must be a valid hex code (e.g., #10B981)'
            ]
        ]
    ];

    /**
     * Whether to use timestamps
     * Override from BaseModel for explicit declaration
     * 
     * @var bool
     */
    protected $useTimestamps = true;

    /**
     * Whether to use soft deletes
     * Override from BaseModel for explicit declaration
     * 
     * @var bool
     */
    protected $useSoftDeletes = true;

    /**
     * Date format for timestamps
     * 
     * @var string
     */
    protected $dateFormat = 'datetime';

    // ==================== CUSTOM SCOPES & QUERY METHODS ====================

    /**
     * Find marketplace badge by exact label (case-insensitive)
     * 
     * @param string $label Badge label to search
     * @return MarketplaceBadge|null
     */
    public function findByLabel(string $label): ?MarketplaceBadge
    {
        $result = $this->where('LOWER(label)', strtolower($label))
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof MarketplaceBadge ? $result : null;
    }

    /**
     * Find marketplace badges by icon class
     * 
     * @param string $icon FontAwesome or custom icon class
     * @return MarketplaceBadge[]
     */
    public function findByIcon(string $icon): array
    {
        $result = $this->where('icon', $icon)
                      ->where($this->deletedField, null)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Find marketplace badges with icons (non-null)
     * 
     * @return MarketplaceBadge[]
     */
    public function findWithIcons(): array
    {
        $result = $this->where('icon IS NOT NULL')
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Find marketplace badges without icons (NULL or empty)
     * 
     * @return MarketplaceBadge[]
     */
    public function findWithoutIcons(): array
    {
        $result = $this->where('icon IS NULL')
                      ->orWhere('icon', '')
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Find marketplace badges by color
     * 
     * @param string $color Hex color code (e.g., #10B981)
     * @return MarketplaceBadge[]
     */
    public function findByColor(string $color): array
    {
        $result = $this->where('color', strtoupper($color))
                      ->where($this->deletedField, null)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Search marketplace badges by label with LIKE
     * 
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return MarketplaceBadge[]
     */
    public function searchByLabel(string $searchTerm, int $limit = 10, int $offset = 0): array
    {
        $result = $this->like('label', $searchTerm, 'both')
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll($limit, $offset);

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Get all active marketplace badges ordered by label
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return MarketplaceBadge[]
     */
    public function findAllActive(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField, null)
                      ->orderBy('label', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Get paginated active marketplace badges
     * 
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: MarketplaceBadge[], pager: \CodeIgniter\Pager\Pager}
     */
    public function paginateActive(int $perPage = 20, int $page = 1): array
    {
        $total = $this->where($this->deletedField, null)->countAllResults(false);
        
        $data = $this->where($this->deletedField, null)
                    ->orderBy('label', 'ASC')
                    ->paginate($perPage, 'default', $page);

        $pager = $this->pager;

        return [
            'data' => array_filter($data, fn($item) => $item instanceof MarketplaceBadge),
            'pager' => $pager
        ];
    }

    /**
     * Find marketplace badges by IDs (batch lookup)
     * 
     * @param array<int|string> $ids Array of badge IDs
     * @return MarketplaceBadge[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = $this->whereIn($this->primaryKey, $ids)
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Check if marketplace badge label exists (excluding current ID)
     * 
     * @param string $label Label to check
     * @param int|string|null $excludeId ID to exclude (for updates)
     * @return bool
     */
    public function labelExists(string $label, int|string|null $excludeId = null): bool
    {
        $query = $this->where('LOWER(label)', strtolower($label))
                     ->where($this->deletedField, null);

        if ($excludeId !== null) {
            $query->where($this->primaryKey . ' !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }

    /**
     * Find common/default marketplace badges
     * Based on the predefined common badges in MarketplaceBadge entity
     * 
     * @return MarketplaceBadge[]
     */
    public function findCommonBadges(): array
    {
        $commonLabels = [
            'Official Store',
            'Top Seller',
            'Verified Seller',
            'Fast Delivery',
            'Recommended',
            'Trusted',
            'Choice',
            'Premium Seller'
        ];

        $result = $this->whereIn('label', $commonLabels)
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Find badges by icon prefix (e.g., 'fas fa-')
     * 
     * @param string $iconPrefix Icon class prefix
     * @return MarketplaceBadge[]
     */
    public function findByIconPrefix(string $iconPrefix): array
    {
        $result = $this->like('icon', $iconPrefix, 'after')
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Get marketplace badge usage statistics
     * Counts how many links each badge is assigned to
     * 
     * @param int $limit Limit results
     * @return array<array{badge: MarketplaceBadge, link_count: int}>
     */
    public function findUsageStatistics(int $limit = 20): array
    {
        $builder = $this->builder();
        
        // Join with links table to count assignments
        $query = $builder->select('marketplace_badges.*, COUNT(links.id) as link_count')
                        ->join('links', 'links.marketplace_badge_id = marketplace_badges.id', 'left')
                        ->where('marketplace_badges.deleted_at', null)
                        ->where('links.deleted_at', null)
                        ->groupBy('marketplace_badges.id')
                        ->orderBy('link_count', 'DESC')
                        ->orderBy('marketplace_badges.label', 'ASC')
                        ->limit($limit)
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $badge = new MarketplaceBadge($row['label']);
            $badge->setId($row['id']);
            $badge->setIcon($row['icon']);
            $badge->setColor($row['color']);
            
            $results[] = [
                'badge' => $badge,
                'link_count' => (int) $row['link_count']
            ];
        }

        return $results;
    }

    /**
     * Find badges that are not assigned to any active link
     * Useful for identifying unused badges that can be archived
     * 
     * @return MarketplaceBadge[]
     */
    public function findUnassignedBadges(): array
    {
        $builder = $this->builder();
        
        // Subquery to find badge IDs that are assigned to active links
        $subQuery = $builder->db->table('links')
                               ->select('marketplace_badge_id')
                               ->where('deleted_at', null)
                               ->where('marketplace_badge_id IS NOT NULL', null)
                               ->groupBy('marketplace_badge_id');

        $query = $builder->select('marketplace_badges.*')
                        ->where('marketplace_badges.deleted_at', null)
                        ->whereNotIn('marketplace_badges.id', $subQuery)
                        ->orderBy('marketplace_badges.label', 'ASC')
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $badge = new MarketplaceBadge($row['label']);
            $badge->setId($row['id']);
            $badge->setIcon($row['icon']);
            $badge->setColor($row['color']);
            
            $results[] = $badge;
        }

        return $results;
    }

    /**
     * Find badges with FontAwesome icons
     * 
     * @return MarketplaceBadge[]
     */
    public function findWithFontAwesomeIcons(): array
    {
        $result = $this->like('icon', 'fas fa-', 'after')
                      ->orLike('icon', 'far fa-', 'after')
                      ->orLike('icon', 'fab fa-', 'after')
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Find archived (soft-deleted) marketplace badges
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return MarketplaceBadge[]
     */
    public function findArchived(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField . ' IS NOT NULL', null)
                      ->orderBy('label', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof MarketplaceBadge);
    }

    /**
     * Get marketplace badge statistics
     * 
     * @return array{
     *     total: int,
     *     active: int,
     *     archived: int,
     *     with_icon: int,
     *     without_icon: int,
     *     with_color: int,
     *     without_color: int
     * }
     */
    public function getStatistics(): array
    {
        $total = $this->countAllResults(false);
        
        $active = $this->where($this->deletedField, null)
                      ->countAllResults(false);
        
        $archived = $this->where($this->deletedField . ' IS NOT NULL', null)
                        ->countAllResults(false);
        
        $withIcon = $this->where('icon IS NOT NULL', null)
                        ->where($this->deletedField, null)
                        ->countAllResults(false);
        
        $withoutIcon = $this->where('icon IS NULL', null)
                           ->where($this->deletedField, null)
                           ->countAllResults(false);
        
        $withColor = $this->where('color IS NOT NULL', null)
                         ->where($this->deletedField, null)
                         ->countAllResults(false);
        
        $withoutColor = $this->where('color IS NULL', null)
                           ->where($this->deletedField, null)
                           ->countAllResults(false);

        return [
            'total' => (int) $total,
            'active' => (int) $active,
            'archived' => (int) $archived,
            'with_icon' => (int) $withIcon,
            'without_icon' => (int) $withoutIcon,
            'with_color' => (int) $withColor,
            'without_color' => (int) $withoutColor
        ];
    }

    // ==================== OVERRIDDEN METHODS ====================

    /**
     * Insert data with validation
     * Override to ensure type safety for MarketplaceBadge entity
     * 
     * @param array<string, mixed>|object|null $data
     * @return int|string|false
     */
    public function insert($data = null, bool $returnID = true)
    {
        // Convert MarketplaceBadge entity to array if needed
        if ($data instanceof MarketplaceBadge) {
            $data = [
                'label' => $data->getLabel(),
                'icon' => $data->getIcon(),
                'color' => $data->getColor(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        return parent::insert($data, $returnID);
    }

    /**
     * Update data with validation
     * Override to ensure type safety for MarketplaceBadge entity
     * 
     * @param array<string, mixed>|int|string|null $id
     * @param array<string, mixed>|object|null $data
     * @return bool
     */
    public function update($id = null, $data = null): bool
    {
        // Convert MarketplaceBadge entity to array if needed
        if ($data instanceof MarketplaceBadge) {
            $updateData = [
                'label' => $data->getLabel(),
                'icon' => $data->getIcon(),
                'color' => $data->getColor(),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Only update if values changed
            return $this->updateIfChanged($id, $updateData);
        }

        return parent::update($id, $data);
    }

    /**
     * Physical delete protection
     * Override to enforce soft delete only for marketplace badges
     * 
     * @throws RuntimeException Always, since physical deletes are disabled
     */
    public function delete($id = null, bool $purge = false): bool
    {
        if ($purge) {
            throw new RuntimeException('Physical deletes are disabled in MVP. Use archive() method.');
        }

        return parent::delete($id, false);
    }

    /**
     * Bulk archive marketplace badges
     * 
     * @param array<int|string> $ids Badge IDs to archive
     * @return int Number of archived badges
     */
    public function bulkArchive(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $data = [
            $this->deletedField => date('Y-m-d H:i:s'),
            $this->updatedField => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Bulk restore archived marketplace badges
     * 
     * @param array<int|string> $ids Badge IDs to restore
     * @return int Number of restored badges
     */
    public function bulkRestore(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $data = [
            $this->deletedField => null,
            $this->updatedField => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Initialize common marketplace badges if they don't exist
     * Useful for database seeding
     * 
     * @return array{created: int, existing: int}
     */
    public function initializeCommonBadges(): array
    {
        $commonBadges = MarketplaceBadge::createAllCommon();
        $created = 0;
        $existing = 0;

        foreach ($commonBadges as $badge) {
            if (!$this->labelExists($badge->getLabel())) {
                $this->insert($badge);
                $created++;
            } else {
                $existing++;
            }
        }

        return [
            'created' => $created,
            'existing' => $existing
        ];
    }
}