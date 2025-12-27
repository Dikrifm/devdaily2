<?php

namespace App\Contracts;

use App\DTOs\Requests\Auth\LoginRequest;
use App\DTOs\Responses\AdminResponse;
use App\DTOs\Responses\Auth\LoginResponse;
use App\DTOs\Responses\Auth\SessionResponse;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;

/**
 * Authentication Service Interface
 * 
 * Business Orchestrator Layer (Layer 5): Contract for authentication, session management,
 * and security operations. Defines protocol for login, logout, session validation,
 * and security-related functions.
 *
 * @package App\Contracts
 */
interface AuthInterface extends BaseInterface
{
    // ==================== AUTHENTICATION OPERATIONS ====================

    /**
     * Authenticate admin credentials and create session
     *
     * @param LoginRequest $request
     * @return LoginResponse
     * @throws ValidationException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function login(LoginRequest $request): LoginResponse;

    /**
     * Logout current admin and invalidate session
     *
     * @param string $sessionId
     * @return bool
     * @throws DomainException
     */
    public function logout(string $sessionId): bool;

    /**
     * Logout admin from all devices
     *
     * @param int $adminId
     * @param string|null $reason
     * @return int Number of sessions invalidated
     * @throws NotFoundException
     * @throws DomainException
     */
    public function logoutAll(int $adminId, ?string $reason = null): int;

    /**
     * Validate and refresh authentication session
     *
     * @param string $sessionId
     * @param string $refreshToken
     * @return LoginResponse
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function refreshSession(string $sessionId, string $refreshToken): LoginResponse;

    /**
     * Validate authentication token (JWT/API Token)
     *
     * @param string $token
     * @param string $tokenType jwt|api|session
     * @return AdminResponse
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function validateToken(string $token, string $tokenType = 'jwt'): AdminResponse;

    /**
     * Invalidate authentication token
     *
     * @param string $token
     * @param string $tokenType
     * @param string|null $reason
     * @return bool
     */
    public function invalidateToken(string $token, string $tokenType = 'jwt', ?string $reason = null): bool;

    // ==================== SESSION MANAGEMENT ====================

    /**
     * Get active session by ID
     *
     * @param string $sessionId
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getSession(string $sessionId): SessionResponse;

    /**
     * Get all active sessions for admin
     *
     * @param int $adminId
     * @param bool $includeExpired
     * @return array<SessionResponse>
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getAdminSessions(int $adminId, bool $includeExpired = false): array;

    /**
     * Get current active session for admin
     *
     * @param int $adminId
     * @return SessionResponse|null
     * @throws NotFoundException
     */
    public function getCurrentSession(int $adminId): ?SessionResponse;

    /**
     * Create new session for admin (passwordless/impersonation)
     *
     * @param int $adminId
     * @param array<string, mixed> $sessionData
     * @param bool $requirePassword
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function createSession(int $adminId, array $sessionData = [], bool $requirePassword = false): SessionResponse;

    /**
     * Update session data/metadata
     *
     * @param string $sessionId
     * @param array<string, mixed> $updates
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function updateSession(string $sessionId, array $updates): SessionResponse;

    /**
     * Extend session lifetime
     *
     * @param string $sessionId
     * @param int $extensionSeconds
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function extendSession(string $sessionId, int $extensionSeconds = 3600): SessionResponse;

    /**
     * Terminate session by ID
     *
     * @param string $sessionId
     * @param string|null $reason
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function terminateSession(string $sessionId, ?string $reason = null): bool;

    /**
     * Terminate all expired sessions
     *
     * @param int $olderThanSeconds
     * @return int Number of sessions terminated
     */
    public function cleanupExpiredSessions(int $olderThanSeconds = 86400): int;

    // ==================== PASSWORD MANAGEMENT ====================

    /**
     * Change admin password with validation
     *
     * @param int $adminId
     * @param string $currentPassword
     * @param string $newPassword
     * @param bool $logoutOtherDevices
     * @return bool
     * @throws NotFoundException
     * @throws ValidationException
     * @throws DomainException
     */
    public function changePassword(
        int $adminId,
        string $currentPassword,
        string $newPassword,
        bool $logoutOtherDevices = false
    ): bool;

