<?php

namespace App\Services;

use App\Contracts\BaseInterface;
use App\Contracts\CacheInterface;
use App\DTOs\BaseDTO;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\BaseRepositoryInterface;
use App\Services\AuditService;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Base Service Abstract Class
 *
 * Business Orchestrator Layer (Layer 5): Abstract implementation of BaseInterface.
 * Provides concrete implementation for common business operations with atomic cache management.
 *
 * @package App\Services
 */
abstract class BaseService implements BaseInterface
{
    /**
     * Database connection for transaction management
     *
     * @var ConnectionInterface
     */
    protected ConnectionInterface $db;

    /**
     * Cache service for distributed caching
     *
     * @var CacheInterface
     */
    protected CacheInterface $cache;

    /**
     * Audit service for recording business operations
     *
     * @var AuditService
     */
    protected AuditService $auditService;

    /**
     * Current admin ID (if in admin context)
     *
     * @var int|null
     */
    protected ?int $currentAdminId = null;

    /**
     * Pending cache operations for deferred execution
     * Ensures atomic cache updates after successful transactions
     *
     * @var array<Closure|string>
     */
    private array $pendingCacheOperations = [];

    /**
     * Service performance metrics
     *
     * @var array
     */
    private array $metrics = [
        'total_transactions' => 0,
        'successful_transactions' => 0,
        'failed_transactions' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'total_duration_ms' => 0,
    ];

    /**
     * Service initialization timestamp
     *
     * @var string
     */
    private string $initializedAt;

