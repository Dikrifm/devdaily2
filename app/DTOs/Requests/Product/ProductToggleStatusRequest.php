<?php

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Enums\ProductStatus;
use App\Exceptions\ValidationException;
use DateTimeImmutable;

final class ProductToggleStatusRequest extends BaseDTO
{
    private int $productId;
    private int $adminId;
    private ProductStatus $targetStatus;
    private ?string $notes = null;
    private bool $forceStatusChange = false;
    private DateTimeImmutable $requestedAt;
    private string $ipAddress;
    private string $userAgent;

    private function __construct() {}

    public static function fromRequest(array $requestData, int $adminId, ?string $ipAddress = null, ?string $userAgent = null): self
    {
        $instance = new self();
        $instance->validateAndHydrate($requestData, $adminId, $ipAddress, $userAgent);
        return $instance;
    }

    public static function create(
        int $productId,
        int $adminId,
        ProductStatus $targetStatus,
        ?string $notes = null,
        bool $forceStatusChange = false
    ): self {
        $instance = new self();
        
        $data = [
            'product_id' => $productId,
            'admin_id' => $adminId,
            'target_status' => $targetStatus->value,
            'notes' => $notes,
            'force_status_change' => $forceStatusChange,
        ];
        
        $instance->validateAndHydrate($data, $adminId, '127.0.0.1', 'CLI');
        return $instance;
    }

    private function validateAndHydrate(array $data, int $adminId, ?string $ipAddress, ?string $userAgent): void
    {
        $errors = [];
        
        // Validate product_id
        if (!isset($data['product_id']) || !is_numeric($data['product_id'])) {
            $errors['product_id'] = 'Product ID is required and must be numeric';
        } else {
            $this->productId = (int) $data['product_id'];
            if ($this->productId <= 0) {
                $errors['product_id'] = 'Product ID must be positive integer';
            }
        }
        
        // Set admin ID
        $this->adminId = $adminId;
        if ($this->adminId <= 0) {
            $errors['admin_id'] = 'Admin ID must be positive integer';
        }
        
        // Validate target_status
        if (!isset($data['target_status'])) {
            $errors['target_status'] = 'Target status is required';
        } else {
            try {
                $this->targetStatus = ProductStatus::from($data['target_status']);
            } catch (\ValueError $e) {
                $errors['target_status'] = sprintf(
                    'Invalid status. Allowed values: %s',
                    implode(', ', array_column(ProductStatus::cases(), 'value'))
                );
            }
        }
        
        // Validate optional fields
        if (isset($data['notes'])) {
            $notes = trim($data['notes']);
            if (strlen($notes) > 500) {
                $errors['notes'] = 'Notes must not exceed 500 characters';
            } else {
                $this->notes = $notes ?: null;
            }
        }
        
        $this->forceStatusChange = boolval($data['force_status_change'] ?? false);
        
        // Set metadata
        $this->requestedAt = new DateTimeImmutable();
        $this->ipAddress = $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->userAgent = $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw ValidationException::forField('toggle_status', 'Validation failed', $errors);
        }
    }

    private function validateBusinessRules(array &$errors): void
    {
        // Add any business rule validations here
        // Example: Cannot publish without at least one active affiliate link
        // This would require checking with the service layer, so maybe skip for DTO
    }

    public static function rules(): array
    {
        return [
            'product_id' => 'required|integer|min:1',
            'target_status' => 'required|string|in:' . implode(',', array_column(ProductStatus::cases(), 'value')),
            'notes' => 'nullable|string|max:500',
            'force_status_change' => 'nullable|boolean',
        ];
    }

    public static function messages(): array
    {
        return [
            'product_id.required' => 'Product ID is required',
            'product_id.integer' => 'Product ID must be an integer',
            'product_id.min' => 'Product ID must be at least 1',
            'target_status.required' => 'Target status is required',
            'target_status.in' => 'Invalid status value',
            'notes.max' => 'Notes must not exceed 500 characters',
            'force_status_change.boolean' => 'Force status change must be true or false',
        ];
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getAdminId(): int
    {
        return $this->adminId;
    }

    public function getTargetStatus(): ProductStatus
    {
        return $this->targetStatus;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function isForceStatusChange(): bool
    {
        return $this->forceStatusChange;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'admin_id' => $this->adminId,
            'target_status' => $this->targetStatus->value,
            'notes' => $this->notes,
            'force_status_change' => $this->forceStatusChange,
            'requested_at' => $this->requestedAt->format('Y-m-d H:i:s'),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];
    }

    public function toDatabaseArray(): array
    {
        return [
            'product_id' => $this->productId,
            'admin_id' => $this->adminId,
            'old_status' => null, // Will be filled by service
            'new_status' => $this->targetStatus->value,
            'notes' => $this->notes,
            'forced' => $this->forceStatusChange,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'requested_at' => $this->requestedAt,
        ];
    }

    public function getAuditMessage(): string
    {
        return sprintf(
            'Product #%d status changed to %s by Admin #%d%s',
            $this->productId,
            $this->targetStatus->value,
            $this->adminId,
            $this->notes ? " (Note: {$this->notes})" : ''
        );
    }

    public function validate(): array
    {
        $errors = [];
        
        if ($this->productId <= 0) {
            $errors['product_id'] = 'Product ID must be positive';
        }
        
        if ($this->adminId <= 0) {
            $errors['admin_id'] = 'Admin ID must be positive';
        }
        
        return $errors;
    }
}