    /**
     * Reset admin password (admin-initiated)
     *
     * @param int $adminId
     * @param string $newPassword
     * @param bool $forceChangeOnLogin
     * @param string|null $reason
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function resetPassword(
        int $adminId,
        string $newPassword,
        bool $forceChangeOnLogin = true,
        ?string $reason = null
    ): bool;

    /**
     * Request password reset (self-service)
     *
     * @param string $identifier Username or email
     * @return array{
     *     token: string,
     *     expires_at: string,
     *     delivery_method: string
     * }
     * @throws NotFoundException
     * @throws DomainException
     */
    public function requestPasswordReset(string $identifier): array;

    /**
     * Verify password reset token
     *
     * @param string $token
     * @return array{
     *     valid: bool,
     *     admin_id: int|null,
     *     expires_at: string|null
     * }
     */
    public function verifyPasswordResetToken(string $token): array;

    /**
     * Complete password reset with token
     *
     * @param string $token
     * @param string $newPassword
     * @return bool
     * @throws ValidationException
     * @throws DomainException
     */
    public function completePasswordReset(string $token, string $newPassword): bool;

    /**
     * Check if password meets policy requirements
     *
     * @param string $password
     * @param int|null $adminId
     * @return array{
     *     valid: bool,
     *     score: int,
     *     feedback: array<string>,
     *     meets_policy: bool
     * }
     */
    public function validatePasswordStrength(string $password, ?int $adminId = null): array;

    /**
     * Get password policy configuration
     *
     * @return array{
     *     min_length: int,
     *     require_uppercase: bool,
     *     require_lowercase: bool,
     *     require_numbers: bool,
     *     require_special_chars: bool,
     *     prevent_reuse: int,
     *     expiry_days: int|null
     * }
     */
    public function getPasswordPolicy(): array;

    // ==================== ACCOUNT LOCKOUT & SECURITY ====================

    /**
     * Check if admin account is locked
     *
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     */
    public function isAccountLocked(int $adminId): bool;

    /**
     * Get account lockout status with details
     *
     * @param int $adminId
     * @return array{
     *     locked: bool,
     *     remaining_attempts: int,
     *     lockout_expires: string|null,
     *     last_failed_attempt: string|null
     * }
     * @throws NotFoundException
     */
    public function getAccountLockoutStatus(int $adminId): array;

    /**
     * Unlock admin account
     *
     * @param int $adminId
     * @param string|null $reason
     * @return bool
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function unlockAccount(int $adminId, ?string $reason = null): bool;

    /**
     * Record failed login attempt
     *
     * @param int $adminId
     * @param array<string, mixed> $attemptData
     * @return array{
     *     attempts_remaining: int,
     *     locked: bool,
     *     lockout_expires: string|null
     * }
     * @throws NotFoundException
     */
    public function recordFailedAttempt(int $adminId, array $attemptData = []): array;

    /**
     * Reset failed login attempts counter
     *
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     */
    public function resetFailedAttempts(int $adminId): bool;

    /**
     * Get failed login attempts history
     *
     * @param int $adminId
     * @param int $limit
     * @return array<array{
     *     timestamp: string,
     *     ip_address: string|null,
     *     user_agent: string|null,
     *     reason: string|null
     * }>
     * @throws NotFoundException
     */
    public function getFailedAttemptsHistory(int $adminId, int $limit = 10): array;

    /**
     * Check if admin needs to re-authenticate (for sensitive operations)
     *
     * @param int $adminId
     * @param string $operation
     * @return bool
     * @throws NotFoundException
     */
    public function requiresReauthentication(int $adminId, string $operation): bool;

    // ==================== TWO-FACTOR AUTHENTICATION ====================

    /**
     * Check if 2FA is enabled for admin
     *
     * @param int $adminId
     * @return bool
     * @throws NotFoundException
     */
    public function isTwoFactorEnabled(int $adminId): bool;

    /**
     * Enable two-factor authentication
     *
     * @param int $adminId
     * @param string $verificationCode
     * @param string $secret (optional - generated if not provided)
     * @return array{
     *     enabled: bool,
     *     backup_codes: array<string>,
     *     recovery_codes: array<string>
     * }
     * @throws NotFoundException
     * @throws ValidationException
     * @throws DomainException
     */
    public function enableTwoFactor(int $adminId, string $verificationCode, string $secret = ''): array;

    /**
     * Disable two-factor authentication
     *
     * @param int $adminId
     * @param string $verificationCode
     * @param string|null $reason
     * @return bool
     * @throws NotFoundException
     * @throws ValidationException
     * @throws DomainException
     */
    public function disableTwoFactor(int $adminId, string $verificationCode, ?string $reason = null): bool;

