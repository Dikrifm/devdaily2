<?php

namespace App\DTOs\Requests\Product;

use App\DTOs\BaseDTO;
use App\Enums\ProductBulkActionType;
use App\Exceptions\ValidationException;

/**
 * Data Transfer Object for Product Bulk Action Request
 * 
 * Validates and encapsulates data for bulk operations on products
 * Supports: publish, unpublish, delete, move to category, etc.
 * 
 * @package DevDaily
 * @subpackage ProductDTOs
 */
class ProductBulkActionRequest extends BaseDTO
{
    /**
     * @var ProductBulkActionType Action to perform (required)
     */
    private ProductBulkActionType $action;
    
    /**
     * @var array<int> Product IDs to process (required, non-empty)
     */
    private array $productIds;
    
    /**
     * @var int User ID performing the action (required)
     */
    private int $userId;
    
    /**
     * @var array Additional action-specific parameters
     */
    private array $parameters = [];
    
    /**
     * @var bool Whether to process in background (for large batches)
     */
    private bool $processInBackground = false;
    
    /**
     * @var int|null Background job ID (set by service)
     */
    private ?int $jobId = null;
    
    /**
     * @var \DateTimeImmutable Timestamp of request
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
        
        // 1. Validate action - required, must be valid enum
        if (!isset($data['action'])) {
            $errors['action'] = 'Action is required';
        } else {
            try {
                $this->action = is_string($data['action']) 
                    ? ProductBulkActionType::from($data['action'])
                    : ProductBulkActionType::from((string)$data['action']);
            } catch (\ValueError $e) {
                $allowedActions = array_column(ProductBulkActionType::cases(), 'value');
                $errors['action'] = 'Invalid action. Must be one of: ' . 
                    implode(', ', $allowedActions);
            }
        }
        
        // 2. Validate product_ids - required, must be non-empty array of positive integers
        if (!isset($data['product_ids'])) {
            $errors['product_ids'] = 'Product IDs are required';
        } elseif (!is_array($data['product_ids'])) {
            $errors['product_ids'] = 'Product IDs must be an array';
        } elseif (empty($data['product_ids'])) {
            $errors['product_ids'] = 'At least one product ID must be selected';
        } else {
            $validIds = [];
            foreach ($data['product_ids'] as $index => $id) {
                if (!is_numeric($id) || (int)$id <= 0) {
                    $errors['product_ids_' . $index] = 'Product ID must be a positive integer';
                } else {
                    $validIds[] = (int)$id;
                }
            }
            
            // Remove duplicates and validate max batch size
            $uniqueIds = array_unique($validIds);
            if (count($uniqueIds) > 1000) {
                $errors['product_ids'] = 'Cannot process more than 1000 products at once';
            } else {
                $this->productIds = $uniqueIds;
            }
        }
        
        // 3. Validate user_id - required, positive integer
        if (!isset($data['user_id'])) {
            $errors['user_id'] = 'User ID is required';
        } elseif (!is_numeric($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors['user_id'] = 'User ID must be a positive integer';
        } else {
            $this->userId = (int)$data['user_id'];
        }
        
        // 4. Validate parameters (optional, action-specific)
        if (isset($data['parameters']) && is_array($data['parameters'])) {
            $this->parameters = $this->validateParameters($data['parameters'], $errors);
        }
        
        // 5. Validate process_in_background flag (optional)
        if (isset($data['process_in_background'])) {
            if (!is_bool($data['process_in_background'])) {
                $backgroundFlag = filter_var($data['process_in_background'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($backgroundFlag === null) {
                    $errors['process_in_background'] = 'process_in_background must be a boolean value';
                } else {
                    $this->processInBackground = $backgroundFlag;
                }
            } else {
                $this->processInBackground = $data['process_in_background'];
            }
        }
        
        // 6. Auto-enable background processing for large batches
        if (isset($this->productIds) && count($this->productIds) > 100) {
            $this->processInBackground = true;
        }
        
        // 7. Set IP address and user agent
        $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'HTMX-Client/1.0';
        
        // 8. Throw ValidationException if any errors
        if (!empty($errors)) {
            throw new ValidationException('Product bulk action validation failed', $errors);
        }
    }
    
    /**
     * Validate action-specific parameters
     * 
     * @param array $parameters
     * @param array &$errors
     * @return array Validated parameters
     */
    private function validateParameters(array $parameters, array &$errors): array
    {
        $validated = [];
        
        // Validate based on action type
        if (!isset($this->action)) {
            return $validated;
        }
        
        switch ($this->action) {
            case ProductBulkActionType::CHANGE_CATEGORY:
                if (!isset($parameters['category_id'])) {
                    $errors['parameters.category_id'] = 'Category ID is required for category change';
                } elseif (!is_numeric($parameters['category_id']) || (int)$parameters['category_id'] < 0) {
                    $errors['parameters.category_id'] = 'Category ID must be a non-negative integer';
                } else {
                    $validated['category_id'] = (int)$parameters['category_id'];
                }
                break;
                
            case ProductBulkActionType::CHANGE_PRICE:
                if (!isset($parameters['price_adjustment_type'])) {
                    $errors['parameters.price_adjustment_type'] = 'Price adjustment type is required';
                } elseif (!in_array($parameters['price_adjustment_type'], ['set', 'increase', 'decrease', 'percentage_increase', 'percentage_decrease'])) {
                    $errors['parameters.price_adjustment_type'] = 'Invalid price adjustment type';
                } else {
                    $validated['price_adjustment_type'] = $parameters['price_adjustment_type'];
                }
                
                if (!isset($parameters['price_value'])) {
                    $errors['parameters.price_value'] = 'Price value is required';
                } elseif (!is_numeric($parameters['price_value']) || (float)$parameters['price_value'] < 0) {
                    $errors['parameters.price_value'] = 'Price value must be a non-negative number';
                } else {
                    $validated['price_value'] = (float)$parameters['price_value'];
                }
                break;
                
            case ProductBulkActionType::ADD_TAGS:
                if (!isset($parameters['tags'])) {
                    $errors['parameters.tags'] = 'Tags are required';
                } elseif (!is_array($parameters['tags'])) {
                    $errors['parameters.tags'] = 'Tags must be an array';
                } elseif (empty($parameters['tags'])) {
                    $errors['parameters.tags'] = 'At least one tag must be provided';
                } else {
                    // Validate each tag
                    $validTags = [];
                    foreach ($parameters['tags'] as $index => $tag) {
                        if (!is_string($tag)) {
                            $errors['parameters.tags_' . $index] = 'Tag must be a string';
                        } elseif (trim($tag) === '') {
                            $errors['parameters.tags_' . $index] = 'Tag cannot be empty';
                        } else {
                            $validTags[] = trim($tag);
                        }
                    }
                    $validated['tags'] = array_unique($validTags);
                }
                break;
                
            case ProductBulkActionType::EXPORT:
                if (!isset($parameters['export_format'])) {
                    $validated['export_format'] = 'csv'; // Default
                } elseif (!in_array($parameters['export_format'], ['csv', 'json', 'excel'])) {
                    $errors['parameters.export_format'] = 'Invalid export format';
                } else {
                    $validated['export_format'] = $parameters['export_format'];
                }
                break;
                
            case ProductBulkActionType::DELETE:
                if (isset($parameters['reason']) && is_string($parameters['reason'])) {
                    $validated['reason'] = substr(trim($parameters['reason']), 0, 500);
                }
                if (isset($parameters['hard_delete'])) {
                    $validated['hard_delete'] = (bool)$parameters['hard_delete'];
                }
                break;
        }
        
        return $validated;
    }
    
