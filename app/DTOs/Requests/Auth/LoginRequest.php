<?php

namespace App\DTOs\Requests\Auth;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use App\Validators\SlugValidator;

class LoginRequest extends BaseDTO
{
    private string $identifier;
    private string $password;
    private bool $rememberMe = false;
    private ?string $redirectUrl = null;
    private ?string $ipAddress = null;
    private ?string $userAgent = null;
    
    // Validation constants
    private const MIN_PASSWORD_LENGTH = 6;
    private const MAX_PASSWORD_LENGTH = 72; // BCrypt limit
    private const MAX_IDENTIFIER_LENGTH = 255;
    private const ALLOWED_REDIRECT_PATHS = [
        '/admin',
        '/admin/dashboard',
        '/admin/products',
        '/admin/categories',
        '/admin/links',
        '/admin/marketplaces',
    ];

    private function __construct() {}

    /**
     * Create LoginRequest from HTTP request data
     */
    public static function fromRequest(array $requestData, ?string $ipAddress = null, ?string $userAgent = null): self
    {
        $instance = new self();
        $instance->validateAndHydrate($requestData, $ipAddress, $userAgent);
        return $instance;
    }

    /**
     * Create LoginRequest with explicit values (for testing/API)
     */
    public static function create(string $identifier, string $password, bool $rememberMe = false): self
    {
        $instance = new self();
        $data = [
            'identifier' => $identifier,
            'password' => $password,
            'remember_me' => $rememberMe,
        ];
        $instance->validateAndHydrate($data);
        return $instance;
    }

