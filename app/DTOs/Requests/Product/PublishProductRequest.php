<?php

namespace App\DTOs\Requests\Product;

use App\Enums\ProductStatus;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Publish Product Request DTO
 * 
 * Data Transfer Object for product publishing requests.
 * Enterprise-grade with immutable design and comprehensive validation.
 * 
 * @package App\DTOs\Requests\Product
 */
final class PublishProductRequest
{
    /**
     * Product ID to publish
     * 
     * @var int
     */
    private int $productId;

    /**
     * Admin ID performing the publish action
     * 
     * @var int
     */
    private int $adminId;

    /**
     * Custom publish timestamp (for scheduling)
     * Null means publish immediately
     * 
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $scheduledAt;

    /**
     * Publishing notes for audit trail
     * 
     * @var string|null
     */
    private ?string $notes;

    /**
     * Force publish ignoring some validations
     * 
     * @var bool
     */
    private bool $forcePublish;

    /**
     * Private constructor for immutability
     * 
     * @param int $productId
     * @param int $adminId
     * @param DateTimeImmutable|null $scheduledAt
     * @param string|null $notes
     * @param bool $forcePublish
     */
    private function __construct(
        int $productId,
        int $adminId,
        ?DateTimeImmutable $scheduledAt = null,
        ?string $notes = null,
        bool $forcePublish = false
    ) {
        $this->productId = $productId;
        $this->adminId = $adminId;
        $this->scheduledAt = $scheduledAt;
        $this->notes = $notes;
        $this->forcePublish = $forcePublish;
    }

    /**
     * Create PublishProductRequest from HTTP request data
     * 
     * @param int $productId
     * @param int $adminId
     * @param array $requestData
     * @return self
     */
    public static function fromRequest(int $productId, int $adminId, array $requestData): self
    {
        $scheduledAt = null;
        if (!empty($requestData['scheduled_at'])) {
            $scheduledAt = DateTimeImmutable::createFromFormat(
                'Y-m-d\TH:i:s',
                $requestData['scheduled_at'],
                new DateTimeZone('UTC')
            );
            
            if ($scheduledAt === false) {
                $scheduledAt = DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $requestData['scheduled_at'],
                    new DateTimeZone('UTC')
                );
            }
        }

