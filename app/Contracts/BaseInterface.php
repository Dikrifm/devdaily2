<?php

namespace App\Contracts;

use App\DTOs\BaseDTO;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\BaseRepositoryInterface;
use Closure;

/**
 * Base Interface
 * 
 * Business Orchestrator Layer (Layer 5): Root contract for all service interfaces.
 * Defines the common protocol for all business logic operations with strict type safety.
 *
 * @package App\Contracts
 */
interface BaseInterface
{
    // ==================== TRANSACTION MANAGEMENT ====================

    /**
     * Execute operation within database transaction boundary
     *
     * @template T
     * @param Closure(): T $operation Business logic operation
     * @param string|null $transactionName Optional transaction name for logging
     * @return T
     * @throws DomainException On transaction failure
     */
    public function transaction(Closure $operation, ?string $transactionName = null): mixed;

    /**
     * Execute operation with retry logic for deadlocks and transient failures
     *
     * @template T
     * @param Closure(): T $operation
     * @param int $maxRetries Maximum retry attempts
     * @param int $retryDelayMs Delay between retries in milliseconds
     * @return T
     */
    public function transactionWithRetry(
        Closure $operation,
        int $maxRetries = 3,
        int $retryDelayMs = 100
    ): mixed;

    // ==================== AUTHORIZATION & VALIDATION ====================

    /**
     * Check authorization for current admin context
     *
     * @param string $permission Required permission
     * @param mixed $resource Optional resource for ownership check
     * @throws AuthorizationException
     */
    public function authorize(string $permission, $resource = null): void;

    /**
     * Validate DTO against business rules
     *
     * @param BaseDTO $dto
     * @param array<string, mixed> $context Additional validation context
     * @return array<string, string[]> Validation errors
     */
    public function validateDTO(BaseDTO $dto, array $context = []): array;

    /**
     * Throw validation exception if DTO is invalid
     *
     * @param BaseDTO $dto
     * @param array<string, mixed> $context
     * @throws ValidationException
     */
    public function validateDTOOrFail(BaseDTO $dto, array $context = []): void;

    /**
     * Validate business rules for DTO
     * Must be implemented by concrete services for domain-specific validation
     *
     * @param BaseDTO $dto
     * @param array<string, mixed> $context
     * @return array<string, string[]>
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array;

    // ==================== ENTITY & REPOSITORY COORDINATION ====================

    /**
     * Coordinate multiple repositories in a single transaction
     *
     * @template T
     * @param array<BaseRepositoryInterface> $repositories
     * @param Closure(array<BaseRepositoryInterface>): T $operation
     * @return T
     */
    public function coordinateRepositories(array $repositories, Closure $operation): mixed;

    /**
     * Get entity from repository with proper error handling
     *
     * @template TEntity
     * @param BaseRepositoryInterface $repository
     * @param int|string $id
     * @param bool $throwIfNotFound
     * @return TEntity|null
     * @throws NotFoundException
     */
    public function getEntity(
        BaseRepositoryInterface $repository,
        $id,
        bool $throwIfNotFound = true
    );

    // ==================== AUDIT LOGGING ====================

    /**
     * Record audit log for business operation
     *
     * @param string $actionType
     * @param string $entityType
     * @param int $entityId
     * @param array|null $oldValues
     * @param array|null $newValues
     * @param array $additionalContext
     * @return void
     */
    public function audit(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $additionalContext = []
    ): void;

    // ==================== CACHE MANAGEMENT ====================

    /**
     * Clear cache for specific entity or pattern
     *
     * @param BaseRepositoryInterface $repository
     * @param int|string|null $entityId Entity ID or null for all
     * @param string|null $pattern Cache pattern for bulk invalidation
     * @return bool
     */
    public function clearCacheForEntity(
        BaseRepositoryInterface $repository,
        $entityId = null,
        ?string $pattern = null
    ): bool;

    /**
     * Clear all cache related to this service
     *
     * @return bool
     */
    public function clearServiceCache(): bool;

    /**
     * Execute callback with result caching
     *
     * @template T
     * @param string $cacheKey
     * @param Closure(): T $callback
     * @param int|null $ttl Cache TTL in seconds
     * @return T
     */
    public function withCaching(string $cacheKey, Closure $callback, ?int $ttl = null): mixed;

    // ==================== BATCH OPERATIONS ====================

    /**
     * Execute batch operation with progress tracking
     *
     * @template T
     * @param array $items
     * @param Closure(mixed, int): T $itemOperation
     * @param int $batchSize Maximum items per batch
     * @param callable|null $progressCallback Called after each item (item, index, total)
     * @return array<T>
     */
    public function batchOperation(
        array $items,
        Closure $itemOperation,
        int $batchSize = 100,
        ?callable $progressCallback = null
    ): array;

    // ==================== ADMIN CONTEXT MANAGEMENT ====================

    /**
     * Set current admin context for authorization and auditing
     *
     * @param int|null $adminId
     * @return self
     */
    public function setAdminContext(?int $adminId): self;

    /**
     * Get current admin ID
     *
     * @return int|null
     */
    public function getCurrentAdminId(): ?int;

    // ==================== SERVICE METADATA ====================

    /**
     * Get service name for logging and auditing
     *
     * @return string
     */
    public function getServiceName(): string;

    /**
     * Generate cache key for service operation
     *
     * @param string $operation
     * @param array<string, mixed> $parameters
     * @return string
     */
    public function getServiceCacheKey(string $operation, array $parameters = []): string;

    /**
     * Get service initialization timestamp
     *
     * @return string ISO 8601 timestamp
     */
    public function getInitializedAt(): string;

    /**
     * Check if service is ready for operations
     *
     * @return bool
     */
    public function isReady(): bool;

    // ==================== ERROR HANDLING & DIAGNOSTICS ====================

    /**
     * Get service health status
     *
     * @return array{
     *     status: string,
     *     ready: bool,
     *     dependencies: array<string, bool>,
     *     initialized_at: string,
     *     operation_count: int
     * }
     */
    public function getHealthStatus(): array;

    /**
     * Get service performance metrics
     *
     * @return array{
     *     total_transactions: int,
     *     successful_transactions: int,
     *     failed_transactions: int,
     *     average_duration_ms: float,
     *     cache_hit_rate: float
     * }
     */
    public function getPerformanceMetrics(): array;

    /**
     * Reset service statistics and metrics
     *
     * @return void
     */
    public function resetMetrics(): void;

    /**
     * Validate service configuration
     *
     * @return array<string, mixed> Configuration validation results
     */
    public function validateConfiguration(): array;
}