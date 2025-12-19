<?php

namespace App\Exceptions;

use Throwable;

/**
 * Custom InvalidArgumentException dengan fitur tambahan
 * Extends dari built-in InvalidArgumentException
 */
class InvalidArgumentException extends \InvalidArgumentException
{
    /**
     * Error code untuk kategorisasi
     */
    protected string $errorCode = 'INVALID_ARGUMENT';

    /**
     * Context data tambahan
     */
    protected array $context = [];

    /**
     * HTTP status code yang sesuai
     */
    protected int $httpStatusCode = 400;

    /**
     * Constructor dengan parameter tambahan
     */
    public function __construct(
        string $message = '',
        string $errorCode = 'INVALID_ARGUMENT',
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->context = $context;

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get error code
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get context data
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get HTTP status code
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Set HTTP status code
     */
    public function setHttpStatusCode(int $httpStatusCode): self
    {
        $this->httpStatusCode = $httpStatusCode;
        return $this;
    }

    /**
     * Create instance untuk argument yang tidak valid
     */
    public static function forInvalidValue(string $argumentName, $actualValue, $expectedType): self
    {
        return new self(
            sprintf(
                'Invalid value for argument "%s": expected %s, got %s',
                $argumentName,
                $expectedType,
                is_object($actualValue) ? get_class($actualValue) : gettype($actualValue)
            ),
            'INVALID_ARGUMENT_VALUE',
            [
                'argument' => $argumentName,
                'expected_type' => $expectedType,
                'actual_value' => $actualValue,
                'actual_type' => is_object($actualValue) ? get_class($actualValue) : gettype($actualValue)
            ]
        );
    }

    /**
     * Create instance untuk argument yang required tapi kosong
     */
    public static function forMissingArgument(string $argumentName): self
    {
        return new self(
            sprintf('Missing required argument: %s', $argumentName),
            'MISSING_REQUIRED_ARGUMENT',
            ['argument' => $argumentName]
        );
    }

    /**
     * Create instance untuk nilai di luar range
     */
    public static function forOutOfRange(string $argumentName, $value, $min, $max): self
    {
        return new self(
            sprintf(
                'Argument "%s" value %s is out of range. Must be between %s and %s',
                $argumentName,
                $value,
                $min,
                $max
            ),
            'ARGUMENT_OUT_OF_RANGE',
            [
                'argument' => $argumentName,
                'value' => $value,
                'min' => $min,
                'max' => $max
            ]
        );
    }

    /**
     * Create instance untuk nilai yang tidak diizinkan
     */
    public static function forInvalidOption(string $argumentName, $value, array $allowedValues): self
    {
        return new self(
            sprintf(
                'Invalid value "%s" for argument "%s". Allowed values: %s',
                $value,
                $argumentName,
                implode(', ', $allowedValues)
            ),
            'INVALID_OPTION',
            [
                'argument' => $argumentName,
                'value' => $value,
                'allowed_values' => $allowedValues
            ]
        );
    }

    /**
     * Convert to array untuk response API
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'context' => $this->context,
                'http_status' => $this->httpStatusCode
            ]
        ];
    }

    /**
     * Convert to JSON
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Get data untuk logging
     */
    public function toLogContext(): array
    {
        return [
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }

    /**
     * Cek apakah ini error client side
     */
    public function isClientError(): bool
    {
        return $this->httpStatusCode >= 400 && $this->httpStatusCode < 500;
    }

    /**
     * Cek apakah ini error server side
     */
    public function isServerError(): bool
    {
        return $this->httpStatusCode >= 500;
    }
}