        return new self(
            productId: $productId,
            adminId: $adminId,
            scheduledAt: $scheduledAt,
            notes: $requestData['notes'] ?? null,
            forcePublish: filter_var($requestData['force_publish'] ?? false, FILTER_VALIDATE_BOOLEAN)
        );
    }

    /**
     * Create immediate publish request
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $notes
     * @return self
     */
    public static function forImmediatePublish(int $productId, int $adminId, ?string $notes = null): self
    {
        return new self(
            productId: $productId,
            adminId: $adminId,
            scheduledAt: null,
            notes: $notes,
            forcePublish: false
        );
    }

    /**
     * Create scheduled publish request
     * 
     * @param int $productId
     * @param int $adminId
     * @param DateTimeImmutable $scheduledAt
     * @param string|null $notes
     * @return self
     */
    public static function forScheduledPublish(
        int $productId,
        int $adminId,
        DateTimeImmutable $scheduledAt,
        ?string $notes = null
    ): self {
        return new self(
            productId: $productId,
            adminId: $adminId,
            scheduledAt: $scheduledAt,
            notes: $notes,
            forcePublish: false
        );
    }

    /**
     * Create force publish request (bypasses some validations)
     * 
     * @param int $productId
     * @param int $adminId
     * @param string|null $notes
     * @return self
     */
    public static function forForcePublish(int $productId, int $adminId, ?string $notes = null): self
    {
        return new self(
            productId: $productId,
            adminId: $adminId,
            scheduledAt: null,
            notes: $notes,
            forcePublish: true
        );
    }

    /**
     * Get validation rules for request data
     * 
     * @return array
     */
    public static function rules(): array
    {
        return [
            'productId' => 'required|integer|greater_than[0]',
            'adminId' => 'required|integer|greater_than[0]',
            'scheduled_at' => 'permit_empty|valid_date|future_date',
            'notes' => 'permit_empty|string|max_length[1000]',
            'force_publish' => 'permit_empty|in_list[true,false,1,0]',
        ];
    }

    /**
     * Get validation messages
     * 
     * @return array
     */
    public static function messages(): array
    {
        return [
            'productId.required' => 'Product ID is required for publishing',
            'productId.greater_than' => 'Product ID must be valid',
            'adminId.required' => 'Admin ID is required for audit trail',
            'adminId.greater_than' => 'Admin ID must be valid',
            'scheduled_at.valid_date' => 'Scheduled date must be a valid date/time',
            'scheduled_at.future_date' => 'Scheduled date must be in the future',
            'notes.max_length' => 'Notes cannot exceed 1000 characters',
            'force_publish.in_list' => 'Force publish must be true or false',
        ];
    }

    /**
     * Validate the request data
     * 
     * @return array [valid: bool, errors: array]
     */
    public function validate(): array
    {
        $errors = [];
        
        // Basic data validation
        if ($this->productId <= 0) {
            $errors[] = 'Invalid product ID';
        }
        
        if ($this->adminId <= 0) {
            $errors[] = 'Invalid admin ID';
        }
        
        if ($this->notes !== null && strlen($this->notes) > 1000) {
            $errors[] = 'Notes cannot exceed 1000 characters';
        }
        
        // Scheduled date validation
        if ($this->scheduledAt !== null) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($this->scheduledAt <= $now) {
                $errors[] = 'Scheduled publish date must be in the future';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate publishing prerequisites
     * 
     * @param array $productData Current product data from database
     * @param array $linksData Product links data
     * @return array [valid: bool, errors: array]
     */
    public function validatePrerequisites(array $productData, array $linksData = []): array
    {
        $errors = [];
        
        // Skip validation for force publish
        if ($this->forcePublish) {
            return [
                'valid' => true,
                'warnings' => ['Force publish enabled - some validations bypassed'],
                'errors' => [],
            ];
        }
        
        // 1. Check current status
        $currentStatus = $productData['status'] ?? null;
        $allowedFromStatuses = [ProductStatus::VERIFIED->value, ProductStatus::DRAFT->value];
        
        if (!in_array($currentStatus, $allowedFromStatuses, true)) {
            $errors[] = sprintf(
                'Product cannot be published from status "%s". Allowed statuses: %s',
                $currentStatus,
                implode(', ', $allowedFromStatuses)
            );
        }
        
        // 2. Check required fields
        $requiredFields = [
            'name' => 'Product name',
            'slug' => 'Product slug',
            'category_id' => 'Category',
            'image' => 'Product image',
            'description' => 'Product description',
        ];
        
        foreach ($requiredFields as $field => $label) {
            if (empty($productData[$field])) {
                $errors[] = sprintf('%s is required before publishing', $label);
            }
        }
        
        // 3. Check market price
        if (empty($productData['market_price']) || (float)$productData['market_price'] <= 0) {
            $errors[] = 'Valid market price is required before publishing';
        }
        
        // 4. Check active links (at least one)
        $hasActiveLink = false;
        foreach ($linksData as $link) {
            if (($link['active'] ?? false) && !empty($link['url'])) {
                $hasActiveLink = true;
                break;
            }
        }
        
        if (!$hasActiveLink) {
            $errors[] = 'At least one active product link is required before publishing';
        }
        
        // 5. Check image source type compatibility
        $imageSourceType = $productData['image_source_type'] ?? null;
        $image = $productData['image'] ?? null;
        $imagePath = $productData['image_path'] ?? null;
        
        if ($imageSourceType === 'url' && empty($image)) {
            $errors[] = 'External image URL is required for URL source type';
        }
        
        if ($imageSourceType === 'upload' && empty($imagePath)) {
            $errors[] = 'Image path is required for uploaded images';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Transform to database update array
     * 
     * @return array
     */
    public function toDatabaseArray(): array
    {
        $data = [
            'status' => ProductStatus::PUBLISHED->value,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        
        // Set published_at timestamp
        if ($this->scheduledAt !== null) {
            $data['published_at'] = $this->scheduledAt->format('Y-m-d H:i:s');
        } else {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        
        // If coming from draft, set verified timestamp as well
        // This assumes publishing also implies verification
        $data['verified_at'] = date('Y-m-d H:i:s');
        $data['verified_by'] = $this->adminId;
        
        return $data;
    }

    /**
     * Transform to admin action log data
     * 
     * @param array $oldProductData Product data before changes
     * @return array
     */
    public function toAdminActionLog(array $oldProductData): array
    {
        $newData = array_merge($oldProductData, $this->toDatabaseArray());
        
        return [
            'admin_id' => $this->adminId,
            'action_type' => $this->scheduledAt !== null ? 'schedule_publish' : 'publish',
            'entity_type' => 'Product',
            'entity_id' => $this->productId,
            'old_values' => json_encode($oldProductData, JSON_PRETTY_PRINT),
            'new_values' => json_encode($newData, JSON_PRETTY_PRINT),
            'changes_summary' => $this->generateChangeSummary($oldProductData),
            'notes' => $this->notes,
            'scheduled_for' => $this->scheduledAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Generate human-readable change summary
     * 
     * @param array $oldProductData
     * @return string
     */
    private function generateChangeSummary(array $oldProductData): string
    {
        $oldStatus = $oldProductData['status'] ?? 'unknown';
        $summary = sprintf(
            'Published product from status "%s" to "published"',
            $oldStatus
        );
        
        if ($this->scheduledAt !== null) {
            $summary .= sprintf(' (scheduled for: %s)', $this->scheduledAt->format('Y-m-d H:i:s'));
        }
        
        if ($this->notes) {
            $summary .= sprintf(' - Notes: %s', substr($this->notes, 0, 100));
        }
        
        if ($this->forcePublish) {
            $summary .= ' [FORCE PUBLISH - Validations bypassed]';
        }
        
        return $summary;
    }

    /**
     * Get product ID
     * 
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }

    /**
     * Get admin ID
     * 
     * @return int
     */
    public function getAdminId(): int
    {
        return $this->adminId;
    }

    /**
     * Get scheduled publish time
     * 
     * @return DateTimeImmutable|null
     */
    public function getScheduledAt(): ?DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    /**
     * Check if this is a scheduled publish
     * 
     * @return bool
     */
    public function isScheduled(): bool
    {
        return $this->scheduledAt !== null;
    }

    /**
     * Check if this is an immediate publish
     * 
     * @return bool
     */
    public function isImmediate(): bool
    {
        return $this->scheduledAt === null;
    }

    /**
     * Check if force publish is enabled
     * 
     * @return bool
     */
    public function isForcePublish(): bool
    {
        return $this->forcePublish;
    }

    /**
     * Get publishing notes
     * 
     * @return string|null
     */
    public function getNotes(): ?string
    {
        return $this->notes;
    }

    /**
     * Get calculated publish timestamp
     * Returns scheduled time if set, otherwise current time
     * 
     * @return DateTimeImmutable
     */
    public function getPublishTimestamp(): DateTimeImmutable
    {
        if ($this->scheduledAt !== null) {
            return $this->scheduledAt;
        }
        
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Convert to array for API response
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'admin_id' => $this->adminId,
            'scheduled_at' => $this->scheduledAt?->format(DateTimeImmutable::ATOM),
            'notes' => $this->notes,
            'force_publish' => $this->forcePublish,
            'publish_type' => $this->isScheduled() ? 'scheduled' : 'immediate',
            'estimated_publish_time' => $this->getPublishTimestamp()->format(DateTimeImmutable::ATOM),
        ];
    }

    /**
     * Create a copy with different scheduled time
     * 
     * @param DateTimeImmutable $newScheduledAt
     * @return self
     */
    public function withScheduledAt(DateTimeImmutable $newScheduledAt): self
    {
        return new self(
            productId: $this->productId,
            adminId: $this->adminId,
            scheduledAt: $newScheduledAt,
            notes: $this->notes,
            forcePublish: $this->forcePublish
        );
    }

    /**
     * Create a copy with notes
     * 
     * @param string $notes
     * @return self
     */
    public function withNotes(string $notes): self
    {
        return new self(
            productId: $this->productId,
            adminId: $this->adminId,
            scheduledAt: $this->scheduledAt,
            notes: $notes,
            forcePublish: $this->forcePublish
        );
    }
}