    /**
     * Validate and hydrate the DTO
     */
    private function validateAndHydrate(array $data, ?string $ipAddress = null, ?string $userAgent = null): void
    {
        $errors = [];
        
        // 1. Sanitize input
        $sanitizedData = $this->sanitizeInput($data);
        
        // 2. Validate required fields
        $requiredFields = ['identifier', 'password'];
        if (!$this->validateRequiredFields($sanitizedData, $requiredFields, $errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
        
        // 3. Validate individual fields
        $this->validateIdentifier($sanitizedData['identifier'], $errors);
        $this->validatePassword($sanitizedData['password'], $errors);
        
        // 4. Validate optional fields
        $this->validateOptionalFields($sanitizedData, $errors);
        
        // 5. Validate business rules
        $this->validateBusinessRules($sanitizedData, $errors);
        
        // 6. If there are errors, throw exception
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
        
        // 7. Hydrate the object
        $this->identifier = $sanitizedData['identifier'];
        $this->password = $sanitizedData['password'];
        $this->rememberMe = (bool)($sanitizedData['remember_me'] ?? false);
        $this->redirectUrl = $this->sanitizeRedirectUrl($sanitizedData['redirect'] ?? '');
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Sanitize all input data
     */
    private function sanitizeInput(array $data): array
    {
        return [
            'identifier' => $this->sanitizeString($data['identifier'] ?? ''),
            'password' => $this->sanitizeString($data['password'] ?? ''),
            'remember_me' => $data['remember_me'] ?? $data['rememberMe'] ?? false,
            'redirect' => $this->sanitizeString($data['redirect'] ?? $data['redirect_url'] ?? ''),
        ];
    }

    /**
     * Validate identifier (email or username)
     */
    private function validateIdentifier(string $identifier, array &$errors): void
    {
        // Check length
        if (strlen($identifier) < 1) {
            $errors['identifier'] = 'Identifier is required';
            return;
        }
        
        if (strlen($identifier) > self::MAX_IDENTIFIER_LENGTH) {
            $errors['identifier'] = sprintf(
                'Identifier must be less than %d characters',
                self::MAX_IDENTIFIER_LENGTH
            );
            return;
        }
        
        // Check if it's an email
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            // Valid email format
            return;
        }
        
        // Check if it's a valid username (alphanumeric + underscore, 3-50 chars)
        if (preg_match('/^[a-zA-Z0-9_]{3,50}$/', $identifier)) {
            // Valid username format
            return;
        }
        
        // If neither email nor valid username
        $errors['identifier'] = 'Identifier must be a valid email or username (3-50 alphanumeric characters)';
    }

    /**
     * Validate password
     */
    private function validatePassword(string $password, array &$errors): void
    {
        // Check length
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = sprintf(
                'Password must be at least %d characters',
                self::MIN_PASSWORD_LENGTH
            );
            return;
        }
        
        if (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors['password'] = sprintf(
                'Password must be less than %d characters',
                self::MAX_PASSWORD_LENGTH
            );
            return;
        }
        
        // Optional: Add password strength rules for MVP
        // For now, just basic validation
    }

    /**
     * Validate optional fields
     */
    private function validateOptionalFields(array $data, array &$errors): void
    {
        // Validate remember_me is boolean
        $rememberMe = $data['remember_me'] ?? false;
        if (!is_bool($rememberMe) && !in_array(strtolower($rememberMe), ['true', 'false', '1', '0', 'on', 'off'])) {
            $errors['remember_me'] = 'Remember me must be a boolean value';
        }
        
        // Validate redirect URL if provided
        if (!empty($data['redirect'])) {
            $redirect = $this->sanitizeRedirectUrl($data['redirect']);
            if (!$this->isValidRedirectUrl($redirect)) {
                $errors['redirect'] = 'Invalid redirect URL';
            }
        }
    }

    /**
     * Validate business rules
     */
    private function validateBusinessRules(array $data, array &$errors): void
    {
        // MVP: No complex business rules for login
        // Could add things like:
        // - Check if identifier is known to be locked out
        // - Check if IP is blocked
        // etc.
    }

    /**
     * Sanitize and validate redirect URL
     */
    private function sanitizeRedirectUrl(string $url): string
    {
        $url = trim($url);
        
        // Remove any protocol or domain for security
        $url = preg_replace('/^(https?:\/\/[^\/]+)?/', '', $url);
        
        // Remove leading slash for consistency
        $url = ltrim($url, '/');
        
        return $url;
    }

    /**
     * Check if redirect URL is allowed
     */
    private function isValidRedirectUrl(string $url): bool
    {
        if (empty($url)) {
            return true;
        }
        
        // Add leading slash for comparison
        $url = '/' . $url;
        
        // Check against allowed paths
        foreach (self::ALLOWED_REDIRECT_PATHS as $allowedPath) {
            if (strpos($url, $allowedPath) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get validation rules for this DTO
     */
    public static function rules(): array
    {
        return [
            'identifier' => 'required|max_length:' . self::MAX_IDENTIFIER_LENGTH,
            'password' => 'required|min_length:' . self::MIN_PASSWORD_LENGTH . '|max_length:' . self::MAX_PASSWORD_LENGTH,
            'remember_me' => 'permit_empty|in_list[true,false,1,0,on,off]',
            'redirect' => 'permit_empty|string|max_length:500',
        ];
    }

    /**
     * Get validation messages
     */
    public static function messages(): array
    {
        return [
            'identifier' => [
                'required' => 'Email or username is required',
                'max_length' => 'Identifier is too long',
            ],
            'password' => [
                'required' => 'Password is required',
                'min_length' => 'Password must be at least {param} characters',
                'max_length' => 'Password is too long',
            ],
            'remember_me' => [
                'in_list' => 'Remember me must be true or false',
            ],
            'redirect' => [
                'max_length' => 'Redirect URL is too long',
            ],
        ];
    }

    /**
     * ==================== GETTER METHODS ====================
     */

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRememberMe(): bool
    {
        return $this->rememberMe;
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function isEmailIdentifier(): bool
    {
        return filter_var($this->identifier, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isUsernameIdentifier(): bool
    {
        return !$this->isEmailIdentifier();
    }

    /**
     * Get identifier type for logging/auditing
     */
    public function getIdentifierType(): string
    {
        return $this->isEmailIdentifier() ? 'email' : 'username';
    }

    /**
     * Convert to array for logging (without sensitive data)
     */
    public function toLogArray(): array
    {
        return [
            'identifier_type' => $this->getIdentifierType(),
            'remember_me' => $this->rememberMe,
            'redirect_url' => $this->redirectUrl,
            'has_ip_address' => !empty($this->ipAddress),
            'has_user_agent' => !empty($this->userAgent),
        ];
    }

    /**
     * Convert to array (without sensitive data)
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'remember_me' => $this->rememberMe,
            'redirect_url' => $this->redirectUrl,
            'identifier_type' => $this->getIdentifierType(),
        ];
    }

    /**
     * Convert to database array (for audit logging)
     */
    public function toDatabaseArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'identifier_type' => $this->getIdentifierType(),
            'remember_me' => (int)$this->rememberMe,
            'redirect_url' => $this->redirectUrl,
            'ip_address' => $this->ipAddress,
            'user_agent_hash' => $this->userAgent ? hash('sha256', $this->userAgent) : null,
        ];
    }

    /**
     * Get sanitized version for display
     */
    public function getSanitizedIdentifier(): string
    {
        if ($this->isEmailIdentifier()) {
            // Mask part of email for display
            $parts = explode('@', $this->identifier);
            if (count($parts) === 2) {
                $local = $parts[0];
                $domain = $parts[1];
                
                if (strlen($local) > 2) {
                    $local = substr($local, 0, 2) . '***';
                }
                
                return $local . '@' . $domain;
            }
        }
        
        // For username, just return as is
        return $this->identifier;
    }

    /**
     * Check if redirect is requested
     */
    public function hasRedirect(): bool
    {
        return !empty($this->redirectUrl);
    }

    /**
     * Get full redirect path with base URL
     */
    public function getFullRedirectUrl(string $baseUrl = ''): string
    {
        if (empty($this->redirectUrl)) {
            return $baseUrl ? rtrim($baseUrl, '/') . '/admin/dashboard' : '/admin/dashboard';
        }
        
        return $baseUrl ? rtrim($baseUrl, '/') . '/' . $this->redirectUrl : '/' . $this->redirectUrl;
    }
}