    /**
     * Generate new two-factor secret
     *
     * @param int $adminId
     * @return array{
     *     secret: string,
     *     qr_code_url: string,
     *     provisioning_url: string
     * }
     * @throws NotFoundException
     * @throws DomainException
     */
    public function generateTwoFactorSecret(int $adminId): array;

    /**
     * Verify two-factor authentication code
     *
     * @param int $adminId
     * @param string $code
     * @param bool $isBackupCode
     * @return bool
     * @throws NotFoundException
     * @throws DomainException
     */
    public function verifyTwoFactorCode(int $adminId, string $code, bool $isBackupCode = false): bool;

    /**
     * Generate new backup codes
     *
     * @param int $adminId
     * @param int $count
     * @return array<string>
     * @throws NotFoundException
     * @throws DomainException
     */
    public function generateBackupCodes(int $adminId, int $count = 10): array;

    /**
     * Validate backup code
     *
     * @param int $adminId
     * @param string $code
     * @return bool
     * @throws NotFoundException
     */
    public function validateBackupCode(int $adminId, string $code): bool;

    // ==================== API TOKEN MANAGEMENT ====================

    /**
     * Generate API token for admin
     *
     * @param int $adminId
     * @param string $name
     * @param array<string> $scopes
     * @param \DateTimeInterface|null $expiresAt
     * @return array{
     *     token: string,
     *     token_id: string,
     *     expires_at: string|null,
     *     scopes: array<string>
     * }
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function generateApiToken(
        int $adminId,
        string $name,
        array $scopes = [],
        ?\DateTimeInterface $expiresAt = null
    ): array;

    /**
     * Revoke API token
     *
     * @param string $token
     * @param string|null $reason
     * @return bool
     * @throws NotFoundException
     */
    public function revokeApiToken(string $token, ?string $reason = null): bool;

    /**
     * Revoke all API tokens for admin
     *
     * @param int $adminId
     * @param string|null $reason
     * @return int Number of tokens revoked
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function revokeAllApiTokens(int $adminId, ?string $reason = null): int;

    /**
     * Get API tokens for admin
     *
     * @param int $adminId
     * @param bool $includeRevoked
     * @return array<array{
     *     id: string,
     *     name: string,
     *     last_used: string|null,
     *     created_at: string,
     *     expires_at: string|null,
     *     scopes: array<string>,
     *     revoked: bool
     * }>
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getApiTokens(int $adminId, bool $includeRevoked = false): array;

    /**
     * Validate API token and return admin data
     *
     * @param string $token
     * @param array<string> $requiredScopes
     * @return array{
     *     valid: bool,
     *     admin: array|null,
     *     scopes: array<string>,
     *     expires_at: string|null
     * }
     */
    public function validateApiToken(string $token, array $requiredScopes = []): array;

    // ==================== SECURITY AUDIT & MONITORING ====================

    /**
     * Get authentication security events for admin
     *
     * @param int $adminId
     * @param int $days
     * @return array<array{
     *     timestamp: string,
     *     event_type: string,
     *     ip_address: string|null,
     *     user_agent: string|null,
     *     location: array<string, mixed>|null,
     *     success: bool
     * }>
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getSecurityEvents(int $adminId, int $days = 30): array;

    /**
     * Check for suspicious login activity
     *
     * @param int $adminId
     * @param array<string, mixed> $loginData
     * @return array{
     *     suspicious: bool,
     *     risk_score: int,
     *     factors: array<string>,
     *     recommendations: array<string>
     * }
     * @throws NotFoundException
     */
    public function detectSuspiciousActivity(int $adminId, array $loginData): array;

