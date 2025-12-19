<?php

namespace App\Exceptions;

use App\Entities\BaseEntity;
use Throwable;

/**
 * Validation Exception
 *
 * Thrown when input validation or business rule validation fails.
 * This is a domain exception that represents validation failures.
 *
 * @package App\Exceptions
 */
class ValidationException extends DomainException
{
    /**
     * Error code for machine-readable identification
     */
    protected const ERROR_CODE = 'VALIDATION_ERROR';

    /**
     * Validation errors organized by field
     *
     * @var array
     */
    protected array $errors = [];

    /**
     * ValidationException constructor
     *
     * @param string $message Custom message or default will be used
     * @param array $errors Validation errors array [field => [messages]]
     * @param array $details Additional context about the failure
     * @param int $code HTTP status code (default: 422)
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        array $details = [],
        int $code = 422,
        ?Throwable $previous = null
    ) {
        $this->errors = $errors;

        $details = array_merge([
            'entity' => 'Validation',
            'timestamp' => date('Y-m-d H:i:s'),
            'error_count' => $this->countErrors(),
        ], $details);

        parent::__construct(
            $message,
            self::ERROR_CODE,
            $details,
            $code,
            $previous
        );
    }

    /**
     * Create exception from CodeIgniter 4 validation errors
     *
     * @param array $validationErrors CI4 validation errors
     * @param string $entityName Name of entity being validated
     * @return static
     */
    public static function fromCodeIgniterValidation(array $validationErrors, string $entityName = 'Entity'): self
    {
        $errors = [];

        foreach ($validationErrors as $field => $message) {
            $errors[$field] = is_array($message) ? $message : [$message];
        }

        return new self(
            sprintf('%s validation failed', $entityName),
            $errors,
            [
                'validation_source' => 'codeigniter',
                'entity' => $entityName,
            ]
        );
    }

    /**
     * Create exception from entity validation
     *
     * @param BaseEntity $entity The entity that failed validation
     * @param array $validationResult Validation result from entity->validate()
     * @return static
     */
    public static function fromEntityValidation(BaseEntity $entity, array $validationResult): self
    {
        $errors = [];

        // Group errors by field if possible
        foreach ($validationResult['errors'] as $error) {
            // Try to extract field name from error message
            // This is simplistic - you might want more sophisticated parsing
            $field = 'general';

            // Example: "Name cannot be empty" -> field = "name"
            if (preg_match('/^([A-Za-z_]+)/', $error, $matches)) {
                $field = strtolower($matches[1]);
            }

            $errors[$field][] = $error;
        }

        return new self(
            sprintf('%s validation failed', $entity->getEntityType()),
            $errors,
            [
                'validation_source' => 'entity',
                'entity_type' => $entity->getEntityType(),
                'entity_id' => $entity->getId(),
                'is_new' => $entity->isNew(),
            ]
        );
    }

    /**
     * Create exception for field-specific validation
     *
     * @param string $field Field name that failed validation
     * @param string $message Error message
     * @param array $additionalErrors Additional field errors
     * @return static
     */
    public static function forField(string $field, string $message, array $additionalErrors = []): self
    {
        $errors = [
            $field => [$message]
        ];

        foreach ($additionalErrors as $additionalField => $additionalMessage) {
            $errors[$additionalField][] = $additionalMessage;
        }

        return new self(
            sprintf('Field "%s" validation failed', $field),
            $errors,
            [
                'validation_source' => 'field_specific',
                'failed_field' => $field,
            ]
        );
    }

    /**
     * Create exception for business rule validation
     *
     * @param string $businessRule Name of business rule
     * @param string $message Error message
     * @param array $context Additional context about the violation
     * @return static
     */
    public static function forBusinessRule(string $businessRule, string $message, array $context = []): self
    {
        return new self(
            $message,
            [
                'business_rules' => [$message]
            ],
            array_merge([
                'validation_source' => 'business_rule',
                'business_rule' => $businessRule,
                'rule_context' => $context,
            ], $context)
        );
    }

    /**
     * Get all validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field
     *
     * @param string $field
     * @return array
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if a specific field has errors
     *
     * @param string $field
     * @return bool
     */
    public function hasFieldErrors(string $field): bool
    {
        return !empty($this->errors[$field]);
    }

    /**
     * Count total number of validation errors
     *
     * @return int
     */
    public function countErrors(): int
    {
        $count = 0;

        foreach ($this->errors as $fieldErrors) {
            $count += count($fieldErrors);
        }

        return $count;
    }

    /**
     * Get all fields that have errors
     *
     * @return array
     */
    public function getErrorFields(): array
    {
        return array_keys($this->errors);
    }

    /**
     * Merge another validation exception into this one
     *
     * @param ValidationException $other
     * @return self
     */
    public function merge(ValidationException $other): self
    {
        foreach ($other->getErrors() as $field => $errors) {
            if (!isset($this->errors[$field])) {
                $this->errors[$field] = [];
            }

            $this->errors[$field] = array_merge($this->errors[$field], $errors);
        }

        // Update error count in details
        $this->details['error_count'] = $this->countErrors();

        return $this;
    }

    /**
     * Convert to log context for structured logging
     *
     * @return array
     */
    public function toLogContext(): array
    {
        return [
            'exception' => self::class,
            'error_code' => self::ERROR_CODE,
            'error_count' => $this->countErrors(),
            'error_fields' => $this->getErrorFields(),
            'http_code' => $this->getCode(),
            'details' => $this->getDetails(),
            'validation_errors' => $this->getErrors(),
        ];
    }

    /**
     * Create a simplified API response structure
     * Override parent to include validation-specific details
     *
     * @return array
     */
    public function toArray(): array
    {
        $baseArray = parent::toArray();

        // Add validation-specific information
        $baseArray['error']['type'] = 'validation_error';
        $baseArray['error']['validation_errors'] = $this->getErrors();
        $baseArray['error']['field_count'] = count($this->getErrorFields());
        $baseArray['error']['total_errors'] = $this->countErrors();

        // Remove generic details if we have structured validation errors
        if (!empty($this->errors)) {
            unset($baseArray['error']['details']);
        }

        return $baseArray;
    }

    /**
     * Check if this is a simple validation (single field)
     *
     * @return bool
     */
    public function isSimpleValidation(): bool
    {
        return count($this->errors) === 1 && count(reset($this->errors)) === 1;
    }

    /**
     * Get first error message (useful for simple validations)
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        if (empty($this->errors)) {
            return null;
        }

        $firstFieldErrors = reset($this->errors);
        return reset($firstFieldErrors) ?: null;
    }

    /**
     * Get first field with error (useful for UI focus)
     *
     * @return string|null
     */
    public function getFirstErrorField(): ?string
    {
        $errorFields = $this->getErrorFields();
        return !empty($errorFields) ? $errorFields[0] : null;
    }
}
