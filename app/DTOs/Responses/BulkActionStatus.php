<?php

namespace App\DTOs\Responses;

use App\DTOs\BaseDTO;
use App\DTOs\Requests\Product\ProductBulkActionRequest;
use App\Enums\BulkActionStatusType;

/**
 * Bulk Action Status DTO
 * 
 * Represents the status of a background bulk operation
 * Used for polling updates and progress tracking
 * 
 * @package DevDaily
 * @subpackage ResponseDTOs
 */
class BulkActionStatus extends BaseDTO
{
    /**
     * @var string Unique job identifier
     */
    private string $jobId;
    
    /**
     * @var BulkActionStatusType Current status of the job
     */
    private BulkActionStatusType $status;
    
    /**
     * @var int Progress percentage (0-100)
     */
    private int $progress;
    
    /**
     * @var int Number of items processed so far
     */
    private int $processedCount;
    
    /**
     * @var int Total number of items to process
     */
    private int $totalCount;
    
    /**
     * @var int Number of successful items
     */
    private int $successCount;
    
    /**
     * @var int Number of failed items
     */
    private int $failedCount;
    
    /**
     * @var array<int, string> Failed items with error messages
     */
    private array $failedItems;
    
    /**
     * @var \DateTimeImmutable When the job was created
     */
    private \DateTimeImmutable $createdAt;
    
    /**
     * @var \DateTimeImmutable|null When the job started processing
     */
    private ?\DateTimeImmutable $startedAt;
    
    /**
     * @var \DateTimeImmutable|null When the job was completed
     */
    private ?\DateTimeImmutable $completedAt;
    
    /**
     * @var \DateTimeImmutable|null When the job was last updated
     */
    private ?\DateTimeImmutable $updatedAt;
    
    /**
     * @var string|null Error message if job failed
     */
    private ?string $errorMessage;
    
    /**
     * @var array|null Original request data for reference
     */
    private ?array $requestData;
    
