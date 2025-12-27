<?php

namespace App\Repositories;

use App\Entities\BaseEntity;
use App\DTOs\Queries\PaginationQuery;
use Closure;
use RuntimeException;

/**
 * Base Repository Interface
 *
 * Generic contract for all repository implementations.
 * Enforces type safety and cache management across all data operations.
 *
 * @template TEntity of BaseEntity
 * @package App\Repositories
 */
interface BaseRepositoryInterface
{
    /**
     * Find entity by ID with caching (L1 Cache Strategy)
     *
     * @param int|string $id
     * @return TEntity|null
     * @throws RuntimeException
     */
    public function findById(int|string $id): ?BaseEntity;

    /**
     * Find entity by ID or throw exception if not found
     *
     * @param int|string $id
     * @return TEntity
     * @throws \App\Exceptions\NotFoundException
     */
    public function findByIdOrFail(int|string $id): BaseEntity;

    /**
     * Find all active entities
     *
     * @param array<string, mixed> $criteria
     * @return array<TEntity>
     */
    public function findAll(array $criteria = []): array;

    /**
     * Find entities with pagination support (L2 Cache Strategy)
     *
     * @param PaginationQuery $query
     * @param array<string, mixed> $filters
     * @return array{
     *     data: array<TEntity>,
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
    public function paginate(PaginationQuery $query, array $filters = []): array;

    /**
     * Save entity (create or update)
     * Implements Write-Through caching strategy
     *
     * @param TEntity $entity
     * @return TEntity
     * @throws \App\Exceptions\ValidationException
     * @throws \App\Exceptions\DomainException
     */
    public function save(BaseEntity $entity): BaseEntity;

    /**
     * Create new entity
     *
     * @param array<string, mixed> $data
     * @return TEntity
     * @throws \App\Exceptions\ValidationException
     */
    public function create(array $data): BaseEntity;

    /**
     * Update existing entity
     *
     * @param int|string $id
     * @param array<string, mixed> $data
     * @return TEntity
     * @throws \App\Exceptions\NotFoundException
     * @throws \App\Exceptions\ValidationException
     */
    public function update(int|string $id, array $data): BaseEntity;

    /**
     * Soft delete entity (archive)
     *
     * @param int|string $id
     * @return bool
     * @throws \App\Exceptions\NotFoundException
     */
    public function delete(int|string $id): bool;

    /**
     * Force delete entity (permanent)
     *
     * @param int|string $id
     * @return bool
     * @throws \App\Exceptions\NotFoundException
     */
    public function forceDelete(int|string $id): bool;

    /**
     * Restore soft-deleted entity
     *
     * @param int|string $id
     * @return bool
     * @throws \App\Exceptions\NotFoundException
     */
    public function restore(int|string $id): bool;

    /**
     * Bulk update entities
     *
     * @param array<int|string> $ids
     * @param array<string, mixed> $data
     * @return int Number of affected entities
     */
    public function bulkUpdate(array $ids, array $data): int;

    /**
     * Bulk delete entities
     *
     * @param array<int|string> $ids
     * @return int Number of affected entities
     */
    public function bulkDelete(array $ids): int;

    /**
     * Bulk restore entities
     *
     * @param array<int|string> $ids
     * @return int Number of affected entities
     */
    public function bulkRestore(array $ids): int;

    /**
     * Check if entity exists by ID
     *
     * @param int|string $id
     * @return bool
     */
    public function exists(int|string $id): bool;

    /**
     * Count entities matching criteria
     *
     * @param array<string, mixed> $criteria
     * @return int
     */
    public function count(array $criteria = []): int;

    /**
     * Execute within database transaction
     *
     * @param Closure $callback Function to execute within transaction
     * @return mixed
     * @throws \Throwable
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Clear all cache entries for this repository
     * Implements cache invalidation strategy
     *
     * @return bool
     */
    public function clearCache(): bool;

    /**
     * Clear cache for specific entity
     *
     * @param int|string $id
     * @return bool
     */
    public function clearEntityCache(int|string $id): bool;

    /**
     * Clear cache matching pattern
     * Used for query/collection cache invalidation (L2 Cache)
     *
     * @param string $pattern
     * @return bool
     */
    public function clearCacheMatching(string $pattern): bool;

    /**
     * Get cache key for entity
     *
     * @param int|string $id
     * @return string
     */
    public function getEntityCacheKey(int|string $id): string;

    /**
     * Get cache key for query/collection
     *
     * @param array<string, mixed> $parameters
     * @return string
     */
    public function getQueryCacheKey(array $parameters): string;

    /**
     * Remember query result with caching (Cache-Aside Pattern)
     *
     * @param string $key
     * @param Closure $callback
     * @param int|null $ttl
     * @return mixed
     */
    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed;

    /**
     * Remember forever (until manually invalidated)
     *
     * @param string $key
     * @param Closure $callback
     * @return mixed
     */
    public function rememberForever(string $key, Closure $callback): mixed;

    /**
     * Find entities by IDs with batch optimization
     *
     * @param array<int|string> $ids
     * @return array<TEntity>
     */
    public function findByIds(array $ids): array;

    /**
     * Search entities with complex criteria
     *
     * @param array<string, mixed> $criteria
     * @param array<string, string> $orderBy
     * @param int|null $limit
     * @param int|null $offset
     * @return array<TEntity>
     */
    public function findBy(
        array $criteria,
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null
    ): array;

    /**
     * Find single entity by criteria
     *
     * @param array<string, mixed> $criteria
     * @return TEntity|null
     */
    public function findOneBy(array $criteria): ?BaseEntity;

    /**
     * Get entity class name
     *
     * @return class-string<TEntity>
     */
    public function getEntityClass(): string;

    /**
     * Get table name
     *
     * @return string
     */
    public function getTableName(): string;

    /**
     * Get cache statistics for this repository
     *
     * @return array{
     *     hits: int,
     *     misses: int,
     *     memory_usage: string,
     *     keys_count: int
     * }
     */
    public function getCacheStats(): array;

    /**
     * Preload entities into cache (warm cache)
     *
     * @param array<int|string> $ids
     * @return int Number of entities preloaded
     */
    public function preloadCache(array $ids): int;

    /**
     * Perform atomic cache operation with transaction safety
     * Ensures cache is only updated after successful database transaction
     *
     * @param string $cacheKey
     * @param Closure $dataCallback
     * @param Closure|null $cacheCallback
     * @param int|null $ttl
     * @return mixed
     */
    public function atomicCacheOperation(
        string $cacheKey,
        Closure $dataCallback,
        ?Closure $cacheCallback = null,
        ?int $ttl = null
    ): mixed;
}