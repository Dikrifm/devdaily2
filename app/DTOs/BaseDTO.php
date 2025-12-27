<?php

namespace App\DTOs;

use App\Enums\BaseEnum;
use App\Entities\BaseEntity;
use App\Exceptions\ValidationException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use ReflectionClass;
use ReflectionProperty;
use InvalidArgumentException;
use RuntimeException;

/**
 * Base Data Transfer Object (DTO)
 *
 * Data Transfer Protocol Layer (Layer 6): Base class for all DTOs.
 * Provides strict type safety, validation, and immutable data transfer between layers.
 *
 * @package App\DTOs
 */
abstract class BaseDTO implements JsonSerializable
{
    /**
     * Validation errors
     *
     * @var array<string, string[]>
     */
    private array $validationErrors = [];

    /**
     * Whether the DTO has been validated
     *
     * @var bool
     */
    private bool $isValidated = false;

    /**
     * Original data used to create the DTO
     *
     * @var array<string, mixed>
     */
    private array $originalData = [];

    /**
     * Constructor is protected to enforce factory pattern
     *
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException
     */
    final protected function __construct(array $data)
    {
        $this->originalData = $data;
        $this->hydrate($data);
        $this->validateTypes();
    }

    /**
     * Factory method to create DTO from array
     *
     * @param array<string, mixed> $data
     * @return static
     * @throws ValidationException
     */
    public static function fromArray(array $data): static
    {
        try {
            $dto = new static($data);
            $validationErrors = $dto->validate();
            
            if (!empty($validationErrors)) {
                throw ValidationException::forBusinessRule(
                    static::class,
                    'DTO validation failed',
                    ['errors' => $validationErrors, 'data' => $data]
                );
            }
            
            return $dto;
        } catch (InvalidArgumentException $e) {
            throw ValidationException::forBusinessRule(
                static::class,
                $e->getMessage(),
                ['data' => $data]
            );
        }
    }

    /**
     * Convert DTO to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PUBLIC);
        
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            
            // Skip properties that start with underscore
            if (strpos($propertyName, '_') === 0) {
                continue;
            }
            
            $value = $property->getValue($this);
            
            // Handle nested DTOs
            if ($value instanceof BaseDTO) {
                $result[$propertyName] = $value->toArray();
            }
            // Handle collections of DTOs
            elseif (is_array($value) && !empty($value) && $value[0] instanceof BaseDTO) {
                $result[$propertyName] = array_map(fn($dto) => $dto->toArray(), $value);
            }
            // Handle Enums
            elseif ($value instanceof BaseEnum) {
                $result[$propertyName] = $value->value;
            }
            // Handle DateTime objects
            elseif ($value instanceof DateTimeInterface) {
                $result[$propertyName] = $value->format(DateTimeInterface::ATOM);
            }
            // Handle Entities (extract identifier)
            elseif ($value instanceof BaseEntity) {
                $result[$propertyName] = $value->getId();
            }
            // Handle arrays recursively
            elseif (is_array($value)) {
                $result[$propertyName] = $this->convertArray($value);
            }
            // Handle other types
            else {
                $result[$propertyName] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Convert nested arrays recursively
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    private function convertArray(array $array): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            if ($value instanceof BaseDTO) {
                $result[$key] = $value->toArray();
            } elseif ($value instanceof BaseEnum) {
                $result[$key] = $value->value;
            } elseif ($value instanceof DateTimeInterface) {
                $result[$key] = $value->format(DateTimeInterface::ATOM);
            } elseif ($value instanceof BaseEntity) {
                $result[$key] = $value->getId();
            } elseif (is_array($value)) {
                $result[$key] = $this->convertArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Hydrate DTO properties from array data
     *
     * @param array<string, mixed> $data
     * @return void
     * @throws InvalidArgumentException
     */
    private function hydrate(array $data): void
    {
        $reflection = new ReflectionClass($this);
        
        foreach ($data as $key => $value) {
            // Convert snake_case to camelCase for property names
            $propertyName = $this->snakeToCamel($key);
            
            if (!$reflection->hasProperty($propertyName)) {
                // Skip properties that don't exist in DTO (strict mode would throw)
                continue;
            }
            
            $property = $reflection->getProperty($propertyName);
            
            // Only hydrate protected and public properties
            if ($property->isPrivate()) {
                continue;
            }
            
            $typedValue = $this->castToType($value, $property);
            $property->setValue($this, $typedValue);
        }
    }

