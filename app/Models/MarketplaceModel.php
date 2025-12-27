<?php

namespace App\Models;

use App\Entities\Marketplace;
use CodeIgniter\Database\BaseResult;
use RuntimeException;

/**
 * Marketplace Model
 * 
 * Layer 2: SQL Encapsulator for Marketplace entities.
 * 0% Business Logic - Pure data access layer for e-commerce marketplaces.
 * 
 * @package App\Models
 */
final class MarketplaceModel extends BaseModel
{
    /**
     * Database table name
     * 
     * @var string
     */
    protected $table = 'marketplaces';

    /**
     * Primary key column
     * 
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Entity class for hydration
     * 
     * @var class-string<Marketplace>
     */
    protected $returnType = Marketplace::class;

    /**
     * Fields allowed for mass assignment
     * 
     * @var array<string>
     */
    protected $allowedFields = [
        'name',
        'slug',
        'icon',
        'color',
        'active',
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
        'name' => [
            'label' => 'Marketplace Name',
            'rules' => 'required|max_length[100]|is_unique[marketplaces.name,id,{id}]',
            'errors' => [
                'required' => 'Marketplace name is required',
                'max_length' => 'Marketplace name cannot exceed 100 characters',
                'is_unique' => 'This marketplace name already exists'
            ]
        ],
        'slug' => [
            'label' => 'Marketplace Slug',
            'rules' => 'required|alpha_dash|max_length[50]|is_unique[marketplaces.slug,id,{id}]',
            'errors' => [
                'required' => 'Marketplace slug is required',
                'alpha_dash' => 'Slug can only contain letters, numbers, dashes, and underscores',
                'max_length' => 'Slug cannot exceed 50 characters',
                'is_unique' => 'This slug is already taken'
            ]
        ],
        'icon' => [
            'label' => 'Marketplace Icon',
            'rules' => 'permit_empty|max_length[100]',
            'errors' => [
                'max_length' => 'Icon class cannot exceed 100 characters'
            ]
        ],
        'color' => [
            'label' => 'Marketplace Color',
            'rules' => 'required|regex_match[/^#[0-9A-F]{6}$/i]',
            'errors' => [
                'required' => 'Marketplace color is required',
                'regex_match' => 'Color must be a valid hex code (e.g., #3b82f6)'
            ]
        ],
        'active' => [
            'label' => 'Active Status',
            'rules' => 'required|in_list[0,1]',
            'errors' => [
                'required' => 'Active status is required',
                'in_list' => 'Active status must be either 0 or 1'
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

    // ==================== CORE QUERY METHODS ====================

    /**
     * Find marketplace by slug (case-insensitive)
     * 
     * @param string $slug Marketplace slug
     * @return Marketplace|null
     */
    public function findBySlug(string $slug): ?Marketplace
    {
        $result = $this->where('LOWER(slug)', strtolower($slug))
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Marketplace ? $result : null;
    }

    /**
     * Find marketplace by name (case-insensitive)
     * 
     * @param string $name Marketplace name
     * @return Marketplace|null
     */
    public function findByName(string $name): ?Marketplace
    {
        $result = $this->where('LOWER(name)', strtolower($name))
                      ->where($this->deletedField, null)
                      ->first();

        return $result instanceof Marketplace ? $result : null;
    }

    /**
     * Find active marketplaces
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return Marketplace[]
     */
    public function findActive(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Find inactive marketplaces
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return Marketplace[]
     */
    public function findInactive(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 0)
                      ->orderBy('name', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Find marketplaces with icons
     * 
     * @return Marketplace[]
     */
    public function findWithIcons(): array
    {
        $result = $this->where('icon IS NOT NULL', null)
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Find marketplaces without icons
     * 
     * @return Marketplace[]
     */
    public function findWithoutIcons(): array
    {
        $result = $this->where('icon IS NULL', null)
                      ->orWhere('icon', '')
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Find marketplace by color
     * 
     * @param string $color Hex color code (e.g., #3B82F6)
     * @return Marketplace[]
     */
    public function findByColor(string $color): array
    {
        $result = $this->where('color', strtoupper($color))
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Search marketplaces by name
     * 
     * @param string $searchTerm Search term
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return Marketplace[]
     */
    public function searchByName(string $searchTerm, int $limit = 20, int $offset = 0): array
    {
        $result = $this->like('name', $searchTerm, 'both')
                      ->where($this->deletedField, null)
                      ->orderBy('name', 'ASC')
                      ->findAll($limit, $offset);

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Get all active marketplaces ordered by name
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return Marketplace[]
     */
    public function findAllActive(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Get paginated active marketplaces
     * 
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: Marketplace[], pager: \CodeIgniter\Pager\Pager}
     */
    public function paginateActive(int $perPage = 20, int $page = 1): array
    {
        $total = $this->where($this->deletedField, null)
                     ->where('active', 1)
                     ->countAllResults(false);
        
        $data = $this->where($this->deletedField, null)
                    ->where('active', 1)
                    ->orderBy('name', 'ASC')
                    ->paginate($perPage, 'default', $page);

        $pager = $this->pager;

        return [
            'data' => array_filter($data, fn($item) => $item instanceof Marketplace),
            'pager' => $pager
        ];
    }

    /**
     * Find marketplaces by IDs (batch lookup)
     * 
     * @param array<int|string> $ids Array of marketplace IDs
     * @return Marketplace[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $result = $this->whereIn($this->primaryKey, $ids)
                      ->where($this->deletedField, null)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Check if slug exists (excluding current ID)
     * 
     * @param string $slug Slug to check
     * @param int|string|null $excludeId ID to exclude (for updates)
     * @return bool
     */
    public function slugExists(string $slug, int|string|null $excludeId = null): bool
    {
        $query = $this->where('LOWER(slug)', strtolower($slug))
                     ->where($this->deletedField, null);

        if ($excludeId !== null) {
            $query->where($this->primaryKey . ' !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }

    /**
     * Check if marketplace name exists (excluding current ID)
     * 
     * @param string $name Name to check
     * @param int|string|null $excludeId ID to exclude (for updates)
     * @return bool
     */
    public function nameExists(string $name, int|string|null $excludeId = null): bool
    {
        $query = $this->where('LOWER(name)', strtolower($name))
                     ->where($this->deletedField, null);

        if ($excludeId !== null) {
            $query->where($this->primaryKey . ' !=', $excludeId);
        }

        return $query->countAllResults() > 0;
    }

    // ==================== LINK & STATISTICS QUERY METHODS ====================

    /**
     * Get marketplace link statistics
     * Counts how many active links each marketplace has
     * 
     * @return array<array{marketplace: Marketplace, link_count: int, active_links: int}>
     */
    public function getLinkStatistics(): array
    {
        $builder = $this->builder();
        
        // Join with links table to count all links and active links
        $query = $builder->select('marketplaces.*, 
                                   COUNT(links.id) as link_count,
                                   SUM(CASE WHEN links.active = 1 THEN 1 ELSE 0 END) as active_links')
                        ->join('links', 'links.marketplace_id = marketplaces.id', 'left')
                        ->where('marketplaces.deleted_at', null)
                        ->where('marketplaces.active', 1)
                        ->groupBy('marketplaces.id')
                        ->orderBy('link_count', 'DESC')
                        ->orderBy('marketplaces.name', 'ASC')
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $marketplace = new Marketplace($row['name'], $row['slug']);
            $marketplace->setId($row['id']);
            $marketplace->setIcon($row['icon']);
            $marketplace->setColor($row['color']);
            $marketplace->setActive((bool) $row['active']);
            
            $results[] = [
                'marketplace' => $marketplace,
                'link_count' => (int) $row['link_count'],
                'active_links' => (int) $row['active_links']
            ];
        }

        return $results;
    }

    /**
     * Find marketplaces with active links
     * 
     * @return Marketplace[]
     */
    public function findWithActiveLinks(): array
    {
        $builder = $this->builder();
        
        // Subquery to find marketplace IDs with active links
        $subQuery = $builder->db->table('links')
                               ->select('marketplace_id')
                               ->where('deleted_at', null)
                               ->where('active', 1)
                               ->groupBy('marketplace_id');

        $query = $builder->select('marketplaces.*')
                        ->where('marketplaces.deleted_at', null)
                        ->where('marketplaces.active', 1)
                        ->whereIn('marketplaces.id', $subQuery)
                        ->orderBy('marketplaces.name', 'ASC')
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $marketplace = new Marketplace($row['name'], $row['slug']);
            $marketplace->setId($row['id']);
            $marketplace->setIcon($row['icon']);
            $marketplace->setColor($row['color']);
            $marketplace->setActive((bool) $row['active']);
            
            $results[] = $marketplace;
        }

        return $results;
    }

    /**
     * Find marketplaces without any active links
     * Useful for identifying unused marketplaces
     * 
     * @return Marketplace[]
     */
    public function findWithoutActiveLinks(): array
    {
        $builder = $this->builder();
        
        // Subquery to find marketplace IDs with active links
        $subQuery = $builder->db->table('links')
                               ->select('marketplace_id')
                               ->where('deleted_at', null)
                               ->where('active', 1)
                               ->groupBy('marketplace_id');

        $query = $builder->select('marketplaces.*')
                        ->where('marketplaces.deleted_at', null)
                        ->where('marketplaces.active', 1)
                        ->whereNotIn('marketplaces.id', $subQuery)
                        ->orderBy('marketplaces.name', 'ASC')
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $marketplace = new Marketplace($row['name'], $row['slug']);
            $marketplace->setId($row['id']);
            $marketplace->setIcon($row['icon']);
            $marketplace->setColor($row['color']);
            $marketplace->setActive((bool) $row['active']);
            
            $results[] = $marketplace;
        }

        return $results;
    }

    /**
     * Get most used marketplaces (by link count)
     * 
     * @param int $limit Limit results
     * @return array<array{marketplace: Marketplace, link_count: int}>
     */
    public function findMostUsed(int $limit = 10): array
    {
        $builder = $this->builder();
        
        $query = $builder->select('marketplaces.*, COUNT(links.id) as link_count')
                        ->join('links', 'links.marketplace_id = marketplaces.id', 'left')
                        ->where('marketplaces.deleted_at', null)
                        ->where('marketplaces.active', 1)
                        ->where('links.deleted_at', null)
                        ->groupBy('marketplaces.id')
                        ->orderBy('link_count', 'DESC')
                        ->orderBy('marketplaces.name', 'ASC')
                        ->limit($limit)
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $marketplace = new Marketplace($row['name'], $row['slug']);
            $marketplace->setId($row['id']);
            $marketplace->setIcon($row['icon']);
            $marketplace->setColor($row['color']);
            $marketplace->setActive((bool) $row['active']);
            
            $results[] = [
                'marketplace' => $marketplace,
                'link_count' => (int) $row['link_count']
            ];
        }

        return $results;
    }

    // ==================== ADVANCED QUERY METHODS ====================

    /**
     * Get paginated marketplaces with optional filters
     * 
     * @param array{
     *     active?: bool|null,
     *     has_icon?: bool|null,
     *     search?: string|null
     * } $filters
     * @param int $perPage Items per page
     * @param int $page Current page
     * @return array{data: Marketplace[], pager: \CodeIgniter\Pager\Pager, total: int}
     */
    public function paginateWithFilters(array $filters = [], int $perPage = 25, int $page = 1): array
    {
        $builder = $this->builder();

        // Apply base filter
        $builder->where($this->deletedField, null);

        if (isset($filters['active']) && $filters['active'] !== null) {
            $builder->where('active', $filters['active'] ? 1 : 0);
        }

        if (isset($filters['has_icon']) && $filters['has_icon'] !== null) {
            if ($filters['has_icon']) {
                $builder->where('icon IS NOT NULL', null)
                       ->where('icon !=', '');
            } else {
                $builder->groupStart()
                       ->where('icon IS NULL', null)
                       ->orWhere('icon', '')
                       ->groupEnd();
            }
        }

        if (isset($filters['search']) && $filters['search'] !== null) {
            $builder->groupStart()
                   ->like('name', $filters['search'], 'both')
                   ->orLike('slug', $filters['search'], 'both')
                   ->groupEnd();
        }

        // Get total count
        $total = $builder->countAllResults(false);
        
        // Get paginated data
        $data = $builder->orderBy('name', 'ASC')
                       ->paginate($perPage, 'default', $page);

        $pager = $this->pager;

        return [
            'data' => array_filter($data, fn($item) => $item instanceof Marketplace),
            'pager' => $pager,
            'total' => (int) $total
        ];
    }

    /**
     * Find marketplaces with FontAwesome icons
     * 
     * @return Marketplace[]
     */
    public function findWithFontAwesomeIcons(): array
    {
        $result = $this->like('icon', 'fas fa-', 'after')
                      ->orLike('icon', 'far fa-', 'after')
                      ->orLike('icon', 'fab fa-', 'after')
                      ->where($this->deletedField, null)
                      ->where('active', 1)
                      ->orderBy('name', 'ASC')
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Find archived (soft-deleted) marketplaces
     * 
     * @param string $orderDirection 'ASC' or 'DESC'
     * @return Marketplace[]
     */
    public function findArchived(string $orderDirection = 'ASC'): array
    {
        $result = $this->where($this->deletedField . ' IS NOT NULL', null)
                      ->orderBy('name', $orderDirection)
                      ->findAll();

        return array_filter($result, fn($item) => $item instanceof Marketplace);
    }

    /**
     * Get marketplace statistics
     * 
     * @return array{
     *     total: int,
     *     active: int,
     *     inactive: int,
     *     archived: int,
     *     with_icon: int,
     *     without_icon: int,
     *     with_links: int,
     *     without_links: int
     * }
     */
    public function getStatistics(): array
    {
        $total = $this->countAllResults(false);
        
        $active = $this->where($this->deletedField, null)
                      ->where('active', 1)
                      ->countAllResults(false);
        
        $inactive = $this->where($this->deletedField, null)
                        ->where('active', 0)
                        ->countAllResults(false);
        
        $archived = $this->where($this->deletedField . ' IS NOT NULL', null)
                        ->countAllResults(false);
        
        $withIcon = $this->where('icon IS NOT NULL', null)
                        ->where($this->deletedField, null)
                        ->where('active', 1)
                        ->countAllResults(false);
        
        $withoutIcon = $this->where('icon IS NULL', null)
                           ->where($this->deletedField, null)
                           ->where('active', 1)
                           ->countAllResults(false);
        
        // Count marketplaces with active links
        $builder = $this->builder();
        $subQuery = $builder->db->table('links')
                               ->select('marketplace_id')
                               ->where('deleted_at', null)
                               ->where('active', 1)
                               ->groupBy('marketplace_id');
        
        $withLinks = $builder->select('marketplaces.id')
                           ->from('marketplaces')
                           ->join('(' . $subQuery->getCompiledSelect() . ')', 'marketplace_id = marketplaces.id', 'inner')
                           ->where('marketplaces.deleted_at', null)
                           ->where('marketplaces.active', 1)
                           ->countAllResults();
        
        // Count marketplaces without active links
        $withoutLinks = $active - $withLinks;

        return [
            'total' => (int) $total,
            'active' => (int) $active,
            'inactive' => (int) $inactive,
            'archived' => (int) $archived,
            'with_icon' => (int) $withIcon,
            'without_icon' => (int) $withoutIcon,
            'with_links' => (int) $withLinks,
            'without_links' => (int) $withoutLinks
        ];
    }

    /**
     * Get color distribution of marketplaces
     * 
     * @return array<array{color: string, count: int}>
     */
    public function getColorDistribution(): array
    {
        $builder = $this->builder();
        
        $query = $builder->select('color, COUNT(*) as count')
                        ->where($this->deletedField, null)
                        ->where('active', 1)
                        ->groupBy('color')
                        ->orderBy('count', 'DESC')
                        ->get();

        $results = [];
        foreach ($query->getResultArray() as $row) {
            $results[] = [
                'color' => $row['color'],
                'count' => (int) $row['count']
            ];
        }

        return $results;
    }

    // ==================== OVERRIDDEN METHODS ====================

    /**
     * Insert data with validation
     * Override to ensure type safety for Marketplace entity
     * 
     * @param array<string, mixed>|object|null $data
     * @return int|string|false
     */
    public function insert($data = null, bool $returnID = true)
    {
        // Convert Marketplace entity to array if needed
        if ($data instanceof Marketplace) {
            $data = [
                'name' => $data->getName(),
                'slug' => $data->getSlug(),
                'icon' => $data->getIcon(),
                'color' => strtoupper($data->getColor()),
                'active' => $data->isActive() ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        } elseif (is_array($data) && isset($data['color'])) {
            // Ensure color is uppercase
            $data['color'] = strtoupper($data['color']);
        }

        return parent::insert($data, $returnID);
    }

    /**
     * Update data with validation
     * Override to ensure type safety for Marketplace entity
     * 
     * @param array<string, mixed>|int|string|null $id
     * @param array<string, mixed>|object|null $data
     * @return bool
     */
    public function update($id = null, $data = null): bool
    {
        // Convert Marketplace entity to array if needed
        if ($data instanceof Marketplace) {
            $updateData = [
                'name' => $data->getName(),
                'slug' => $data->getSlug(),
                'icon' => $data->getIcon(),
                'color' => strtoupper($data->getColor()),
                'active' => $data->isActive() ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Only update if values changed
            return $this->updateIfChanged($id, $updateData);
        } elseif (is_array($data) && isset($data['color'])) {
            // Ensure color is uppercase
            $data['color'] = strtoupper($data['color']);
        }

        return parent::update($id, $data);
    }

    /**
     * Physical delete protection
     * Override to enforce soft delete only for marketplaces
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
     * Activate marketplace
     * 
     * @param int|string $id Marketplace ID
     * @return bool
     */
    public function activate(int|string $id): bool
    {
        $data = [
            'active' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($id, $data);
    }

    /**
     * Deactivate marketplace
     * 
     * @param int|string $id Marketplace ID
     * @return bool
     */
    public function deactivate(int|string $id): bool
    {
        $data = [
            'active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->update($id, $data);
    }

    /**
     * Bulk archive marketplaces
     * 
     * @param array<int|string> $ids Marketplace IDs to archive
     * @return int Number of archived marketplaces
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
     * Bulk restore archived marketplaces
     * 
     * @param array<int|string> $ids Marketplace IDs to restore
     * @return int Number of restored marketplaces
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
     * Bulk activate marketplaces
     * 
     * @param array<int|string> $ids Marketplace IDs to activate
     * @return int Number of activated marketplaces
     */
    public function bulkActivate(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $data = [
            'active' => 1,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Bulk deactivate marketplaces
     * 
     * @param array<int|string> $ids Marketplace IDs to deactivate
     * @return int Number of deactivated marketplaces
     */
    public function bulkDeactivate(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        $data = [
            'active' => 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return $this->bulkUpdate($ids, $data);
    }

    /**
     * Initialize sample marketplaces if they don't exist
     * Useful for database seeding
     * 
     * @return array{created: int, existing: int}
     */
    public function initializeSamples(): array
    {
        $sampleMarketplaces = Marketplace::createSamples();
        $created = 0;
        $existing = 0;

        foreach ($sampleMarketplaces as $marketplace) {
            if (!$this->slugExists($marketplace->getSlug())) {
                $this->insert($marketplace);
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

    /**
     * Create a sample marketplace (for testing/demo)
     * 
     * @param array<string, mixed> $overrides Override default values
     * @return Marketplace
     */
    public function createSample(array $overrides = []): Marketplace
    {
        $defaults = [
            'name' => 'Sample Marketplace',
            'slug' => 'sample-marketplace',
            'icon' => 'fas fa-store',
            'color' => '#3B82F6',
            'active' => true
        ];

        $data = array_merge($defaults, $overrides);

        $marketplace = new Marketplace($data['name'], $data['slug']);
        $marketplace->setIcon($data['icon']);
        $marketplace->setColor($data['color']);
        $marketplace->setActive($data['active']);

        return $marketplace;
    }
}