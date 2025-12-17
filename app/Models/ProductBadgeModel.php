<?php

namespace App\Models;

use App\Entities\ProductBadge;
use App\Entities\Badge;
use CodeIgniter\Database\Exceptions\DataException;

/**
 * ProductBadge Model
 * 
 * Special handling for composite primary key table (product_id, badge_id)
 * Note: CodeIgniter 4 Models don't natively support composite primary keys
 * 
 * @package App\Models
 */
class ProductBadgeModel extends BaseModel
{
    /**
     * Table name
     * 
     * @var string
     */
    protected $table = 'product_badges';

    /**
     * Primary key - using product_id as primary for CI4 Model compatibility
     * Actual composite key: (product_id, badge_id)
     * 
     * @var string
     */
    protected $primaryKey = 'product_id';

    /**
     * Return type
     * 
     * @var string
     */
    protected $returnType = ProductBadge::class;

    /**
     * Allowed fields
     * 
     * @var array
     */
    protected $allowedFields = [
        'product_id',
        'badge_id',
        'assigned_at',
        'assigned_by'
    ];

    /**
     * Use timestamps? NO - table uses assigned_at instead
     * 
     * @var bool
     */
    protected $useTimestamps = false;

    /**
     * Use soft deletes? NO - table doesn't have deleted_at
     * 
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * Validation rules for insert
     * 
     * @var array
     */
    protected $validationRules = [
        'product_id' => 'required|integer|is_not_unique[products.id]',
        'badge_id'   => 'required|integer|is_not_unique[badges.id]',
        'assigned_by' => 'permit_empty|integer|is_not_unique[admins.id]',
        'assigned_at' => 'permit_empty|valid_date'
    ];

    /**
     * Validation messages
     * 
     * @var array
     */
    protected $validationMessages = [
        'product_id' => [
            'required' => 'Product ID is required',
            'is_not_unique' => 'Product does not exist'
        ],
        'badge_id' => [
            'required' => 'Badge ID is required',
            'is_not_unique' => 'Badge does not exist'
        ],
        'assigned_by' => [
            'is_not_unique' => 'Admin does not exist'
        ]
    ];

    /**
     * Before insert callback
     * Set assigned_at if not provided
     * 
     * @param array $data
     * @return array
     */
    protected function beforeInsert(array $data): array
    {
        // Set assigned_at to current time if not provided
        if (!isset($data['assigned_at']) || empty($data['assigned_at'])) {
            $data['assigned_at'] = date('Y-m-d H:i:s');
        }

        return $data;
    }

    /**
     * Find by composite key (product_id + badge_id)
     * This is the actual primary key lookup
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return ProductBadge|null
     */
    public function findByCompositeKey(int $product_id, int $badge_id): ?ProductBadge
    {
        return $this->where('product_id', $product_id)
                    ->where('badge_id', $badge_id)
                    ->first();
    }

    /**
     * Delete by composite key
     * Override parent delete for composite key support
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return bool
     * @throws \Exception
     */
    public function deleteByCompositeKey(int $product_id, int $badge_id): bool
    {
        $builder = $this->builder();
        
        $builder->where('product_id', $product_id);
        $builder->where('badge_id', $badge_id);
        
        $result = $builder->delete();
        
        // Clear relevant caches
        $this->clearBadgeCache($product_id);
        
        return $result !== false;
    }

    /**
     * Assign badge to product
     * Creates relationship if not exists
     * 
     * @param int $product_id
     * @param int $badge_id
     * @param int|null $assigned_by
     * @return bool
     */
    public function assignBadge(int $product_id, int $badge_id, ?int $assigned_by = null): bool
    {
        // Check if relationship already exists
        $existing = $this->findByCompositeKey($product_id, $badge_id);
        if ($existing) {
            // Already assigned
            return true;
        }

        $data = [
            'product_id'  => $product_id,
            'badge_id'    => $badge_id,
            'assigned_by' => $assigned_by
        ];

        $result = $this->insert($data, false); // Don't return ID
        
        if ($result) {
            $this->clearBadgeCache($product_id);
        }
        
        return $result !== false;
    }

    /**
     * Remove badge from product
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return bool
     */
    public function removeBadge(int $product_id, int $badge_id): bool
    {
        return $this->deleteByCompositeKey($product_id, $badge_id);
    }

    /**
     * Get all badges for a product
     * 
     * @param int $product_id
     * @return array
     */
    public function getProductBadges(int $product_id): array
    {
        $cacheKey = $this->cacheKey("product_{$product_id}_badges");
        
        return $this->cached($cacheKey, function() use ($product_id) {
            $badgeModel = model(BadgeModel::class);
            
            $badgeIds = $this->select('badge_id')
                            ->where('product_id', $product_id)
                            ->findAll();
            
            if (empty($badgeIds)) {
                return [];
            }
            
            $ids = array_column($badgeIds, 'badge_id');
            return $badgeModel->findIn('id', $ids);
        });
    }

    /**
     * Get all products for a badge
     * 
     * @param int $badge_id
     * @return array
     */
    public function getBadgeProducts(int $badge_id): array
    {
        $cacheKey = $this->cacheKey("badge_{$badge_id}_products");
        
        return $this->cached($cacheKey, function() use ($badge_id) {
            $productModel = model(ProductModel::class);
            
            $productIds = $this->select('product_id')
                              ->where('badge_id', $badge_id)
                              ->findAll();
            
            if (empty($productIds)) {
                return [];
            }
            
            $ids = array_column($productIds, 'product_id');
            return $productModel->findIn('id', $ids);
        });
    }

