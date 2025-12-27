<?php

declare(strict_types=1);

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * DTO for publishing a product (immediate, scheduled, or force publish)
 * 
 * @package DevDaily\DTOs\Requests\Product
 */
final class PublishProductRequest extends BaseDTO
{
    private int $productId;
    private int $adminId;
    private ?DateTimeImmutable $scheduledAt = null;
    private ?string $notes = null;
    private bool $forcePublish = false;
    private DateTimeImmutable $requestedAt;
    private string $publishType;

    /**
     * Private constructor - use factory methods
     */
    private function __construct(int $productId, int $adminId)
    {
        $this->productId = $productId;
        $this->adminId = $adminId;
        $this->requestedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    }

    /**
     * Create DTO from request data
     */
    public static function fromRequest(int $productId, int $adminId, array $requestData): self
    {
        $dto = new self($productId, $adminId);
        $dto->validateAndHydrate($requestData);
        
        return $dto;
    }

    /**
     * Create DTO for immediate publish
     */
    public static function forImmediatePublish(int $productId, int $adminId, ?string $notes = null): self
    {
        $dto = new self($productId, $adminId);
        $dto->publishType = 'immediate';
        $dto->notes = $notes;
        $dto->validatePublishData();
        
        return $dto;
    }

    /**
     * Create DTO for scheduled publish
     */
    public static function forScheduledPublish(
        int $productId, 
        int $adminId, 
        DateTimeImmutable $scheduledAt, 
        ?string $notes = null
    ): self {
        $dto = new self($productId, $adminId);
        $dto->publishType = 'scheduled';
        $dto->scheduledAt = $scheduledAt;
        $dto->notes = $notes;
        $dto->validatePublishData();
        
        return $dto;
    }

    /**
     * Create DTO for force publish (bypass validations)
     */
    public static function forForcePublish(int $productId, int $adminId, ?string $notes = null): self
    {
        $dto = new self($productId, $adminId);
        $dto->publishType = 'force';
        $dto->forcePublish = true;
        $dto->notes = $notes;
        $dto->validatePublishData();
        
        return $dto;
    }

    /**
     * Validate and hydrate from request data
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // Determine publish type
        if (isset($data['publish_type'])) {
            $this->publishType = $this->sanitizeString($data['publish_type']);
            
            // Validate publish type
            if (!in_array($this->publishType, ['immediate', 'scheduled', 'force'], true)) {
                $errors['publish_type'] = 'Invalid publish type. Must be: immediate, scheduled, or force';
            }
        } else {
            $this->publishType = 'immediate';
        }
        
        // Parse scheduled datetime if provided
        if ($this->publishType === 'scheduled') {
            if (empty($data['scheduled_at'])) {
                $errors['scheduled_at'] = 'Scheduled datetime is required for scheduled publish';
            } else {
                $this->scheduledAt = $this->parseScheduledAt($data['scheduled_at'], $errors);
            }
        }
        
        // Parse force publish flag
        if ($this->publishType === 'force') {
            $this->forcePublish = true;
        } elseif (isset($data['force_publish'])) {
            $this->forcePublish = filter_var($data['force_publish'], FILTER_VALIDATE_BOOLEAN);
        }
        
        // Parse notes
        if (isset($data['notes']) && $data['notes'] !== '') {
            $this->notes = $this->sanitizeString($data['notes']);
            
            // Validate notes length
            if (strlen($this->notes) > 500) {
                $errors['notes'] = 'Notes cannot exceed 500 characters';
            }
        }
        
        // Validate scheduled datetime is in future
        if ($this->scheduledAt !== null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
            if ($this->scheduledAt <= $now) {
                $errors['scheduled_at'] = 'Scheduled datetime must be in the future';
            }
            
            // Limit scheduling to max 30 days in future (MVP constraint)
            $maxScheduled = $now->modify('+30 days');
            if ($this->scheduledAt > $maxScheduled) {
                $errors['scheduled_at'] = 'Cannot schedule publish more than 30 days in advance';
            }
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Publish request validation failed', $errors);
        }
    }

    /**
     * Parse scheduled datetime string
     */
    private function parseScheduledAt(string $dateString, array &$errors): ?DateTimeImmutable
    {
        try {
            // Try ISO 8601 format first
            $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $dateString);
            
            if (!$date) {
                // Try with timezone
                $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $dateString);
            }
            