    /**
     * Cast value to property type
     *
     * @param mixed $value
     * @param ReflectionProperty $property
     * @return mixed
     * @throws InvalidArgumentException
     */
    private function castToType(mixed $value, ReflectionProperty $property): mixed
    {
        // Handle null values
        if ($value === null) {
            // Check if property is nullable
            if (!$property->getType()?->allowsNull() && $property->hasDefaultValue() === false) {
                throw new InvalidArgumentException(
                    sprintf('Property %s::%s cannot be null', $property->getDeclaringClass()->getName(), $property->getName())
                );
            }
            return null;
        }
        
        $type = $property->getType();
        
        if ($type === null) {
            // No type declared, return as-is
            return $value;
        }
        
        $typeName = $type->getName();
        
        // Handle union types (PHP 8.0+)
        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                try {
                    return $this->castToSingleType($value, $unionType->getName(), $property);
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
            throw new InvalidArgumentException(
                sprintf('Value for %s::%s does not match any union type', $property->getDeclaringClass()->getName(), $property->getName())
            );
        }
        
        return $this->castToSingleType($value, $typeName, $property);
    }

    /**
     * Cast to single type
     *
     * @param mixed $value
     * @param string $typeName
     * @param ReflectionProperty $property
     * @return mixed
     * @throws InvalidArgumentException
     */
    private function castToSingleType(mixed $value, string $typeName, ReflectionProperty $property): mixed
    {
        // Handle built-in types
        switch ($typeName) {
            case 'int':
            case 'integer':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Property %s::%s must be integer, got %s', $property->getDeclaringClass()->getName(), $property->getName(), gettype($value))
                    );
                }
                return (int) $value;
                
            case 'float':
            case 'double':
                if (!is_numeric($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Property %s::%s must be float, got %s', $property->getDeclaringClass()->getName(), $property->getName(), gettype($value))
                    );
                }
                return (float) $value;
                
            case 'string':
                if (!is_string($value) && !is_numeric($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Property %s::%s must be string, got %s', $property->getDeclaringClass()->getName(), $property->getName(), gettype($value))
                    );
                }
                return (string) $value;
                
            case 'bool':
            case 'boolean':
                if (is_string($value)) {
                    $value = strtolower($value);
                    if ($value === 'true' || $value === '1') {
                        return true;
                    }
                    if ($value === 'false' || $value === '0') {
                        return false;
                    }
                }
                if (!is_bool($value) && !is_numeric($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Property %s::%s must be boolean, got %s', $property->getDeclaringClass()->getName(), $property->getName(), gettype($value))
                    );
                }
                return (bool) $value;
                
            case 'array':
                if (!is_array($value)) {
                    throw new InvalidArgumentException(
                        sprintf('Property %s::%s must be array, got %s', $property->getDeclaringClass()->getName(), $property->getName(), gettype($value))
                    );
                }
                return $value;
                
