<?php

namespace App\Exceptions;

use Throwable;

class AdminNotFoundException extends DomainException
{
    protected const ERROR_CODE = 'ADMIN_NOT_FOUND';
    protected int $httpStatusCode = 404;

    private ?int $adminId = null;
    private ?string $identifier = null;
    private ?string $searchMethod = null;

    public function __construct(
        string $message = 'Admin not found',
        array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $details, $previous);
    }

    /**
     * Create exception for admin ID not found
     */
    public static function forId(int $adminId, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Admin with ID %d not found%s',
            $adminId,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'admin_id' => $adminId,
            'search_method' => 'id',
            'with_trashed' => $withTrashed,
        ]);

        $exception->adminId = $adminId;
        $exception->searchMethod = 'id';

        return $exception;
    }

    /**
     * Create exception for username not found
     */
    public static function forUsername(string $username, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Admin with username "%s" not found%s',
            $username,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'username' => $username,
            'search_method' => 'username',
            'with_trashed' => $withTrashed,
        ]);

        $exception->identifier = $username;
        $exception->searchMethod = 'username';

        return $exception;
    }

    /**
     * Create exception for email not found
     */
    public static function forEmail(string $email, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Admin with email "%s" not found%s',
            $email,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'email' => $email,
            'search_method' => 'email',
            'with_trashed' => $withTrashed,
        ]);

        $exception->identifier = $email;
        $exception->searchMethod = 'email';

        return $exception;
    }

    /**
     * Create exception for identifier (username or email) not found
     */
    public static function forIdentifier(string $identifier, bool $withTrashed = false): self
    {
        $message = sprintf(
            'Admin with identifier "%s" not found%s',
            $identifier,
            $withTrashed ? ' (including trashed)' : ''
        );

        $exception = new self($message, [
            'identifier' => $identifier,
            'search_method' => 'identifier',
            'with_trashed' => $withTrashed,
        ]);

        $exception->identifier = $identifier;
        $exception->searchMethod = 'identifier';

        return $exception;
    }

    /**
     * Get the admin ID that was searched for
     */
    public function getAdminId(): ?int
    {
        return $this->adminId;
    }

    /**
     * Get the identifier (username/email) that was searched for
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
     * Check if search was by ID
     */
    public function isIdSearch(): bool
    {
        return $this->searchMethod === 'id';
    }

    /**
     * Check if search was by username
     */
    public function isUsernameSearch(): bool
    {
        return $this->searchMethod === 'username';
    }

    /**
     * Check if search was by email
     */
    public function isEmailSearch(): bool
    {
        return $this->searchMethod === 'email';
    }

    /**
     * Get suggestions for similar usernames/emails (placeholder)
     */
    public function getSuggestions(): array
    {
        if (!$this->identifier) {
            return [];
        }

        return [
            'message' => 'Check for typos or try a different identifier',
            'search_term' => $this->identifier,
        ];
    }

    /**
     * Convert to array for API response
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        $data['admin_id'] = $this->adminId;
        $data['identifier'] = $this->identifier;
        $data['search_method'] = $this->searchMethod;
        $data['suggestions'] = $this->getSuggestions();

        return $data;
    }

    /**
     * Convert to log context
     */
    public function toLogContext(): array
    {
        $context = parent::toLogContext();

        $context['admin_id'] = $this->adminId;
        $context['identifier'] = $this->identifier;
        $context['search_method'] = $this->searchMethod;
        $context['exception_type'] = 'AdminNotFoundException';

        return $context;
    }
}