    /**
     * Get action type
     * 
     * @return ProductBulkActionType
     */
    public function getAction(): ProductBulkActionType
    {
        return $this->action;
    }
    
    /**
     * Get product IDs
     * 
     * @return array<int>
     */
    public function getProductIds(): array
    {
        return $this->productIds;
    }
    
    /**
     * Get user ID
     * 
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }
    
    /**
     * Get action parameters
     * 
     * @return array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }
    
    /**
     * Get specific parameter value
     * 
     * @param string $key Parameter key
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function getParameter(string $key, $default = null)
    {
        return $this->parameters[$key] ?? $default;
    }
    
    /**
     * Check if should process in background
     * 
     * @return bool
     */
    public function shouldProcessInBackground(): bool
    {
        return $this->processInBackground;
    }
    
    /**
     * Get background job ID
     * 
     * @return int|null
     */
    public function getJobId(): ?int
    {
        return $this->jobId;
    }
    
    /**
     * Set background job ID (called by service)
     * 
     * @param int $jobId
     * @return void
     */
    public function setJobId(int $jobId): void
    {
        $this->jobId = $jobId;
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
     * Get batch size
     * 
     * @return int
     */
    public function getBatchSize(): int
    {
        return count($this->productIds);
    }
    
    /**
     * Check if batch is large (requires background processing)
     * 
     * @return bool
     */
    public function isLargeBatch(): bool
    {
        return $this->getBatchSize() > 100;
    }
    
    /**
     * Get action description for logging
     * 
     * @return string
     */
    public function getActionDescription(): string
    {
        $actionMap = [
            ProductBulkActionType::PUBLISH->value => 'Publish',
            ProductBulkActionType::UNPUBLISH->value => 'Unpublish',
            ProductBulkActionType::DELETE->value => 'Delete',
            ProductBulkActionType::CHANGE_CATEGORY->value => 'Change Category',
            ProductBulkActionType::CHANGE_PRICE->value => 'Change Price',
            ProductBulkActionType::ADD_TAGS->value => 'Add Tags',
            ProductBulkActionType::EXPORT->value => 'Export',
            ProductBulkActionType::DUPLICATE->value => 'Duplicate',
        ];
        
        return $actionMap[$this->action->value] ?? $this->action->value;
    }
    
    /**
     * Convert DTO to array for logging/API response
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'action_description' => $this->getActionDescription(),
            'product_ids' => $this->productIds,
            'batch_size' => $this->getBatchSize(),
            'user_id' => $this->userId,
            'parameters' => $this->parameters,
            'process_in_background' => $this->processInBackground,
            'is_large_batch' => $this->isLargeBatch(),
            'job_id' => $this->jobId,
            'requested_at' => $this->requestedAt->format('c'),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent
        ];
    }
    
    /**
     * Create audit log message
     * 
     * @return string
     */
    public function toAuditMessage(): string
    {
        return sprintf(
            'Bulk action "%s" requested on %d products by user %d',
            $this->getActionDescription(),
            $this->getBatchSize(),
            $this->userId
        );
    }
}