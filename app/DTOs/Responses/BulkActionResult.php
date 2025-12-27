<?php

namespace App\DTOs\Responses;

use App\DTOs\BaseDTO;

/**
 * Bulk Action Result DTO
 * 
 * Represents the result of a bulk operation on products
 * Used for returning structured responses from bulk actions
 * 
 * @package DevDaily
 * @subpackage ResponseDTOs
 */
class BulkActionResult extends BaseDTO
{
    /**
     * @var int Number of successfully processed items
     */
    private int $successCount;
    
    /**
     * @var int Number of items that failed processing
     */
    private int $failedCount;
    
    /**
     * @var array<int, string> Map of failed item IDs to error messages
     */
    private array $failedItems;
    
    /**
     * @var bool Whether the operation requires background processing
     */
    private bool $requiresBackgroundJob;
    
    /**
     * @var string|null Background job ID (if requiresBackgroundJob = true)
     */
    private ?string $jobId;
    
    /**
     * @var \DateTimeImmutable When the operation was completed
     */
    private \DateTimeImmutable $completedAt;
    
    /**
     * Private constructor - use factory methods
     */
    private function __construct()
    {
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->failedItems = [];
    }
    
    /**
     * Create result for immediate completion
     * 
     * @param int $successCount
     * @param int $failedCount
     * @param array $failedItems
     * @return self
     */
    public static function immediate(int $successCount, int $failedCount = 0, array $failedItems = []): self
    {
        $instance = new self();
        $instance->successCount = $successCount;
        $instance->failedCount = $failedCount;
        $instance->failedItems = $failedItems;
        $instance->requiresBackgroundJob = false;
        $instance->jobId = null;
        
        return $instance;
    }
    
    /**
     * Create result for background job
     * 
     * @param string $jobId Background job ID
     * @param int $totalItems Total items to process
     * @return self
     */
    public static function backgroundJob(string $jobId, int $totalItems): self
    {
        $instance = new self();
        $instance->successCount = 0;
        $instance->failedCount = 0;
        $instance->failedItems = [];
        $instance->requiresBackgroundJob = true;
        $instance->jobId = $jobId;
        
        return $instance;
    }
    
    /**
     * Create DTO from array
     * 
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        
        $instance->successCount = $data['success_count'] ?? 0;
        $instance->failedCount = $data['failed_count'] ?? 0;
        $instance->failedItems = $data['failed_items'] ?? [];
        $instance->requiresBackgroundJob = $data['requires_background_job'] ?? false;
        $instance->jobId = $data['job_id'] ?? null;
        
        if (isset($data['completed_at'])) {
            $instance->completedAt = \DateTimeImmutable::createFromFormat(
                \DateTimeInterface::ATOM,
                $data['completed_at']
            );
        }
        
        return $instance;
    }
    
    /**
     * Check if background job is required
     * 
     * @return bool
     */
    public function requiresBackgroundJob(): bool
    {
        return $this->requiresBackgroundJob;
    }
    
    /**
     * Get success count
     * 
     * @return int
     */
    public function getSuccessCount(): int
    {
        return $this->successCount;
    }
    
    /**
     * Get failed count
     * 
     * @return int
     */
    public function getFailedCount(): int
    {
        return $this->failedCount;
    }
    
    /**
     * Get failed items with error messages
     * 
     * @return array<int, string>
     */
    public function getFailedItems(): array
    {
        return $this->failedItems;
    }
    
    /**
     * Get job ID
     * 
     * @return string|null
     */
    public function getJobId(): ?string
    {
        return $this->jobId;
    }
    
    /**
     * Get completion timestamp
     * 
     * @return \DateTimeImmutable
     */
    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
    
    /**
     * Get total processed items
     * 
     * @return int
     */
    public function getTotalProcessed(): int
    {
        return $this->successCount + $this->failedCount;
    }
    
    /**
     * Check if all items succeeded
     * 
     * @return bool
     */
    public function isCompleteSuccess(): bool
    {
        return $this->failedCount === 0 && !$this->requiresBackgroundJob;
    }
    
    /**
     * Check if operation has any failures
     * 
     * @return bool
     */
    public function hasFailures(): bool
    {
        return $this->failedCount > 0;
    }
    
    /**
     * Check if operation is still processing (background job)
     * 
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->requiresBackgroundJob && $this->jobId !== null;
    }
    
    /**
     * Get success percentage
     * 
     * @return float
     */
    public function getSuccessPercentage(): float
    {
        $total = $this->getTotalProcessed();
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($this->successCount / $total) * 100, 2);
    }
    
    /**
     * Add a failed item
     * 
     * @param int $itemId
     * @param string $errorMessage
     * @return void
     */
    public function addFailedItem(int $itemId, string $errorMessage): void
    {
        $this->failedItems[$itemId] = $errorMessage;
        $this->failedCount++;
    }
    
    /**
     * Add successful item
     * 
     * @return void
     */
    public function addSuccess(): void
    {
        $this->successCount++;
    }
    
    /**
     * Convert to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'success_count' => $this->successCount,
            'failed_count' => $this->failedCount,
            'failed_items' => $this->failedItems,
            'requires_background_job' => $this->requiresBackgroundJob,
            'job_id' => $this->jobId,
            'completed_at' => $this->completedAt->format(\DateTimeInterface::ATOM),
            'total_processed' => $this->getTotalProcessed(),
            'success_percentage' => $this->getSuccessPercentage(),
            'is_complete_success' => $this->isCompleteSuccess(),
            'has_failures' => $this->hasFailures(),
            'is_processing' => $this->isProcessing(),
        ];
    }
    
    /**
     * Get summary message for display
     * 
     * @return string
     */
    public function getSummaryMessage(): string
    {
        if ($this->requiresBackgroundJob) {
            return sprintf(
                'Bulk action started in background (Job ID: %s). Processing %d items.',
                $this->jobId,
                $this->getTotalProcessed()
            );
        }
        
        if ($this->failedCount === 0) {
            return sprintf(
                'Successfully processed %d items.',
                $this->successCount
            );
        }
        
        return sprintf(
            'Processed %d items: %d succeeded, %d failed.',
            $this->getTotalProcessed(),
            $this->successCount,
            $this->failedCount
        );
    }
}