    /**
     * Service operation counter
     *
     * @var int
     */
    private int $operationCount = 0;

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db
     * @param CacheInterface $cache
     * @param AuditService $auditService
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService
    ) {
        $this->db = $db;
        $this->cache = $cache;
        $this->auditService = $auditService;
        $this->initializedAt = Time::now()->format('Y-m-d H:i:s');
        
        $this->validateDependencies();
    }

    /**
     * {@inheritDoc}
     */
    public function transaction(Closure $operation, ?string $transactionName = null): mixed
    {
        $startTime = microtime(true);
        $transactionName = $transactionName ?? $this->getServiceName() . '_' . uniqid();
        
        $this->db->transStart();
        $this->metrics['total_transactions']++;
        
        try {
            $result = $operation();
            
            if ($this->db->transStatus() === false) {
                $this->db->transRollback();
                $this->metrics['failed_transactions']++;
                
                throw new DomainException(
                    sprintf('Transaction failed: %s', $transactionName),
                    'TRANSACTION_FAILED'
                );
            }
            
            $this->db->transCommit();
            $this->metrics['successful_transactions']++;
            
            // Execute deferred cache operations after successful commit
            $this->executePendingCacheOperations();
            
            // Log successful transaction
            $duration = (microtime(true) - $startTime) * 1000;
            $this->metrics['total_duration_ms'] += $duration;
            
            log_message('debug', sprintf(
                'Transaction completed: %s (%.2fms)',
                $transactionName,
                $duration
            ));
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->db->transRollback();
            $this->clearPendingCacheOperations();
            $this->metrics['failed_transactions']++;
            
            // Re-throw with enhanced context
            $this->handleTransactionError($e, $transactionName);
            
            // This line won't be reached, but needed for return type
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function transactionWithRetry(
        Closure $operation,
        int $maxRetries = 3,
        int $retryDelayMs = 100
    ): mixed {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts <= $maxRetries) {
            try {
                return $this->transaction($operation);
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempts++;
                
                // Check if it's a retryable error
                if ($this->isRetryableError($e) && $attempts <= $maxRetries) {
                    log_message('warning', sprintf(
                        'Retrying transaction (attempt %d/%d) after error: %s',
                        $attempts,
                        $maxRetries,
                        $e->getMessage()
                    ));
                    
                    usleep($retryDelayMs * 1000);
                    continue;
                }
                
                break;
            }
        }
        
        throw $lastException;
    }

    /**
     * {@inheritDoc}
     */
    public function authorize(string $permission, $resource = null): void
    {
        if ($this->currentAdminId === null) {
            throw new AuthorizationException(
                'No admin context available for authorization check',
                'NO_ADMIN_CONTEXT'
            );
        }
        
        // Always allow system operations
        if ($this->currentAdminId === 0) {
            return;
        }
        
        $isAuthorized = $this->checkPermission($this->currentAdminId, $permission, $resource);
        
        if (!$isAuthorized) {
            throw AuthorizationException::forPermission(
                $permission,
                $this->currentAdminId,
                $resource
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validateDTO(BaseDTO $dto, array $context = []): array
    {
        $errors = [];
        
        // Basic DTO validation
        if (method_exists($dto, 'validate')) {
            $dtoErrors = $dto->validate();
            if (!empty($dtoErrors)) {
                $errors = array_merge($errors, $dtoErrors);
            }
        }
        
        // Apply business rule validation
        $businessErrors = $this->validateBusinessRules($dto, $context);
        if (!empty($businessErrors)) {
            $errors = array_merge($errors, $businessErrors);
        }
        
        return $errors;
    }

    /**
     * {@inheritDoc}
     */
    public function validateDTOOrFail(BaseDTO $dto, array $context = []): void
    {
        $errors = $this->validateDTO($dto, $context);
        
        if (!empty($errors)) {
            throw ValidationException::forBusinessRule(
                $this->getServiceName(),
                'DTO validation failed',
                [
                    'errors' => $errors,
                    'dto_class' => get_class($dto),
                    'context' => $context
                ]
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function coordinateRepositories(array $repositories, Closure $operation): mixed
    {
        return $this->transaction(function () use ($repositories, $operation) {
            foreach ($repositories as $repository) {
                if (!$repository instanceof BaseRepositoryInterface) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Expected BaseRepositoryInterface, got %s',
                            get_class($repository)
                        )
                    );
                }
            }
            
            return $operation($repositories);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getEntity(
        BaseRepositoryInterface $repository,
        $id,
        bool $throwIfNotFound = true
    ) {
        $this->operationCount++;
        
        $entity = $repository->findById($id);
        
        if ($entity === null && $throwIfNotFound) {
            $entityClass = $repository->getEntityClass();
            $entityName = $this->getEntityShortName($entityClass);
            
            throw NotFoundException::forEntity($entityName, $id);
        }
        
        return $entity;
    }

    /**
     * {@inheritDoc}
     */
    public function audit(
        string $actionType,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $additionalContext = []
    ): void {
        try {
            $this->auditService->log(
                $actionType,
                $entityType,
                $entityId,
                $oldValues,
                $newValues,
                $this->currentAdminId,
                array_merge([
                    'service' => $this->getServiceName(),
                    'operation_count' => $this->operationCount,
                    'timestamp' => Time::now()->toDateTimeString(),
                ], $additionalContext)
            );
        } catch (\Throwable $e) {
            // Don't let audit failures break business operations
            log_message('error', sprintf(
                '[%s] Failed to record audit log: %s',
                $this->getServiceName(),
                $e->getMessage()
            ));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clearCacheForEntity(
        BaseRepositoryInterface $repository,
        $entityId = null,
        ?string $pattern = null
    ): bool {
        if ($entityId !== null) {
            $success = $repository->clearEntityCache($entityId);
            $this->metrics['cache_misses']++;
            return $success;
        }
        
        if ($pattern !== null) {
            $success = $repository->clearCacheMatching($pattern);
            $this->metrics['cache_misses']++;
            return $success;
        }
        
        $success = $repository->clearCache();
        $this->metrics['cache_misses']++;
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function clearServiceCache(): bool
    {
        $pattern = $this->getServiceName() . ':*';
        $this->metrics['cache_misses']++;
        return $this->cache->deleteMatching($pattern);
    }

    /**
     * {@inheritDoc}
     */
    public function withCaching(string $cacheKey, Closure $callback, ?int $ttl = null): mixed
    {
        $fullKey = $this->getServiceCacheKey($cacheKey);
        
        // Try cache first
        $cached = $this->cache->get($fullKey);
        if ($cached !== null) {
            $this->metrics['cache_hits']++;
            return $cached;
        }
        
        $this->metrics['cache_misses']++;
        
        // Execute and cache
        $result = $callback();
        $this->cache->set($fullKey, $result, $ttl ?? 3600);
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function batchOperation(
        array $items,
        Closure $itemOperation,
        int $batchSize = 100,
        ?callable $progressCallback = null
    ): array {
        $results = [];
        $totalItems = count($items);
        
        for ($i = 0; $i < $totalItems; $i += $batchSize) {
            $batch = array_slice($items, $i, $batchSize);
            
            $batchResults = $this->transaction(function () use ($batch, $itemOperation, $i, $totalItems, $progressCallback) {
                $batchResults = [];
                
                foreach ($batch as $index => $item) {
                    $globalIndex = $i + $index;
                    $result = $itemOperation($item, $globalIndex);
                    $batchResults[] = $result;
                    
                    if ($progressCallback !== null) {
                        $progressCallback($item, $globalIndex, $totalItems);
                    }
                }
                
                return $batchResults;
            }, sprintf('batch_%d_%d', $i, min($i + $batchSize, $totalItems)));
            
            $results = array_merge($results, $batchResults);
        }
        
        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function setAdminContext(?int $adminId): self
    {
        $this->currentAdminId = $adminId;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentAdminId(): ?int
    {
        return $this->currentAdminId;
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceCacheKey(string $operation, array $parameters = []): string
    {
        $hash = md5(serialize($parameters));
        return sprintf('%s:%s:%s', $this->getServiceName(), $operation, $hash);
    }

    /**
     * {@inheritDoc}
     */
    public function getInitializedAt(): string
    {
        return $this->initializedAt;
    }

    /**
     * {@inheritDoc}
     */
    public function isReady(): bool
    {
        return $this->db->connect() !== false && $this->cache->isAvailable();
    }

    /**
     * {@inheritDoc}
     */
    public function getHealthStatus(): array
    {
        return [
            'status' => $this->isReady() ? 'healthy' : 'unhealthy',
            'ready' => $this->isReady(),
            'dependencies' => [
                'database' => $this->db->connect() !== false,
                'cache' => $this->cache->isAvailable(),
                'audit_service' => $this->auditService !== null,
            ],
            'initialized_at' => $this->initializedAt,
            'operation_count' => $this->operationCount,
            'service_name' => $this->getServiceName(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceMetrics(): array
    {
        $totalTransactions = $this->metrics['total_transactions'];
        $successRate = $totalTransactions > 0 
            ? ($this->metrics['successful_transactions'] / $totalTransactions) * 100 
            : 0;
        
        $cacheTotal = $this->metrics['cache_hits'] + $this->metrics['cache_misses'];
        $cacheHitRate = $cacheTotal > 0 
            ? ($this->metrics['cache_hits'] / $cacheTotal) * 100 
            : 0;
        
        $avgDuration = $this->metrics['total_transactions'] > 0
            ? $this->metrics['total_duration_ms'] / $this->metrics['total_transactions']
            : 0;
        
        return [
            'total_transactions' => $this->metrics['total_transactions'],
            'successful_transactions' => $this->metrics['successful_transactions'],
            'failed_transactions' => $this->metrics['failed_transactions'],
            'success_rate_percent' => round($successRate, 2),
            'cache_hits' => $this->metrics['cache_hits'],
            'cache_misses' => $this->metrics['cache_misses'],
            'cache_hit_rate_percent' => round($cacheHitRate, 2),
            'average_duration_ms' => round($avgDuration, 2),
            'total_duration_ms' => round($this->metrics['total_duration_ms'], 2),
            'operations_per_second' => $this->calculateOpsPerSecond(),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'total_transactions' => 0,
            'successful_transactions' => 0,
            'failed_transactions' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'total_duration_ms' => 0,
        ];
        
        $this->operationCount = 0;
    }

    /**
     * {@inheritDoc}
     */
    public function validateConfiguration(): array
    {
        return [
            'database' => [
                'connected' => $this->db->connect() !== false,
                'driver' => get_class($this->db),
                'persistent' => $this->db->getConnect()->persistent ?? false,
            ],
            'cache' => [
                'available' => $this->cache->isAvailable(),
                'driver' => get_class($this->cache),
                'stats' => $this->cache->getStats(),
            ],
            'audit_service' => [
                'available' => $this->auditService !== null,
                'class' => get_class($this->auditService),
            ],
            'service_config' => [
                'admin_context_set' => $this->currentAdminId !== null,
                'initialized_at' => $this->initializedAt,
                'service_name' => $this->getServiceName(),
            ],
        ];
    }

    /**
     * Queue cache operation for deferred execution (atomic cache management)
     *
     * @param Closure|string $operation Cache operation or key to invalidate
     * @return void
     */
    protected function queueCacheOperation($operation): void
    {
        $this->pendingCacheOperations[] = $operation;
    }

    /**
     * Execute pending cache operations after successful transaction
     *
     * @return void
     */
    private function executePendingCacheOperations(): void
    {
        foreach ($this->pendingCacheOperations as $operation) {
            try {
                if ($operation instanceof Closure) {
                    $operation();
                } elseif (is_string($operation)) {
                    if (strpos($operation, '*') !== false) {
                        $this->cache->deleteMatching($operation);
                    } else {
                        $this->cache->delete($operation);
                    }
                }
            } catch (\Throwable $e) {
                // Log but don't fail transaction due to cache errors
                log_message('error', sprintf(
                    '[%s] Failed to execute cache operation: %s',
                    $this->getServiceName(),
                    $e->getMessage()
                ));
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
        $this->pendingCacheOperations = [];
    }

    /**
     * Handle transaction errors with proper logging and exception wrapping
     *
     * @param \Throwable $exception
     * @param string $transactionName
     * @return never
     */
    private function handleTransactionError(\Throwable $exception, string $transactionName): void
    {
        log_message('error', sprintf(
            '[%s] Transaction %s failed: %s - %s',
            $this->getServiceName(),
            $transactionName,
            get_class($exception),
            $exception->getMessage()
        ));
        
        // Convert database exceptions to domain exceptions
        if ($exception instanceof \CodeIgniter\Database\Exceptions\DatabaseException) {
            throw new DomainException(
                sprintf('Database operation failed: %s', $exception->getMessage()),
                'DATABASE_ERROR',
                [
                    'transaction' => $transactionName,
                    'service' => $this->getServiceName()
                ]
            );
        }
        
        // Re-throw domain exceptions as-is
        if ($exception instanceof DomainException) {
            throw $exception;
        }
        
        // Wrap other exceptions in DomainException
        throw new DomainException(
            sprintf('Business operation failed: %s', $exception->getMessage()),
            'BUSINESS_OPERATION_FAILED',
            [
                'transaction' => $transactionName,
                'original_exception' => get_class($exception),
                'service' => $this->getServiceName()
            ],
            $exception->getCode(),
            $exception
        );
    }

    /**
     * Check if error is retryable (deadlock, lock timeout, etc.)
     *
     * @param \Throwable $exception
     * @return bool
     */
    private function isRetryableError(\Throwable $exception): bool
    {
        if (!$exception instanceof \CodeIgniter\Database\Exceptions\DatabaseException) {
            return false;
        }
        
        $message = $exception->getMessage();
        $retryablePatterns = [
            '/deadlock/i',
            '/lock wait timeout/i',
            '/try restarting transaction/i',
            '/serialization failure/i',
            '/connection lost/i',
            '/timeout/i'
        ];
        
        foreach ($retryablePatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check permission for admin (placeholder - to be implemented with proper auth system)
     *
     * @param int $adminId
     * @param string $permission
     * @param mixed $resource
     * @return bool
     */
    private function checkPermission(int $adminId, string $permission, $resource = null): bool
    {
        // Placeholder implementation
        // In production, this would integrate with a proper RBAC/ABAC system
        
        // For MVP, we'll use simple rules:
        if ($adminId === 1) {
            return true; // Super admin
        }
        
        // Example permission checks (expand based on actual requirements)
        $permissionMap = [
            'admin.access' => true,
            'product.create' => true,
            'product.delete' => $adminId === 1,
            'user.manage' => $adminId === 1,
            'audit.view' => true,
            // Add more permissions as needed
        ];
        
        return $permissionMap[$permission] ?? false;
    }

    /**
     * Get short entity class name
     *
     * @param string $entityClass
     * @return string
     */
    private function getEntityShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);
        return end($parts);
    }

    /**
     * Validate service dependencies
     *
     * @return void
     * @throws RuntimeException If dependencies are invalid
     */
    private function validateDependencies(): void
    {
        if (!$this->db instanceof ConnectionInterface) {
            throw new RuntimeException('Invalid database connection dependency');
        }
        
        if (!$this->cache instanceof CacheInterface) {
            throw new RuntimeException('Invalid cache service dependency');
        }
        
        if (!$this->auditService instanceof AuditService) {
            throw new RuntimeException('Invalid audit service dependency');
        }
        
        log_message('debug', sprintf(
            '[%s] Service initialized successfully at %s',
            $this->getServiceName(),
            $this->initializedAt
        ));
    }

    /**
     * Calculate operations per second
     *
     * @return float
     */
    private function calculateOpsPerSecond(): float
    {
        if ($this->operationCount === 0) {
            return 0.0;
        }
        
        $startTime = strtotime($this->initializedAt);
        $currentTime = time();
        $elapsedSeconds = max(1, $currentTime - $startTime);
        
        return round($this->operationCount / $elapsedSeconds, 2);
    }

    /**
     * Abstract method - must be implemented by concrete services
     */
    abstract public function validateBusinessRules(BaseDTO $dto, array $context = []): array;

    /**
     * Abstract method - must be implemented by concrete services
     */
    abstract public function getServiceName(): string;
}