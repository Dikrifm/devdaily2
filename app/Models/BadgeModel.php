<?php

namespace App\Models;

use App\Entities\Badge;
use CodeIgniter\Database\BaseResult;
use RuntimeException;

/**
 * Badge Model
 * 
 * Layer 2: SQL Encapsulator for Badge entities.
 * 0% Business Logic - Pure data access layer.
 * 
 * @package App\Models
 */
final class BadgeModel extends BaseModel
{
    /**
     * Database table name
     * 
     * @var string
     */
    protected $table = 'badges';

    /**
     * Primary key column
     * 
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Entity class for hydration
     * 
     * @var class-string<Badge>
     */
    protected $returnType = Badge::class;

    /**
     * Fields allowed for mass assignment
     * 
     * @var array<string>
     */
    protected $allowedFields = [
        'label',
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
            'label' => 'Badge Label',
            'rules' => 'required|max_length[100]|is_unique[badges.label,id,{id}]',
            'errors' => [
                'required' => 'Badge label is required',
                'max_length' => 'Badge label cannot exceed 100 characters',
                'is_unique' => 'This badge label already exists'
            ]
        ],
        'color' => [
            'label' => 'Badge Color',
            'rules' => 'permit_empty|regex_match[/^#[0-9A-F]{6}$/i]',
            'errors' => [
                'regex_match' => 'Color must be a valid hex code (e.g., #ef4444)'
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
     * Find badge by exact label (case-insensitive)
     * 
     * @param string $label Badge label to search
     * @return Badge|null
     */
    public function findByLabel(string $label): ?Badge
    {
        $result = $this->where('LOWER(label)', strtolower($label))
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Badge ? $result : null;
    }

    /**
     * Find badges by color
     * 
     * @param string $color Hex color code (e.g., #EF4444)
     * @return Badge[]
     */
    public function findByColor(string $color): array
    {
        $result = $this->where('color', strtoupper($color))
                      ->where($this->deletedField, null)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Find badges without custom color (NULL color)
     * 
     * @return Badge[]
     */
    public function findWithoutColor(): array
    {
        $result = $this->where('color IS NULL')
                      ->where($this->deletedField, null)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Find badges with custom color (NOT NULL)
     * 
     * @return Badge[]
     */
    public function findWithColor(): array
    {
        $result = $this->where('color IS NOT NULL')
                      ->where($this->deletedField, null)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Search badges by label with LIKE
     * 
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return Badge[]
     */
    public function searchByLabel(string $searchTerm, int $limit = 10, int $offset = 0): array
    {
        $result = $this->like('label', $searchTerm, 'both')
                      ->where($this->deletedField, null)
                      ->orderBy('label', 'ASC')
                      ->findAll($limit, $offset);

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Get all active badges ordered by label
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return Badge[]
     */
    public function findAllActive(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField, null)
                      ->orderBy('label', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Get paginated active badges
     * 
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: Badge[], pager: \CodeIgniter\Pager\Pager}
     */
    public function paginateActive(int $perPage = 20, int $page = 1): array
    {
        $total = $this->where($this->deletedField, null)->countAllResults(false);
        
        $data = $this->where($this->deletedField, null)
                    ->orderBy('label', 'ASC')
                    ->paginate($perPage, 'default', $page);

        $pager = $this->pager;

        return [
            'data' => array_filter($data, fn($item) => $item instanceof Badge),
            'pager' => $pager
        ];
    }

    /**
     * Find badges by IDs (batch lookup)
     * 
     * @param array<int|string> $ids Array of badge IDs
     * @return Badge[]
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

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Check if badge label exists (excluding current ID)
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
     * Get badges count by color status
     * 
     * @return array{with_color: int, without_color: int}
     */
    public function countByColorStatus(): array
    {
        $withColor = $this->where('color IS NOT NULL')
                         ->where($this->deletedField, null)
                         ->countAllResults(false);

        $withoutColor = $this->where('color IS NULL')
                           ->where($this->deletedField, null)
                           ->countAllResults(false);

        return [
            'with_color' => (int) $withColor,
            'without_color' => (int) $withoutColor
        ];
    }

    /**
     * Get most used badges (by product assignment)
     * Note: This requires joining with product_badges table
     * 
     * @param int $limit Limit results
     * @return array<array{badge: Badge, usage_count: int}>
     */
    public function findMostUsed(int $limit = 10): array
    {
        // Since this is Layer 2, we keep the query simple
        // Complex joins should be handled via Model Scopes or Repository
        $builder = $this->builder();
        
        // Example join query - simplified for demonstration
        // In production, this would be a proper join with product_badges table
        $query = $builder->select('badges.*, COUNT(product_badges.id) as usage_count')
                        ->join('product_badges', 'product_badges.badge_id = badges.id', 'left')
                        ->where('badges.deleted_at', null)
                        ->groupBy('badges.id')
                        ->orderBy('usage_count', 'DESC')
                        ->orderBy('badges.label', 'ASC')
                        ->limit($limit)
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $badge = new Badge($row['label']);
            $badge->setId($row['id']);
            $badge->setColor($row['color']);
            
            $results[] = [
                'badge' => $badge,
                'usage_count' => (int) $row['usage_count']
            ];
        }

        return $results;
    }

    /**
     * Find archived (soft-deleted) badges
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return Badge[]
     */
    public function findArchived(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField . ' IS NOT NULL', null)
                      ->orderBy('label', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Badge);
    }

    /**
     * Get badge statistics
     * 
     * @return array{
     *     total: int,
     *     active: int,
     *     archived: int,
     *     with_color: int,
     *     without_color: int
     * }
     */
    public function getStatistics(): array
    {
        $total = $this->countAllResults(false); // false = don't reset query
        
        $active = $this->where($this->deletedField, null)
                      ->countAllResults(false);
        
        $archived = $this->where($this->deletedField . ' IS NOT NULL', null)
                        ->countAllResults(false);
        
        $colorStats = $this->countByColorStatus();

        return [
            'total' => (int) $total,
            'active' => (int) $active,
            'archived' => (int) $archived,
            'with_color' => $colorStats['with_color'],
            'without_color' => $colorStats['without_color']
        ];
    }

    // ==================== OVERRIDDEN METHODS ====================

    /**
     * Insert data with validation
     * Override to ensure type safety
     * 
     * @param array<string, mixed>|object|null $data
     * @return int|string|false
     */
    public function insert($data = null, bool $returnID = true)
    {
        // Convert Badge entity to array if needed
        if ($data instanceof Badge) {
            $data = [
                'label' => $data->getLabel(),
                'color' => $data->getColor(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }

        return parent::insert($data, $returnID);
    }

    /**
     * Update data with validation
     * Override to ensure type safety
     * 
     * @param array<string, mixed>|int|string|null $id
     * @param array<string, mixed>|object|null $data
     * @return bool
     */
    public function update($id = null, $data = null): bool
    {
        // Convert Badge entity to array if needed
        if ($data instanceof Badge) {
            $updateData = [
                'label' => $data->getLabel(),
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
     * Override to enforce soft delete only
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
     * Bulk archive badges
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
     * Bulk restore archived badges
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
}