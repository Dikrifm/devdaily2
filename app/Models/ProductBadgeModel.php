<?php

namespace App\Models;

use App\Entities\ProductBadge;
use CodeIgniter\Database\BaseResult;
use InvalidArgumentException;

/**
 * ProductBadge Model
 *
 * Data Gateway for product_badges junction table.
 * Manages many-to-many relationship between Products and Badges.
 * 
 * Layer: 2 - SQL Encapsulator (0% Business Logic)
 * Responsibility: Query translation, no business rules.
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
     * Primary Key
     * Note: This is a composite key (product_id + badge_id)
     * CodeIgniter 4 doesn't natively support composite primary keys,
     * so we set to false and handle composite operations manually.
     * 
     * @var string|bool
     */
    protected $primaryKey = false;

    /**
     * Return Type
     * Must be set to corresponding Entity for strict type safety
     * 
     * @var class-string<ProductBadge>
     */
    protected $returnType = ProductBadge::class;

    /**
     * Whether to use timestamps
     * Junction table doesn't use CI4's default timestamps
     * but has custom assigned_at timestamp
     * 
     * @var bool
     */
    protected $useTimestamps = false;

    /**
     * Whether to use soft deletes
     * Junction table doesn't need soft deletes
     * 
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * Allowed fields for insert/update
     * 
     * @var array<string>
     */
    protected $allowedFields = [
        'product_id',
        'badge_id',
        'assigned_at',
        'assigned_by',
    ];

    /**
     * Validation rules for insert
     * 
     * @var array<string, string>
     */
    protected $validationRules = [
        'product_id' => 'required|integer|is_not_unique[products.id]',
        'badge_id' => 'required|integer|is_not_unique[badges.id]',
        'assigned_by' => 'permit_empty|integer|is_not_unique[admins.id]',
    ];

    /**
     * Validation messages
     * 
     * @var array<string, string>
     */
    protected $validationMessages = [
        'product_id' => [
            'required' => 'Product ID is required',
            'integer' => 'Product ID must be an integer',
            'is_not_unique' => 'Product does not exist',
        ],
        'badge_id' => [
            'required' => 'Badge ID is required',
            'integer' => 'Badge ID must be an integer',
            'is_not_unique' => 'Badge does not exist',
        ],
        'assigned_by' => [
            'integer' => 'Assigned by must be an integer',
            'is_not_unique' => 'Admin does not exist',
        ],
    ];

    /**
     * Find association by composite key
     * 
     * @param int $productId
     * @param int $badgeId
     * @return ProductBadge|null
     */
    public function findByCompositeKey(int $productId, int $badgeId): ?ProductBadge
    {
        $result = $this->builder()
            ->where('product_id', $productId)
            ->where('badge_id', $badgeId)
            ->get()
            ->getFirstRow($this->returnType);

        return $result instanceof ProductBadge ? $result : null;
    }

    /**
     * Find all badges for a product
     * 
     * @param int $productId
     * @return array<ProductBadge>
     */
    public function findByProductId(int $productId): array
    {
        $result = $this->builder()
            ->where('product_id', $productId)
            ->orderBy('assigned_at', 'DESC')
            ->get()
            ->getResult($this->returnType);

        return $result ?? [];
    }

    /**
     * Find all products for a badge
     * 
     * @param int $badgeId
     * @return array<ProductBadge>
     */
    public function findByBadgeId(int $badgeId): array
    {
        $result = $this->builder()
            ->where('badge_id', $badgeId)
            ->orderBy('assigned_at', 'DESC')
            ->get()
            ->getResult($this->returnType);

        return $result ?? [];
    }

    /**
     * Delete association by composite key
     * 
     * @param int $productId
     * @param int $badgeId
     * @return bool
     */
    public function deleteAssociation(int $productId, int $badgeId): bool
    {
        $builder = $this->builder();
        $builder->where('product_id', $productId)
                ->where('badge_id', $badgeId);
        
        $result = $builder->delete();
        
        // Normalize return type
        if ($result instanceof BaseResult) {
            return $result->connID->affected_rows > 0;
        }
        
        return (bool) $result;
    }

    /**
     * Delete all badges for a product
     * 
     * @param int $productId
     * @return bool
     */
    public function deleteAllForProduct(int $productId): bool
    {
        $builder = $this->builder();
        $builder->where('product_id', $productId);
        
        $result = $builder->delete();
        
        if ($result instanceof BaseResult) {
            return $result->connID->affected_rows > 0;
        }
        
        return (bool) $result;
    }

    /**
     * Delete all products for a badge
     * 
     * @param int $badgeId
     * @return bool
     */
    public function deleteAllForBadge(int $badgeId): bool
    {
        $builder = $this->builder();
        $builder->where('badge_id', $badgeId);
        
        $result = $builder->delete();
        
        if ($result instanceof BaseResult) {
            return $result->connID->affected_rows > 0;
        }
        
        return (bool) $result;
    }

    /**
     * Insert multiple associations at once
     * 
     * @param array<array{product_id: int, badge_id: int, assigned_by?: int}> $associations
     * @return int Number of inserted rows
     * @throws InvalidArgumentException
     */
    public function bulkInsert(array $associations): int
    {
        if (empty($associations)) {
            return 0;
        }

        // Validate each association
        foreach ($associations as $index => $association) {
            if (!isset($association['product_id']) || !isset($association['badge_id'])) {
                throw new InvalidArgumentException(
                    "Association at index {$index} must have product_id and badge_id"
                );
            }

            // Set assigned_at if not provided
            if (!isset($association['assigned_at'])) {
                $associations[$index]['assigned_at'] = date('Y-m-d H:i:s');
            }
        }

        $builder = $this->builder();
        $result = $builder->insertBatch($associations);
        
        return $result ? count($associations) : 0;
    }

    /**
     * Check if association exists
     * 
     * @param int $productId
     * @param int $badgeId
     * @return bool
     */
    public function associationExists(int $productId, int $badgeId): bool
    {
        $builder = $this->builder();
        $builder->select('1')
                ->where('product_id', $productId)
                ->where('badge_id', $badgeId);
        
        $result = $builder->get();
        
        return $result->getNumRows() > 0;
    }

    /**
     * Count badges for a product
     * 
     * @param int $productId
     * @return int
     */
    public function countBadgesForProduct(int $productId): int
    {
        $builder = $this->builder();
        $builder->where('product_id', $productId);
        
        $result = (int) $builder->countAllResults();
        return $result;
    }

    /**
     * Count products for a badge
     * 
     * @param int $badgeId
     * @return int
     */
    public function countProductsForBadge(int $badgeId): int
    {
        $builder = $this->builder();
        $builder->where('badge_id', $badgeId);
        
        $result = (int) $builder->countAllResults();
        return $result;
    }

    /**
     * Get badges for multiple products (batch operation)
     * 
     * @param array<int> $productIds
     * @return array<int, array<ProductBadge>> Product ID => array of badges
     */
    public function findForMultipleProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $result = $this->builder()
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')
            ->orderBy('assigned_at', 'DESC')
            ->get()
            ->getResult($this->returnType);

        $grouped = [];
        foreach ($result as $association) {
            if ($association instanceof ProductBadge) {
                $productId = $association->getProductId();
                if (!isset($grouped[$productId])) {
                    $grouped[$productId] = [];
                }
                $grouped[$productId][] = $association;
            }
        }

        return $grouped;
    }

    /**
     * Get products for multiple badges (batch operation)
     * 
     * @param array<int> $badgeIds
     * @return array<int, array<ProductBadge>> Badge ID => array of products
     */
    public function findForMultipleBadges(array $badgeIds): array
    {
        if (empty($badgeIds)) {
            return [];
        }

        $result = $this->builder()
            ->whereIn('badge_id', $badgeIds)
            ->orderBy('badge_id')
            ->orderBy('assigned_at', 'DESC')
            ->get()
            ->getResult($this->returnType);

        $grouped = [];
        foreach ($result as $association) {
            if ($association instanceof ProductBadge) {
                $badgeId = $association->getBadgeId();
                if (!isset($grouped[$badgeId])) {
                    $grouped[$badgeId] = [];
                }
                $grouped[$badgeId][] = $association;
            }
        }

        return $grouped;
    }

    /**
     * Override parent insert to handle composite key uniqueness
     * 
     * @param array|null $data
     * @return bool|int|string
     */
    public function insert($data = null, bool $returnID = true)
    {
        // Check if association already exists
        if (isset($data['product_id'], $data['badge_id'])) {
            if ($this->associationExists($data['product_id'], $data['badge_id'])) {
                throw new InvalidArgumentException(
                    'Association between product ' . $data['product_id'] . 
                    ' and badge ' . $data['badge_id'] . ' already exists'
                );
            }
        }

        // Set assigned_at if not provided
        if (is_array($data) && !isset($data['assigned_at'])) {
            $data['assigned_at'] = date('Y-m-d H:i:s');
        }

        return parent::insert($data, $returnID);
    }

    /**
     * Override parent update - not applicable for composite key without primary key
     * Use deleteAssociation + insert instead
     * 
     * @param int|string|array|null $id
     * @param array|null $data
     * @return bool
     */
    public function update($id = null, $data = null): bool
    {
        throw new \BadMethodCallException(
            'Direct update not supported for composite key tables. ' .
            'Use deleteAssociation() + insert() instead.'
        );
    }

    /**
     * Override parent delete - use deleteAssociation instead
     * 
     * @param int|string|array|null $id
     * @param bool $purge
     * @return bool|BaseResult
     */
    public function delete($id = null, bool $purge = false)
    {
        throw new \BadMethodCallException(
            'Direct delete not supported for composite key tables. ' .
            'Use deleteAssociation() or deleteAllForProduct() instead.'
        );
    }
}