    /**
     * Get login statistics and patterns
     *
     * @param int|null $adminId
     * @param int $days
     * @return array{
     *     total_logins: int,
     *     failed_attempts: int,
     *     successful_logins: int,
     *     avg_login_time: string,
     *     common_locations: array<string, int>,
     *     device_distribution: array<string, int>,
     *     time_distribution: array<string, int>
     * }
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function getLoginStatistics(?int $adminId = null, int $days = 30): array;

    /**
     * Generate security report for admin
     *
     * @param int $adminId
     * @param string $period
     * @return array{
     *     report_id: string,
     *     generated_at: string,
     *     period: array{start: string, end: string},
     *     summary: array<string, mixed>,
     *     recommendations: array<string>
     * }
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function generateSecurityReport(int $adminId, string $period = 'month'): array;

    // ==================== IMPERSONATION & DELEGATION ====================

    /**
     * Start impersonation session (super admin only)
     *
     * @param int $impersonatorId
     * @param int $targetAdminId
     * @param string $reason
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function startImpersonation(int $impersonatorId, int $targetAdminId, string $reason): SessionResponse;

    /**
     * Stop impersonation and restore original session
     *
     * @param string $sessionId
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function stopImpersonation(string $sessionId): SessionResponse;

    /**
     * Check if session is impersonated
     *
     * @param string $sessionId
     * @return array{
     *     impersonated: bool,
     *     original_admin_id: int|null,
     *     impersonator_admin_id: int|null,
     *     started_at: string|null
     * }
     * @throws NotFoundException
     */
    public function checkImpersonation(string $sessionId): array;

    /**
     * Delegate authentication to another admin (temporary)
     *
     * @param int $delegatorId
     * @param int $delegateId
     * @param array<string> $permissions
     * @param \DateTimeInterface $expiresAt
     * @param string $reason
     * @return array{
     *     delegation_id: string,
     *     token: string,
     *     expires_at: string
     * }
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function delegateAuthentication(
        int $delegatorId,
        int $delegateId,
        array $permissions,
        \DateTimeInterface $expiresAt,
        string $reason
    ): array;

    // ==================== SESSION VALIDATION & HEALTH ====================

    /**
     * Validate session health and integrity
     *
     * @param string $sessionId
     * @return array{
     *     valid: bool,
     *     health_score: int,
     *     issues: array<string>,
     *     recommendations: array<string>
     * }
     */
    public function validateSessionHealth(string $sessionId): array;

    /**
     * Check session expiration and renewal requirements
     *
     * @param string $sessionId
     * @return array{
     *     expires_in: int,
     *     needs_renewal: bool,
     *     renewal_deadline: string|null
     * }
     * @throws NotFoundException
     */
    public function checkSessionExpiration(string $sessionId): array;

    /**
     * Rotate session tokens (security best practice)
     *
     * @param string $sessionId
     * @param bool $invalidateOldTokens
     * @return SessionResponse
     * @throws NotFoundException
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function rotateSessionTokens(string $sessionId, bool $invalidateOldTokens = true): SessionResponse;

    // ==================== CONFIGURATION & SETTINGS ====================

    /**
     * Get authentication configuration
     *
     * @return array{
     *     session_timeout: int,
     *     max_login_attempts: int,
     *     lockout_duration: int,
     *     require_2fa: bool,
     *     password_policy: array<string, mixed>,
     *     allowed_ip_ranges: array<string>,
     *     device_management: array<string, mixed>
     * }
     */
    public function getAuthConfiguration(): array;

    /**
     * Update authentication configuration
     *
     * @param array<string, mixed> $config
     * @return array{
     *     updated: bool,
     *     changes: array<string, array{old: mixed, new: mixed}>
     * }
     * @throws ValidationException
     * @throws DomainException
     * @throws AuthorizationException
     */
    public function updateAuthConfiguration(array $config): array;

    /**
     * Get available authentication methods
     *
     * @return array<string, string>
     */
    public function getAuthMethods(): array;

    /**
     * Check if authentication method is enabled
     *
     * @param string $method
     * @return bool
     */
    public function isAuthMethodEnabled(string $method): bool;

    // ==================== UTILITY & HELPER METHODS ====================

    /**
     * Generate secure random token
     *
     * @param int $length
     * @param string $type
     * @return string
     */
    public function generateSecureToken(int $length = 32, string $type = 'alphanumeric'): string;

    /**
     * Hash sensitive data (passwords, tokens)
     *
     * @param string $data
     * @param array $options
     * @return string
     */
    public function hashData(string $data, array $options = []): string;

    /**
     * Verify hashed data
     *
     * @param string $data
     * @param string $hash
     * @return bool
     */
    public function verifyHash(string $data, string $hash): bool;

    /**
     * Get authentication service health status
     *
     * @return array{
     *     status: string,
     *     active_sessions: int,
     *     failed_logins_last_hour: int,
     *     lockouts_active: int,
     *     token_expiration_check: bool,
     *     warnings: array<string>
     * }
     */
    public function getAuthHealthStatus(): array;

    /**
     * Clear authentication cache
     *
     * @param int|null $adminId
     * @return bool
     */
    public function clearAuthCache(?int $adminId = null): bool;
}