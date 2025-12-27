<?php

namespace App\Enums;

/**
 * Bulk Action Status Type Enum
 * 
 * Defines the possible states of a background bulk action job
 * Used for tracking progress and managing job lifecycle
 * 
 * @package DevDaily
 * @subpackage JobEnums
 */
enum BulkActionStatusType: string
{
    /**
     * Job has been created but not yet started
     */
    case PENDING = 'pending';
    
    /**
     * Job is currently being processed
     */
    case PROCESSING = 'processing';
    
    /**
     * Job has completed successfully
     */
    case COMPLETED = 'completed';
    
    /**
     * Job has failed with errors
     */
    case FAILED = 'failed';
    
    /**
     * Job has been cancelled by user/admin
     */
    case CANCELLED = 'cancelled';
    
    /**
     * Job is paused (can be resumed)
     */
    case PAUSED = 'paused';
    
    /**
     * Get display name for the status
     * 
     * @return string
     */
    public function displayName(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
            self::PAUSED => 'Paused',
        };
    }
    
    /**
     * Get description for the status
     * 
     * @return string
     */
    public function description(): string
    {
        return match($this) {
            self::PENDING => 'Job is waiting to start',
            self::PROCESSING => 'Job is currently running',
            self::COMPLETED => 'Job has finished successfully',
            self::FAILED => 'Job encountered errors',
            self::CANCELLED => 'Job was cancelled by user',
            self::PAUSED => 'Job is temporarily paused',
        };
    }
    
    /**
     * Check if status is a final state (no further transitions)
     * 
     * @return bool
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ]);
    }
    
    /**
     * Check if status is an active state
     * 
     * @return bool
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::PENDING,
            self::PROCESSING,
            self::PAUSED,
        ]);
    }
    
    /**
     * Check if status indicates processing
     * 
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this === self::PROCESSING;
    }
    
    /**
     * Check if status indicates success
     * 
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this === self::COMPLETED;
    }
    
    /**
     * Check if status indicates failure
     * 
     * @return bool
     */
    public function isFailure(): bool
    {
        return in_array($this, [
            self::FAILED,
            self::CANCELLED,
        ]);
    }
    
    /**
     * Get CSS class for UI styling
     * 
     * @return string
     */
    public function cssClass(): string
    {
        return match($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800',
            self::PROCESSING => 'bg-blue-100 text-blue-800',
            self::COMPLETED => 'bg-green-100 text-green-800',
            self::FAILED => 'bg-red-100 text-red-800',
            self::CANCELLED => 'bg-gray-100 text-gray-800',
            self::PAUSED => 'bg-orange-100 text-orange-800',
        };
    }
    
    /**
     * Get icon for UI display
     * 
     * @return string
     */
    public function icon(): string
    {
        return match($this) {
            self::PENDING => 'clock',
            self::PROCESSING => 'arrow-path',
            self::COMPLETED => 'check-circle',
            self::FAILED => 'x-circle',
            self::CANCELLED => 'ban',
            self::PAUSED => 'pause',
        };
    }
    
    /**
     * Get next possible statuses (state transitions)
     * 
     * @return array<BulkActionStatusType>
     */
    public function nextPossibleStatuses(): array
    {
        return match($this) {
            self::PENDING => [self::PROCESSING, self::CANCELLED],
            self::PROCESSING => [self::COMPLETED, self::FAILED, self::PAUSED, self::CANCELLED],
            self::PAUSED => [self::PROCESSING, self::CANCELLED],
            self::COMPLETED, self::FAILED, self::CANCELLED => [],
        };
    }
    
    /**
     * Check if transition to target status is valid
     * 
     * @param BulkActionStatusType $targetStatus
     * @return bool
     */
    public function canTransitionTo(BulkActionStatusType $targetStatus): bool
    {
        return in_array($targetStatus, $this->nextPossibleStatuses());
    }
    
    /**
     * Get statuses that are considered "in progress"
     * (For progress bar calculations)
     * 
     * @return array<BulkActionStatusType>
     */
    public static function inProgressStatuses(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::PAUSED,
        ];
    }
    
    /**
     * Get statuses that indicate job completion
     * 
     * @return array<BulkActionStatusType>
     */
    public static function completionStatuses(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }
    
    /**
     * Get statuses available for MVP
     * 
     * @return array<BulkActionStatusType>
     */
    public static function mvpStatuses(): array
    {
        return [
            self::PENDING,
            self::PROCESSING,
            self::COMPLETED,
            self::FAILED,
        ];
    }
    
    /**
     * Get status priority for sorting
     * Lower number = higher priority
     * 
     * @return int
     */
    public function priority(): int
    {
        return match($this) {
            self::FAILED => 1,
            self::PROCESSING => 2,
            self::PENDING => 3,
            self::PAUSED => 4,
            self::CANCELLED => 5,
            self::COMPLETED => 6,
        };
    }
    
    /**
     * Create from string with validation
     * 
     * @param string $status
     * @return self
     * @throws \ValueError
     */
    public static function fromString(string $status): self
    {
        return self::from($status);
    }
    
    /**
     * Try to create from string, return null on failure
     * 
     * @param string $status
     * @return self|null
     */
    public static function tryFromString(string $status): ?self
    {
        return self::tryFrom($status);
    }
    
    /**
     * Get all status values as array
     * 
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
    
    /**
     * Get all statuses with display names
     * 
     * @return array<string, string>
     */
    public static function allWithDisplayNames(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[$case->value] = $case->displayName();
        }
        return $result;
    }
}