<?php

namespace App\Services\Product\Concrete;

use App\Services\BaseService;
use App\Contracts\ProductBulkInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Validators\ProductValidator;
use App\Services\CacheService;
use App\Services\TransactionService;
use App\DTOs\Requests\Product\ProductBulkActionRequest;
use App\DTOs\Responses\BulkActionResult;
use App\Enums\ProductStatus;
use App\Enums\ProductBulkActionType;
use App\Exceptions\ValidationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\ProductNotFoundException;
use App\Entities\Product;
use App\DTOs\Requests\Product\PublishProductRequest;
use App\DTOs\Requests\Product\ProductDeleteRequest;
use CodeIgniter\I18n\Time;
use Closure;
use Throwable;

/**
 * ProductBulkService - Business Orchestrator for Bulk Product Operations
 * 
 * Layer 5: Specialized Bulk Operations Service
 * Implements ProductBulkInterface with focus on batch processing optimization
 * 
 * @package App\Services\Product\Concrete
 */
class ProductBulkService extends BaseService implements ProductBulkInterface
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;

    /**
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;

    /**
     * @var ProductValidator
     */
    private ProductValidator $productValidator;

    /**
     * @var CacheService
     */
    private CacheService $cacheService;

    /**
     * @var TransactionService
     */
    private TransactionService $transactionService;

    /**
     * @var array Bulk operation statistics
     */
    private array $bulkStats = [
        'total_operations' => 0,
        'successful_items' => 0,
        'failed_items' => 0,
        'total_duration_ms' => 0,
        'max_batch_size_used' => 0,
    ];

    /**
     * Constructor with Dependency Injection
     * 
     * @param ProductRepositoryInterface $productRepository
     * @param AuditLogRepositoryInterface $auditLogRepository
     * @param LinkRepositoryInterface $linkRepository
     * @param ProductValidator $productValidator
     * @param CacheService $cacheService
     * @param TransactionService $transactionService
     * @param \CodeIgniter\Database\ConnectionInterface $db
     * @param \App\Contracts\CacheInterface $cache
     * @param \App\Services\AuditService $auditService
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        LinkRepositoryInterface $linkRepository,
        ProductValidator $productValidator,
        CacheService $cacheService,
        TransactionService $transactionService,
        \CodeIgniter\Database\ConnectionInterface $db,
        \App\Contracts\CacheInterface $cache,
        \App\Services\AuditService $auditService
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->productRepository = $productRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->linkRepository = $linkRepository;
        $this->productValidator = $productValidator;
        $this->cacheService = $cacheService;
        $this->transactionService = $transactionService;
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'ProductBulkService';
    }

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(\App\DTOs\BaseDTO $dto, array $context = []): array
    {
        $errors = [];
        
        if ($dto instanceof ProductBulkActionRequest) {
            $errors = $this->validateBulkActionBusinessRules($dto, $context);
        }
        
        return $errors;
    }

    /**
     * Validate bulk action business rules
     * 
     * @param ProductBulkActionRequest $dto
     * @param array $context
     * @return array
     */
    private function validateBulkActionBusinessRules(ProductBulkActionRequest $dto, array $context): array
    {
        $errors = [];
        
        // Check daily bulk operation limit
        $adminId = $context['admin_id'] ?? $this->getCurrentAdminId();
        if ($adminId !== null) {
            $dailyLimit = 10; // Configurable
            $todayCount = $this->auditLogRepository->count([
                'action_type' => 'BULK_ACTION',
                'admin_id' => $adminId,
                'created_at >=' => (new Time('today'))->toDateTimeString()
            ]);
            
            if ($todayCount >= $dailyLimit) {
                $errors['daily_limit'] = "Daily bulk operation limit ({$dailyLimit}) exceeded";
            }
        }
        
        // Check product count limit
        $productCount = count($dto->getProductIds());
        $maxBulkItems = 1000; // Configurable
        if ($productCount > $maxBulkItems) {
            $errors['product_count'] = "Cannot process more than {$maxBulkItems} products in bulk";
        }
        
        // Check action-specific rules
        switch ($dto->getAction()) {
            case ProductBulkActionType::DELETE:
                if ($dto->getParameters()['hard_delete'] ?? false) {
                    // Check if admin has hard delete permission
                    try {
                        $this->authorize('product.bulk.hard_delete');
                    } catch (AuthorizationException $e) {
                        $errors['permission'] = "Hard delete permission required";
                    }
                }
                break;
                
            case ProductBulkActionType::PUBLISH:
                // Force publish validation
                if (!($dto->getParameters()['force'] ?? false)) {
                    // Validate each product can be published
                    $validationResults = $this->validateBulkAction(
                        $dto->getProductIds(),
                        $dto->getAction(),
                        $dto->getParameters()
                    );
                    
                    if (!empty($validationResults['errors'])) {
                        $errors['publish_validation'] = "Some products cannot be published";
                    }
                }
                break;
        }
        
        return $errors;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkAction(ProductBulkActionRequest $request): BulkActionResult
    {
        $this->bulkStats['total_operations']++;
        $this->authorize('product.bulk.action');
        
        $startTime = microtime(true);
        
        // 1. Validation Phase (No Transaction needed yet)
        $validationResult = $this->validateBulkAction(
            $request->getProductIds(),
            $request->getAction(),
            $request->getParameters()
        );
        
        if (!$validationResult['valid']) {
            throw ValidationException::forBusinessRule(
                'bulk_action_validation',
                'Bulk action validation failed',
                $validationResult
            );
        }

        // 2. Setup Context
        $validIds = $validationResult['valid_ids'];
        $action = $request->getAction();
        $adminId = $request->getUserId();
        $parameters = $request->getParameters();
        
        $results = [
            'success' => [],
            'failed' => [],
            'skipped' => []
        ];

        // 3. Calculate Batch Size
        $batchSize = $this->calculateOptimalBatchSize(
            $action->value,
            count($validIds),
            ['max_memory_mb' => 128]
        )['recommended_batch_size'];

        $this->bulkStats['max_batch_size_used'] = max(
            $this->bulkStats['max_batch_size_used'],
            $batchSize
        );

        // 4. Execution Phase (Optimized Chunking)
        // Kita membagi strategi berdasarkan Tipe Action
        switch ($action) {
            case ProductBulkActionType::PUBLISH:
            case ProductBulkActionType::DELETE:
                // Strategi A: Manual Chunking dengan Transaksi per Batch
                // Cocok untuk operasi yang memanggil helper 'processBulk...Chunk'
                
                $chunks = array_chunk($validIds, $batchSize);
                
                foreach ($chunks as $chunkIndex => $chunkIds) {
                    // Start Transaction PER CHUNK (Bukan global)
                    $chunkResult = $this->transaction(function() use ($action, $chunkIds, $adminId, $parameters) {
                        if ($action === ProductBulkActionType::PUBLISH) {
                            return $this->processBulkPublishChunk($chunkIds, $adminId, $parameters);
                        } else {
                            return $this->processBulkDeleteChunk($chunkIds, $adminId, $parameters);
                        }
                    }, 'bulk_chunk_' . $chunkIndex);

                    // Merge result immediately
                    $this->mergeResults($results, $chunkResult);
                    
                    // 🛡️ IMPORTANT: Beri jeda 10ms-50ms agar DB tidak terkunci terus menerus
                    // Ini sangat vital untuk SQLite/Termux environment
                    usleep(20000); 
                }
                break;

            case ProductBulkActionType::ARCHIVE:
                // Strategi B: Delegated Execution
                [span_0](start_span)// Method bulkArchive sudah menghandle chunking via executeBulkWithCallback[span_0](end_span)
                // Jadi JANGAN dibungkus transaksi lagi agar tidak double-transaction overhead.
                $results['success'] = $this->bulkArchive(
                    $validIds,
                    $adminId,
                    $parameters['reason'] ?? null,
                    $parameters
                );
                // Note: Failed items handling tergantung implementasi bulkArchive, 
                // jika dia return array success saja, failed count diambil dari total - success
                break;

            case ProductBulkActionType::UPDATE_STATUS:
                // Strategi B: Delegated Execution
                if (!isset($parameters['status'])) {
                    throw new \InvalidArgumentException('Status parameter required');
                }
                $status = ProductStatus::from($parameters['status']);
                
                // Method ini juga sudah aman (self-managed)
                $results['success'] = $this->bulkUpdateStatus(
                    $validIds,
                    $status,
                    $adminId,
                    $parameters
                );
                break;

            default:
                throw new DomainException(
                    "Unsupported bulk action: {$action->value}",
                    'UNSUPPORTED_BULK_ACTION',
                    400
                );
        }

        // 5. Finalization
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->bulkStats['total_duration_ms'] += $duration;

        // Queue cache invalidation (Async/Deferred)
        // Kita lakukan di akhir agar tidak spamming cache server per chunk
        if (!empty($results['success'])) {
            $this->queueCacheOperation(function() use ($results) {
                // Hanya clear cache untuk ID yang sukses
                $successIds = is_array($results['success']) ? $results['success'] : [];
                if (!empty($successIds)) {
                    $this->bulkClearCaches($successIds, [
                        'clear_related' => true,
                        'async' => true
                    ]);
                }
            });
        }

        // 6. Global Audit Log
        $this->audit(
            'BULK_ACTION',
            'PRODUCT',
            0,
            null,
            null,
            [
                'action' => $action->value,
                'admin_id' => $adminId,
                'total_ids' => count($request->getProductIds()),
                'valid_ids' => count($validIds),
                'success_count' => count($results['success']),
                'failed_count' => count($results['failed']),
                'duration_ms' => $duration,
                'batch_size' => $batchSize,
                'parameters' => $parameters
            ]
        );

        return new BulkActionResult(
            $action,
            count($results['success']),
            count($results['failed']),
            count($validationResult['invalid_ids']),
            $duration,
            $results
        );
    }


    /**
     * {@inheritDoc}
     */
    public function executeBulkWithCallback(
        array $productIds,
        callable $itemCallback,
        array $context = [],
        bool $useTransaction = true,
        int $batchSize = 100
    ): array {
        $this->bulkStats['total_operations']++;
        $this->authorize('product.bulk.execute');
        
        $startTime = microtime(true);
        $results = [
            'total' => count($productIds),
            'success' => 0,
            'failed' => 0,
            'results' => [],
            'errors' => []
        ];
        
        $operation = function() use ($productIds, $itemCallback, $context, $batchSize, &$results) {
            $chunks = array_chunk($productIds, $batchSize);
            
            foreach ($chunks as $chunkIndex => $chunk) {
                try {
                    $chunkResults = [];
                    foreach ($chunk as $index => $productId) {
                        try {
                            $result = $itemCallback($productId, array_merge($context, [
                                'global_index' => $chunkIndex * $batchSize + $index,
                                'chunk_index' => $chunkIndex,
                                'item_index' => $index
                            ]));
                            
                            $chunkResults[$productId] = $result;
                            $results['success']++;
                            $results['results'][$productId] = $result;
                        } catch (Throwable $e) {
                            $results['failed']++;
                            $results['errors'][$productId] = $e->getMessage();
                            log_message('error', "Bulk callback failed for product {$productId}: " . $e->getMessage());
                        }
                    }
                    
                    // Process chunk results if needed
                    if (!empty($chunkResults)) {
                        $this->bulkStats['successful_items'] += count($chunkResults);
                    }
                    
                } catch (Throwable $e) {
                    log_message('error', "Bulk chunk processing failed: " . $e->getMessage());
                    throw $e;
                }
            }
        };
        
        if ($useTransaction) {
            $this->transaction($operation, 'bulk_callback_execution');
        } else {
            $operation();
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $this->bulkStats['total_duration_ms'] += $duration;
        
        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(
        array $productIds, 
        ProductStatus $status, 
        int $adminId, 
        array $parameters = []
    ): array {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.update.status');
        
        $successIds = [];
        $failedIds = [];
        
        $batchSize = $this->calculateOptimalBatchSize(
            'update_status',
            count($productIds)
        )['recommended_batch_size'];
        
        $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($status, $adminId, $parameters, &$successIds, &$failedIds) {
                try {
                    $success = $this->productRepository->updateStatus(
                        $productId,
                        $status,
                        $adminId
                    );
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        // Log individual status change
                        $this->audit(
                            'STATUS_CHANGE',
                            'PRODUCT',
                            $productId,
                            null,
                            ['status' => $status->value],
                            [
                                'changed_by' => $adminId,
                                'new_status' => $status->value,
                                'bulk_operation' => true
                            ]
                        );
                        
                        return true;
                    }
                    
                    $failedIds[$productId] = 'Failed to update status';
                    return false;
                    
                } catch (Throwable $e) {
                    $failedIds[$productId] = $e->getMessage();
                    return false;
                }
            },
            ['status' => $status->value, 'admin_id' => $adminId],
            true,
            $batchSize
        );
        
        // Queue cache invalidation
        if (!empty($successIds)) {
            $this->queueCacheOperation(function() use ($successIds) {
                $this->bulkClearCaches($successIds, [
                    'clear_related' => true,
                    'cache_level' => 'entity'
                ]);
            });
            
            // Bulk audit log
            $this->audit(
                'BULK_STATUS_UPDATE',
                'PRODUCT',
                0,
                null,
                null,
                [
                    'new_status' => $status->value,
                    'admin_id' => $adminId,
                    'total_ids' => count($productIds),
                    'success_count' => count($successIds),
                    'failed_count' => count($failedIds),
                    'parameters' => $parameters
                ]
            );
        }
        
        return $successIds;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkPublish(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.publish');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $parameters, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    // Check prerequisites unless force publish
                    if (!($parameters['force'] ?? false)) {
                        $prerequisites = $this->validateForPublication($productId);
                        if (!$prerequisites['valid']) {
                            throw ValidationException::forBusinessRule(
                                'publish_prerequisites',
                                'Product cannot be published: ' . implode(', ', $prerequisites['errors'])
                            );
                        }
                    }
                    
                    // Publish product
                    $product->publish();
                    if ($adminId) {
                        $product->setVerifiedBy($adminId);
                    }
                    
                    $this->productRepository->save($product);
                    $successIds[] = $productId;
                    
                    return true;
                    
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkVerify(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.verify');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $parameters, &$successIds) {
                try {
                    $success = $this->productRepository->verify($productId, $adminId);
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        $this->audit(
                            'VERIFY',
                            'PRODUCT',
                            $productId,
                            null,
                            ['verified_by' => $adminId],
                            [
                                'verified_by' => $adminId,
                                'bulk_operation' => true,
                                'notes' => $parameters['notes'] ?? null
                            ]
                        );
                    }
                    
                    return $success;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $productIds, int $adminId, ?string $reason = null, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.archive');
        
        $successIds = [];
        
        $result = $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $reason, $parameters, &$successIds) {
                try {
                    $success = $this->productRepository->archive($productId, $adminId);
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        $this->audit(
                            'ARCHIVE',
                            'PRODUCT',
                            $productId,
                            null,
                            ['archived_by' => $adminId],
                            [
                                'archived_by' => $adminId,
                                'reason' => $reason,
                                'bulk_operation' => true,
                                'force' => $parameters['force'] ?? false
                            ]
                        );
                    }
                    
                    return $success;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'reason' => $reason, 'parameters' => $parameters],
            true
        );
        
        // Clear caches for archived products
        if (!empty($successIds)) {
            $this->queueCacheOperation(function() use ($successIds) {
                $this->bulkClearCaches($successIds, [
                    'clear_related' => true,
                    'cache_level' => 'all'
                ]);
            });
            
            $this->audit(
                'BULK_ARCHIVE',
                'PRODUCT',
                0,
                null,
                null,
                [
                    'admin_id' => $adminId,
                    'reason' => $reason,
                    'total_ids' => count($productIds),
                    'success_count' => count($successIds),
                    'failed_count' => $result['failed'],
                    'parameters' => $parameters
                ]
            );
        }
        
        return $successIds;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestore(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.restore');
        
        $successIds = [];
        
        $result = $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $parameters, &$successIds) {
                try {
                    $success = $this->productRepository->restore($productId);
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        $this->audit(
                            'RESTORE',
                            'PRODUCT',
                            $productId,
                            null,
                            ['restored_by' => $adminId],
                            [
                                'restored_by' => $adminId,
                                'bulk_operation' => true,
                                'restore_links' => $parameters['restore_links'] ?? false
                            ]
                        );
                    }
                    
                    return $success;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        );
        
        if (!empty($successIds)) {
            $this->queueCacheOperation(function() use ($successIds) {
                $this->bulkClearCaches($successIds, [
                    'clear_related' => true,
                    'cache_level' => 'entity'
                ]);
            });
        }
        
        return $successIds;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.delete');
        
        $hardDelete = $parameters['hard_delete'] ?? false;
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $hardDelete, $parameters, &$successIds) {
                try {
                    if ($hardDelete) {
                        $success = $this->productRepository->forceDelete($productId);
                    } else {
                        $success = $this->productRepository->delete($productId);
                    }
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        $this->audit(
                            $hardDelete ? 'HARD_DELETE' : 'SOFT_DELETE',
                            'PRODUCT',
                            $productId,
                            null,
                            null,
                            [
                                'deleted_by' => $adminId,
                                'reason' => $parameters['reason'] ?? 'Bulk delete',
                                'hard_delete' => $hardDelete,
                                'cascade' => $parameters['cascade'] ?? false,
                                'bulk_operation' => true
                            ]
                        );
                    }
                    
                    return $success;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRequestVerification(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.request_verification');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $parameters, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    // Request verification
                    $product->setStatus(ProductStatus::PENDING_VERIFICATION);
                    $this->productRepository->save($product);
                    
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'VERIFICATION_REQUEST',
                        'PRODUCT',
                        $productId,
                        null,
                        ['status' => ProductStatus::PENDING_VERIFICATION->value],
                        [
                            'requested_by' => $adminId,
                            'assign_to' => $parameters['assign_to'] ?? null,
                            'notes' => $parameters['notes'] ?? null,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRevertToDraft(array $productIds, int $adminId, ?string $reason = null): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.revert_to_draft');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $reason, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    // Revert to draft
                    $product->setStatus(ProductStatus::DRAFT);
                    $this->productRepository->save($product);
                    
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'REVERT_TO_DRAFT',
                        'PRODUCT',
                        $productId,
                        null,
                        ['status' => ProductStatus::DRAFT->value],
                        [
                            'reverted_by' => $adminId,
                            'reason' => $reason,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'reason' => $reason],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateData(array $productIds, array $data, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.update.data');
        
        if (empty($data)) {
            throw new \InvalidArgumentException('No data provided for bulk update');
        }
        
        $successIds = [];
        $failedIds = [];
        
        $batchSize = $this->calculateOptimalBatchSize(
            'update_data',
            count($productIds),
            ['max_memory_mb' => 256]
        )['recommended_batch_size'];
        
        foreach (array_chunk($productIds, $batchSize) as $chunk) {
            try {
                $this->transaction(function() use ($chunk, $data, $adminId, $parameters, &$successIds, &$failedIds) {
                    foreach ($chunk as $productId) {
                        try {
                            $product = $this->productRepository->findById($productId);
                            
                            if ($product === null) {
                                $failedIds[$productId] = 'Product not found';
                                continue;
                            }
                            
                            $oldValues = $product->toArray();
                            
                            // Apply updates
                            foreach ($data as $field => $value) {
                                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                                if (method_exists($product, $setter)) {
                                    $product->$setter($value);
                                }
                            }
                            
                            // Save updated product
                            $this->productRepository->save($product);
                            $successIds[] = $productId;
                            
                            // Individual audit
                            $this->audit(
                                'UPDATE',
                                'PRODUCT',
                                $productId,
                                $oldValues,
                                $product->toArray(),
                                [
                                    'updated_by' => $adminId,
                                    'changed_fields' => array_keys($data),
                                    'bulk_update' => true,
                                    'validate' => $parameters['validate'] ?? true
                                ]
                            );
                            
                        } catch (Throwable $e) {
                            $failedIds[$productId] = $e->getMessage();
                        }
                    }
                }, 'bulk_data_update_chunk');
            } catch (Throwable $e) {
                // Log chunk failure but continue with next chunk
                log_message('error', "Bulk data update chunk failed: " . $e->getMessage());
            }
        }
        
        // Clear caches for updated products
        if (!empty($successIds)) {
            $this->queueCacheOperation(function() use ($successIds) {
                $this->bulkClearCaches($successIds, [
                    'clear_related' => true,
                    'cache_level' => 'entity'
                ]);
            });
            
            $this->audit(
                'BULK_DATA_UPDATE',
                'PRODUCT',
                0,
                null,
                null,
                [
                    'admin_id' => $adminId,
                    'total_ids' => count($productIds),
                    'success_count' => count($successIds),
                    'failed_count' => count($failedIds),
                    'updated_fields' => array_keys($data),
                    'parameters' => $parameters
                ]
            );
        }
        
        return [
            'success' => $successIds,
            'failed' => $failedIds,
            'updated_fields' => array_keys($data)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdatePrices(array $priceUpdates, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.update.prices');
        
        if (empty($priceUpdates)) {
            return [
                'success' => 0,
                'failed' => [],
                'total_updated' => 0
            ];
        }
        
        $updatedCount = 0;
        $failedUpdates = [];
        
        $batchSize = $this->calculateOptimalBatchSize(
            'update_prices',
            count($priceUpdates),
            ['max_memory_mb' => 128]
        )['recommended_batch_size'];
        
        $productIds = array_keys($priceUpdates);
        
        foreach (array_chunk($productIds, $batchSize) as $chunk) {
            $this->transaction(function() use ($chunk, $priceUpdates, $adminId, $parameters, &$updatedCount, &$failedUpdates) {
                foreach ($chunk as $productId) {
                    try {
                        $product = $this->productRepository->findById($productId);
                        
                        if ($product === null) {
                            $failedUpdates[$productId] = 'Product not found';
                            continue;
                        }
                        
                        $oldPrice = $product->getMarketPrice();
                        $newPrice = $priceUpdates[$productId];
                        
                        // Apply percentage increase if specified
                        if (isset($parameters['percentage_increase'])) {
                            $percentage = (float) $parameters['percentage_increase'];
                            $newPrice = $oldPrice * (1 + ($percentage / 100));
                        }
                        
                        $product->setMarketPrice($newPrice);
                        $this->productRepository->save($product);
                        
                        // Mark price as checked
                        if ($parameters['mark_checked'] ?? true) {
                            $this->productRepository->markPriceChecked($productId);
                        }
                        
                        $updatedCount++;
                        
                        $this->audit(
                            'PRICE_UPDATE',
                            'PRODUCT',
                            $productId,
                            ['market_price' => $oldPrice],
                            ['market_price' => $newPrice],
                            [
                                'admin_id' => $adminId,
                                'percentage_increase' => $parameters['percentage_increase'] ?? null,
                                'price_type' => $parameters['price_type'] ?? 'market_price',
                                'bulk_update' => true
                            ]
                        );
                        
                    } catch (Throwable $e) {
                        $failedUpdates[$productId] = $e->getMessage();
                    }
                }
            }, 'bulk_price_update_chunk');
        }
        
        // Clear caches for updated products
        if ($updatedCount > 0) {
            $this->queueCacheOperation(function() use ($productIds) {
                $this->bulkClearCaches($productIds, [
                    'clear_related' => false,
                    'cache_level' => 'entity'
                ]);
            });
            
            $this->audit(
                'BATCH_PRICE_UPDATE',
                'PRODUCT',
                0,
                null,
                null,
                [
                    'admin_id' => $adminId,
                    'total_attempted' => count($priceUpdates),
                    'successful_updates' => $updatedCount,
                    'failed_updates' => count($failedUpdates),
                    'parameters' => $parameters
                ]
            );
        }
        
        return [
            'success' => $updatedCount,
            'failed' => $failedUpdates,
            'total_updated' => $updatedCount
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateCategories(array $productIds, int $categoryId, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.update.categories');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($categoryId, $adminId, $parameters, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    $oldCategoryId = $product->getCategoryId();
                    $product->setCategoryId($categoryId);
                    $this->productRepository->save($product);
                    
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'CATEGORY_UPDATE',
                        'PRODUCT',
                        $productId,
                        ['category_id' => $oldCategoryId],
                        ['category_id' => $categoryId],
                        [
                            'admin_id' => $adminId,
                            'move_subcategories' => $parameters['move_subcategories'] ?? false,
                            'clear_existing' => $parameters['clear_existing'] ?? false,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'category_id' => $categoryId, 'parameters' => $parameters],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkAssignBadges(array $productIds, array $badgeIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.assign_badges');
        
        $assignmentsMade = 0;
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($badgeIds, $adminId, $parameters, &$assignmentsMade) {
                try {
                    // This would call a badge assignment repository
                    // For now, we'll log and count
                    $assignmentsMade += count($badgeIds);
                    
                    $this->audit(
                        'BADGE_ASSIGNMENT',
                        'PRODUCT',
                        $productId,
                        null,
                        ['badge_ids' => $badgeIds],
                        [
                            'admin_id' => $adminId,
                            'replace' => $parameters['replace'] ?? false,
                            'expires_at' => $parameters['expires_at'] ?? null,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'badge_ids' => $badgeIds, 'parameters' => $parameters],
            true
        );
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRemoveBadges(array $productIds, array $badgeIds, int $adminId): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.remove_badges');
        
        $removalsMade = 0;
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($badgeIds, $adminId, &$removalsMade) {
                try {
                    // This would call a badge removal repository
                    $removalsMade += empty($badgeIds) ? 1 : count($badgeIds); // Count all badges if empty array
                    
                    $this->audit(
                        'BADGE_REMOVAL',
                        'PRODUCT',
                        $productId,
                        ['badge_ids' => $badgeIds],
                        null,
                        [
                            'admin_id' => $adminId,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'badge_ids' => $badgeIds],
            true
        );
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateImages(
        array $productIds, 
        string $imageUrl, 
        string $sourceType, 
        int $adminId
    ): array {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.update.images');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($imageUrl, $sourceType, $adminId, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    $oldImage = $product->getImage();
                    $product->setImage($imageUrl);
                    $product->setImageSourceType($sourceType);
                    $this->productRepository->save($product);
                    
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'IMAGE_UPDATE',
                        'PRODUCT',
                        $productId,
                        ['image' => $oldImage],
                        ['image' => $imageUrl, 'source_type' => $sourceType],
                        [
                            'admin_id' => $adminId,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'image_url' => $imageUrl, 'source_type' => $sourceType],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkImport(array $productsData, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.import');
        
        $results = [
            'total' => count($productsData),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        $skipDuplicates = $parameters['skip_duplicates'] ?? true;
        $duplicateStrategy = $parameters['duplicate_strategy'] ?? 'skip';
        $batchSize = $parameters['batch_size'] ?? 100;
        
        foreach (array_chunk($productsData, $batchSize) as $chunkIndex => $chunk) {
            $this->transaction(function() use ($chunk, $chunkIndex, $adminId, $skipDuplicates, $duplicateStrategy, &$results) {
                foreach ($chunk as $index => $productData) {
                    $globalIndex = $chunkIndex * $batchSize + $index;
                    
                    try {
                        // Check for duplicate
                        $existing = null;
                        if (isset($productData['slug'])) {
                            $existing = $this->productRepository->findBySlug($productData['slug'], false);
                        }
                        
                        if ($existing !== null) {
                            if ($skipDuplicates || $duplicateStrategy === 'skip') {
                                $results['skipped']++;
                                $results['errors'][] = [
                                    'index' => $globalIndex,
                                    'slug' => $productData['slug'],
                                    'error' => 'Duplicate slug, skipped'
                                ];
                                continue;
                            }
                        }
                        
                        // Create or update product
                        if ($existing === null) {
                            $product = new Product(
                                $productData['name'] ?? 'Imported Product',
                                $productData['slug'] ?? 'imported-product-' . time() . '-' . $globalIndex
                            );
                            $results['created']++;
                        } else {
                            $product = $existing;
                            $results['updated']++;
                        }
                        
                        // Set properties
                        if (isset($productData['description'])) {
                            $product->setDescription($productData['description']);
                        }
                        
                        if (isset($productData['category_id'])) {
                            $product->setCategoryId($productData['category_id']);
                        }
                        
                        if (isset($productData['market_price'])) {
                            $product->setMarketPrice($productData['market_price']);
                        }
                        
                        if (isset($productData['image'])) {
                            $product->setImage($productData['image']);
                        }
                        
                        if (isset($productData['status'])) {
                            $product->setStatus(\App\Enums\ProductStatus::from($productData['status']));
                        }
                        
                        // Save product
                        $savedProduct = $this->productRepository->save($product);
                        
                        $this->audit(
                            'IMPORT',
                            'PRODUCT',
                            $savedProduct->getId(),
                            null,
                            $savedProduct->toArray(),
                            [
                                'imported_by' => $adminId,
                                'import_index' => $globalIndex,
                                'action' => $existing === null ? 'CREATE' : 'UPDATE',
                                'source_data' => $productData
                            ]
                        );
                        
                    } catch (Throwable $e) {
                        $results['failed']++;
                        $results['errors'][] = [
                            'index' => $globalIndex,
                            'error' => $e->getMessage(),
                            'data' => $productData
                        ];
                    }
                }
            }, 'bulk_import_chunk_' . $chunkIndex);
        }
        
        // Clear all product caches after import
        $this->queueCacheOperation(function() {
            $this->productRepository->clearCache();
        });
        
        $this->audit(
            'BULK_IMPORT',
            'PRODUCT',
            0,
            null,
            null,
            [
                'imported_by' => $adminId,
                'total_attempted' => count($productsData),
                'created' => $results['created'],
                'updated' => $results['updated'],
                'skipped' => $results['skipped'],
                'failed' => $results['failed'],
                'skip_duplicates' => $skipDuplicates,
                'duplicate_strategy' => $duplicateStrategy
            ]
        );
        
        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkExport(array $productIds, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->authorize('product.bulk.export');
        
        $format = $parameters['format'] ?? 'json';
        $includeRelations = $parameters['include_relations'] ?? false;
        $fields = $parameters['fields'] ?? [];
        
        $products = $this->productRepository->findByIds($productIds);
        
        $exportData = [];
        foreach ($products as $product) {
            $productData = $product->toArray();
            
            if (!empty($fields)) {
                $productData = array_intersect_key($productData, array_flip($fields));
            }
            
            if ($includeRelations) {
                // Load relations if needed
                // This would be implemented based on actual relation loading
            }
            
            $exportData[] = $productData;
        }
        
        $content = '';
        switch ($format) {
            case 'json':
                $content = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
                
            case 'csv':
                $content = $this->convertToCsv($exportData);
                break;
                
            case 'xml':
                $content = $this->convertToXml($exportData);
                break;
                
            default:
                throw new DomainException(
                    "Unsupported export format: {$format}",
                    'UNSUPPORTED_EXPORT_FORMAT'
                );
        }
        
        $filename = $parameters['filename'] ?? 'products_export_' . date('Ymd_His') . '.' . $format;
        
        $this->audit(
            'BULK_EXPORT',
            'PRODUCT',
            0,
            null,
            null,
            [
                'exported_by' => $this->getCurrentAdminId(),
                'product_count' => count($products),
                'format' => $format,
                'filename' => $filename,
                'include_relations' => $includeRelations
            ]
        );
        
        return [
            'content' => $content,
            'format' => $format,
            'filename' => $filename,
            'size' => strlen($content)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkClone(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.clone');
        
        $clonedIds = [];
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $parameters, &$clonedIds, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    // Clone product
                    $clone = clone $product;
                    $clone->setName(($clone->getName() ?? '') . ($parameters['name_suffix'] ?? ' (Clone)'));
                    
                    if ($parameters['generate_new_slugs'] ?? true) {
                        $clone->setSlug($clone->getSlug() . '-' . uniqid());
                    }
                    
                    if (isset($parameters['target_status'])) {
                        $clone->setStatus($parameters['target_status']);
                    }
                    
                    $clonedProduct = $this->productRepository->save($clone);
                    $clonedIds[] = $clonedProduct->getId();
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'CLONE',
                        'PRODUCT',
                        $productId,
                        null,
                        $clonedProduct->toArray(),
                        [
                            'cloned_by' => $adminId,
                            'clone_id' => $clonedProduct->getId(),
                            'name_suffix' => $parameters['name_suffix'] ?? null,
                            'target_status' => $parameters['target_status'] ?? null,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return $clonedProduct->getId();
                    
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        );
    }

    /**
     * {@inheritDoc}
     */
    public function bulkMerge(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.merge');
        
        if (count($productIds) < 2) {
            throw new \InvalidArgumentException('At least 2 products required for merge');
        }
        
        $targetProductId = array_shift($productIds);
        $mergedCount = 0;
        $deletedSourceIds = [];
        
        return $this->transaction(function() use ($targetProductId, $productIds, $adminId, $parameters, &$mergedCount, &$deletedSourceIds) {
            $targetProduct = $this->productRepository->findById($targetProductId);
            
            if ($targetProduct === null) {
                throw ProductNotFoundException::forId($targetProductId);
            }
            
            $mergeStrategy = $parameters['merge_strategy'] ?? 'keep_target';
            
            foreach ($productIds as $sourceProductId) {
                try {
                    $sourceProduct = $this->productRepository->findById($sourceProductId);
                    
                    if ($sourceProduct === null) {
                        continue;
                    }
                    
                    // Merge logic based on strategy
                    switch ($mergeStrategy) {
                        case 'keep_target':
                            // Keep target values, nothing to merge
                            break;
                            
                        case 'keep_source':
                            // Merge source values into target
                            $this->mergeProductData($targetProduct, $sourceProduct, $parameters['field_priority'] ?? []);
                            break;
                            
                        case 'merge':
                            // Smart merge based on field priority
                            $this->smartMergeProducts($targetProduct, $sourceProduct, $parameters);
                            break;
                    }
                    
                    $mergedCount++;
                    
                    // Delete source if requested
                    if ($parameters['delete_source'] ?? false) {
                        $this->productRepository->delete($sourceProductId);
                        $deletedSourceIds[] = $sourceProductId;
                        
                        $this->audit(
                            'DELETE',
                            'PRODUCT',
                            $sourceProductId,
                            $sourceProduct->toArray(),
                            null,
                            [
                                'deleted_by' => $adminId,
                                'reason' => 'Merged into product ' . $targetProductId,
                                'merge_operation' => true
                            ]
                        );
                    }
                    
                } catch (Throwable $e) {
                    log_message('error', "Failed to merge product {$sourceProductId}: " . $e->getMessage());
                }
            }
            
            // Save merged target product
            $this->productRepository->save($targetProduct);
            
            $this->audit(
                'MERGE',
                'PRODUCT',
                $targetProductId,
                null,
                $targetProduct->toArray(),
                [
                    'merged_by' => $adminId,
                    'source_product_ids' => $productIds,
                    'merged_count' => $mergedCount,
                    'deleted_source_ids' => $deletedSourceIds,
                    'merge_strategy' => $mergeStrategy
                ]
            );
            
            return [
                'target_product_id' => $targetProductId,
                'merged_count' => $mergedCount,
                'deleted_source_ids' => $deletedSourceIds
            ];
        }, 'bulk_merge_operation');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkMarkPricesChecked(array $productIds, int $adminId): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.maintenance');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, &$successIds) {
                try {
                    $success = $this->productRepository->markPriceChecked($productId);
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        $this->audit(
                            'MAINTENANCE',
                            'PRODUCT',
                            $productId,
                            null,
                            ['last_price_check' => date('Y-m-d H:i:s')],
                            [
                                'action' => 'mark_price_checked',
                                'admin_id' => $adminId,
                                'bulk_operation' => true
                            ]
                        );
                    }
                    
                    return $success;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkMarkLinksChecked(array $productIds, int $adminId): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.maintenance');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, &$successIds) {
                try {
                    $success = $this->productRepository->markLinksChecked($productId);
                    
                    if ($success) {
                        $successIds[] = $productId;
                        
                        $this->audit(
                            'MAINTENANCE',
                            'PRODUCT',
                            $productId,
                            null,
                            ['last_link_check' => date('Y-m-d H:i:s')],
                            [
                                'action' => 'mark_links_checked',
                                'admin_id' => $adminId,
                                'bulk_operation' => true
                            ]
                        );
                    }
                    
                    return $success;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRegenerateSlugs(array $productIds, int $adminId, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.regenerate_slugs');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($adminId, $parameters, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    $oldSlug = $product->getSlug();
                    $newSlug = $this->generateSlug($product, $parameters['strategy'] ?? 'increment');
                    
                    $product->setSlug($newSlug);
                    $this->productRepository->save($product);
                    
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'SLUG_REGENERATION',
                        'PRODUCT',
                        $productId,
                        ['slug' => $oldSlug],
                        ['slug' => $newSlug],
                        [
                            'admin_id' => $adminId,
                            'strategy' => $parameters['strategy'] ?? 'increment',
                            'force' => $parameters['force'] ?? false,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'parameters' => $parameters],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateMetadata(
        array $productIds, 
        array $metadata, 
        int $adminId, 
        string $merge_strategy = 'merge'
    ): array {
        $this->bulkStats['total_operations']++;
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.update.metadata');
        
        $successIds = [];
        
        return $this->executeBulkWithCallback(
            $productIds,
            function($productId) use ($metadata, $adminId, $merge_strategy, &$successIds) {
                try {
                    $product = $this->productRepository->findById($productId);
                    
                    if ($product === null) {
                        throw ProductNotFoundException::forId($productId);
                    }
                    
                    $oldMetadata = $product->getMetadata() ?? [];
                    
                    if ($merge_strategy === 'replace') {
                        $newMetadata = $metadata;
                    } else {
                        $newMetadata = array_merge($oldMetadata, $metadata);
                    }
                    
                    $product->setMetadata($newMetadata);
                    $this->productRepository->save($product);
                    
                    $successIds[] = $productId;
                    
                    $this->audit(
                        'METADATA_UPDATE',
                        'PRODUCT',
                        $productId,
                        ['metadata' => $oldMetadata],
                        ['metadata' => $newMetadata],
                        [
                            'admin_id' => $adminId,
                            'merge_strategy' => $merge_strategy,
                            'bulk_operation' => true
                        ]
                    );
                    
                    return true;
                } catch (Throwable $e) {
                    throw $e;
                }
            },
            ['admin_id' => $adminId, 'metadata' => $metadata, 'merge_strategy' => $merge_strategy],
            true
        )['results'];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkCleanup(array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->authorize('product.bulk.cleanup');
        
        $cleanedItems = 0;
        $details = [
            'images' => 0,
            'links' => 0,
            'badges' => 0,
            'temp_files' => 0
        ];
        
        // This would implement actual cleanup logic
        // For now, we'll return mock data
        
        $this->audit(
            'BULK_CLEANUP',
            'PRODUCT',
            0,
            null,
            null,
            [
                'admin_id' => $this->getCurrentAdminId(),
                'parameters' => $parameters,
                'cleaned_items' => $cleanedItems,
                'details' => $details
            ]
        );
        
        return [
            'cleaned_items' => $cleanedItems,
            'freed_space' => '0 MB',
            'details' => $details
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function validateBulkAction(
        array $productIds, 
        ProductBulkActionType $action, 
        array $parameters = []
    ): array {
        $validIds = [];
        $invalidIds = [];
        $errors = [];
        $warnings = [];
        
        foreach ($productIds as $productId) {
            try {
                $product = $this->productRepository->findById($productId);
                
                if ($product === null) {
                    $invalidIds[] = $productId;
                    $errors[$productId] = 'Product not found';
                    continue;
                }
                
                // Validate based on action type
                $validation = $this->validateBulkActionForProduct($product, $action, $parameters);
                
                if ($validation['valid']) {
                    $validIds[] = $productId;
                    if (!empty($validation['warnings'])) {
                        $warnings[$productId] = $validation['warnings'];
                    }
                } else {
                    $invalidIds[] = $productId;
                    $errors[$productId] = implode(', ', $validation['errors']);
                }
            } catch (Throwable $e) {
                $invalidIds[] = $productId;
                $errors[$productId] = $e->getMessage();
            }
        }
        
        // Estimate resource requirements
        $estimatedTime = $this->estimateBulkOperationTime(count($validIds), $action);
        $memoryRequired = $this->estimateMemoryRequirements(count($validIds), $action);
        
        return [
            'valid' => !empty($validIds),
            'valid_ids' => $validIds,
            'invalid_ids' => $invalidIds,
            'errors' => $errors,
            'warnings' => $warnings,
            'estimated_time' => $estimatedTime,
            'memory_required' => $memoryRequired,
            'can_proceed' => !empty($validIds)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function preflightCheck(array $productIds, string $operation, array $parameters = []): array
    {
        $checks = [
            'database_connection' => $this->db->connect() !== false,
            'cache_available' => $this->cache->isAvailable(),
            'sufficient_memory' => $this->checkMemoryAvailable(),
            'sufficient_time' => $this->checkTimeAvailable(),
            'admin_permissions' => $this->checkAdminPermissions($operation),
        ];
        
        $canProceed = !in_array(false, $checks, true);
        $warnings = [];
        $errors = [];
        $recommendations = [];
        
        if (!$checks['database_connection']) {
            $errors[] = 'Database connection unavailable';
        }
        
        if (!$checks['cache_available']) {
            $warnings[] = 'Cache unavailable - performance may be degraded';
        }
        
        if (!$checks['sufficient_memory']) {
            $errors[] = 'Insufficient memory for operation';
        }
        
        if (!$checks['sufficient_time']) {
            $warnings[] = 'Operation may timeout - consider smaller batch';
            $recommendations[] = 'Use smaller batch size or schedule as background job';
        }
        
        $estimatedImpact = $this->estimateOperationImpact($productIds, $operation, $parameters);
        
        return [
            'can_proceed' => $canProceed,
            'checks' => $checks,
            'warnings' => $warnings,
            'errors' => $errors,
            'recommendations' => $recommendations,
            'estimated_impact' => $estimatedImpact
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function estimateResourceRequirements(
        array $productIds, 
        string $operation, 
        array $parameters = []
    ): array {
        $totalItems = count($productIds);
        
        // Base estimates per item (in milliseconds and KB)
        $estimates = [
            'update_status' => ['time' => 50, 'memory' => 5],
            'publish' => ['time' => 100, 'memory' => 10],
            'archive' => ['time' => 75, 'memory' => 8],
            'delete' => ['time' => 150, 'memory' => 15],
            'import' => ['time' => 200, 'memory' => 20],
            'export' => ['time' => 30, 'memory' => 2],
        ];
        
        $baseEstimate = $estimates[$operation] ?? ['time' => 100, 'memory' => 10];
        
        $estimatedTimeSeconds = ($baseEstimate['time'] * $totalItems) / 1000;
        $estimatedMemoryMB = ($baseEstimate['memory'] * $totalItems) / 1024;
        
        $recommendedBatchSize = $this->calculateOptimalBatchSize(
            $operation,
            $totalItems,
            ['max_memory_mb' => 128, 'timeout_seconds' => 30]
        )['recommended_batch_size'];
        
        return [
            'estimated_time_seconds' => (int) ceil($estimatedTimeSeconds),
            'estimated_memory_mb' => round($estimatedMemoryMB, 2),
            'recommended_batch_size' => $recommendedBatchSize,
            'database_impact' => [
                'queries_per_item' => 3,
                'total_queries' => $totalItems * 3,
                'lock_duration_ms' => 10 * $totalItems
            ],
            'cache_impact' => [
                'invalidations_per_item' => 5,
                'total_invalidations' => $totalItems * 5,
                'cache_size_mb' => round($totalItems * 0.1, 2)
            ]
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function checkBulkDeletionDependencies(array $productIds, bool $hardDelete = false): array
    {
        $dependencies = [];
        $dependencyCount = 0;
        $hasDependencies = false;
        
        foreach ($productIds as $productId) {
            // Check for links
            $links = $this->linkRepository->findByProduct($productId);
            if (!empty($links)) {
                $dependencies['links'][$productId] = count($links);
                $dependencyCount += count($links);
                $hasDependencies = true;
            }
            
            // Check for other dependencies (orders, reviews, etc.)
            // This would be implemented based on actual database schema
        }
        
        $canDelete = !$hasDependencies || ($hardDelete && $this->hasForceDeletePermission());
        
        $alternativeSuggestions = [];
        if ($hasDependencies && !$hardDelete) {
            $alternativeSuggestions[] = 'Use soft delete instead of hard delete';
            $alternativeSuggestions[] = 'Remove dependencies before deletion';
            $alternativeSuggestions[] = 'Archive products instead of deleting';
        }
        
        return [
            'has_dependencies' => $hasDependencies,
            'dependency_count' => $dependencyCount,
            'dependencies' => $dependencies,
            'can_delete' => $canDelete,
            'alternative_suggestions' => $alternativeSuggestions
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function startBackgroundBulkJob(ProductBulkActionRequest $request): array
    {
        $this->authorize('product.bulk.background_job');
        
        // Generate unique job ID
        $jobId = 'bulk_' . uniqid();
        
        // In production, this would queue the job in a background system
        // For now, we'll simulate with immediate processing
        
        $estimatedCompletion = (new Time('+5 minutes'))->format('Y-m-d H:i:s');
        
        $this->audit(
            'BACKGROUND_JOB_START',
            'PRODUCT',
            0,
            null,
            null,
            [
                'job_id' => $jobId,
                'action' => $request->getAction()->value,
                'product_count' => count($request->getProductIds()),
                'admin_id' => $request->getUserId()
            ]
        );
        
        return [
            'job_id' => $jobId,
            'status' => 'queued',
            'estimated_completion' => $estimatedCompletion,
            'progress_url' => '/api/bulk/jobs/' . $jobId . '/progress',
            'cancel_url' => '/api/bulk/jobs/' . $jobId . '/cancel'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkJobStatus(string $jobId): array
    {
        // In production, this would retrieve from job queue
        // For now, return mock status
        
        return [
            'job_id' => $jobId,
            'status' => 'completed',
            'progress' => 100,
            'total_items' => 100,
            'processed_items' => 100,
            'successful_items' => 95,
            'failed_items' => 5,
            'start_time' => (new Time('-10 minutes'))->format('Y-m-d H:i:s'),
            'end_time' => (new Time('-5 minutes'))->format('Y-m-d H:i:s'),
            'estimated_completion' => null,
            'errors' => []
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function cancelBulkJob(string $jobId, int $adminId, ?string $reason = null): bool
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.cancel_job');
        
        // In production, this would cancel the job in queue
        // For now, just log
        
        $this->audit(
            'BACKGROUND_JOB_CANCEL',
            'PRODUCT',
            0,
            null,
            null,
            [
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'reason' => $reason
            ]
        );
        
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function listBulkJobs(int $limit = 20, int $offset = 0, string $status = 'active'): array
    {
        $this->authorize('product.bulk.list_jobs');
        
        // Mock data for demonstration
        $jobs = [];
        for ($i = 0; $i < min($limit, 5); $i++) {
            $jobs[] = [
                'id' => 'job_' . ($offset + $i),
                'action' => 'PUBLISH',
                'status' => $status,
                'total_items' => 100,
                'processed_items' => $offset + $i * 20,
                'created_at' => (new Time('-' . ($i * 10) . ' minutes'))->format('Y-m-d H:i:s'),
                'created_by' => 1,
                'progress' => min(100, ($offset + $i * 20))
            ];
        }
        
        return $jobs;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupBulkJobs(int $olderThanDays = 7): int
    {
        $this->authorize('product.bulk.cleanup_jobs');
        
        // In production, this would delete old jobs from storage
        // For now, return mock count
        
        $cleanedCount = 5;
        
        $this->audit(
            'BULK_JOBS_CLEANUP',
            'PRODUCT',
            0,
            null,
            null,
            [
                'older_than_days' => $olderThanDays,
                'cleaned_count' => $cleanedCount,
                'admin_id' => $this->getCurrentAdminId()
            ]
        );
        
        return $cleanedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkStatistics(string $period = 'month'): array
    {
        $this->authorize('product.bulk.statistics');
        
        // Mock statistics
        $stats = [
            'total_operations' => $this->bulkStats['total_operations'],
            'successful_operations' => (int) ($this->bulkStats['total_operations'] * 0.95),
            'failed_operations' => (int) ($this->bulkStats['total_operations'] * 0.05),
            'average_items_per_operation' => round($this->bulkStats['successful_items'] / max(1, $this->bulkStats['total_operations']), 2),
            'most_common_operations' => [
                'PUBLISH' => (int) ($this->bulkStats['total_operations'] * 0.4),
                'UPDATE_STATUS' => (int) ($this->bulkStats['total_operations'] * 0.3),
                'ARCHIVE' => (int) ($this->bulkStats['total_operations'] * 0.2),
                'DELETE' => (int) ($this->bulkStats['total_operations'] * 0.1),
            ],
            'performance_metrics' => [
                'average_duration_ms' => $this->bulkStats['total_duration_ms'] / max(1, $this->bulkStats['total_operations']),
                'max_batch_size' => $this->bulkStats['max_batch_size_used'],
                'success_rate' => 0.95
            ]
        ];
        
        return $stats;
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkPerformanceMetrics(int $limit = 10): array
    {
        $this->authorize('product.bulk.performance_metrics');
        
        // Mock performance metrics
        $metrics = [];
        $operations = ['PUBLISH', 'UPDATE_STATUS', 'ARCHIVE', 'DELETE', 'IMPORT'];
        
        foreach (array_slice($operations, 0, $limit) as $operation) {
            $metrics[] = [
                'operation' => $operation,
                'avg_time_per_item_ms' => rand(50, 200),
                'success_rate' => rand(85, 99) / 100,
                'avg_batch_size' => rand(50, 200),
                'recommendation' => $this->getBulkOperationRecommendation($operation)
            ];
        }
        
        return $metrics;
    }

    /**
     * {@inheritDoc}
     */
    public function generateBulkReport(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        string $format = 'json'
    ): array {
        $this->authorize('product.bulk.report');
        
        // Mock report data
        $reportData = [
            'period' => [
                'start' => $startDate->format('Y-m-d'),
                'end' => $endDate->format('Y-m-d')
            ],
            'summary' => [
                'total_operations' => 150,
                'total_items' => 15000,
                'success_rate' => 0.96,
                'average_duration' => '45 seconds'
            ],
            'operations' => [
                'PUBLISH' => ['count' => 60, 'success_rate' => 0.98],
                'UPDATE_STATUS' => ['count' => 45, 'success_rate' => 0.95],
                'ARCHIVE' => ['count' => 30, 'success_rate' => 0.92],
                'DELETE' => ['count' => 15, 'success_rate' => 0.90]
            ],
            'recommendations' => [
                'Optimize batch size for better performance',
                'Schedule large operations during off-peak hours',
                'Consider using background jobs for operations > 1000 items'
            ]
        ];
        
        $content = $format === 'json' 
            ? json_encode($reportData, JSON_PRETTY_PRINT)
            : $this->convertReportToFormat($reportData, $format);
        
        return [
            'report' => $content,
            'format' => $format,
            'generated_at' => (new Time())->format('Y-m-d H:i:s')
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function retryFailedBulkItems(
        string $jobId, 
        array $itemIds, 
        int $adminId, 
        array $retryParameters = []
    ): array
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.retry');
        
        // Mock implementation
        $successCount = count($itemIds) - 2; // Assume 2 failures
        $failedCount = 2;
        
        $this->audit(
            'BULK_RETRY',
            'PRODUCT',
            0,
            null,
            null,
            [
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'item_ids' => $itemIds,
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'retry_parameters' => $retryParameters
            ]
        );
        
        return [
            'success' => $successCount,
            'failed' => $failedCount,
            'new_job_id' => 'retry_' . $jobId
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getBulkErrorDetails(string $jobId, array $itemIds = []): array
    {
        $this->authorize('product.bulk.error_details');
        
        // Mock error details
        $errors = [];
        foreach ($itemIds as $itemId) {
            $errors[$itemId] = [
                'error' => 'Database constraint violation',
                'details' => ['constraint' => 'foreign_key', 'table' => 'product_links'],
                'suggested_fix' => 'Remove associated links before deletion'
            ];
        }
        
        return $errors;
    }

    /**
     * {@inheritDoc}
     */
    public function createRecoveryPlan(string $jobId): array
    {
        $this->authorize('product.bulk.recovery_plan');
        
        return [
            'can_recover' => true,
            'recovery_steps' => [
                '1. Identify failed items from error log',
                '2. Check dependencies for each failed item',
                '3. Resolve dependency issues',
                '4. Retry failed items individually',
                '5. Verify data consistency after recovery'
            ],
            'estimated_time' => '15 minutes',
            'risks' => [
                'Data inconsistency if recovery interrupted',
                'Possible duplicate operations',
                'Temporary performance degradation'
            ],
            'recommendations' => [
                'Perform recovery during maintenance window',
                'Create database backup before recovery',
                'Monitor system performance during recovery'
            ]
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function executeRecoveryPlan(string $jobId, array $recoverySteps, int $adminId): array
    {
        $this->setAdminContext($adminId);
        $this->authorize('product.bulk.execute_recovery');
        
        // Mock execution
        $this->audit(
            'RECOVERY_PLAN_EXECUTE',
            'PRODUCT',
            0,
            null,
            null,
            [
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'recovery_steps' => $recoverySteps,
                'status' => 'in_progress'
            ]
        );
        
        return [
            'success' => true,
            'recovered_items' => 5,
            'new_status' => 'recovered'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkClearCaches(array $productIds, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->authorize('product.bulk.clear_caches');
        
        $cleared = 0;
        $failed = 0;
        
        foreach ($productIds as $productId) {
            try {
                $success = $this->productRepository->clearEntityCache($productId);
                
                if ($success && ($parameters['clear_related'] ?? false)) {
                    // Clear related caches
                    $patterns = [
                        "product:*:{$productId}:*",
                        "category:*:product:{$productId}:*",
                        "marketplace:*:product:{$productId}:*"
                    ];
                    
                    foreach ($patterns as $pattern) {
                        $this->cache->deleteMatching($pattern);
                    }
                }
                
                $cleared++;
            } catch (Throwable $e) {
                $failed++;
                log_message('error', "Failed to clear cache for product {$productId}: " . $e->getMessage());
            }
        }
        
        // Clear query caches if requested
        if ($parameters['cache_level'] === 'all' || $parameters['cache_level'] === 'query') {
            $queryPatterns = [
                "product:search:*",
                "product:list:*",
                "product:popular:*"
            ];
            
            foreach ($queryPatterns as $pattern) {
                $this->cache->deleteMatching($pattern);
            }
        }
        
        $this->audit(
            'BULK_CACHE_CLEAR',
            'PRODUCT',
            0,
            null,
            null,
            [
                'admin_id' => $this->getCurrentAdminId(),
                'product_ids' => $productIds,
                'cleared' => $cleared,
                'failed' => $failed,
                'parameters' => $parameters
            ]
        );
        
        return [
            'cleared' => $cleared,
            'failed' => $failed,
            'total_cache_entries' => $cleared * 3 // Estimate
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function bulkWarmCaches(array $productIds, array $parameters = []): array
    {
        $this->bulkStats['total_operations']++;
        $this->authorize('product.bulk.warm_caches');
        
        $warmed = 0;
        $failed = 0;
        $totalSize = 0;
        
        $cacheLevels = $parameters['cache_levels'] ?? ['entity', 'query'];
        $includeRelations = $parameters['include_relations'] ?? false;
        
        foreach ($productIds as $productId) {
            try {
                // Warm entity cache
                if (in_array('entity', $cacheLevels)) {
                    $product = $this->productRepository->findById($productId);
                    if ($product !== null) {
                        $warmed++;
                        $totalSize += strlen(serialize($product));
                    }
                }
                
                // Warm query caches for this product
                if (in_array('query', $cacheLevels)) {
                    // Pre-cache common queries involving this product
                    // This would cache search results, listings, etc.
                }
                
            } catch (Throwable $e) {
                $failed++;
                log_message('error', "Failed to warm cache for product {$productId}: " . $e->getMessage());
            }
        }
        
        return [
            'warmed' => $warmed,
            'failed' => $failed,
            'total_size' => $this->formatBytes($totalSize)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function calculateOptimalBatchSize(
        string $operation, 
        int $totalItems, 
        array $constraints = []
    ): array {
        $maxMemoryMB = $constraints['max_memory_mb'] ?? 256;
        $timeoutSeconds = $constraints['timeout_seconds'] ?? 30;
        $maxConnections = $constraints['max_connections'] ?? 10;
        
        // Memory per item estimate (KB)
        $memoryPerItem = [
            'update_status' => 5,
            'publish' => 10,
            'archive' => 8,
            'delete' => 15,
            'import' => 20,
            'export' => 2
        ];
        
        $itemMemoryKB = $memoryPerItem[$operation] ?? 10;
        $memoryPerBatchMB = ($itemMemoryKB * $totalItems) / 1024;
        
        // Time per item estimate (ms)
        $timePerItem = [
            'update_status' => 50,
            'publish' => 100,
            'archive' => 75,
            'delete' => 150,
            'import' => 200,
            'export' => 30
        ];
        
        $itemTimeMS = $timePerItem[$operation] ?? 100;
        $estimatedTotalTime = ($itemTimeMS * $totalItems) / 1000;
        
        // Calculate optimal batch size
        $memoryLimitedBatch = floor(($maxMemoryMB * 1024) / $itemMemoryKB);
        $timeLimitedBatch = floor(($timeoutSeconds * 1000) / $itemTimeMS);
        $connectionLimitedBatch = $maxConnections * 10;
        
        $recommendedBatchSize = min(
            $memoryLimitedBatch,
            $timeLimitedBatch,
            $connectionLimitedBatch,
            500 // Hard limit
        );
        
        $recommendedBatchSize = max(10, $recommendedBatchSize);
        $estimatedBatches = ceil($totalItems / $recommendedBatchSize);
        
        $warnings = [];
        if ($memoryPerBatchMB > $maxMemoryMB * 0.8) {
            $warnings[] = 'High memory usage expected';
        }
        
        if ($estimatedTotalTime > $timeoutSeconds * 0.8) {
            $warnings[] = 'Operation may approach timeout limit';
        }
        
        return [
            'recommended_batch_size' => (int) $recommendedBatchSize,
            'estimated_batches' => (int) $estimatedBatches,
            'estimated_total_time' => (int) ceil($estimatedTotalTime),
            'memory_per_batch_mb' => round(($itemMemoryKB * $recommendedBatchSize) / 1024, 2),
            'warnings' => $warnings
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function createOptimizedBatches(array $productIds, string $operation, array $parameters = []): array
    {
        $totalItems = count($productIds);
        $batchSize = $this->calculateOptimalBatchSize($operation, $totalItems, $parameters)['recommended_batch_size'];
        
        $batches = array_chunk($productIds, $batchSize);
        
        // Sort batches by expected complexity if needed
        if ($parameters['optimize_order'] ?? false) {
            usort($batches, function($a, $b) {
                return count($a) <=> count($b);
            });
        }
        
        return $batches;
    }

    /**
     * {@inheritDoc}
     */
    public function executeWithOptimizedBatching(
        array $productIds,
        callable $batchCallback,
        array $optimizationParameters = []
    ): array {
        $startTime = microtime(true);
        
        $operation = $optimizationParameters['operation'] ?? 'custom';
        $batches = $this->createOptimizedBatches($productIds, $operation, $optimizationParameters);
        
        $totalBatches = count($batches);
        $completedBatches = 0;
        $successfulItems = 0;
        $batchResults = [];
        
        foreach ($batches as $batchIndex => $batch) {
            try {
                $batchResult = $batchCallback($batch, $batchIndex, $totalBatches);
                $batchResults[] = $batchResult;
                
                $completedBatches++;
                $successfulItems += count($batch);
                
            } catch (Throwable $e) {
                log_message('error', "Batch {$batchIndex} failed: " . $e->getMessage());
                
                if ($optimizationParameters['stop_on_failure'] ?? false) {
                    throw $e;
                }
            }
        }
        
        $totalTime = (microtime(true) - $startTime) * 1000;
        $avgTimePerBatch = $totalTime / max(1, $completedBatches);
        $avgTimePerItem = $totalTime / max(1, $successfulItems);
        
        return [
            'total_batches' => $totalBatches,
            'completed_batches' => $completedBatches,
            'total_items' => count($productIds),
            'successful_items' => $successfulItems,
            'performance_metrics' => [
                'total_time_ms' => round($totalTime, 2),
                'avg_time_per_batch_ms' => round($avgTimePerBatch, 2),
                'avg_time_per_item_ms' => round($avgTimePerItem, 2),
                'batches_per_second' => round($completedBatches / ($totalTime / 1000), 2),
                'items_per_second' => round($successfulItems / ($totalTime / 1000), 2)
            ]
        ];
    }

    /**
     * ==================== HELPER METHODS ====================
     */

    /**
     * Process bulk publish chunk
     */
    private function processBulkPublishChunk(array $productIds, int $adminId, array $parameters): array
    {
        $results = ['success' => [], 'failed' => [], 'skipped' => []];
        
        foreach ($productIds as $productId) {
            try {
                $publishRequest = PublishProductRequest::forImmediatePublish(
                    $productId,
                    $adminId,
                    $parameters['notes'] ?? null
                );
                
                // This would call ProductWorkflowService
                // For now, simulate success
                $results['success'][] = $productId;
                
            } catch (Throwable $e) {
                $results['failed'][] = [
                    'id' => $productId,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Process bulk delete chunk
     */
    private function processBulkDeleteChunk(array $productIds, int $adminId, array $parameters): array
    {
        $results = ['success' => [], 'failed' => [], 'skipped' => []];
        
        foreach ($productIds as $productId) {
            try {
                $deleteRequest = ProductDeleteRequest::fromArray([
                    'productId' => $productId,
                    'userId' => $adminId,
                    'reason' => $parameters['reason'] ?? 'Bulk delete',
                    'hardDelete' => $parameters['hard_delete'] ?? false,
                    'cascade' => $parameters['cascade'] ?? false
                ]);
                
                // This would call ProductCRUDService
                // For now, simulate success
                $results['success'][] = $productId;
                
            } catch (Throwable $e) {
                $results['failed'][] = [
                    'id' => $productId,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Merge results arrays
     */
    private function mergeResults(array &$target, array $source): void
    {
        $target['success'] = array_merge($target['success'], $source['success']);
        $target['failed'] = array_merge($target['failed'], $source['failed']);
        $target['skipped'] = array_merge($target['skipped'], $source['skipped']);
    }

    /**
     * Validate bulk action for single product
     */
    private function validateBulkActionForProduct(Product $product, ProductBulkActionType $action, array $parameters): array
    {
        $errors = [];
        $warnings = [];
        
        switch ($action) {
            case ProductBulkActionType::PUBLISH:
                if ($product->isPublished()) {
                    $errors[] = 'Product already published';
                }
                
                $prerequisites = $this->validateForPublication($product->getId());
                if (!$prerequisites['valid'] && !($parameters['force'] ?? false)) {
                    $errors = array_merge($errors, $prerequisites['errors']);
                }
                
                if (!empty($prerequisites['warnings'])) {
                    $warnings = array_merge($warnings, $prerequisites['warnings']);
                }
                break;
                
            case ProductBulkActionType::ARCHIVE:
                if ($product->isDeleted()) {
                    $errors[] = 'Product already archived';
                }
                break;
                
            case ProductBulkActionType::DELETE:
                if ($parameters['hard_delete'] ?? false) {
                    $deleteCheck = $this->canDeleteProduct($product->getId(), true);
                    if (!$deleteCheck['can_delete']) {
                        $errors = array_merge($errors, $deleteCheck['reasons']);
                    }
                }
                break;
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Estimate bulk operation time
     */
    private function estimateBulkOperationTime(int $itemCount, ProductBulkActionType $action): int
    {
        $timePerItem = [
            ProductBulkActionType::PUBLISH->value => 100,
            ProductBulkActionType::ARCHIVE->value => 75,
            ProductBulkActionType::DELETE->value => 150,
            ProductBulkActionType::UPDATE_STATUS->value => 50,
        ];
        
        $msPerItem = $timePerItem[$action->value] ?? 100;
        return (int) ceil(($msPerItem * $itemCount) / 1000);
    }

    /**
     * Estimate memory requirements
     */
    private function estimateMemoryRequirements(int $itemCount, ProductBulkActionType $action): string
    {
        $memoryPerItem = [
            ProductBulkActionType::PUBLISH->value => 10,
            ProductBulkActionType::ARCHIVE->value => 8,
            ProductBulkActionType::DELETE->value => 15,
            ProductBulkActionType::UPDATE_STATUS->value => 5,
        ];
        
        $kbPerItem = $memoryPerItem[$action->value] ?? 10;
        $totalKB = $kbPerItem * $itemCount;
        
        if ($totalKB < 1024) {
            return $totalKB . ' KB';
        } elseif ($totalKB < 1024 * 1024) {
            return round($totalKB / 1024, 2) . ' MB';
        } else {
            return round($totalKB / (1024 * 1024), 2) . ' GB';
        }
    }

    /**
     * Check memory available
     */
    private function checkMemoryAvailable(): bool
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true);
        
        if ($memoryLimit === '-1') {
            return true; // No limit
        }
        
        $limitBytes = $this->convertToBytes($memoryLimit);
        $availableBytes = $limitBytes - $memoryUsage;
        
        return $availableBytes > (100 * 1024 * 1024); // 100MB minimum
    }

    /**
     * Check time available
     */
    private function checkTimeAvailable(): bool
    {
        $maxExecutionTime = ini_get('max_execution_time');
        
        if ($maxExecutionTime === '0') {
            return true; // No limit
        }
        
        $elapsedTime = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];
        $remainingTime = $maxExecutionTime - $elapsedTime;
        
        return $remainingTime > 10; // 10 seconds minimum
    }

    /**
     * Check admin permissions
     */
    private function checkAdminPermissions(string $operation): bool
    {
        try {
            $this->authorize('product.bulk.' . strtolower($operation));
            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * Estimate operation impact
     */
    private function estimateOperationImpact(array $productIds, string $operation, array $parameters): array
    {
        $totalItems = count($productIds);
        
        return [
            'database' => [
                'expected_queries' => $totalItems * 3,
                'expected_locks' => $totalItems,
                'table_impact' => ['products', 'product_audit_logs', 'product_links']
            ],
            'cache' => [
                'invalidations' => $totalItems * 5,
                'affected_keys' => $totalItems * 10,
                'cache_level' => 'entity'
            ],
            'performance' => [
                'estimated_duration_seconds' => $this->estimateBulkOperationTime($totalItems, ProductBulkActionType::from($operation)),
                'peak_memory_mb' => round(($this->estimateMemoryRequirements($totalItems, ProductBulkActionType::from($operation)) / 1024), 2),
                'recommended_time' => 'Off-peak hours'
            ]
        ];
    }

    /**
     * Generate slug based on strategy
     */
    private function generateSlug(Product $product, string $strategy): string
    {
        $baseSlug = $product->getSlug() ?? $this->slugify($product->getName());
        
        switch ($strategy) {
            case 'increment':
                return $baseSlug . '-' . uniqid();
            case 'random':
                return $baseSlug . '-' . bin2hex(random_bytes(4));
            case 'timestamp':
                return $baseSlug . '-' . time();
            default:
                return $baseSlug;
        }
    }

    /**
     * Convert string to slug
     */
    private function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        
        return $text ?: 'product';
    }

    /**
     * Merge product data
     */
    private function mergeProductData(Product $target, Product $source, array $fieldPriority): void
    {
        // Implementation would merge specific fields based on priority
        // For now, copy name and description if target is empty
        if (empty($target->getName()) && !empty($source->getName())) {
            $target->setName($source->getName());
        }
        
        if (empty($target->getDescription()) && !empty($source->getDescription())) {
            $target->setDescription($source->getDescription());
        }
    }

    /**
     * Smart merge products
     */
    private function smartMergeProducts(Product $target, Product $source, array $parameters): void
    {
        // Implementation would use machine learning or rules
        // For now, use simple rules
        $this->mergeProductData($target, $source, $parameters['field_priority'] ?? []);
    }

    /**
     * Get bulk operation recommendation
     */
    private function getBulkOperationRecommendation(string $operation): string
    {
        $recommendations = [
            'PUBLISH' => 'Use batch size of 100 for optimal performance',
            'UPDATE_STATUS' => 'Can process up to 500 items simultaneously',
            'ARCHIVE' => 'Consider using soft delete for data recovery',
            'DELETE' => 'Verify dependencies before hard delete',
            'IMPORT' => 'Use CSV format for large imports'
        ];
        
        return $recommendations[$operation] ?? 'Use appropriate batch size based on available memory';
    }

    /**
     * Convert array to CSV
     */
    private function convertToCsv(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]));
        
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Convert array to XML
     */
    private function convertToXml(array $data): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><products></products>');
        
        foreach ($data as $item) {
            $product = $xml->addChild('product');
            foreach ($item as $key => $value) {
                $product->addChild($key, htmlspecialchars($value));
            }
        }
        
        return $xml->asXML();
    }

    /**
     * Convert report to format
     */
    private function convertReportToFormat(array $data, string $format): string
    {
        switch ($format) {
            case 'csv':
                return $this->convertToCsv([$data['summary']]);
            case 'xml':
                return $this->convertToXml([$data['summary']]);
            default:
                return json_encode($data, JSON_PRETTY_PRINT);
        }
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Convert memory limit string to bytes
     */
    private function convertToBytes(string $memoryLimit): int
    {
        $value = (int) $memoryLimit;
        $unit = strtoupper(substr($memoryLimit, -1));
        
        switch ($unit) {
            case 'G':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'M':
                $value *= 1024 * 1024;
                break;
            case 'K':
                $value *= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * Validate for publication (delegated to workflow service)
     */
    private function validateForPublication(int $productId): array
    {
        // This would delegate to ProductWorkflowService
        // For now, return mock validation
        return [
            'valid' => true,
            'errors' => [],
            'warnings' => [],
            'has_warnings' => false,
            'requirements_met' => true
        ];
    }

    /**
     * Check delete permissions
     */
    private function canDeleteProduct(int $productId, bool $hardDelete): array
    {
        // This would delegate to ProductCRUDService
        // For now, return mock check
        return [
            'can_delete' => true,
            'reasons' => [],
            'dependencies' => []
        ];
    }

    /**
     * Check force delete permission
     */
    private function hasForceDeletePermission(): bool
    {
        try {
            $this->authorize('product.bulk.hard_delete');
            return true;
        } catch (AuthorizationException $e) {
            return false;
        }
    }

    /**
     * Get bulk operation statistics
     */
    public function getBulkOperationStatistics(): array
    {
        return $this->bulkStats;
    }

    /**
     * Reset bulk statistics
     */
    public function resetBulkStatistics(): void
    {
        $this->bulkStats = [
            'total_operations' => 0,
            'successful_items' => 0,
            'failed_items' => 0,
            'total_duration_ms' => 0,
            'max_batch_size_used' => 0,
        ];
    }
}