<?php

namespace App\Services;

use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;
use Throwable;

class TransactionService
{
    private ConnectionInterface $db;
    private array $config;
    private array $metrics;
    private int $transactionLevel = 0;
    private bool $isRolledBack = false;
    
    // Transaction isolation levels
    public const ISOLATION_READ_UNCOMMITTED = 'READ UNCOMMITTED';
    public const ISOLATION_READ_COMMITTED = 'READ COMMITTED';
    public const ISOLATION_REPEATABLE_READ = 'REPEATABLE READ';
    public const ISOLATION_SERIALIZABLE = 'SERIALIZABLE';
    
    // Error codes
    public const ERROR_DEADLOCK = 1213;
    public const ERROR_LOCK_WAIT_TIMEOUT = 1205;
    public const ERROR_LOCK_ACQUIRE = 3572;
    
    // Default configuration
    private const DEFAULT_CONFIG = [
        'max_retries' => 3,
        'retry_delay' => 100, // milliseconds
        'default_isolation' => self::ISOLATION_READ_COMMITTED,
        'auto_commit' => false,
        'log_queries' => false,
        'log_transactions' => true,
        'deadlock_retry' => true,
        'lock_timeout' => 30, // seconds
        'max_transaction_time' => 60, // seconds
        'enable_savepoints' => true,
    ];
    
    public function __construct(?ConnectionInterface $db = null, array $config = [])
    {
        $this->db = $db ?? db_connect();
        $this->config = array_merge(self::DEFAULT_CONFIG, $config);
        $this->metrics = [
            'transactions_started' => 0,
            'transactions_committed' => 0,
            'transactions_rolled_back' => 0,
            'retries_attempted' => 0,
            'deadlocks_detected' => 0,
            'total_execution_time' => 0,
        ];
    }
    
    // ==================== BASIC TRANSACTION MANAGEMENT ====================
    
    /**
     * Start a new database transaction
     *
     * @param string|null $isolationLevel Transaction isolation level
     * @param bool $withSavepoint Create savepoint for nested transactions
     * @return bool
     * @throws DatabaseException
     */
    public function start(?string $isolationLevel = null, bool $withSavepoint = true): bool
    {
        $isolationLevel = $isolationLevel ?? $this->config['default_isolation'];
        
        try {
            if ($this->transactionLevel === 0) {
                // Set transaction isolation level if supported
                if ($isolationLevel !== null) {
                    $this->setIsolationLevel($isolationLevel);
                }
                
                $this->db->transStart();
                $this->metrics['transactions_started']++;
                
                // Set lock timeout if supported
                if ($this->config['lock_timeout'] > 0) {
                    $this->setLockTimeout($this->config['lock_timeout']);
                }
                
                $this->logTransaction('Transaction started', [
                    'isolation_level' => $isolationLevel,
                    'lock_timeout' => $this->config['lock_timeout'],
                ]);
            } elseif ($this->config['enable_savepoints'] && $withSavepoint) {
                // Nested transaction - create savepoint
                $savepointName = 'SAVEPOINT_' . ($this->transactionLevel - 1);
                $this->db->query("SAVEPOINT {$savepointName}");
                
                $this->logTransaction('Savepoint created', [
                    'savepoint_name' => $savepointName,
                    'transaction_level' => $this->transactionLevel,
                ]);
            }
            
            $this->transactionLevel++;
            $this->isRolledBack = false;
            
            return true;
            
        } catch (DatabaseException $e) {
            $this->logError('Failed to start transaction', $e);
            throw $e;
        }
    }
    
