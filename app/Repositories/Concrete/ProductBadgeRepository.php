<?php

namespace App\Repositories\Concrete;

use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\ProductBadgeRepositoryInterface;
use App\Entities\ProductBadge;
use App\Models\ProductBadgeModel;
use App\Contracts\CacheInterface;
use CodeIgniter\Database\ConnectionInterface;
use App\DTOs\Queries\PaginationQuery;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainException;
use RuntimeException;
use InvalidArgumentException;
use Closure;

/**
 * ProductBadge Repository
 *
 * Data Orchestrator Layer (Layer 3): Concrete implementation for ProductBadge persistence operations.
 * Handles many-to-many relationship between Product and Badge entities with composite key support.
 *
 * @package App\Repositories\Concrete
 */
class ProductBadgeRepository extends BaseRepository implements ProductBadgeRepositoryInterface
{
    /**
     * @var ProductBadgeModel
     */
    protected ProductBadgeModel $productBadgeModel;

    /**
     * Constructor with dependency injection
     *
     * @param ProductBadgeModel $model
     * @param CacheInterface $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        ProductBadgeModel $model,
        CacheInterface $cache,
        ConnectionInterface $db
    ) {
        parent::__construct($model, $cache, $db, ProductBadge::class, 'product_badges');
        
        $this->productBadgeModel = $model;
        // Override default TTL for association cache (shorter as associations change frequently)
        $this->defaultCacheTtl = 1800; // 30 minutes
    }

    /**
     * {@inheritDoc}
     */
    public function findByCompositeKey(int $productId, int $badgeId): ?ProductBadge
    {
        $cacheKey = $this->getCompositeCacheKey($productId, $badgeId);
        
        // Try cache first
        $cached = $this->cache->get($cacheKey);
        if ($cached instanceof ProductBadge) {
            return $cached;
        }
        
        // Fetch from database
        $association = $this->productBadgeModel->findByCompositeKey($productId, $badgeId);
        if ($association === null) {
            return null;
        }
        
        // Cache the association
        $this->cache->set($cacheKey, $association, $this->defaultCacheTtl);
        
        return $association;
    }

    /**
     * {@inheritDoc}
     */
    public function findByProductId(int $productId): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByProductId', 'productId' => $productId]);
        