    /**
     * Update badge assignments for a product (replace all)
     * 
     * @param int $product_id
     * @param array $badge_ids
     * @param int|null $assigned_by
     * @return bool
     */
    public function updateProductBadges(int $product_id, array $badge_ids, ?int $assigned_by = null): bool
    {
        $this->db->transStart();
        
        try {
            // Remove existing badges
            $this->where('product_id', $product_id)->delete();
            
            // Add new badges
            foreach ($badge_ids as $badge_id) {
                $this->assignBadge($product_id, $badge_id, $assigned_by);
            }
            
            $this->db->transComplete();
            
            if ($this->db->transStatus() === false) {
                return false;
            }
            
            $this->clearBadgeCache($product_id);
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            log_message('error', 'Failed to update product badges: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if badge is assigned to product
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return bool
     */
    public function isAssigned(int $product_id, int $badge_id): bool
    {
        $count = $this->where('product_id', $product_id)
                      ->where('badge_id', $badge_id)
                      ->countAllResults();
        
        return $count > 0;
    }

    /**
     * Count badges for a product
     * 
     * @param int $product_id
     * @return int
     */
    public function countProductBadges(int $product_id): int
    {
        return $this->where('product_id', $product_id)->countAllResults();
    }

    /**
     * Get assigned_at timestamp for a badge assignment
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return string|null
     */
    public function getAssignmentDate(int $product_id, int $badge_id): ?string
    {
        $assignment = $this->select('assigned_at')
                          ->where('product_id', $product_id)
                          ->where('badge_id', $badge_id)
                          ->first();
        
        return $assignment ? $assignment->assigned_at : null;
    }

    /**
     * Get admin who assigned a badge
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return int|null
     */
    public function getAssignedBy(int $product_id, int $badge_id): ?int
    {
        $assignment = $this->select('assigned_by')
                          ->where('product_id', $product_id)
                          ->where('badge_id', $badge_id)
                          ->first();
        
        return $assignment ? $assignment->assigned_by : null;
    }

    /**
     * Clear badge cache for a product
     * 
     * @param int $product_id
     * @return void
     */
    private function clearBadgeCache(int $product_id): void
    {
        $this->clearCache($this->cacheKey("product_{$product_id}_badges"));
        
        // Also clear any product list caches that might include this product
        $this->clearCache($this->cacheKey('all_active'));
    }

    /**
     * Find badges with assigned products (for admin dashboard)
     * 
     * @return array
     */
    public function getBadgeUsageStats(): array
    {
        $cacheKey = $this->cacheKey('badge_usage_stats');
        
        return $this->cached($cacheKey, function() {
            $sql = "SELECT 
                    b.id as badge_id,
                    b.label as badge_label,
                    COUNT(pb.product_id) as product_count,
                    GROUP_CONCAT(DISTINCT pb.assigned_by) as assigned_admins
                FROM badges b
                LEFT JOIN product_badges pb ON b.id = pb.badge_id
                WHERE b.deleted_at IS NULL
                GROUP BY b.id
                ORDER BY product_count DESC";
            
            $query = $this->db->query($sql);
            return $query->getResultArray();
        }, 1800); // 30 minutes cache
    }

    /**
     * Bulk assign badges to multiple products
     * 
     * @param array $product_ids
     * @param array $badge_ids
     * @param int|null $assigned_by
     * @return array [success_count, failed_count]
     */
    public function bulkAssign(array $product_ids, array $badge_ids, ?int $assigned_by = null): array
    {
        $success = 0;
        $failed = 0;
        
        foreach ($product_ids as $product_id) {
            foreach ($badge_ids as $badge_id) {
                try {
                    if ($this->assignBadge($product_id, $badge_id, $assigned_by)) {
                        $success++;
                    } else {
                        $failed++;
                    }
                } catch (\Exception $e) {
                    $failed++;
                    log_message('error', "Failed to assign badge {$badge_id} to product {$product_id}: " . $e->getMessage());
                }
            }
        }
        
        return [$success, $failed];
    }

    /**
     * Override parent find method to prevent misuse
     * ProductBadge table requires composite key lookup
     * 
     * @param mixed $id
     * @return ProductBadge|array|null
     */
    public function find($id = null)
    {
        if ($id !== null) {
            log_message('warning', 'ProductBadgeModel::find() called with single ID. Use findByCompositeKey() instead.');
        }
        
        return parent::find($id);
    }

    /**
     * Override save to handle composite key uniqueness
     * 
     * @param array|object $data
     * @return bool
     */
    public function save($data): bool
    {
        // Convert to array if object
        if (is_object($data)) {
            $data = (array) $data;
        }
        
        // Check for composite key
        if (isset($data['product_id']) && isset($data['badge_id'])) {
            // Check if relationship already exists
            $existing = $this->findByCompositeKey($data['product_id'], $data['badge_id']);
            if ($existing) {
                // Update existing (though typically we don't update junction tables)
                return $this->update($existing->id, $data);
            }
        }
        
        return parent::save($data);
    }

    /**
     * Custom validation for composite key uniqueness
     * 
     * @param int $product_id
     * @param int $badge_id
     * @return bool
     */
    public function validateUniqueAssignment(int $product_id, int $badge_id): bool
    {
        $count = $this->where('product_id', $product_id)
                      ->where('badge_id', $badge_id)
                      ->countAllResults();
        
        return $count === 0;
    }
}