            if (!$date) {
                // Try simple format (assuming Jakarta timezone)
                $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateString, new DateTimeZone('Asia/Jakarta'));
            }
            
            if (!$date) {
                $errors['scheduled_at'] = 'Invalid datetime format. Use ISO 8601 format (e.g., 2025-12-25T14:30:00+07:00)';
                return null;
            }
            
            return $date;
        } catch (\Exception $e) {
            $errors['scheduled_at'] = 'Invalid datetime format: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Validate publish data after factory methods
     */
    private function validatePublishData(): void
    {
        $errors = [];
        
        // Validate scheduled datetime for scheduled publish
        if ($this->publishType === 'scheduled' && $this->scheduledAt === null) {
            $errors['scheduled_at'] = 'Scheduled datetime is required for scheduled publish';
        }
        
        // Validate scheduled datetime is in future
        if ($this->scheduledAt !== null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
            if ($this->scheduledAt <= $now) {
                $errors['scheduled_at'] = 'Scheduled datetime must be in the future';
            }
            
            // Limit scheduling to max 30 days in future
            $maxScheduled = $now->modify('+30 days');
            if ($this->scheduledAt > $maxScheduled) {
                $errors['scheduled_at'] = 'Cannot schedule publish more than 30 days in advance';
            }
        }
        
        // Validate notes length
        if ($this->notes !== null && strlen($this->notes) > 500) {
            $errors['notes'] = 'Notes cannot exceed 500 characters';
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Publish request validation failed', $errors);
        }
    }

    /**
     * Get validation rules for form validation
     */
    public static function rules(): array
    {
        return [
            'publish_type' => 'permit_empty|string|in_list[immediate,scheduled,force]',
            'scheduled_at' => 'permit_empty|valid_date',
            'force_publish' => 'permit_empty|in_list[0,1,true,false]',
            'notes' => 'permit_empty|string|max:500',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'publish_type' => [
                'in_list' => 'Publish type must be: immediate, scheduled, or force',
            ],
            'scheduled_at' => [
                'valid_date' => 'Please provide a valid datetime in ISO 8601 format',
            ],
            'notes' => [
                'max' => 'Notes cannot exceed 500 characters',
            ],
        ];
    }

    /**
     * Validate prerequisites for publishing
     */
    public function validatePrerequisites(array $productData, array $linksData = []): array
    {
        $errors = [];
        
        // MVP: Basic prerequisites
        if (empty($productData['name'])) {
            $errors['product'] = 'Product must have a name';
        }
        
        if (empty($productData['market_price']) || (float)$productData['market_price'] <= 0) {
            $errors['product'] = 'Product must have a valid price';
        }
        
        // For non-force publish, require at least one active link
        if (!$this->forcePublish) {
            if (empty($linksData)) {
                $errors['links'] = 'Product must have at least one affiliate link to publish';
            } else {
                $hasActiveLink = false;
                foreach ($linksData as $link) {
                    if ($link['active'] ?? false) {
                        $hasActiveLink = true;
                        break;
                    }
                }
                
                if (!$hasActiveLink) {
                    $errors['links'] = 'Product must have at least one active affiliate link';
                }
            }
        }
        
        return $errors;
    }

    // Getters
    public function getProductId(): int { return $this->productId; }
    public function getAdminId(): int { return $this->adminId; }
    public function getScheduledAt(): ?DateTimeImmutable { return $this->scheduledAt; }
    public function getNotes(): ?string { return $this->notes; }
    public function isForcePublish(): bool { return $this->forcePublish; }
    public function getRequestedAt(): DateTimeImmutable { return $this->requestedAt; }
    public function getPublishType(): string { return $this->publishType; }

    /**
     * Check if this is a scheduled publish
     */
    public function isScheduled(): bool
    {
        return $this->publishType === 'scheduled';
    }

    /**
     * Check if this is an immediate publish
     */
    public function isImmediate(): bool
    {
        return $this->publishType === 'immediate';
    }

    /**
     * Get the actual publish timestamp (now for immediate, scheduledAt for scheduled)
     */
    public function getPublishTimestamp(): DateTimeImmutable
    {
        if ($this->isScheduled() && $this->scheduledAt !== null) {
            return $this->scheduledAt;
        }
        
        return $this->requestedAt;
    }

    /**
     * Convert to database array for audit/queue
     */
    public function toDatabaseArray(): array
    {
        return [
            'product_id' => $this->productId,
            'admin_id' => $this->adminId,
            'publish_type' => $this->publishType,
            'scheduled_at' => $this->scheduledAt?->format('Y-m-d H:i:s'),
            'force_publish' => $this->forcePublish ? 1 : 0,
            'notes' => $this->notes,
            'requested_at' => $this->requestedAt->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate change summary for audit log
     */
    public function toAdminActionLog(array $oldProductData): array
    {
        $changeSummary = $this->generateChangeSummary($oldProductData);
        
        return [
            'action' => 'PUBLISH',
            'entity_type' => 'PRODUCT',
            'entity_id' => $this->productId,
            'admin_id' => $this->adminId,
            'changes_summary' => $changeSummary,
            'metadata' => [
                'publish_type' => $this->publishType,
                'force_publish' => $this->forcePublish,
                'scheduled_at' => $this->scheduledAt?->format('Y-m-d H:i:s'),
                'notes' => $this->notes,
            ],
        ];
    }

    /**
     * Generate change summary
     */
    private function generateChangeSummary(array $oldProductData): string
    {
        $changes = [];
        
        // Status change
        $oldStatus = $oldProductData['status'] ?? 'unknown';
        $changes[] = sprintf('Status: %s → published', $oldStatus);
        
        // Add publish type info
        if ($this->isScheduled()) {
            $changes[] = sprintf('Scheduled for: %s', $this->scheduledAt?->format('Y-m-d H:i:s') ?? 'unknown');
        }
        
        if ($this->forcePublish) {
            $changes[] = 'Force published (bypassed validations)';
        }
        
        if ($this->notes) {
            $changes[] = 'Notes: ' . (strlen($this->notes) > 50 ? substr($this->notes, 0, 47) . '...' : $this->notes);
        }
        
        return implode('; ', $changes);
    }

    /**
     * Create new instance with updated scheduled datetime
     */
    public function withScheduledAt(DateTimeImmutable $newScheduledAt): self
    {
        $new = clone $this;
        $new->scheduledAt = $newScheduledAt;
        $new->validatePublishData();
        
        return $new;
    }

    /**
     * Create new instance with updated notes
     */
    public function withNotes(string $notes): self
    {
        $new = clone $this;
        $new->notes = $notes;
        $new->validatePublishData();
        
        return $new;
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        $data = [
            'product_id' => $this->productId,
            'admin_id' => $this->adminId,
            'publish_type' => $this->publishType,
            'force_publish' => $this->forcePublish,
            'requested_at' => $this->requestedAt->format('Y-m-d H:i:s'),
            'is_scheduled' => $this->isScheduled(),
            'is_immediate' => $this->isImmediate(),
            'publish_timestamp' => $this->getPublishTimestamp()->format('Y-m-d H:i:s'),
        ];
        
        if ($this->scheduledAt !== null) {
            $data['scheduled_at'] = $this->scheduledAt->format('Y-m-d H:i:s');
            $data['scheduled_at_iso'] = $this->scheduledAt->format(DateTimeImmutable::ATOM);
        }
        
        if ($this->notes !== null) {
            $data['notes'] = $this->notes;
        }
        
        return $data;
    }
}