        return $this->remember($cacheKey, function () use ($productId) {
            return $this->productBadgeModel->findByProductId($productId);
        }, $this->defaultCacheTtl);
    }

    /**
     * {@inheritDoc}
     */
    public function findByBadgeId(int $badgeId): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByBadgeId', 'badgeId' => $badgeId]);
        
        return $this->remember($cacheKey, function () use ($badgeId) {
            return $this->productBadgeModel->findByBadgeId($badgeId);
        }, $this->defaultCacheTtl);
    }

    /**
     * {@inheritDoc}
     */
    public function associate(int $productId, int $badgeId, ?int $assignedBy = null): ProductBadge
    {
        return $this->transaction(function () use ($productId, $badgeId, $assignedBy) {
            // Check if association already exists
            if ($this->associationExists($productId, $badgeId)) {
                throw new DomainException(
                    sprintf('Badge %d is already associated with product %d', $badgeId, $productId)
                );
            }
            
            // Create new association
            $data = [
                'product_id' => $productId,
                'badge_id' => $badgeId,
                'assigned_at' => date('Y-m-d H:i:s')
            ];
            
            if ($assignedBy !== null) {
                $data['assigned_by'] = $assignedBy;
            }
            
            // Insert association
            $success = $this->productBadgeModel->insert($data);
            if (!$success) {
                throw new RuntimeException('Failed to create product-badge association');
            }
            
            // Create entity
            $association = new ProductBadge($productId, $badgeId);
            if ($assignedBy !== null) {
                $association->setAssignedBy($assignedBy);
            }
            $association->setAssignedAt(new \DateTimeImmutable());
            
            // Cache the new association
            $this->queueCacheOperation(function () use ($association, $productId, $badgeId) {
                $this->cache->set(
                    $this->getCompositeCacheKey($productId, $badgeId),
                    $association,
                    $this->defaultCacheTtl
                );
                
                // Invalidate related caches
                $this->cache->deleteMatching($this->cachePrefix . ':query:*');
            });
            
            return $association;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function dissociate(int $productId, int $badgeId): bool
    {
        return $this->transaction(function () use ($productId, $badgeId) {
            // Check if association exists
            if (!$this->associationExists($productId, $badgeId)) {
                throw new NotFoundException(
                    sprintf('Association between product %d and badge %d not found', $productId, $badgeId)
                );
            }
            
            // Delete association
            $success = $this->productBadgeModel->deleteAssociation($productId, $badgeId);
            if (!$success) {
                return false;
            }
            
            // Invalidate caches
            $this->queueCacheInvalidation($this->getCompositeCacheKey($productId, $badgeId));
            $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function syncForProduct(int $productId, array $badgeIds, ?int $assignedBy = null): array
    {
        return $this->transaction(function () use ($productId, $badgeIds, $assignedBy) {
            // Remove all existing associations for this product
            $this->productBadgeModel->deleteAllForProduct($productId);
            
            $newAssociations = [];
            
            // Create new associations
            foreach ($badgeIds as $badgeId) {
                try {
                    $association = $this->associate($productId, $badgeId, $assignedBy);
                    $newAssociations[] = $association;
                } catch (DomainException $e) {
                    // Skip if already associated (shouldn't happen after deleteAll)
                    continue;
                }
            }
            
            // Clear all cache for this product
            $this->queueCacheInvalidation($this->cachePrefix . '*product:' . $productId . '*');
            $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            
            return $newAssociations;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function syncForBadge(int $badgeId, array $productIds, ?int $assignedBy = null): array
    {
        return $this->transaction(function () use ($badgeId, $productIds, $assignedBy) {
            // Remove all existing associations for this badge
            $this->productBadgeModel->deleteAllForBadge($badgeId);
            
            $newAssociations = [];
            
            // Create new associations
            foreach ($productIds as $productId) {
                try {
                    $association = $this->associate($productId, $badgeId, $assignedBy);
                    $newAssociations[] = $association;
                } catch (DomainException $e) {
                    // Skip if already associated (shouldn't happen after deleteAll)
                    continue;
                }
            }
            
            // Clear all cache for this badge
            $this->queueCacheInvalidation($this->cachePrefix . '*badge:' . $badgeId . '*');
            $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            
            return $newAssociations;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function removeAllForProduct(int $productId): int
    {
        return $this->transaction(function () use ($productId) {
            // Get current associations for cache invalidation
            $currentAssociations = $this->findByProductId($productId);
            
            // Delete all associations
            $deletedCount = $this->productBadgeModel->deleteAllForProduct($productId);
            
            if ($deletedCount > 0) {
                // Invalidate cache for each association
                foreach ($currentAssociations as $association) {
                    $this->queueCacheInvalidation(
                        $this->getCompositeCacheKey($productId, $association->getBadgeId())
                    );
                }
                
                // Invalidate query caches
                $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            }
            
            return $deletedCount;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function removeAllForBadge(int $badgeId): int
    {
        return $this->transaction(function () use ($badgeId) {
            // Get current associations for cache invalidation
            $currentAssociations = $this->findByBadgeId($badgeId);
            
            // Delete all associations
            $deletedCount = $this->productBadgeModel->deleteAllForBadge($badgeId);
            
            if ($deletedCount > 0) {
                // Invalidate cache for each association
                foreach ($currentAssociations as $association) {
                    $this->queueCacheInvalidation(
                        $this->getCompositeCacheKey($association->getProductId(), $badgeId)
                    );
                }
                
                // Invalidate query caches
                $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            }
            
            return $deletedCount;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function associationExists(int $productId, int $badgeId): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'associationExists',
            'productId' => $productId,
            'badgeId' => $badgeId
        ]);
        
        return $this->remember($cacheKey, function () use ($productId, $badgeId) {
            return $this->productBadgeModel->associationExists($productId, $badgeId);
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function countBadgesForProduct(int $productId): int
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'countBadgesForProduct', 'productId' => $productId]);
        
        return $this->remember($cacheKey, function () use ($productId) {
            return $this->productBadgeModel->countBadgesForProduct($productId);
        }, 600); // 10 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function countProductsForBadge(int $badgeId): int
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'countProductsForBadge', 'badgeId' => $badgeId]);
        
        return $this->remember($cacheKey, function () use ($badgeId) {
            return $this->productBadgeModel->countProductsForBadge($badgeId);
        }, 600); // 10 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findForMultipleProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        
        sort($productIds);
        $cacheKey = $this->getQueryCacheKey(['action' => 'findForMultipleProducts', 'productIds' => $productIds]);
        
        return $this->remember($cacheKey, function () use ($productIds) {
            $associations = $this->productBadgeModel->findForMultipleProducts($productIds);
            
            // Cache individual associations
            foreach ($associations as $association) {
                $compositeKey = $this->getCompositeCacheKey(
                    $association->getProductId(),
                    $association->getBadgeId()
                );
                $this->cache->set($compositeKey, $association, $this->defaultCacheTtl);
            }
            
            // Index by composite key
            $indexed = [];
            foreach ($associations as $association) {
                $key = $association->getProductId() . '_' . $association->getBadgeId();
                $indexed[$key] = $association;
            }
            
            return $indexed;
        }, $this->defaultCacheTtl);
    }

    /**
     * {@inheritDoc}
     */
    public function findForMultipleBadges(array $badgeIds): array
    {
        if (empty($badgeIds)) {
            return [];
        }
        
        sort($badgeIds);
        $cacheKey = $this->getQueryCacheKey(['action' => 'findForMultipleBadges', 'badgeIds' => $badgeIds]);
        
        return $this->remember($cacheKey, function () use ($badgeIds) {
            $associations = $this->productBadgeModel->findForMultipleBadges($badgeIds);
            
            // Cache individual associations
            foreach ($associations as $association) {
                $compositeKey = $this->getCompositeCacheKey(
                    $association->getProductId(),
                    $association->getBadgeId()
                );
                $this->cache->set($compositeKey, $association, $this->defaultCacheTtl);
            }
            
            // Index by composite key
            $indexed = [];
            foreach ($associations as $association) {
                $key = $association->getProductId() . '_' . $association->getBadgeId();
                $indexed[$key] = $association;
            }
            
            return $indexed;
        }, $this->defaultCacheTtl);
    }

    /**
     * {@inheritDoc}
     */
    public function paginateProductIdsForBadge(int $badgeId, PaginationQuery $query): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'paginateProductIdsForBadge',
            'badgeId' => $badgeId,
            'page' => $query->page,
            'perPage' => $query->perPage
        ]);
        
        return $this->remember($cacheKey, function () use ($badgeId, $query) {
            // Get all associations for the badge
            $allAssociations = $this->findByBadgeId($badgeId);
            $productIds = array_map(fn($assoc) => $assoc->getProductId(), $allAssociations);
            
            // Apply pagination
            $total = count($productIds);
            $offset = ($query->page - 1) * $query->perPage;
            $paginatedIds = array_slice($productIds, $offset, $query->perPage);
            
            $lastPage = ceil($total / $query->perPage);
            $from = $total > 0 ? $offset + 1 : 0;
            $to = min($offset + $query->perPage, $total);
            
            return [
                'data' => $paginatedIds,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $query->perPage,
                    'current_page' => $query->page,
                    'last_page' => (int) $lastPage,
                    'from' => $from,
                    'to' => $to
                ]
            ];
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function paginateBadgeIdsForProduct(int $productId, PaginationQuery $query): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'paginateBadgeIdsForProduct',
            'productId' => $productId,
            'page' => $query->page,
            'perPage' => $query->perPage
        ]);
        
        return $this->remember($cacheKey, function () use ($productId, $query) {
            // Get all associations for the product
            $allAssociations = $this->findByProductId($productId);
            $badgeIds = array_map(fn($assoc) => $assoc->getBadgeId(), $allAssociations);
            
            // Apply pagination
            $total = count($badgeIds);
            $offset = ($query->page - 1) * $query->perPage;
            $paginatedIds = array_slice($badgeIds, $offset, $query->perPage);
            
            $lastPage = ceil($total / $query->perPage);
            $from = $total > 0 ? $offset + 1 : 0;
            $to = min($offset + $query->perPage, $total);
            
            return [
                'data' => $paginatedIds,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $query->perPage,
                    'current_page' => $query->page,
                    'last_page' => (int) $lastPage,
                    'from' => $from,
                    'to' => $to
                ]
            ];
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getStatistics']);
        
        return $this->remember($cacheKey, function () {
            // Get all associations
            $builder = $this->productBadgeModel->builder();
            $allAssociations = $builder->get()->getResult(ProductBadge::class);
            
            if (empty($allAssociations)) {
                return [
                    'total_associations' => 0,
                    'average_badges_per_product' => 0.0,
                    'average_products_per_badge' => 0.0,
                    'most_used_badges' => [],
                    'products_with_most_badges' => []
                ];
            }
            
            // Calculate statistics
            $productBadgeCount = [];
            $badgeProductCount = [];
            
            foreach ($allAssociations as $association) {
                $productId = $association->getProductId();
                $badgeId = $association->getBadgeId();
                
                $productBadgeCount[$productId] = ($productBadgeCount[$productId] ?? 0) + 1;
                $badgeProductCount[$badgeId] = ($badgeProductCount[$badgeId] ?? 0) + 1;
            }
            
            $totalAssociations = count($allAssociations);
            $uniqueProducts = count($productBadgeCount);
            $uniqueBadges = count($badgeProductCount);
            
            // Sort by usage
            arsort($badgeProductCount);
            arsort($productBadgeCount);
            
            // Get top 10
            $mostUsedBadges = array_slice($badgeProductCount, 0, 10, true);
            $productsWithMostBadges = array_slice($productBadgeCount, 0, 10, true);
            
            // Format results
            $formattedBadges = [];
            foreach ($mostUsedBadges as $badgeId => $count) {
                $formattedBadges[] = ['id' => $badgeId, 'count' => $count];
            }
            
            $formattedProducts = [];
            foreach ($productsWithMostBadges as $productId => $count) {
                $formattedProducts[] = ['id' => $productId, 'count' => $count];
            }
            
            return [
                'total_associations' => $totalAssociations,
                'average_badges_per_product' => $uniqueProducts > 0 ? $totalAssociations / $uniqueProducts : 0,
                'average_products_per_badge' => $uniqueBadges > 0 ? $totalAssociations / $uniqueBadges : 0,
                'most_used_badges' => $formattedBadges,
                'products_with_most_badges' => $formattedProducts
            ];
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function bulkCreate(array $associations): int
    {
        if (empty($associations)) {
            return 0;
        }
        
        return $this->transaction(function () use ($associations) {
            $created = 0;
            
            foreach ($associations as $association) {
                $productId = $association['product_id'] ?? null;
                $badgeId = $association['badge_id'] ?? null;
                $assignedBy = $association['assigned_by'] ?? null;
                
                if ($productId === null || $badgeId === null) {
                    continue;
                }
                
                try {
                    $this->associate($productId, $badgeId, $assignedBy);
                    $created++;
                } catch (DomainException $e) {
                    // Skip if already exists
                    continue;
                }
            }
            
            // Invalidate all query caches
            $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            
            return $created;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDeleteAssociations(array $associations): int
    {
        if (empty($associations)) {
            return 0;
        }
        
        return $this->transaction(function () use ($associations) {
            $deleted = 0;
            
            foreach ($associations as $association) {
                $productId = $association['product_id'] ?? null;
                $badgeId = $association['badge_id'] ?? null;
                
                if ($productId === null || $badgeId === null) {
                    continue;
                }
                
                try {
                    if ($this->dissociate($productId, $badgeId)) {
                        $deleted++;
                    }
                } catch (NotFoundException $e) {
                    // Skip if not found
                    continue;
                }
            }
            
            // Invalidate all query caches
            $this->queueCacheInvalidation($this->cachePrefix . ':query:*');
            
            return $deleted;
        });
    }

    /**
     * Override BaseRepository methods for composite key support
     */

    /**
     * {@inheritDoc}
     * Note: For ProductBadge, ID is a composite. This method accepts a string in format "productId_badgeId"
     */
    public function findById(int|string $id): ?ProductBadge
    {
        list($productId, $badgeId) = $this->parseCompositeId($id);
        return $this->findByCompositeKey($productId, $badgeId);
    }

    /**
     * {@inheritDoc}
     * Note: For ProductBadge, ID is a composite. This method accepts a string in format "productId_badgeId"
     */
    public function findByIdOrFail(int|string $id): ProductBadge
    {
        $entity = $this->findById($id);
        if ($entity === null) {
            throw NotFoundException::forEntity('ProductBadge', $id);
        }
        return $entity;
    }

    /**
     * {@inheritDoc}
     * Note: For ProductBadge, delete uses composite key
     */
    public function delete(int|string $id): bool
    {
        list($productId, $badgeId) = $this->parseCompositeId($id);
        return $this->dissociate($productId, $badgeId);
    }

    /**
     * {@inheritDoc}
     * Note: ProductBadge doesn't support soft delete, so forceDelete is same as delete
     */
    public function forceDelete(int|string $id): bool
    {
        return $this->delete($id);
    }

    /**
     * {@inheritDoc}
     * Note: ProductBadge doesn't support restore
     */
    public function restore(int|string $id): bool
    {
        // ProductBadge doesn't support soft delete, so restore is not applicable
        throw new DomainException('ProductBadge does not support restore operation');
    }

    /**
     * {@inheritDoc}
     */
    public function exists(int|string $id): bool
    {
        list($productId, $badgeId) = $this->parseCompositeId($id);
        return $this->associationExists($productId, $badgeId);
    }

    /**
     * Get cache key for composite entity
     *
     * @param int $productId
     * @param int $badgeId
     * @return string
     */
    private function getCompositeCacheKey(int $productId, int $badgeId): string
    {
        return sprintf('%s:composite:%d_%d', $this->cachePrefix, $productId, $badgeId);
    }

    /**
     * Parse composite ID string into productId and badgeId
     *
     * @param int|string $id
     * @return array{int, int}
     * @throws InvalidArgumentException
     */
    private function parseCompositeId(int|string $id): array
    {
        if (is_int($id)) {
            throw new InvalidArgumentException('ProductBadge ID must be a string in format "productId_badgeId"');
        }
        
        $parts = explode('_', $id);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Invalid composite ID format. Expected "productId_badgeId"');
        }
        
        $productId = (int) $parts[0];
        $badgeId = (int) $parts[1];
        
        if ($productId <= 0 || $badgeId <= 0) {
            throw new InvalidArgumentException('Product ID and Badge ID must be positive integers');
        }
        
        return [$productId, $badgeId];
    }

    /**
     * Override save method to handle composite key
     *
     * @param ProductBadge $entity
     * @return ProductBadge
     * @throws DomainException
     */
    public function save(BaseEntity $entity): ProductBadge
    {
        if (!$entity instanceof ProductBadge) {
            throw new InvalidArgumentException('Expected ProductBadge entity');
        }
        
        $productId = $entity->getProductId();
        $badgeId = $entity->getBadgeId();
        
        // Check if association exists
        $existing = $this->findByCompositeKey($productId, $badgeId);
        
        if ($existing !== null) {
            // Update existing association
            if ($entity->getAssignedBy() !== null) {
                $existing->setAssignedBy($entity->getAssignedBy());
            }
            if ($entity->getAssignedAt() !== null) {
                $existing->setAssignedAt($entity->getAssignedAt());
            }
            
            // Update in database
            $data = $existing->toArray();
            unset($data['product_id'], $data['badge_id']); // Composite key cannot be updated
            
            $builder = $this->productBadgeModel->builder();
            $builder->where('product_id', $productId);
            $builder->where('badge_id', $badgeId);
            $success = $builder->update($data);
            
            if (!$success) {
                throw new DomainException('Failed to update ProductBadge association');
            }
            
            // Invalidate cache
            $this->queueCacheInvalidation($this->getCompositeCacheKey($productId, $badgeId));
            
            return $existing;
        } else {
            // Create new association
            return $this->associate($productId, $badgeId, $entity->getAssignedBy());
        }
    }

    /**
     * Override create method for composite key
     *
     * @param array $data
     * @return ProductBadge
     */
    public function create(array $data): ProductBadge
    {
        $productId = $data['product_id'] ?? null;
        $badgeId = $data['badge_id'] ?? null;
        
        if ($productId === null || $badgeId === null) {
            throw new InvalidArgumentException('product_id and badge_id are required');
        }
        
        $assignedBy = $data['assigned_by'] ?? null;
        
        return $this->associate($productId, $badgeId, $assignedBy);
    }

    /**
     * Override update method - not applicable for composite key
     * Use associate/dissociate or save instead
     *
     * @param int|string $id
     * @param array $data
     * @return never
     * @throws DomainException
     */
    public function update(int|string $id, array $data): BaseEntity
    {
        throw new DomainException('Use save() method for ProductBadge updates');
    }
}