<?php

namespace App\Repositories\Concrete;

use App\Entities\Category;
use App\Models\CategoryModel;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Contracts\CacheInterface;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\BaseBuilder;
use App\Exceptions\NotFoundException;
use App\Exceptions\DomainException;
use InvalidArgumentException;

/**
 * Category Repository Concrete Implementation
 * 
 * Data Orchestrator Layer (Layer 3): Persistence Abstraction & Cache Manager for Category entity.
 * Implements Category-specific operations with transaction safety and caching strategies.
 * Handles tree structure operations with proper cache invalidation.
 * 
 * @extends \App\Repositories\BaseRepository<Category>
 */
final class CategoryRepository extends \App\Repositories\BaseRepository implements CategoryRepositoryInterface
{
    /**
     * Constructor with dependency injection
     * 
     * @param CategoryModel $model
     * @param CacheInterface $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        CategoryModel $model,
        CacheInterface $cache,
        ConnectionInterface $db
    ) {
        parent::__construct($model, $cache, $db, Category::class, 'categories');
    }

    /**
     * {@inheritDoc}
     */
    public function findBySlug(string $slug): ?Category
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findBySlug', 'slug' => $slug]);
        
        return $this->remember($cacheKey, function () use ($slug) {
            return $this->getModel()->findBySlug($slug);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findWithParent(int $id): ?Category
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findWithParent', 'id' => $id]);
        
        return $this->remember($cacheKey, function () use ($id) {
            return $this->getModel()->findWithParent($id);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findRootCategories(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findRootCategories']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findRootCategories();
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findSubCategories(int $parentId): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findSubCategories', 'parentId' => $parentId]);
        
        return $this->remember($cacheKey, function () use ($parentId) {
            return $this->getModel()->findSubCategories($parentId);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findCategoryTree(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findCategoryTree']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findCategoryTree();
        }, 7200); // 2 hours TTL (tree structure changes less frequently)
    }

    /**
     * {@inheritDoc}
     */
    public function findWithProductCounts(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findWithProductCounts']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findWithProductCounts();
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findWithChildrenCounts(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findWithChildrenCounts']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->findWithChildrenCounts();
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findWithAllCounts(int $id): ?Category
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findWithAllCounts', 'id' => $id]);
        
        return $this->remember($cacheKey, function () use ($id) {
            return $this->getModel()->findWithAllCounts($id);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findDescendants(int $categoryId): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findDescendants', 'categoryId' => $categoryId]);
        
        return $this->remember($cacheKey, function () use ($categoryId) {
            return $this->getModel()->findDescendants($categoryId);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findCategoryPath(int $categoryId): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findCategoryPath', 'categoryId' => $categoryId]);
        
        return $this->remember($cacheKey, function () use ($categoryId) {
            return $this->getModel()->findCategoryPath($categoryId);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function isAncestorOf(int $ancestorId, int $descendantId): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'isAncestorOf',
            'ancestorId' => $ancestorId,
            'descendantId' => $descendantId
        ]);
        
        return $this->remember($cacheKey, function () use ($ancestorId, $descendantId) {
            return $this->getModel()->isAncestorOf($ancestorId, $descendantId);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function bulkReparent(array $categoryIds, int $newParentId): int
    {
        return $this->transaction(function () use ($categoryIds, $newParentId) {
            $affected = $this->getModel()->bulkReparent($categoryIds, $newParentId);
            
            if ($affected > 0) {
                // Invalidate cache for each category
                foreach ($categoryIds as $id) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                }
                
                // Invalidate all category tree and list caches
                $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
                $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
                
                // Also invalidate cache for new parent if applicable
                if ($newParentId > 0) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($newParentId));
                }
            }
            
            return $affected;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateSortOrder(array $sortOrders): int
    {
        return $this->transaction(function () use ($sortOrders) {
            $affected = $this->getModel()->bulkUpdateSortOrder($sortOrders);
            
            if ($affected > 0) {
                // Invalidate cache for each affected category
                foreach (array_keys($sortOrders) as $categoryId) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($categoryId));
                }
                
                // Invalidate list and tree caches
                $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
                $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
            }
            
            return $affected;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkSetActive(array $categoryIds, bool $active): int
    {
        return $this->transaction(function () use ($categoryIds, $active) {
            $affected = $this->getModel()->bulkSetActive($categoryIds, $active);
            
            if ($affected > 0) {
                // Invalidate cache for each category
                foreach ($categoryIds as $id) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                }
                
                // Invalidate list caches
                $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
                
                // If activating/deactivating, also invalidate tree cache
                $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
            }
            
            return $affected;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'slugExists',
            'slug' => $slug,
            'excludeId' => $excludeId
        ]);
        
        return $this->remember($cacheKey, function () use ($slug, $excludeId) {
            return $this->getModel()->slugExists($slug, $excludeId);
        }, 1800); // 30 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function isValidParent(int $parentId): bool
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'isValidParent', 'parentId' => $parentId]);
        
        return $this->remember($cacheKey, function () use ($parentId) {
            return $this->getModel()->isValidParent($parentId);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function checkDeletionPreconditions(int $categoryId): array
    {
        // No caching for preconditions check (always fresh data)
        return $this->getModel()->checkDeletionPreconditions($categoryId);
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalActiveCount(): int
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getTotalActiveCount']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->getTotalActiveCount();
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function getMaxDepth(): int
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getMaxDepth']);
        
        return $this->remember($cacheKey, function () {
            return $this->getModel()->getMaxDepth();
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function generateSlug(string $name, ?int $excludeId = null): string
    {
        // No caching for slug generation (unique per request)
        return $this->getModel()->generateSlug($name, $excludeId);
    }

    /**
     * {@inheritDoc}
     */
    public function isValidSlugFormat(string $slug): bool
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'isValidSlugFormat', 'slug' => $slug]);
        
        return $this->remember($cacheKey, function () use ($slug) {
            return $this->getModel()->isValidSlugFormat($slug);
        }, 86400); // 24 hours TTL (validation rules don't change often)
    }

    /**
     * {@inheritDoc}
     */
    public function getSubtreeIds(int $parentId): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getSubtreeIds', 'parentId' => $parentId]);
        
        return $this->remember($cacheKey, function () use ($parentId) {
            return $this->getModel()->getSubtreeIds($parentId);
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function activate(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $category = $this->findByIdOrFail($id);
            
            if ($category->isActive()) {
                return true; // Already active
            }
            
            $category->activate();
            $success = $this->save($category);
            
            if ($success) {
                // Invalidate cache for this category
                $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                
                // Invalidate parent's children list if this is a subcategory
                if ($category->isSubCategory()) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($category->getParentId()));
                }
                
                // Invalidate list and tree caches
                $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
                $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
            }
            
            return (bool) $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $category = $this->findByIdOrFail($id);
            
            if (!$category->isActive()) {
                return true; // Already inactive
            }
            
            // Check if category can be deactivated (has no active children or products)
            if ($this->isInUse($id)) {
                throw new DomainException('Cannot deactivate category that is in use');
            }
            
            $category->deactivate();
            $success = $this->save($category);
            
            if ($success) {
                // Invalidate cache for this category
                $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                
                // Invalidate parent's children list if this is a subcategory
                if ($category->isSubCategory()) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($category->getParentId()));
                }
                
                // Invalidate list and tree caches
                $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
                $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
            }
            
            return (bool) $success;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function findActiveCategories(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findActiveCategories']);
        
        return $this->remember($cacheKey, function () {
            $builder = $this->getModel()->builder();
            $this->getModel()->scopeActive($builder);
            $builder->orderBy('sort_order', 'ASC');
            $builder->orderBy('name', 'ASC');
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findInactiveCategories(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findInactiveCategories']);
        
        return $this->remember($cacheKey, function () {
            $builder = $this->getModel()->builder();
            $builder->where('active', false);
            $builder->where($this->getModel()->deletedField, null);
            $builder->orderBy('name', 'ASC');
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, 3600); // 1 hour TTL
    }

    /**
     * {@inheritDoc}
     */
    public function isInUse(int $categoryId): bool
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'isInUse', 'categoryId' => $categoryId]);
        
        return $this->remember($cacheKey, function () use ($categoryId) {
            // Check if category has products
            $builder = $this->db->table('products');
            $builder->select('1');
            $builder->where('category_id', $categoryId);
            $builder->where('deleted_at', null);
            $hasProducts = $builder->get()->getRow() !== null;
            
            if ($hasProducts) {
                return true;
            }
            
            // Check if category has active children
            $builder = $this->getModel()->builder();
            $builder->select('1');
            $builder->where('parent_id', $categoryId);
            $builder->where('active', true);
            $builder->where('deleted_at', null);
            $hasActiveChildren = $builder->get()->getRow() !== null;
            
            return $hasActiveChildren;
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'getStatistics']);
        
        return $this->remember($cacheKey, function () {
            $stats = [
                'total' => $this->count(),
                'active' => $this->getTotalActiveCount(),
                'inactive' => $this->count(['active' => false]),
                'root_categories' => count($this->findRootCategories()),
                'max_depth' => $this->getMaxDepth(),
            ];
            
            return $stats;
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function createSample(array $overrides = []): Category
    {
        // No caching for sample creation
        return $this->getModel()->createSample($overrides);
    }

    /**
     * Override save to handle tree structure cache invalidation
     * 
     * {@inheritDoc}
     */
    public function save(\App\Entities\BaseEntity $entity): \App\Entities\BaseEntity
    {
        if (!$entity instanceof Category) {
            throw new InvalidArgumentException(
                sprintf('Expected entity of type %s, got %s', Category::class, get_class($entity))
            );
        }
        
        $oldParentId = null;
        $isUpdate = $entity->exists();
        
        if ($isUpdate) {
            // Get old parent ID before save for cache invalidation
            $oldCategory = $this->findById($entity->getId());
            if ($oldCategory instanceof Category) {
                $oldParentId = $oldCategory->getParentId();
            }
        }
        
        // Call parent save method
        $savedEntity = parent::save($entity);
        
        if (!$savedEntity instanceof Category) {
            throw new DomainException('Saved entity is not a Category');
        }
        
        // Additional cache invalidation for tree structure
        $newParentId = $savedEntity->getParentId();
        
        // Invalidate old parent's children list if parent changed
        if ($isUpdate && $oldParentId !== $newParentId) {
            if ($oldParentId > 0) {
                $this->queueCacheInvalidation($this->getEntityCacheKey($oldParentId));
            }
        }
        
        // Invalidate new parent's children list
        if ($newParentId > 0) {
            $this->queueCacheInvalidation($this->getEntityCacheKey($newParentId));
        }
        
        // Invalidate tree and list caches
        $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
        $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
        
        return $savedEntity;
    }

    /**
     * Override delete to handle tree structure cache invalidation
     * 
     * {@inheritDoc}
     */
    public function delete(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $category = $this->findByIdOrFail($id);
            $parentId = $category->getParentId();
            
            $success = parent::delete($id);
            
            if ($success) {
                // Invalidate parent's children list if this was a subcategory
                if ($parentId > 0) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($parentId));
                }
                
                // Invalidate tree and list caches
                $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
                $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
            }
            
            return $success;
        });
    }

    /**
     * Override restore to handle tree structure cache invalidation
     * 
     * {@inheritDoc}
     */
    public function restore(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            // First restore the category
            $success = parent::restore($id);
            
            if ($success) {
                $category = $this->findById($id);
                if ($category instanceof Category) {
                    $parentId = $category->getParentId();
                    
                    // Invalidate parent's children list if this is a subcategory
                    if ($parentId > 0) {
                        $this->queueCacheInvalidation($this->getEntityCacheKey($parentId));
                    }
                    
                    // Invalidate tree and list caches
                    $this->queueCacheInvalidation($this->cachePrefix . ':tree:*');
                    $this->queueCacheInvalidation($this->cachePrefix . ':list:*');
                }
            }
            
            return $success;
        });
    }

    /**
     * Apply Category-specific criteria to query builder
     * 
     * @param BaseBuilder $builder
     * @param array<string, mixed> $criteria
     * @return void
     */
    protected function applyCriteria(BaseBuilder $builder, array $criteria): void
    {
        foreach ($criteria as $field => $value) {
            switch ($field) {
                case 'parent_id':
                    if ($value === null || $value === 0) {
                        $builder->where('parent_id', null);
                    } else {
                        $builder->where('parent_id', $value);
                    }
                    break;
                    
                case 'active':
                    $builder->where('active', (bool) $value);
                    break;
                    
                case 'search':
                    if (is_string($value) && !empty($value)) {
                        $builder->groupStart()
                            ->like('name', $value)
                            ->orLike('slug', $value)
                            ->groupEnd();
                    }
                    break;
                    
                case 'has_products':
                    if ($value === true) {
                        $builder->where('product_count >', 0);
                    } elseif ($value === false) {
                        $builder->where('product_count', 0);
                    }
                    break;
                    
                case 'has_children':
                    if ($value === true) {
                        $builder->where('children_count >', 0);
                    } elseif ($value === false) {
                        $builder->where('children_count', 0);
                    }
                    break;
                    
                case 'min_product_count':
                    $builder->where('product_count >=', (int) $value);
                    break;
                    
                case 'max_product_count':
                    $builder->where('product_count <=', (int) $value);
                    break;
                    
                default:
                    // Use parent's default handling
                    parent::applyCriteria($builder, [$field => $value]);
                    break;
            }
        }
    }

    /**
     * Get CategoryModel instance
     * 
     * @return CategoryModel
     */
    private function getModel(): CategoryModel
    {
        return $this->model;
    }
}