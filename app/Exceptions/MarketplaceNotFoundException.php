<?php

namespace App\Exceptions;

use Throwable;

class MarketplaceNotFoundException extends DomainException
{
    protected const ERROR_CODE = 'MARKETPLACE_NOT_FOUND';
    protected int $httpStatusCode = 404;

    private ?int $marketplaceId = null;
    private ?string $identifier = null;
    private ?string $searchMethod = null;
    private ?string $marketplaceType = null;

    public function __construct(
        string $message = 'Marketplace not found',
        array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $details, $previous);
    }

    /**
     * Create exception for marketplace ID not found
     */
    public static function forId(int $marketplaceId, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Marketplace with ID %d not found%s',
            $marketplaceId,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'marketplace_id' => $marketplaceId,
            'search_method' => 'id',
            'with_trashed' => $withTrashed,
        ]);

        $exception->marketplaceId = $marketplaceId;
        $exception->searchMethod = 'id';

        return $exception;
    }

    /**
     * Create exception for slug not found
     */
    public static function forSlug(string $slug, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Marketplace with slug "%s" not found%s',
            $slug,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'slug' => $slug,
            'search_method' => 'slug',
            'with_trashed' => $withTrashed,
        ]);

        $exception->identifier = $slug;
        $exception->searchMethod = 'slug';

        return $exception;
    }

    /**
     * Create exception for name not found
     */
    public static function forName(string $name, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Marketplace with name "%s" not found%s',
            $name,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'name' => $name,
            'search_method' => 'name',
            'with_trashed' => $withTrashed,
        ]);

        $exception->identifier = $name;
        $exception->searchMethod = 'name';

        return $exception;
    }

    /**
     * Create exception for identifier (ID or slug) not found
     */
    public static function forIdentifier($identifier, bool $withTrashed = false): self
    {
        if (is_numeric($identifier)) {
            return self::forId((int) $identifier, $withTrashed);
        }

        return self::forSlug($identifier, $withTrashed);
    }

    /**
     * Create exception for marketplace with specific type not found
     */
    public static function forType(string $type, bool $activeOnly = true): self
    {
        $message = sprintf(
            'Marketplace with type "%s" not found%s',
            $type,
            $activeOnly ? ' (active only)' : ''
        );

        $exception = new self($message, [
            'marketplace_type' => $type,
            'search_method' => 'type',
            'active_only' => $activeOnly,
        ]);

        $exception->marketplaceType = $type;
        $exception->searchMethod = 'type';

        return $exception;
    }

    /**
     * Get the marketplace ID that was searched for
     */
    public function getMarketplaceId(): ?int
    {
        return $this->marketplaceId;
    }

    /**
     * Get the identifier (slug/name) that was searched for
     */
    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    /**
     * Get the search method used
     */
    public function getSearchMethod(): ?string
    {
        return $this->searchMethod;
    }

    /**
     * Get the marketplace type that was searched for
     */
    public function getMarketplaceType(): ?string
    {
        return $this->marketplaceType;
    }

    /**
     * Check if search was by ID
     */
    public function isIdSearch(): bool
    {
        return $this->searchMethod === 'id';
    }

    /**
     * Check if search was by slug
     */
    public function isSlugSearch(): bool
    {
        return $this->searchMethod === 'slug';
    }

    /**
     * Check if search was by name
     */
    public function isNameSearch(): bool
    {
        return $this->searchMethod === 'name';
    }

    /**
     * Check if search was by type
     */
    public function isTypeSearch(): bool
    {
        return $this->searchMethod === 'type';
    }

    /**
     * Get suggestions for similar marketplaces
     */
    public function getSuggestions(): array
    {
        if (!$this->identifier && !$this->marketplaceType) {
            return [];
        }

        $suggestions = [
            'message' => 'Check for typos or try a different search term',
            'search_term' => $this->identifier ?? $this->marketplaceType,
        ];

        // Add type-specific suggestions
        if ($this->marketplaceType) {
            $suggestions['type_hint'] = sprintf(
                'Marketplace type "%s" might not be supported or registered',
                $this->marketplaceType
            );
        }

        return $suggestions;
    }

    /**
     * Get alternative marketplace names/slugs (placeholder for future implementation)
     */
    public function getAlternatives(): array
    {
        // This could be populated with actual similar marketplace names from database
        return [
            'message' => 'No alternatives found',
            'alternatives' => [],
        ];
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        $data['marketplace_id'] = $this->marketplaceId;
        $data['identifier'] = $this->identifier;
        $data['marketplace_type'] = $this->marketplaceType;
        $data['search_method'] = $this->searchMethod;
        $data['suggestions'] = $this->getSuggestions();
        $data['alternatives'] = $this->getAlternatives();

        return $data;
    }

    /**
     * Convert to log context
     */
    public function toLogContext(): array
    {
        $context = parent::toLogContext();

        $context['marketplace_id'] = $this->marketplaceId;
        $context['identifier'] = $this->identifier;
        $context['marketplace_type'] = $this->marketplaceType;
        $context['search_method'] = $this->searchMethod;
        $context['exception_type'] = 'MarketplaceNotFoundException';

        return $context;
    }
}
