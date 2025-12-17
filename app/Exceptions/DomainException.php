<?php

namespace App\Exceptions;

use Throwable;

/**
 * Domain Exception Base Class
 * 
 * Foundation for all domain/business exceptions in the system.
 * Provides structured error information for API responses and logging.
 * 
 * @package App\Exceptions
 */
class DomainException extends \RuntimeException
{
    /**
     * Machine-readable error code for client handling
     * 
     * @var string
     */
    protected string $errorCode = 'DOMAIN_ERROR';

    /**
     * Additional context about the error
     * 
     * @var array
     */
    protected array $details = [];

    /**
     * HTTP status code for API responses
     * 
     * @var int
     */
    protected int $httpStatusCode = 400;

    /**
     * DomainException constructor
     * 
     * @param string $message Human-readable error message
     * @param string $errorCode Machine-readable error code
     * @param array $details Additional context about the error
     * @param int $httpStatusCode HTTP status code (default: 400)
     * @param Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'A domain error occurred',
        string $errorCode = 'DOMAIN_ERROR',
        array $details = [],
        int $httpStatusCode = 400,
        ?Throwable $previous = null
    ) {
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->httpStatusCode = $httpStatusCode;

        parent::__construct($message, 0, $previous);
    }

    /**
     * Get machine-readable error code
     * 
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get additional error details
     * 
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get HTTP status code for this exception
     * 
     * @return int
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Add additional details to the exception
     * 
     * @param array $details
     * @return self
     */
    public function withDetails(array $details): self
    {
        $this->details = array_merge($this->details, $details);
        return $this;
    }

    /**
     * Convert exception to array for API response
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'error' => [
                'code' => $this->getErrorCode(),
                'message' => $this->getMessage(),
                'details' => $this->getDetails(),
                'type' => 'domain_exception',
                'timestamp' => date('c'),
            ],
            'meta' => [
                'http_status' => $this->getHttpStatusCode(),
                'exception' => static::class,
            ]
        ];
    }

    /**
     * Convert exception to JSON for API response
     * 
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT);
    }

    /**
     * Get structured log context for monitoring
     * 
     * @return array
     */
    public function toLogContext(): array
    {
        return [
            'exception' => static::class,
            'error_code' => $this->getErrorCode(),
            'message' => $this->getMessage(),
            'details' => $this->getDetails(),
            'http_status' => $this->getHttpStatusCode(),
            'trace_id' => $this->generateTraceId(),
        ];
    }

    /**
     * Generate a unique trace ID for error tracking
     * 
     * @return string
     */
    protected function generateTraceId(): string
    {
        return uniqid('exc_', true);
    }

    /**
     * Check if this exception has specific detail key
     * 
     * @param string $key
     * @return bool
     */
    public function hasDetail(string $key): bool
    {
        return array_key_exists($key, $this->details);
    }

    /**
     * Get specific detail value
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(string $key, $default = null)
    {
        return $this->details[$key] ?? $default;
    }

    /**
     * Create a simplified string representation for logging
     * 
     * @return string
     */
    public function toString(): string
    {
        return sprintf(
            '[%s] %s (Code: %s, HTTP: %d)',
            static::class,
            $this->getMessage(),
            $this->getErrorCode(),
            $this->getHttpStatusCode()
        );
    }

    /**
     * Check if this is a client error (4xx)
     * 
     * @return bool
     */
    public function isClientError(): bool
    {
        return $this->httpStatusCode >= 400 && $this->httpStatusCode < 500;
    }

    /**
     * Check if this is a server error (5xx)
     * 
     * @return bool
     */
    public function isServerError(): bool
    {
        return $this->httpStatusCode >= 500;
    }
}