    /**
     * Private constructor - use factory methods
     */
    private function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->startedAt = null;
        $this->completedAt = null;
        $this->updatedAt = null;
        $this->errorMessage = null;
        $this->requestData = null;
        $this->failedItems = [];
        $this->progress = 0;
        $this->processedCount = 0;
        $this->successCount = 0;
        $this->failedCount = 0;
    }
    
    /**
     * Create initial status for a new job
     * 
     * @param string $jobId
     * @param ProductBulkActionRequest $request
     * @return self
     */
    public static function createInitial(string $jobId, ProductBulkActionRequest $request): self
    {
        $instance = new self();
        $instance->jobId = $jobId;
        $instance->status = BulkActionStatusType::PENDING;
        $instance->totalCount = $request->getBatchSize();
        $instance->requestData = $request->toArray();
        $instance->updatedAt = $instance->createdAt;
        
        return $instance;
    }
    
    /**
     * Create processing status
     * 
     * @param string $jobId
     * @param ProductBulkActionRequest $request
     * @return self
     */
    public static function createProcessing(string $jobId, ProductBulkActionRequest $request): self
    {
        $instance = new self();
        $instance->jobId = $jobId;
        $instance->status = BulkActionStatusType::PROCESSING;
        $instance->totalCount = $request->getBatchSize();
        $instance->startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $instance->updatedAt = $instance->startedAt;
        $instance->requestData = $request->toArray();
        
        return $instance;
    }
    
    /**
     * Create completed status
     * 
     * @param string $jobId
     * @param BulkActionResult $result
     * @return self
     */
    public static function createCompleted(string $jobId, BulkActionResult $result): self
    {
        $instance = new self();
        $instance->jobId = $jobId;
        $instance->status = BulkActionStatusType::COMPLETED;
        $instance->successCount = $result->getSuccessCount();
        $instance->failedCount = $result->getFailedCount();
        $instance->failedItems = $result->getFailedItems();
        $instance->processedCount = $result->getTotalProcessed();
        $instance->totalCount = $instance->processedCount;
        $instance->progress = 100;
        $instance->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $instance->updatedAt = $instance->completedAt;
        
        return $instance;
    }
    
    /**
     * Create failed status
     * 
     * @param string $jobId
     * @param string $errorMessage
     * @return self
     */
    public static function createFailed(string $jobId, string $errorMessage): self
    {
        $instance = new self();
        $instance->jobId = $jobId;
        $instance->status = BulkActionStatusType::FAILED;
        $instance->errorMessage = $errorMessage;
        $instance->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $instance->updatedAt = $instance->completedAt;
        
        return $instance;
    }
    
    /**
     * Create from array (for deserialization)
     * 
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        
        $instance->jobId = $data['job_id'];
        $instance->status = BulkActionStatusType::from($data['status']);
        $instance->progress = $data['progress'] ?? 0;
        $instance->processedCount = $data['processed_count'] ?? 0;
        $instance->totalCount = $data['total_count'] ?? 0;
        $instance->successCount = $data['success_count'] ?? 0;
        $instance->failedCount = $data['failed_count'] ?? 0;
        $instance->failedItems = $data['failed_items'] ?? [];
        $instance->errorMessage = $data['error_message'] ?? null;
        $instance->requestData = $data['request_data'] ?? null;
        
        // Parse timestamps
        $instance->createdAt = self::parseDateTime($data['created_at'] ?? null);
        $instance->startedAt = self::parseDateTime($data['started_at'] ?? null);
        $instance->completedAt = self::parseDateTime($data['completed_at'] ?? null);
        $instance->updatedAt = self::parseDateTime($data['updated_at'] ?? null);
        
        return $instance;
    }
    
    /**
     * Parse datetime string to DateTimeImmutable
     * 
     * @param string|null $dateString
     * @return \DateTimeImmutable|null
     */
    private static function parseDateTime(?string $dateString): ?\DateTimeImmutable
    {
        if (!$dateString) {
            return null;
        }
        
        try {
            return new \DateTimeImmutable($dateString, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Update progress
     * 
     * @param int $processedCount
     * @param int $successCount
     * @param int $failedCount
     * @param array $failedItems
     * @return void
     */
    public function updateProgress(int $processedCount, int $successCount, int $failedCount, array $failedItems = []): void
    {
        $this->processedCount = $processedCount;
        $this->successCount = $successCount;
        $this->failedCount = $failedCount;
        $this->failedItems = $failedItems;
        
        if ($this->totalCount > 0) {
            $this->progress = (int) round(($processedCount / $this->totalCount) * 100);
        }
        
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    
    /**
     * Mark as completed
     * 
     * @return void
     */
    public function markAsCompleted(): void
    {
        $this->status = BulkActionStatusType::COMPLETED;
        $this->progress = 100;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->completedAt;
    }
    
    /**
     * Mark as failed
     * 
     * @param string $errorMessage
     * @return void
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->status = BulkActionStatusType::FAILED;
        $this->errorMessage = $errorMessage;
        $this->completedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->completedAt;
    }
    
    /**
     * Get job ID
     * 
     * @return string
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }
    
    /**
     * Get current status
     * 
     * @return BulkActionStatusType
     */
    public function getStatus(): BulkActionStatusType
    {
        return $this->status;
    }
    
    /**
     * Check if job is pending
     * 
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === BulkActionStatusType::PENDING;
    }
    
    /**
     * Check if job is processing
     * 
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === BulkActionStatusType::PROCESSING;
    }
    
    /**
     * Check if job is completed
     * 
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === BulkActionStatusType::COMPLETED;
    }
    
    /**
     * Check if job failed
     * 
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === BulkActionStatusType::FAILED;
    }
    
    /**
     * Get progress percentage
     * 
     * @return int
     */
    public function getProgress(): int
    {
        return $this->progress;
    }
    
    /**
     * Get processed count
     * 
     * @return int
     */
    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }
    
    /**
     * Get total count
     * 
     * @return int
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
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
     * Get failed items
     * 
     * @return array<int, string>
     */
    public function getFailedItems(): array
    {
        return $this->failedItems;
    }
    
    /**
     * Get error message
     * 
     * @return string|null
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
    
    /**
     * Get estimated time remaining (in seconds)
     * 
     * @return int|null
     */
    public function getEstimatedTimeRemaining(): ?int
    {
        if (!$this->startedAt || $this->progress <= 0 || $this->progress >= 100) {
            return null;
        }
        
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $elapsedSeconds = $now->getTimestamp() - $this->startedAt->getTimestamp();
        
        if ($elapsedSeconds <= 0) {
            return null;
        }
        
        // Calculate: time per percent * remaining percent
        $secondsPerPercent = $elapsedSeconds / $this->progress;
        $remainingSeconds = $secondsPerPercent * (100 - $this->progress);
        
        return (int) round($remainingSeconds);
    }
    
    /**
     * Get duration in seconds
     * 
     * @return int|null
     */
    public function getDuration(): ?int
    {
        if (!$this->startedAt) {
            return null;
        }
        
        $endTime = $this->completedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $endTime->getTimestamp() - $this->startedAt->getTimestamp();
    }
    
    /**
     * Get formatted duration
     * 
     * @return string|null
     */
    public function getFormattedDuration(): ?string
    {
        $duration = $this->getDuration();
        if ($duration === null) {
            return null;
        }
        
        if ($duration < 60) {
            return sprintf('%d seconds', $duration);
        }
        
        $minutes = floor($duration / 60);
        $seconds = $duration % 60;
        
        return sprintf('%d minutes %d seconds', $minutes, $seconds);
    }
    
    /**
     * Convert to array
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'job_id' => $this->jobId,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'processed_count' => $this->processedCount,
            'total_count' => $this->totalCount,
            'success_count' => $this->successCount,
            'failed_count' => $this->failedCount,
            'failed_items' => $this->failedItems,
            'error_message' => $this->errorMessage,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'started_at' => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'completed_at' => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            'estimated_time_remaining' => $this->getEstimatedTimeRemaining(),
            'duration' => $this->getDuration(),
            'formatted_duration' => $this->getFormattedDuration(),
            'is_pending' => $this->isPending(),
            'is_processing' => $this->isProcessing(),
            'is_completed' => $this->isCompleted(),
            'is_failed' => $this->isFailed(),
        ];
    }
    
    /**
     * Get status message for display
     * 
     * @return string
     */
    public function getStatusMessage(): string
    {
        return match($this->status) {
            BulkActionStatusType::PENDING => 'Job is pending and will start soon.',
            BulkActionStatusType::PROCESSING => sprintf(
                'Processing... %d%% complete (%d/%d items)',
                $this->progress,
                $this->processedCount,
                $this->totalCount
            ),
            BulkActionStatusType::COMPLETED => sprintf(
                'Completed! %d succeeded, %d failed out of %d total items.',
                $this->successCount,
                $this->failedCount,
                $this->totalCount
            ),
            BulkActionStatusType::FAILED => sprintf(
                'Job failed: %s',
                $this->errorMessage ?? 'Unknown error'
            ),
        };
    }
}