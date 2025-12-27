<?php

namespace App\Repositories;

use App\Contracts\CacheInterface;
use App\Repositories\BaseRepositoryInterface;
use App\Entities\BaseEntity;
use App\DTOs\Queries\PaginationQuery;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Model;
use Closure;
use RuntimeException;
use InvalidArgumentException;

/**
 * Base Repository Abstract Class
 *
 * Data Orchestrator Layer (Layer 3): Persistence Abstraction & Cache Manager.
 * Provides complete implementation with caching, transactions, and type safety.
 *
 * @template TEntity of BaseEntity
 * @implements BaseRepositoryInterface<TEntity>
 * @package App\Repositories
 */
abstract class BaseRepository implements BaseRepositoryInterface
{
    /**
     * The Model instance (Data Gateway - Layer 2)
     *
     * @var Model
     */
    protected Model $model;

    /**
     * Cache service instance
     *
     * @var CacheInterface
     */
    protected CacheInterface $cache;

    /**
     * Database connection for transactions
     *
     * @var ConnectionInterface
     */
    protected ConnectionInterface $db;

    /**
     * Entity class name (for type safety and hydration)
     *
     * @var class-string<TEntity>
     */
    protected string $entityClass;

    /**
     * Table name (for cache key generation)
     *
     * @var string
     */
    protected string $tableName;

    /**
     * Cache key prefix for this repository
     *
     * @var string
     */
    protected string $cachePrefix;

    /**
     * Default cache TTL in seconds
     *
     * @var int
     */
    protected int $defaultCacheTtl = 3600; // 1 hour

    /**
     * Whether to use atomic cache operations
     *
     * @var bool
     */
    protected bool $useAtomicCache = true;

    /**
     * Pending cache invalidations (for transaction safety)
     *
     * @var array<string>
     */
    private array $pendingCacheInvalidations = [];

    /**
     * Constructor with dependency injection
     *
     * @param Model $model
     * @param CacheInterface $cache
     * @param ConnectionInterface $db
     * @param class-string<TEntity> $entityClass
     * @param string $tableName
     */
    public function __construct(
        Model $model,
        CacheInterface $cache,
        ConnectionInterface $db,
        string $entityClass,
        string $tableName
    ) {
        if (!is_subclass_of($entityClass, BaseEntity::class)) {
            throw new InvalidArgumentException(
                sprintf('Entity class must extend BaseEntity, %s given', $entityClass)
            );
        }

        $this->model = $model;
        $this->cache = $cache;
        $this->db = $db;
        $this->entityClass = $entityClass;
        $this->tableName = $tableName;
        $this->cachePrefix = $this->generateCachePrefix();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int|string $id): ?BaseEntity
    {
        $cacheKey = $this->getEntityCacheKey($id);

        // Try cache first (L1 Cache Strategy)
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null && $cached instanceof BaseEntity) {
            return $cached;
        }

        // Cache miss - fetch from database
        $entity = $this->model->findActiveById($id);
        if ($entity === null) {
            return null;
        }