    /**
     * Commit the current transaction
     *
     * @param bool $force Force commit even if nested
     * @return bool
     * @throws DatabaseException
     */
    public function commit(bool $force = false): bool
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('No active transaction to commit');
        }
        
        if ($this->isRolledBack) {
            throw new RuntimeException('Cannot commit rolled back transaction');
        }
        
        try {
            $this->transactionLevel--;
            
            if ($this->transactionLevel === 0 || $force) {
                $result = $this->db->transComplete();
                
                if ($result) {
                    $this->metrics['transactions_committed']++;
                    $this->logTransaction('Transaction committed');
                } else {
                    $this->logError('Transaction commit failed');
                }
                
                return $result;
            }
            
            // Nested transaction - just decrement level
            return true;
            
        } catch (DatabaseException $e) {
            $this->logError('Failed to commit transaction', $e);
            $this->transactionLevel = max(0, $this->transactionLevel - 1);
            throw $e;
        }
    }
    
    /**
     * Rollback the current transaction
     *
     * @param bool $force Force rollback even if nested
     * @param string|null $savepointName Specific savepoint to rollback to
     * @return bool
     * @throws DatabaseException
     */
    public function rollback(bool $force = false, ?string $savepointName = null): bool
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('No active transaction to rollback');
        }
        
        try {
            $this->isRolledBack = true;
            
            if ($savepointName !== null && $this->config['enable_savepoints']) {
                // Rollback to specific savepoint
                $this->db->query("ROLLBACK TO SAVEPOINT {$savepointName}");
                $this->logTransaction('Rolled back to savepoint', ['savepoint' => $savepointName]);
                return true;
            }
            
            $this->transactionLevel--;
            
            if ($this->transactionLevel === 0 || $force) {
                $this->db->transRollback();
                $this->metrics['transactions_rolled_back']++;
                $this->logTransaction('Transaction rolled back');
                return true;
            }
            
            // Nested transaction - rollback to previous savepoint
            if ($this->config['enable_savepoints']) {
                $savepointName = 'SAVEPOINT_' . ($this->transactionLevel - 1);
                $this->db->query("ROLLBACK TO SAVEPOINT {$savepointName}");
                $this->logTransaction('Nested transaction rolled back', [
                    'savepoint' => $savepointName,
                    'remaining_level' => $this->transactionLevel,
                ]);
            }
            
            return true;
            
        } catch (DatabaseException $e) {
            $this->logError('Failed to rollback transaction', $e);
            // Force transaction level reset on rollback failure
            $this->transactionLevel = 0;
            throw $e;
        }
    }
    
    /**
     * Execute callback within a transaction with automatic handling
     *
     * @param callable $callback Function to execute within transaction
     * @param array $options Transaction options
     * @return mixed Callback return value
     * @throws Throwable
     */
    public function execute(callable $callback, array $options = [])
    {
        $options = array_merge([
            'isolation_level' => null,
            'max_retries' => $this->config['max_retries'],
            'retry_delay' => $this->config['retry_delay'],
            'deadlock_retry' => $this->config['deadlock_retry'],
            'throw_on_failure' => true,
            'log_errors' => true,
        ], $options);
        
        $attempt = 0;
        $lastException = null;
        $startTime = microtime(true);
        
        while ($attempt <= $options['max_retries']) {
            try {
                $attempt++;
                if ($attempt > 1) {
                    $this->metrics['retries_attempted']++;
                    $this->logTransaction('Retry attempt', [
                        'attempt' => $attempt,
                        'max_retries' => $options['max_retries'],
                    ]);
                    
                    // Exponential backoff
                    $delay = $options['retry_delay'] * pow(2, $attempt - 2);
                    usleep($delay * 1000);
                }
                
                $this->start($options['isolation_level']);
                
                $result = $callback($this->db);
                
                $this->commit();
                
                $executionTime = microtime(true) - $startTime;
                $this->metrics['total_execution_time'] += $executionTime;
                
                $this->logTransaction('Transaction executed successfully', [
                    'attempts' => $attempt,
                    'execution_time' => round($executionTime, 4),
                ]);
                
                return $result;
                
            } catch (DatabaseException $e) {
                $this->rollback();
                
                // Check if retryable error
                if ($this->isRetryableError($e) && 
                    $options['deadlock_retry'] && 
                    $attempt < $options['max_retries']) {
                    
                    if ($this->isDeadlock($e)) {
                        $this->metrics['deadlocks_detected']++;
                        $this->logTransaction('Deadlock detected, retrying', [
                            'error_code' => $e->getCode(),
                            'attempt' => $attempt,
                        ]);
                    }
                    
                    $lastException = $e;
                    continue;
                }
                
                if ($options['log_errors']) {
                    $this->logError('Transaction execution failed', $e, [
                        'attempt' => $attempt,
                        'max_retries' => $options['max_retries'],
                    ]);
                }
                
                if ($options['throw_on_failure']) {
                    throw $e;
                }
                
                return null;
                
            } catch (Throwable $e) {
                $this->rollback();
                
                if ($options['log_errors']) {
                    $this->logError('Non-database error in transaction', $e);
                }
                
                if ($options['throw_on_failure']) {
                    throw $e;
                }
                
                return null;
            }
        }
        
        // Max retries exceeded
        if ($options['throw_on_failure'] && $lastException) {
            throw $lastException;
        }
        
        return null;
    }
    
    // ==================== BATCH & BULK OPERATIONS ====================
    
    /**
     * Execute batch operations in chunks within transaction
     *
     * @param array $items Items to process
     * @param callable $processor Function to process each item
     * @param array $options Batch options
     * @return array Results
     */
    public function executeBatch(array $items, callable $processor, array $options = []): array
    {
        $options = array_merge([
            'chunk_size' => 100,
            'stop_on_error' => false,
            'log_progress' => false,
            'progress_interval' => 100,
        ], $options);
        
        $results = [
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'errors' => [],
            'chunks' => 0,
        ];
        
        $chunks = array_chunk($items, $options['chunk_size']);
        $results['chunks'] = count($chunks);
        
        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $this->start();
                
                $chunkResults = [
                    'processed' => 0,
                    'succeeded' => 0,
                    'failed' => 0,
                    'errors' => [],
                ];
                
                foreach ($chunk as $itemIndex => $item) {
                    try {
                        $processorResult = $processor($item, $chunkIndex * $options['chunk_size'] + $itemIndex);
                        
                        $chunkResults['processed']++;
                        $chunkResults['succeeded']++;
                        
                        if (isset($processorResult['error'])) {
                            $chunkResults['failed']++;
                            $chunkResults['errors'][] = [
                                'item' => $itemIndex,
                                'error' => $processorResult['error'],
                            ];
                            
                            if ($options['stop_on_error']) {
                                throw new RuntimeException($processorResult['error']);
                            }
                        }
                        
                    } catch (Throwable $e) {
                        $chunkResults['processed']++;
                        $chunkResults['failed']++;
                        $chunkResults['errors'][] = [
                            'item' => $itemIndex,
                            'error' => $e->getMessage(),
                            'exception' => get_class($e),
                        ];
                        
                        if ($options['stop_on_error']) {
                            throw $e;
                        }
                    }
                }
                
                $this->commit();
                
                // Update overall results
                $results['processed'] += $chunkResults['processed'];
                $results['succeeded'] += $chunkResults['succeeded'];
                $results['failed'] += $chunkResults['failed'];
                $results['errors'] = array_merge($results['errors'], $chunkResults['errors']);
                
                // Log progress
                if ($options['log_progress'] && 
                    ($chunkIndex + 1) % $options['progress_interval'] === 0) {
                    $this->logTransaction('Batch progress', [
                        'chunk' => $chunkIndex + 1,
                        'total_chunks' => count($chunks),
                        'processed' => $results['processed'],
                        'succeeded' => $results['succeeded'],
                        'failed' => $results['failed'],
                    ]);
                }
                
            } catch (Throwable $e) {
                $this->rollback();
                
                $results['failed'] += count($chunk) - $chunkResults['processed'];
                $results['errors'][] = [
                    'chunk' => $chunkIndex,
                    'error' => 'Chunk processing failed: ' . $e->getMessage(),
                ];
                
                $this->logError('Batch chunk processing failed', $e, [
                    'chunk' => $chunkIndex,
                    'chunk_size' => count($chunk),
                ]);
                
                if ($options['stop_on_error']) {
                    break;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Execute raw SQL in transaction with parameter binding
     *
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param array $options Execution options
     * @return mixed Query result
     */
    public function executeQuery(string $sql, array $params = [], array $options = [])
    {
        $options = array_merge([
            'isolation_level' => null,
            'max_retries' => $this->config['max_retries'],
            'return_result' => true,
        ], $options);
        
        return $this->execute(function($db) use ($sql, $params, $options) {
            $query = $db->query($sql, $params);
            
            if (!$options['return_result']) {
                return $db->affectedRows();
            }
            
            return $query->getResultArray();
        }, $options);
    }
    
    // ==================== TRANSACTION STATE & INFO ====================
    
    /**
     * Check if transaction is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->transactionLevel > 0;
    }
    
    /**
     * Get current transaction level (for nested transactions)
     *
     * @return int
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }
    
    /**
     * Check if transaction was rolled back
     *
     * @return bool
     */
    public function isRolledBack(): bool
    {
        return $this->isRolledBack;
    }
    
    /**
     * Get transaction metrics
     *
     * @return array
     */
    public function getMetrics(): array
    {
        return array_merge($this->metrics, [
            'active_transaction_level' => $this->transactionLevel,
            'is_rolled_back' => $this->isRolledBack,
            'config' => $this->config,
        ]);
    }
    
    /**
     * Reset transaction metrics
     *
     * @return void
     */
    public function resetMetrics(): void
    {
        $this->metrics = [
            'transactions_started' => 0,
            'transactions_committed' => 0,
            'transactions_rolled_back' => 0,
            'retries_attempted' => 0,
            'deadlocks_detected' => 0,
            'total_execution_time' => 0,
        ];
    }
    
    /**
     * Get database connection status
     *
     * @return array
     */
    public function getConnectionInfo(): array
    {
        return [
            'database' => $this->db->getDatabase(),
            'platform' => $this->db->getPlatform(),
            'connected' => $this->db->isConnected(),
            'persistent' => $this->db->isPersistent(),
            'charset' => $this->db->getCharset(),
        ];
    }
    
    // ==================== ERROR HANDLING & RETRY LOGIC ====================
    
    /**
     * Check if error is retryable (deadlock, lock timeout, etc.)
     *
     * @param DatabaseException $exception
     * @return bool
     */
    private function isRetryableError(DatabaseException $exception): bool
    {
        $errorCode = $exception->getCode();
        $errorMessage = $exception->getMessage();
        
        // MySQL error codes for retryable errors
        $retryableCodes = [
            self::ERROR_DEADLOCK,
            self::ERROR_LOCK_WAIT_TIMEOUT,
            self::ERROR_LOCK_ACQUIRE,
        ];
        
        if (in_array($errorCode, $retryableCodes)) {
            return true;
        }
        
        // Check error message for deadlock or lock timeout patterns
        $retryablePatterns = [
            '/deadlock/i',
            '/lock wait timeout/i',
            '/try restarting transaction/i',
            '/serialization failure/i',
        ];
        
        foreach ($retryablePatterns as $pattern) {
            if (preg_match($pattern, $errorMessage)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if error is a deadlock
     *
     * @param DatabaseException $exception
     * @return bool
     */
    private function isDeadlock(DatabaseException $exception): bool
    {
        return $exception->getCode() === self::ERROR_DEADLOCK || 
               stripos($exception->getMessage(), 'deadlock') !== false;
    }
    
    /**
     * Set transaction isolation level
     *
     * @param string $isolationLevel
     * @return void
     */
    private function setIsolationLevel(string $isolationLevel): void
    {
        $validLevels = [
            self::ISOLATION_READ_UNCOMMITTED,
            self::ISOLATION_READ_COMMITTED,
            self::ISOLATION_REPEATABLE_READ,
            self::ISOLATION_SERIALIZABLE,
        ];
        
        if (!in_array($isolationLevel, $validLevels)) {
            throw new InvalidArgumentException("Invalid isolation level: {$isolationLevel}");
        }
        
        try {
            $this->db->query("SET TRANSACTION ISOLATION LEVEL {$isolationLevel}");
            $this->logTransaction('Isolation level set', ['level' => $isolationLevel]);
        } catch (DatabaseException $e) {
            $this->logError('Failed to set isolation level', $e);
            // Continue without setting if not supported
        }
    }
    
    /**
     * Set lock timeout
     *
     * @param int $timeoutSeconds
     * @return void
     */
    private function setLockTimeout(int $timeoutSeconds): void
    {
        try {
            // MySQL
            $this->db->query("SET innodb_lock_wait_timeout = {$timeoutSeconds}");
            // PostgreSQL
            // $this->db->query("SET lock_timeout = {$timeoutSeconds * 1000}");
            
            $this->logTransaction('Lock timeout set', ['timeout' => $timeoutSeconds]);
        } catch (DatabaseException $e) {
            $this->logError('Failed to set lock timeout', $e);
            // Continue without setting if not supported
        }
    }
    
    // ==================== LOGGING & MONITORING ====================
    
    /**
     * Log transaction event
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    private function logTransaction(string $message, array $context = []): void
    {
        if (!$this->config['log_transactions']) {
            return;
        }
        
        $logData = array_merge([
            'timestamp' => date('Y-m-d H:i:s'),
            'transaction_level' => $this->transactionLevel,
            'connection' => $this->db->getDatabase(),
        ], $context);
        
        log_message('info', "TransactionService: {$message}", $logData);
    }
    
    /**
     * Log error
     *
     * @param string $message
     * @param Throwable|null $exception
     * @param array $context
     * @return void
     */
    private function logError(string $message, ?Throwable $exception = null, array $context = []): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'transaction_level' => $this->transactionLevel,
            'connection' => $this->db->getDatabase(),
        ];
        
        if ($exception) {
            $logData['error'] = $exception->getMessage();
            $logData['code'] = $exception->getCode();
            $logData['file'] = $exception->getFile();
            $logData['line'] = $exception->getLine();
            $logData['trace'] = $exception->getTraceAsString();
        }
        
        $logData = array_merge($logData, $context);
        
        log_message('error', "TransactionService: {$message}", $logData);
    }
    
    /**
     * Get query log if enabled
     *
     * @return array
     */
    public function getQueryLog(): array
    {
        if (!$this->config['log_queries']) {
            return [];
        }
        
        return $this->db->getQueries() ?? [];
    }
    
    // ==================== UTILITY METHODS ====================
    
    /**
     * Create savepoint (for nested transactions)
     *
     * @param string $name Savepoint name
     * @return bool
     */
    public function createSavepoint(string $name): bool
    {
        if (!$this->isActive()) {
            throw new RuntimeException('Cannot create savepoint without active transaction');
        }
        
        if (!$this->config['enable_savepoints']) {
            throw new RuntimeException('Savepoints are not enabled');
        }
        
        try {
            $this->db->query("SAVEPOINT {$name}");
            $this->logTransaction('Savepoint created', ['name' => $name]);
            return true;
        } catch (DatabaseException $e) {
            $this->logError('Failed to create savepoint', $e);
            return false;
        }
    }
    
    /**
     * Release savepoint
     *
     * @param string $name Savepoint name
     * @return bool
     */
    public function releaseSavepoint(string $name): bool
    {
        if (!$this->isActive()) {
            throw new RuntimeException('Cannot release savepoint without active transaction');
        }
        
        try {
            $this->db->query("RELEASE SAVEPOINT {$name}");
            $this->logTransaction('Savepoint released', ['name' => $name]);
            return true;
        } catch (DatabaseException $e) {
            $this->logError('Failed to release savepoint', $e);
            return false;
        }
    }
    
    /**
     * Wrap existing database connection in transaction service
     *
     * @param ConnectionInterface $db
     * @return self
     */
    public static function wrap(ConnectionInterface $db): self
    {
        return new self($db);
    }
    
    /**
     * Create new instance with configuration
     *
     * @param array $config
     * @return self
     */
    public static function create(array $config = []): self
    {
        return new self(null, $config);
    }
    
    /**
     * Get default configuration
     *
     * @return array
     */
    public static function getDefaultConfig(): array
    {
        return self::DEFAULT_CONFIG;
    }
    
    // ==================== DESTRUCTOR ====================
    
    /**
     * Ensure transaction is closed on destruction
     */
    public function __destruct()
    {
        if ($this->isActive()) {
            $this->logError('Transaction was not properly closed');
            try {
                $this->rollback(true);
            } catch (Throwable $e) {
                // Silently fail during destruct
            }
        }
    }
}