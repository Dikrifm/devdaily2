<?php

namespace App\Exceptions;

/**
 * Product Not Found Exception
 *
 * Thrown when a product entity cannot be found by ID or slug.
 * This is a domain exception that represents a business rule violation.
 *
 * @package App\Exceptions
 */
class ProductNotFoundException extends DomainException
{
    /**
     * Error code for machine-readable identification
     */
    protected const ERROR_CODE = 'PRODUCT_NOT_FOUND';

    /**
     * ProductNotFoundException constructor
     *
     * @param string $message Custom message or default will be used
     * @param array $details Additional context about the failure
     * @param int $code HTTP status code (default: 404)
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = 'Product not found',
        array $details = [],
        int $code = 404,
        ?\Throwable $previous = null
    ) {
        $details = array_merge([
            'entity' => 'Product',
            'timestamp' => date('Y-m-d H:i:s'),
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
     * Create exception for product not found by ID
     *
     * @param int $productId
     * @return static
     */
    public static function forId(int $productId): self
    {
        return new self(
            sprintf('Product with ID %d not found', $productId),
            [
                'product_id' => $productId,
                'search_by' => 'id',
                'reason' => 'Product may have been deleted or does not exist'
            ]
        );
    }

    /**
     * Create exception for product not found by slug
     *
     * @param string $slug
     * @return static
     */
    public static function forSlug(string $slug): self
    {
        return new self(
            sprintf('Product with slug "%s" not found', $slug),
            [
                'slug' => $slug,
                'search_by' => 'slug',
                'reason' => 'Product may have been deleted or does not exist'
            ]
        );
    }

    /**
     * Create exception for product not found in specific status
     *
     * @param int $productId
     * @param string $status Required status
     * @return static
     */
    public static function forStatus(int $productId, string $status): self
    {
        return new self(
            sprintf('Product with ID %d not found in status: %s', $productId, $status),
            [
                'product_id' => $productId,
                'required_status' => $status,
                'search_by' => 'id_and_status',
                'reason' => 'Product exists but not in the required workflow status'
            ]
        );
    }

    /**
     * Check if this exception includes product ID in details
     *
     * @return bool
     */
    public function hasProductId(): bool
    {
        return isset($this->details['product_id']);
    }

    /**
     * Get product ID from exception details
     *
     * @return int|null
     */
    public function getProductId(): ?int
    {
        return $this->details['product_id'] ?? null;
    }

    /**
     * Get search method used
     *
     * @return string|null
     */
    public function getSearchMethod(): ?string
    {
        return $this->details['search_by'] ?? null;
    }

    /**
     * Suggest alternative actions based on exception context
     *
     * @return array
     */
    public function getSuggestions(): array
    {
        $suggestions = [
            'Check if the product ID or slug is correct',
            'Verify the product has not been archived or deleted',
            'Ensure you have proper permissions to access this product'
        ];

        if (isset($this->details['required_status'])) {
            $suggestions[] = sprintf(
                'The product needs to be in "%s" status for this operation',
                $this->details['required_status']
            );
        }

        return $suggestions;
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
            'product_id' => $this->getProductId(),
            'search_method' => $this->getSearchMethod(),
            'http_code' => $this->getCode(),
            'details' => $this->getDetails(),
            'suggestions' => $this->getSuggestions(),
        ];
    }

    /**
     * Create a simplified API response structure
     * Override parent to include more product-specific details
     *
     * @return array
     */
    public function toArray(): array
    {
        $baseArray = parent::toArray();

        // Add product-specific information
        $baseArray['error']['type'] = 'product_not_found';
        $baseArray['error']['suggestions'] = $this->getSuggestions();

        // Include search context if available
        if ($this->getSearchMethod()) {
            $baseArray['error']['search_context'] = [
                'by' => $this->getSearchMethod(),
                'value' => $this->getProductId() ?? $this->details['slug'] ?? null
            ];
        }

        return $baseArray;
    }
}
