<?php

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;

/**
 * Data Transfer Object for Product Delete Request
 * 
 * Validates and encapsulates data for deleting/archiving products
 * Supports both soft delete (archive) and hard delete based on business rules
 * 
 * @package DevDaily
 * @subpackage ProductDTOs
 */
class ProductDeleteRequest extends BaseDTO
{
    /**
     * @var int Product ID to delete (required)
     */
    private int $productId;
    
    /**
     * @var int User ID performing the deletion (required)
     */
    private int $userId;
    
    /**
     * @var string Reason for deletion (optional, for audit trail)
     */
    private string $reason;
    
    /**
     * @var bool Whether to perform hard delete (permanent) vs soft delete
     */
    private bool $hardDelete = false;
    
    /**
     * @var bool Whether to delete associated links/cascade
     */
    private bool $cascade = false;
    
    /**
     * @var \DateTimeImmutable Timestamp of deletion request
     */
    private \DateTimeImmutable $requestedAt;
    
    /**
     * @var string IP address of requester
     */
    private string $ipAddress;
    
    /**
     * @var string User agent of requester
     */
    private string $userAgent;
    
    /**
     * Private constructor - use fromArray() static method
     */
    private function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
    
    /**
     * Create DTO from array input
     * 
     * @param array $data Input data
     * @return self
     * @throws ValidationException
     */
    public static function fromArray(array $data): self
    {
        $instance = new self();
        $instance->validateAndHydrate($data);
        return $instance;
    }
    
    /**
     * Validate input data and hydrate DTO properties
     * 
     * @param array $data
     * @throws ValidationException
     */
    private function validateAndHydrate(array $data): void
    {
        $errors = [];
        
        // 1. Validate product_id - required, positive integer
        if (!isset($data['product_id'])) {
            $errors['product_id'] = 'Product ID is required';
        } elseif (!is_numeric($data['product_id']) || (int)$data['product_id'] <= 0) {
            $errors['product_id'] = 'Product ID must be a positive integer';
        } else {
            $this->productId = (int)$data['product_id'];
        }
        
        // 2. Validate user_id - required, positive integer
        if (!isset($data['user_id'])) {
            $errors['user_id'] = 'User ID is required';
        } elseif (!is_numeric($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors['user_id'] = 'User ID must be a positive integer';
        } else {
            $this->userId = (int)$data['user_id'];
        }
        
        // 3. Validate reason (optional, default if not provided)
        if (isset($data['reason']) && is_string($data['reason'])) {
            $reason = trim($data['reason']);
            if ($reason === '') {
                $this->reason = 'No reason provided';
            } elseif (mb_strlen($reason) > 500) {
                $errors['reason'] = 'Reason cannot exceed 500 characters';
            } else {
                $this->reason = $reason;
            }
        } else {
            $this->reason = 'No reason provided';
        }
        
        // 4. Validate hard_delete flag (optional)
        if (isset($data['hard_delete'])) {
            if (!is_bool($data['hard_delete'])) {
                // Try to convert from string
                $hardDelete = filter_var($data['hard_delete'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($hardDelete === null) {
                    $errors['hard_delete'] = 'hard_delete must be a boolean value';
                } else {
                    $this->hardDelete = $hardDelete;
                }
            } else {
                $this->hardDelete = $data['hard_delete'];
            }
        }
        
        // 5. Validate cascade flag (optional)
        if (isset($data['cascade'])) {
            if (!is_bool($data['cascade'])) {
                $cascade = filter_var($data['cascade'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($cascade === null) {
                    $errors['cascade'] = 'cascade must be a boolean value';
                } else {
                    $this->cascade = $cascade;
                }
            } else {
                $this->cascade = $data['cascade'];
            }
        }
        
        // 6. Set IP address from request or data
        if (isset($data['ip_address']) && is_string($data['ip_address'])) {
            $this->ipAddress = filter_var($data['ip_address'], FILTER_VALIDATE_IP) ? $data['ip_address'] : '0.0.0.0';
        } else {
            $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        // 7. Set user agent
        if (isset($data['user_agent']) && is_string($data['user_agent'])) {
            $this->userAgent = substr($data['user_agent'], 0, 512);
        } else {
            $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'HTMX-Client/1.0';
        }
        
        // 8. Safety check: Prevent hard delete in MVP unless explicitly allowed
        if ($this->hardDelete && !($data['force_hard_delete'] ?? false)) {
            // In MVP, we only allow soft delete by default
            $this->hardDelete = false;
            // Log warning but don't error
            log_message('warning', 'Hard delete attempted but overridden to soft delete for product: ' . $this->productId);
        }
        
        // 9. Throw ValidationException if any errors
        if (!empty($errors)) {
            throw new ValidationException('Product delete validation failed', $errors);
        }
    }
    
    /**
     * Get product ID to delete
     * 
     * @return int
     */
    public function getProductId(): int
    {
        return $this->productId;
    }
    
    /**
     * Get user ID performing deletion
     * 
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }
    
    /**
     * Get deletion reason
     * 
     * @return string
     */
    public function getReason(): string
    {
        return $this->reason;
    }
    
    /**
     * Check if hard delete requested
     * 
     * @return bool
     */
    public function isHardDelete(): bool
    {
        return $this->hardDelete;
    }
    
    /**
     * Check if cascade delete requested
     * 
     * @return bool
     */
    public function isCascade(): bool
    {
        return $this->cascade;
    }
    
    /**
     * Get requested at timestamp
     * 
     * @return \DateTimeImmutable
     */
    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }
    
    /**
     * Get IP address
     * 
     * @return string
     */
    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }
    
    /**
     * Get user agent
     * 
     * @return string
     */
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
    
    /**
     * Convert DTO to array for logging/audit trail
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'user_id' => $this->userId,
            'reason' => $this->reason,
            'hard_delete' => $this->hardDelete,
            'cascade' => $this->cascade,
            'requested_at' => $this->requestedAt->format('c'),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent
        ];
    }
    
    /**
     * Convert DTO to JSON string
     * 
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }
    
    /**
     * Create audit log message
     * 
     * @return string
     */
    public function toAuditMessage(): string
    {
        return sprintf(
            'Product deletion requested: ID %d by user %d. Reason: %s. Type: %s',
            $this->productId,
            $this->userId,
            $this->reason,
            $this->hardDelete ? 'HARD DELETE' : 'SOFT DELETE'
        );
    }
}