        // Store in cache (Cache-Aside Pattern)
        $this->cache->set($cacheKey, $entity, $this->defaultCacheTtl);

        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdOrFail(int|string $id): BaseEntity
    {
        $entity = $this->findById($id);
        if ($entity === null) {
            $entityName = $this->getEntityShortName();
            throw NotFoundException::forEntity($entityName, $id);
        }
        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(array $criteria = []): array
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'findAll', 'criteria' => $criteria]);

        return $this->remember($cacheKey, function () use ($criteria) {
            $builder = $this->model->builder();
            
            // Apply criteria to query
            $this->applyCriteria($builder, $criteria);
            
            // Order by created_at desc by default
            $builder->orderBy('created_at', 'DESC');
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, $this->defaultCacheTtl);
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(PaginationQuery $query, array $filters = []): array
    {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'paginate',
            'page' => $query->page,
            'perPage' => $query->perPage,
            'filters' => $filters,
            'orderBy' => $query->orderBy,
            'orderDirection' => $query->orderDirection
        ]);

        return $this->remember($cacheKey, function () use ($query, $filters) {
            $builder = $this->model->builder();
            
            // Apply filters
            $this->applyCriteria($builder, $filters);
            
            // Apply ordering
            if ($query->orderBy && $query->orderDirection) {
                $builder->orderBy($query->orderBy, $query->orderDirection);
            } else {
                $builder->orderBy('created_at', 'DESC');
            }
            
            // Get total count for pagination
            $totalBuilder = clone $builder;
            $total = $totalBuilder->countAllResults(false);
            
            // Apply pagination
            $offset = ($query->page - 1) * $query->perPage;
            $builder->limit($query->perPage, $offset);
            
            // Execute query
            $data = $builder->get()->getResult($this->entityClass);
            $data = $data ?: [];
            
            // Calculate pagination metadata
            $lastPage = ceil($total / $query->perPage);
            $from = $total > 0 ? $offset + 1 : 0;
            $to = min($offset + $query->perPage, $total);
            
            return [
                'data' => $data,
                'pagination' => [
                    'total' => (int) $total,
                    'per_page' => $query->perPage,
                    'current_page' => $query->page,
                    'last_page' => (int) $lastPage,
                    'from' => (int) $from,
                    'to' => (int) $to
                ]
            ];
        }, 300); // 5 minutes TTL for paginated results
    }

    /**
     * {@inheritDoc}
     */
    public function save(BaseEntity $entity): BaseEntity
    {
        if (!$entity instanceof $this->entityClass) {
            throw new InvalidArgumentException(
                sprintf('Expected entity of type %s, got %s', $this->entityClass, get_class($entity))
            );
        }

        // Validate entity before save
        $validationResult = $entity->validate();
        if (!$validationResult['valid']) {
            throw ValidationException::forEntity($entity, $validationResult['errors']);
        }

        // Prepare entity for save
        $entity->prepareForSave($entity->exists());

        // Execute within transaction for data consistency
        return $this->transaction(function () use ($entity) {
            $isUpdate = $entity->exists();
            
            if ($isUpdate) {
                $id = $entity->getId();
                $data = $entity->toArray();
                
                // Remove ID from update data
                unset($data['id']);
                
                $success = $this->model->update($id, $data);
                if (!$success) {
                    throw new DomainException('Failed to update entity');
                }
                
                // Refresh entity from database
                $updatedEntity = $this->model->findActiveById($id);
                if (!$updatedEntity) {
                    throw new NotFoundException('Entity not found after update');
                }
                
                // Invalidate cache for this entity (deferred)
                $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                
                return $updatedEntity;
            } else {
                $data = $entity->toArray();
                unset($data['id']);
                
                $insertId = $this->model->insert($data, true);
                if (!$insertId) {
                    throw new DomainException('Failed to create entity');
                }
                
                // Fetch newly created entity
                $newEntity = $this->model->findActiveById($insertId);
                if (!$newEntity) {
                    throw new NotFoundException('Entity not found after creation');
                }
                
                // Cache the new entity (deferred)
                $this->queueCacheOperation(function () use ($newEntity, $insertId) {
                    $this->cache->set(
                        $this->getEntityCacheKey($insertId),
                        $newEntity,
                        $this->defaultCacheTtl
                    );
                });
                
                return $newEntity;
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): BaseEntity
    {
        // Convert array to entity
        $entity = $this->entityClass::fromArray($data);
        
        // Save the entity
        return $this->save($entity);
    }

    /**
     * {@inheritDoc}
     */
    public function update(int|string $id, array $data): BaseEntity
    {
        // Find existing entity
        $entity = $this->findByIdOrFail($id);
        
        // Update entity properties
        $updatedEntity = $this->entityClass::fromArray(array_merge($entity->toArray(), $data));
        $updatedEntity->setId($id);
        
        // Save updated entity
        return $this->save($updatedEntity);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            $entity = $this->findByIdOrFail($id);
            
            // Check if entity can be archived
            if (method_exists($entity, 'canBeArchived') && !$entity->canBeArchived()) {
                throw new DomainException('Entity cannot be archived');
            }
            
            // Perform soft delete
            $success = $this->model->delete($id);
            if (!$success) {
                return false;
            }
            
            // Invalidate entity cache (deferred)
            $this->queueCacheInvalidation($this->getEntityCacheKey($id));
            
            // Invalidate query caches that might include this entity
            $this->queueCacheInvalidation($this->cachePrefix . '*');
            
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function forceDelete(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            // Check if entity exists
            $this->findByIdOrFail($id);
            
            // Perform hard delete (purge)
            $success = $this->model->delete($id, true);
            if (!$success) {
                return false;
            }
            
            // Invalidate caches (deferred)
            $this->queueCacheInvalidation($this->getEntityCacheKey($id));
            $this->queueCacheInvalidation($this->cachePrefix . '*');
            
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function restore(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            // Check if entity exists (including soft-deleted)
            $builder = $this->model->builder();
            $builder->withDeleted();
            $builder->where($this->model->primaryKey, $id);
            $result = $builder->get()->getRow();
            
            if (!$result) {
                throw NotFoundException::forEntity($this->getEntityShortName(), $id);
            }
            
            // Perform restore
            $success = $this->model->restore($id);
            if (!$success) {
                return false;
            }
            
            // Invalidate caches (deferred)
            $this->queueCacheInvalidation($this->getEntityCacheKey($id));
            $this->queueCacheInvalidation($this->cachePrefix . '*');
            
            return true;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdate(array $ids, array $data): int
    {
        if (empty($ids) || empty($data)) {
            return 0;
        }

        return $this->transaction(function () use ($ids, $data) {
            // Perform bulk update
            $affected = $this->model->bulkUpdate($ids, $data);
            
            if ($affected > 0) {
                // Invalidate cache for each entity
                foreach ($ids as $id) {
                    $this->queueCacheInvalidation($this->getEntityCacheKey($id));
                }
                
                // Invalidate query caches
                $this->queueCacheInvalidation($this->cachePrefix . '*');
            }
            
            return $affected;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return $this->transaction(function () use ($ids) {
            $successCount = 0;
            
            foreach ($ids as $id) {
                try {
                    if ($this->delete($id)) {
                        $successCount++;
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other deletions
                    log_message('error', sprintf(
                        'Failed to delete entity %s: %s',
                        $id,
                        $e->getMessage()
                    ));
                }
            }
            
            return $successCount;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return $this->transaction(function () use ($ids) {
            $successCount = 0;
            
            foreach ($ids as $id) {
                try {
                    if ($this->restore($id)) {
                        $successCount++;
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other restores
                    log_message('error', sprintf(
                        'Failed to restore entity %s: %s',
                        $id,
                        $e->getMessage()
                    ));
                }
            }
            
            return $successCount;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function exists(int|string $id): bool
    {
        $cacheKey = $this->getEntityCacheKey($id) . ':exists';
        
        return $this->remember($cacheKey, function () use ($id) {
            $builder = $this->model->builder();
            $builder->select('1');
            $builder->where($this->model->primaryKey, $id);
            $builder->where($this->model->deletedField, null);
            
            return $builder->get()->getRow() !== null;
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function count(array $criteria = []): int
    {
        $cacheKey = $this->getQueryCacheKey(['action' => 'count', 'criteria' => $criteria]);
        
        return $this->remember($cacheKey, function () use ($criteria) {
            $builder = $this->model->builder();
            $this->applyCriteria($builder, $criteria);
            
            return (int) $builder->countAllResults();
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function transaction(Closure $callback): mixed
    {
        $this->db->transStart();
        
        try {
            $result = $callback();
            
            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                throw new RuntimeException('Transaction failed');
            }
            
            $this->db->transCommit();
            
            // Execute pending cache operations after successful commit
            $this->executePendingCacheOperations();
            
            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->clearPendingCacheOperations();
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clearCache(): bool
    {
        $pattern = $this->cachePrefix . '*';
        return $this->cache->deleteMatching($pattern);
    }

    /**
     * {@inheritDoc}
     */
    public function clearEntityCache(int|string $id): bool
    {
        $cacheKey = $this->getEntityCacheKey($id);
        return $this->cache->delete($cacheKey);
    }

    /**
     * {@inheritDoc}
     */
    public function clearCacheMatching(string $pattern): bool
    {
        $fullPattern = $this->cachePrefix . $pattern;
        return $this->cache->deleteMatching($fullPattern);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityCacheKey(int|string $id): string
    {
        return sprintf('%s:entity:%s', $this->cachePrefix, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function getQueryCacheKey(array $parameters): string
    {
        // Generate hash from parameters to keep cache keys manageable
        $hash = md5(serialize($parameters));
        return sprintf('%s:query:%s', $this->cachePrefix, $hash);
    }

    /**
     * {@inheritDoc}
     */
    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $fullKey = $this->cachePrefix . ':' . $key;
        
        // Try cache first
        $cached = $this->cache->get($fullKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // Execute callback and cache result
        $result = $callback();
        $this->cache->set($fullKey, $result, $ttl ?? $this->defaultCacheTtl);
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function rememberForever(string $key, Closure $callback): mixed
    {
        $fullKey = $this->cachePrefix . ':' . $key;
        
        // Try cache first
        $cached = $this->cache->get($fullKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // Execute callback and cache forever
        $result = $callback();
        $this->cache->forever($fullKey, $result);
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        
        $cacheKey = $this->getQueryCacheKey(['action' => 'findByIds', 'ids' => $ids]);
        
        return $this->remember($cacheKey, function () use ($ids) {
            $result = [];
            $uncachedIds = [];
            
            // Try to get from cache first
            foreach ($ids as $id) {
                $entity = $this->cache->get($this->getEntityCacheKey($id));
                if ($entity instanceof BaseEntity) {
                    $result[$id] = $entity;
                } else {
                    $uncachedIds[] = $id;
                }
            }
            
            // Fetch uncached entities from database
            if (!empty($uncachedIds)) {
                $builder = $this->model->builder();
                $builder->whereIn($this->model->primaryKey, $uncachedIds);
                $builder->where($this->model->deletedField, null);
                
                $entities = $builder->get()->getResult($this->entityClass);
                
                // Cache and add to result
                foreach ($entities as $entity) {
                    $id = $entity->getId();
                    $result[$id] = $entity;
                    
                    // Cache entity individually
                    $this->cache->set(
                        $this->getEntityCacheKey($id),
                        $entity,
                        $this->defaultCacheTtl
                    );
                }
            }
            
            // Return in original order
            return array_values(array_filter(array_replace(array_flip($ids), $result)));
        }, 300); // 5 minutes TTL
    }

    /**
     * {@inheritDoc}
     */
    public function findBy(
        array $criteria,
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null
    ): array {
        $cacheKey = $this->getQueryCacheKey([
            'action' => 'findBy',
            'criteria' => $criteria,
            'orderBy' => $orderBy,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        return $this->remember($cacheKey, function () use ($criteria, $orderBy, $limit, $offset) {
            $builder = $this->model->builder();
            
            // Apply criteria
            $this->applyCriteria($builder, $criteria);
            
            // Apply ordering
            foreach ($orderBy as $field => $direction) {
                $builder->orderBy($field, $direction);
            }
            
            // Apply limit and offset
            if ($limit !== null) {
                $builder->limit($limit, $offset);
            }
            
            $result = $builder->get()->getResult($this->entityClass);
            return $result ?: [];
        }, $this->defaultCacheTtl);
    }

    /**
     * {@inheritDoc}
     */
    public function findOneBy(array $criteria): ?BaseEntity
    {
        $results = $this->findBy($criteria, [], 1);
        return $results[0] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /**
     * {@inheritDoc}
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }

    /**
     * {@inheritDoc}
     */
    public function getCacheStats(): array
    {
        return $this->cache->getStats();
    }

    /**
     * {@inheritDoc}
     */
    public function preloadCache(array $ids): int
    {
        $preloaded = 0;
        
        foreach ($ids as $id) {
            $entity = $this->findById($id);
            if ($entity !== null) {
                $preloaded++;
            }
        }
        
        return $preloaded;
    }

    /**
     * {@inheritDoc}
     */
    public function atomicCacheOperation(
        string $cacheKey,
        Closure $dataCallback,
        ?Closure $cacheCallback = null,
        ?int $ttl = null
    ): mixed {
        $fullKey = $this->cachePrefix . ':' . $cacheKey;
        
        if (!$this->useAtomicCache) {
            return $dataCallback();
        }
        
        // Start transaction for atomicity
        $this->db->transStart();
        
        try {
            // Get data
            $result = $dataCallback();
            
            // Execute cache callback if provided
            if ($cacheCallback !== null) {
                $cacheCallback($result);
            }
            
            // Store in cache with transaction safety
            if ($this->db->transStatus()) {
                $this->db->transCommit();
                $this->cache->set($fullKey, $result, $ttl ?? $this->defaultCacheTtl);
                return $result;
            } else {
                $this->db->transRollback();
                // Clear cache if transaction failed
                $this->cache->delete($fullKey);
                throw new RuntimeException('Atomic cache operation failed: transaction rolled back');
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->cache->delete($fullKey);
            throw $e;
        }
    }

    /**
     * Apply criteria to query builder
     * Protected method for child classes to extend
     *
     * @param \CodeIgniter\Database\BaseBuilder $builder
     * @param array<string, mixed> $criteria
     * @return void
     */
    protected function applyCriteria(\CodeIgniter\Database\BaseBuilder $builder, array $criteria): void
    {
        foreach ($criteria as $field => $value) {
            if ($value === null) {
                $builder->where($field, null);
            } elseif (is_array($value)) {
                if (!empty($value)) {
                    $builder->whereIn($field, $value);
                }
            } else {
                $builder->where($field, $value);
            }
        }
    }

    /**
     * Generate cache prefix based on entity and table
     *
     * @return string
     */
    private function generateCachePrefix(): string
    {
        $entityShortName = $this->getEntityShortName();
        return sprintf('%s:%s', strtolower($entityShortName), $this->tableName);
    }

    /**
     * Get short entity class name (without namespace)
     *
     * @return string
     */
    private function getEntityShortName(): string
    {
        $parts = explode('\\', $this->entityClass);
        return end($parts);
    }

    /**
     * Queue cache invalidation for transaction safety
     *
     * @param string $cacheKeyOrPattern
     * @return void
     */
    private function queueCacheInvalidation(string $cacheKeyOrPattern): void
    {
        $this->pendingCacheInvalidations[] = $cacheKeyOrPattern;
    }

    /**
     * Queue cache operation for deferred execution
     *
     * @param Closure $operation
     * @return void
     */
    private function queueCacheOperation(Closure $operation): void
    {
        $this->pendingCacheInvalidations[] = $operation;
    }

    /**
     * Execute pending cache operations after successful transaction
     *
     * @return void
     */
    private function executePendingCacheOperations(): void
    {
        foreach ($this->pendingCacheInvalidations as $operation) {
            if ($operation instanceof Closure) {
                $operation();
            } elseif (is_string($operation)) {
                if (strpos($operation, '*') !== false) {
                    $this->cache->deleteMatching($operation);
                } else {
                    $this->cache->delete($operation);
                }
            }
        }
        
        $this->clearPendingCacheOperations();
    }

    /**
     * Clear pending cache operations (on transaction rollback)
     *
     * @return void
     */
    private function clearPendingCacheOperations(): void
    {
        $this->pendingCacheInvalidations = [];
    }

    /**
     * Get model instance (for child classes that need direct access)
     *
     * @return Model
     */
    protected function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Get cache service instance (for child classes)
     *
     * @return CacheInterface
     */
    protected function getCache(): CacheInterface
    {
        return $this->cache;
    }
}