            case DateTime::class:
            case DateTimeImmutable::class:
            case DateTimeInterface::class:
                if (is_string($value)) {
                    try {
                        return $typeName === DateTimeImmutable::class 
                            ? new DateTimeImmutable($value)
                            : new DateTime($value);
                    } catch (\Exception $e) {
                        throw new InvalidArgumentException(
                            sprintf('Property %s::%s must be valid datetime string: %s', $property->getDeclaringClass()->getName(), $property->getName(), $e->getMessage())
                        );
                    }
                }
                if ($value instanceof DateTimeInterface) {
                    if ($typeName === DateTimeImmutable::class && $value instanceof DateTime) {
                        return DateTimeImmutable::createFromMutable($value);
                    }
                    if ($typeName === DateTime::class && $value instanceof DateTimeImmutable) {
                        return DateTime::createFromImmutable($value);
                    }
                    return $value;
                }
                throw new InvalidArgumentException(
                    sprintf('Property %s::%s must be DateTimeInterface, got %s', $property->getDeclaringClass()->getName(), $property->getName(), gettype($value))
                );
        }
        
        // Handle DTO types
        if (is_subclass_of($typeName, BaseDTO::class)) {
            if (is_array($value)) {
                return $typeName::fromArray($value);
            }
            if ($value instanceof $typeName) {
                return $value;
            }
            throw new InvalidArgumentException(
                sprintf('Property %s::%s must be array or %s, got %s', $property->getDeclaringClass()->getName(), $property->getName(), $typeName, gettype($value))
            );
        }
        
        // Handle Enum types
        if (is_subclass_of($typeName, BaseEnum::class)) {
            if (is_string($value) || is_int($value)) {
                return $typeName::from($value);
            }
            if ($value instanceof $typeName) {
                return $value;
            }
            throw new InvalidArgumentException(
                sprintf('Property %s::%s must be string, int or %s, got %s', $property->getDeclaringClass()->getName(), $property->getName(), $typeName, gettype($value))
            );
        }
        
        // Handle Entity types
        if (is_subclass_of($typeName, BaseEntity::class)) {
            if (is_int($value) || is_string($value)) {
                // Return ID - actual entity hydration should be done by Repository
                return $value;
            }
            if ($value instanceof $typeName) {
                return $value;
            }
            throw new InvalidArgumentException(
                sprintf('Property %s::%s must be int, string or %s, got %s', $property->getDeclaringClass()->getName(), $property->getName(), $typeName, gettype($value))
            );
        }
        
        // Handle class types
        if (class_exists($typeName)) {
            if ($value instanceof $typeName) {
                return $value;
            }
            throw new InvalidArgumentException(
                sprintf('Property %s::%s must be instance of %s, got %s', $property->getDeclaringClass()->getName(), $property->getName(), $typeName, gettype($value))
            );
        }
        
        // For other types, return as-is
        return $value;
    }

    /**
     * Validate DTO types and constraints
     *
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateTypes(): void
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PUBLIC);
        
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $value = $property->getValue($this);
            $type = $property->getType();
            
            // Check required properties (non-nullable, no default value)
            if ($value === null && $type !== null && !$type->allowsNull() && !$property->hasDefaultValue()) {
                $this->addValidationError($propertyName, sprintf('%s is required', $propertyName));
            }
            
            // Run property-specific validation
            $this->validateProperty($property, $value);
        }
    }

    /**
     * Validate individual property
     *
     * @param ReflectionProperty $property
     * @param mixed $value
     * @return void
     */
    private function validateProperty(ReflectionProperty $property, mixed $value): void
    {
        $propertyName = $property->getName();
        
        // Skip null values for optional properties
        if ($value === null) {
            return;
        }
        
        // Get validation rules from method annotations or custom methods
        $validationMethod = 'validate' . ucfirst($propertyName);
        if (method_exists($this, $validationMethod)) {
            $error = $this->$validationMethod($value);
            if ($error !== null) {
                $this->addValidationError($propertyName, $error);
            }
        }
        
        // Built-in validation based on type
        $type = $property->getType();
        if ($type !== null) {
            $typeName = $type->getName();
            
            switch ($typeName) {
                case 'string':
                    if (!is_string($value)) {
                        $this->addValidationError($propertyName, sprintf('Must be string, got %s', gettype($value)));
                    }
                    break;
                    
                case 'int':
                    if (!is_int($value)) {
                        $this->addValidationError($propertyName, sprintf('Must be integer, got %s', gettype($value)));
                    }
                    break;
                    
                case 'float':
                    if (!is_float($value)) {
                        $this->addValidationError($propertyName, sprintf('Must be float, got %s', gettype($value)));
                    }
                    break;
                    
                case 'bool':
                    if (!is_bool($value)) {
                        $this->addValidationError($propertyName, sprintf('Must be boolean, got %s', gettype($value)));
                    }
                    break;
                    
                case 'array':
                    if (!is_array($value)) {
                        $this->addValidationError($propertyName, sprintf('Must be array, got %s', gettype($value)));
                    }
                    break;
            }
        }
    }

    /**
     * Main validation method to be implemented by concrete DTOs
     *
     * @return array<string, string[]> Validation errors indexed by property name
     */
    abstract public function validate(): array;

    /**
     * Get all validation errors
     *
     * @return array<string, string[]>
     */
    public function getValidationErrors(): array
    {
        if (!$this->isValidated) {
            $this->validationErrors = array_merge($this->validationErrors, $this->validate());
            $this->isValidated = true;
        }
        
        return $this->validationErrors;
    }

    /**
     * Check if DTO is valid
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return empty($this->getValidationErrors());
    }

    /**
     * Get first validation error message
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        $errors = $this->getValidationErrors();
        
        foreach ($errors as $propertyErrors) {
            if (!empty($propertyErrors)) {
                return $propertyErrors[0];
            }
        }
        
        return null;
    }

    /**
     * Add validation error for a property
     *
     * @param string $property
     * @param string $message
     * @return void
     */
    protected function addValidationError(string $property, string $message): void
    {
        if (!isset($this->validationErrors[$property])) {
            $this->validationErrors[$property] = [];
        }
        
        $this->validationErrors[$property][] = $message;
    }

    /**
     * Get original data used to create DTO
     *
     * @return array<string, mixed>
     */
    public function getOriginalData(): array
    {
        return $this->originalData;
    }

    /**
     * Convert snake_case to camelCase
     *
     * @param string $input
     * @return string
     */
    private function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }

    /**
     * Convert camelCase to snake_case
     *
     * @param string $input
     * @return string
     */
    private function camelToSnake(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    /**
     * Implement JsonSerializable interface
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert DTO to JSON string
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->jsonSerialize(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * String representation of DTO
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Get DTO class name without namespace
     *
     * @return string
     */
    public function getDtoName(): string
    {
        $reflection = new ReflectionClass($this);
        return $reflection->getShortName();
    }

    /**
     * Get property value by name
     *
     * @param string $name
     * @return mixed
     * @throws RuntimeException If property doesn't exist
     */
    public function get(string $name): mixed
    {
        $propertyName = $this->snakeToCamel($name);
        
        if (!property_exists($this, $propertyName)) {
            throw new RuntimeException(
                sprintf('Property %s does not exist in %s', $name, static::class)
            );
        }
        
        $reflection = new ReflectionProperty($this, $propertyName);
        
        if ($reflection->isPrivate()) {
            throw new RuntimeException(
                sprintf('Property %s is private and cannot be accessed', $name)
            );
        }
        
        return $reflection->getValue($this);
    }

    /**
     * Check if property exists
     *
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool
    {
        $propertyName = $this->snakeToCamel($name);
        return property_exists($this, $propertyName);
    }

    /**
     * Get all property names
     *
     * @return array<string>
     */
    public function getPropertyNames(): array
    {
        $reflection = new ReflectionClass($this);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PUBLIC);
        
        return array_map(fn($prop) => $prop->getName(), $properties);
    }

    /**
     * Get DTO schema (property names with types)
     *
     * @return array<string, string>
     */
    public static function getSchema(): array
    {
        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PROTECTED | ReflectionProperty::IS_PUBLIC);
        $schema = [];
        
        foreach ($properties as $property) {
            $type = $property->getType();
            $typeName = $type ? $type->getName() : 'mixed';
            $schema[$property->getName()] = $typeName;
        }
        
        return $schema;
    }

    /**
     * Create copy of DTO with modified values
     *
     * @param array<string, mixed> $changes
     * @return static
     */
    public function with(array $changes): static
    {
        $data = array_merge($this->toArray(), $changes);
        return static::fromArray($data);
    }

    /**
     * Validate that all required fields are present in data
     *
     * @param array<string, mixed> $data
     * @param array<string> $requiredFields
     * @return void
     * @throws ValidationException
     */
    protected function validateRequiredFields(array $data, array $requiredFields): void
    {
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null) {
                throw ValidationException::forField(
                    $field,
                    sprintf('Field %s is required', $field)
                );
            }
        }
    }
}