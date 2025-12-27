<?php

namespace App\Services;

use App\Contracts\AuthInterface;
use App\DTOs\Requests\Auth\LoginRequest;
use App\DTOs\Responses\AdminResponse;
use App\DTOs\Responses\Auth\LoginResponse;
use App\DTOs\Responses\Auth\SessionResponse;
use App\Entities\Admin;
use App\Enums\ProductStatus;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Validators\AuthValidator;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Authentication Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation of AuthInterface.
 * Manages authentication, session management, security, and access control.
 *
 * @package App\Services
 */
final class AuthService extends BaseService implements AuthInterface
{
    /**
     * Admin repository for admin data
     *
     * @var AdminRepositoryInterface
     */
    private AdminRepositoryInterface $adminRepository;

    /**
     * Audit log repository for security events
     *
     * @var AuditLogRepositoryInterface
     */
    private AuditLogRepositoryInterface $auditLogRepository;

    /**
     * Auth validator for business rules
     *
     * @var AuthValidator
     */
    private AuthValidator $authValidator;

    /**
     * Authentication configuration
     *
     * @var array<string, mixed>
     */
    private array $configuration;

    /**
     * Active sessions storage (in production, use Redis/database)
     *
     * @var array<string, array>
     */
    private array $sessions = [];

    /**
     * Password reset tokens (in production, use database)
     *
     * @var array<string, array>
     */
    private array $passwordResetTokens = [];

    /**
     * API tokens storage (in production, use database)
     *
     * @var array<string, array>
     */
    private array $apiTokens = [];

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db
     * @param CacheInterface $cache
     * @param AuditService $auditService
     * @param AdminRepositoryInterface $adminRepository
     * @param AuditLogRepositoryInterface $auditLogRepository
     * @param AuthValidator $authValidator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        AdminRepositoryInterface $adminRepository,
        AuditLogRepositoryInterface $auditLogRepository,
        AuthValidator $authValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->adminRepository = $adminRepository;
        $this->auditLogRepository = $auditLogRepository;
        $this->authValidator = $authValidator;
        $this->configuration = $this->loadConfiguration();
    }

    // ==================== AUTHENTICATION OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function login(LoginRequest $request): LoginResponse
    {
        // Validate request
        $validationErrors = $this->validateBusinessRules($request, ['context' => 'login']);
        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                $this->getServiceName(),
                'Login validation failed',
                $validationErrors
            );
        }

        return $this->transaction(function () use ($request) {
            // Find admin by username or email
            $admin = $this->adminRepository->findByUsernameOrEmail($request->identifier);
            
            if ($admin === null) {
                // Record failed attempt for tracking (even if admin not found)
                $this->recordAnonymousFailedAttempt($request->identifier, $request->ipAddress);
                
                throw new AuthorizationException(
                    'Invalid credentials',
                    'INVALID_CREDENTIALS'
                );
            }

            // Check if account is locked
            if ($this->isAccountLocked($admin->getId())) {
                throw new AuthorizationException(
                    'Account is locked. Please contact administrator.',
                    'ACCOUNT_LOCKED'
                );
            }

            // Check if account is active
            if (!$admin->isActive()) {
                throw new AuthorizationException(
                    'Account is inactive',
                    'ACCOUNT_INACTIVE'
                );
            }

            // Verify password
            if (!$admin->verifyPassword($request->password)) {
                // Record failed attempt
                $this->recordFailedAttempt($admin->getId(), [
                    'ip_address' => $request->ipAddress,
                    'user_agent' => $request->userAgent
                ]);

                // Check if account is now locked
                if ($this->isAccountLocked($admin->getId())) {
                    throw new AuthorizationException(
                        'Account has been locked due to too many failed attempts',
                        'ACCOUNT_LOCKED_AFTER_ATTEMPTS'
                    );
                }

                throw new AuthorizationException(
                    'Invalid credentials',
                    'INVALID_CREDENTIALS'
                );
            }

            // Check if password needs rehash
            if ($admin->passwordNeedsRehash()) {
                $admin->setPasswordWithHash($request->password);
                $this->adminRepository->save($admin);
            }

            // Reset failed attempts on successful login
            $this->resetFailedAttempts($admin->getId());

            // Check for suspicious activity
            $suspiciousActivity = $this->detectSuspiciousActivity($admin->getId(), [
                'ip_address' => $request->ipAddress,
                'user_agent' => $request->userAgent,
                'location' => $request->location
            ]);

            // Handle suspicious activity
            if ($suspiciousActivity['suspicious']) {
                $this->handleSuspiciousLogin($admin->getId(), $suspiciousActivity, $request);
                
                if ($suspiciousActivity['risk_score'] >= 70) {
                    throw new AuthorizationException(
                        'Suspicious login detected. Additional verification required.',
                        'SUSPICIOUS_LOGIN'
                    );
                }
            }

            // Check if 2FA is required
            $requires2FA = $this->isTwoFactorEnabled($admin->getId()) || 
                          ($suspiciousActivity['risk_score'] >= 50);

            if ($requires2FA && empty($request->twoFactorCode)) {
                return LoginResponse::createForTwoFactorRequired(
                    $admin->getId(),
                    $suspiciousActivity['risk_score'],
                    $suspiciousActivity['factors']
                );
            }

            // Verify 2FA code if provided
            if ($requires2FA && !empty($request->twoFactorCode)) {
                $twoFactorValid = $this->verifyTwoFactorCode($admin->getId(), $request->twoFactorCode);
                
                if (!$twoFactorValid) {
                    throw new AuthorizationException(
                        'Invalid two-factor authentication code',
                        'INVALID_2FA_CODE'
                    );
                }
            }

            // Create session
            $sessionData = [
                'ip_address' => $request->ipAddress,
                'user_agent' => $request->userAgent,
                'location' => $request->location,
                'device_info' => $this->extractDeviceInfo($request->userAgent),
                'login_time' => Time::now()->toDateTimeString()
            ];

            $session = $this->createSession($admin->getId(), $sessionData, false);
            
            // Update admin last login
            $this->adminRepository->recordSuccessfulLogin($admin->getId());

            // Clear relevant cache
            $this->queueCacheOperation('admin:' . $admin->getId());
            $this->queueCacheOperation('auth:sessions:' . $admin->getId() . ':*');

            // Audit successful login
            $this->audit(
                'admin.logged_in',
                'admin',
                $admin->getId(),
                null,
                [
                    'login_time' => Time::now()->toDateTimeString(),
                    'ip_address' => $request->ipAddress,
                    'device_info' => $sessionData['device_info']
                ],
                [
                    'suspicious_activity' => $suspiciousActivity,
                    'requires_2fa' => $requires2FA,
                    'session_id' => $session->sessionId
                ]
            );

            return LoginResponse::createSuccess(
                AdminResponse::fromEntity($admin),
                $session,
                $requires2FA
            );
        }, 'auth_login');
    }

    /**
     * {@inheritDoc}
     */
    public function logout(string $sessionId): bool
    {
        return $this->transaction(function () use ($sessionId) {
            $session = $this->getSessionFromStorage($sessionId);
            
            if ($session === null) {
                return false;
            }

            $adminId = $session['admin_id'];
            
            // Remove session from storage
            $this->removeSession($sessionId);

            // Clear session cache
            $this->queueCacheOperation('auth:session:' . $sessionId);
            $this->queueCacheOperation('auth:sessions:' . $adminId . ':*');

            // Audit logout
            $this->audit(
                'admin.logged_out',
                'admin',
                $adminId,
                null,
                ['logout_time' => Time::now()->toDateTimeString()],
                ['session_id' => $sessionId]
            );

            return true;
        }, 'auth_logout');
    }

    /**
     * {@inheritDoc}
     */
    public function logoutAll(int $adminId, ?string $reason = null): int
    {
        $this->authorize('auth.manage_sessions');

        return $this->transaction(function () use ($adminId, $reason) {
            $sessions = $this->getAdminSessionsFromStorage($adminId);
            $terminatedCount = 0;

            foreach ($sessions as $sessionId => $session) {
                try {
                    $this->terminateSession($sessionId, $reason);
                    $terminatedCount++;
                } catch (\Throwable $e) {
                    log_message('error', sprintf(
                        'Failed to terminate session %s: %s',
                        $sessionId,
                        $e->getMessage()
                    ));
                }
            }

            // Clear all session cache for admin
            $this->queueCacheOperation('auth:sessions:' . $adminId . ':*');
            $this->queueCacheOperation('auth:session:*');

            // Audit bulk logout
            $this->audit(
                'admin.logged_out_all',
                'admin',
                $adminId,
                null,
                [
                    'terminated_count' => $terminatedCount,
                    'logout_time' => Time::now()->toDateTimeString()
                ],
                ['reason' => $reason]
            );

            return $terminatedCount;
        }, 'auth_logout_all');
    }

    /**
     * {@inheritDoc}
     */
    public function refreshSession(string $sessionId, string $refreshToken): LoginResponse
    {
        return $this->transaction(function () use ($sessionId, $refreshToken) {
            $session = $this->getSessionFromStorage($sessionId);
            
            if ($session === null) {
                throw new AuthorizationException(
                    'Session not found',
                    'SESSION_NOT_FOUND'
                );
            }

            // Validate refresh token
            if (!isset($session['refresh_token']) || $session['refresh_token'] !== $refreshToken) {
                throw new AuthorizationException(
                    'Invalid refresh token',
                    'INVALID_REFRESH_TOKEN'
                );
            }

            // Check if session is expired
            if ($this->isSessionExpired($session)) {
                throw new AuthorizationException(
                    'Session expired',
                    'SESSION_EXPIRED'
                );
            }

            // Get admin
            $admin = $this->getEntity($this->adminRepository, $session['admin_id']);

            // Rotate tokens
            $newSession = $this->rotateSessionTokens($sessionId, true);

            // Extend session
            $extendedSession = $this->extendSession($sessionId, $this->configuration['session_timeout']);

            // Clear cache
            $this->queueCacheOperation('auth:session:' . $sessionId);

            // Audit session refresh
            $this->audit(
                'session.refreshed',
                'admin',
                $admin->getId(),
                null,
                ['refreshed_at' => Time::now()->toDateTimeString()],
                ['session_id' => $sessionId]
            );

            return LoginResponse::createSuccess(
                AdminResponse::fromEntity($admin),
                $extendedSession,
                false
            );
        }, 'auth_refresh_session');
    }

    /**
     * {@inheritDoc}
     */
    public function validateToken(string $token, string $tokenType = 'jwt'): AdminResponse
    {
        switch ($tokenType) {
            case 'session':
                $session = $this->getSessionFromStorage($token);
                if ($session === null || $this->isSessionExpired($session)) {
                    throw new AuthorizationException(
                        'Invalid or expired session',
                        'INVALID_SESSION'
                    );
                }
                
                $admin = $this->getEntity($this->adminRepository, $session['admin_id']);
                break;

            case 'api':
                $apiToken = $this->validateApiToken($token);
                if (!$apiToken['valid']) {
                    throw new AuthorizationException(
                        'Invalid API token',
                        'INVALID_API_TOKEN'
                    );
                }
                
                $admin = $this->getEntity($this->adminRepository, $apiToken['admin']['id']);
                break;

            case 'jwt':
                // For MVP, implement basic JWT validation
                // In production, use proper JWT library
                $admin = $this->validateJwtToken($token);
                break;

            default:
                throw new DomainException(
                    'Unsupported token type: ' . $tokenType,
                    'UNSUPPORTED_TOKEN_TYPE'
                );
        }

        // Check if admin is active
        if (!$admin->isActive()) {
            throw new AuthorizationException(
                'Account is inactive',
                'ACCOUNT_INACTIVE'
            );
        }

        return AdminResponse::fromEntity($admin);
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateToken(string $token, string $tokenType = 'jwt', ?string $reason = null): bool
    {
        switch ($tokenType) {
            case 'session':
                return $this->terminateSession($token, $reason);
                
            case 'api':
                return $this->revokeApiToken($token, $reason);
                
            case 'jwt':
                // For MVP, add to blacklist
                // In production, implement JWT blacklist
                $this->cache->set('jwt:blacklist:' . md5($token), true, 86400);
                return true;
                
            default:
                throw new DomainException(
                    'Unsupported token type: ' . $tokenType,
                    'UNSUPPORTED_TOKEN_TYPE'
                );
        }
    }

    // ==================== SESSION MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function getSession(string $sessionId): SessionResponse
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            throw NotFoundException::forEntity('Session', $sessionId);
        }

        // Check permissions
        if ($session['admin_id'] !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.view_sessions')) {
            throw new AuthorizationException(
                'Not authorized to view this session',
                'VIEW_SESSION_FORBIDDEN'
            );
        }

        return $this->createSessionResponse($session);
    }

    /**
     * {@inheritDoc}
     */
    public function getAdminSessions(int $adminId, bool $includeExpired = false): array
    {
        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.view_sessions')) {
            throw new AuthorizationException(
                'Not authorized to view other admin sessions',
                'VIEW_SESSIONS_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('admin_sessions', [
            'admin_id' => $adminId,
            'include_expired' => $includeExpired
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $includeExpired) {
            $sessions = $this->getAdminSessionsFromStorage($adminId);
            
            if (!$includeExpired) {
                $sessions = array_filter($sessions, function ($session) {
                    return !$this->isSessionExpired($session);
                });
            }

            return array_map(function ($session) {
                return $this->createSessionResponse($session);
            }, $sessions);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentSession(int $adminId): ?SessionResponse
    {
        $cacheKey = $this->getServiceCacheKey('current_session', ['admin_id' => $adminId]);

        return $this->withCaching($cacheKey, function () use ($adminId) {
            $sessions = $this->getAdminSessionsFromStorage($adminId);
            
            // Find most recent active session
            $currentSession = null;
            foreach ($sessions as $session) {
                if (!$this->isSessionExpired($session)) {
                    if ($currentSession === null || 
                        strtotime($session['last_activity']) > strtotime($currentSession['last_activity'])) {
                        $currentSession = $session;
                    }
                }
            }

            return $currentSession ? $this->createSessionResponse($currentSession) : null;
        }, 60); // 1 minute cache for current session
    }

    /**
     * {@inheritDoc}
     */
    public function createSession(int $adminId, array $sessionData = [], bool $requirePassword = false): SessionResponse
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        if ($requirePassword && !isset($sessionData['password'])) {
            throw new ValidationException(
                'Password required for session creation',
                'PASSWORD_REQUIRED'
            );
        }

        if ($requirePassword && isset($sessionData['password'])) {
            if (!$admin->verifyPassword($sessionData['password'])) {
                throw new AuthorizationException(
                    'Invalid password',
                    'INVALID_PASSWORD'
                );
            }
        }

        return $this->transaction(function () use ($adminId, $sessionData) {
            // Generate session ID
            $sessionId = $this->generateSecureToken(64);
            $accessToken = $this->generateSecureToken(32);
            $refreshToken = $this->generateSecureToken(32);

            // Calculate expiration
            $createdAt = Time::now()->toDateTimeString();
            $expiresAt = Time::now()->addSeconds($this->configuration['session_timeout'])->toDateTimeString();
            $refreshExpiresAt = Time::now()->addSeconds($this->configuration['session_timeout'] * 24)->toDateTimeString();

            // Create session data
            $session = [
                'session_id' => $sessionId,
                'admin_id' => $adminId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'refresh_expires_at' => $refreshExpiresAt,
                'last_activity' => $createdAt,
                'ip_address' => $sessionData['ip_address'] ?? null,
                'user_agent' => $sessionData['user_agent'] ?? null,
                'device_info' => $sessionData['device_info'] ?? [],
                'location' => $sessionData['location'] ?? null,
                'metadata' => $sessionData['metadata'] ?? [],
                'is_impersonated' => false,
                'impersonator_id' => null,
                'impersonation_started' => null
            ];

            // Store session
            $this->storeSession($sessionId, $session);

            // Clear cache
            $this->queueCacheOperation('auth:sessions:' . $adminId . ':*');

            return $this->createSessionResponse($session);
        }, 'auth_create_session');
    }

    /**
     * {@inheritDoc}
     */
    public function updateSession(string $sessionId, array $updates): SessionResponse
    {
        return $this->transaction(function () use ($sessionId, $updates) {
            $session = $this->getSessionFromStorage($sessionId);
            
            if ($session === null) {
                throw NotFoundException::forEntity('Session', $sessionId);
            }

            // Check permissions
            if ($session['admin_id'] !== $this->getCurrentAdminId() && 
                !$this->checkPermission($this->getCurrentAdminId(), 'auth.manage_sessions')) {
                throw new AuthorizationException(
                    'Not authorized to update this session',
                    'UPDATE_SESSION_FORBIDDEN'
                );
            }

            // Allowed updates
            $allowedUpdates = ['metadata', 'device_info', 'location'];
            foreach ($updates as $key => $value) {
                if (in_array($key, $allowedUpdates)) {
                    $session[$key] = $value;
                }
            }

            // Update last activity
            $session['last_activity'] = Time::now()->toDateTimeString();

            // Save session
            $this->storeSession($sessionId, $session);

            // Clear cache
            $this->queueCacheOperation('auth:session:' . $sessionId);

            return $this->createSessionResponse($session);
        }, 'auth_update_session');
    }

    /**
     * {@inheritDoc}
     */
    public function extendSession(string $sessionId, int $extensionSeconds = 3600): SessionResponse
    {
        return $this->transaction(function () use ($sessionId, $extensionSeconds) {
            $session = $this->getSessionFromStorage($sessionId);
            
            if ($session === null) {
                throw NotFoundException::forEntity('Session', $sessionId);
            }

            // Check permissions
            if ($session['admin_id'] !== $this->getCurrentAdminId() && 
                !$this->checkPermission($this->getCurrentAdminId(), 'auth.manage_sessions')) {
                throw new AuthorizationException(
                    'Not authorized to extend this session',
                    'EXTEND_SESSION_FORBIDDEN'
                );
            }

            // Check max extension limit
            $maxExtension = $this->configuration['max_session_extension'] ?? 86400;
            if ($extensionSeconds > $maxExtension) {
                throw new DomainException(
                    'Extension exceeds maximum allowed time',
                    'EXTENSION_LIMIT_EXCEEDED'
                );
            }

            // Extend expiration
            $currentExpiry = new \DateTime($session['expires_at']);
            $newExpiry = $currentExpiry->add(new \DateInterval('PT' . $extensionSeconds . 'S'));
            
            $session['expires_at'] = $newExpiry->format('Y-m-d H:i:s');
            $session['last_activity'] = Time::now()->toDateTimeString();

            // Save session
            $this->storeSession($sessionId, $session);

            // Clear cache
            $this->queueCacheOperation('auth:session:' . $sessionId);

            // Audit session extension
            $this->audit(
                'session.extended',
                'admin',
                $session['admin_id'],
                ['old_expiry' => $currentExpiry->format('Y-m-d H:i:s')],
                ['new_expiry' => $session['expires_at']],
                ['session_id' => $sessionId]
            );

            return $this->createSessionResponse($session);
        }, 'auth_extend_session');
    }

    /**
     * {@inheritDoc}
     */
    public function terminateSession(string $sessionId, ?string $reason = null): bool
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            throw NotFoundException::forEntity('Session', $sessionId);
        }

        // Check permissions
        if ($session['admin_id'] !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.manage_sessions')) {
            throw new AuthorizationException(
                'Not authorized to terminate this session',
                'TERMINATE_SESSION_FORBIDDEN'
            );
        }

        return $this->transaction(function () use ($sessionId, $session, $reason) {
            // Remove session
            $this->removeSession($sessionId);

            // Clear cache
            $this->queueCacheOperation('auth:session:' . $sessionId);
            $this->queueCacheOperation('auth:sessions:' . $session['admin_id'] . ':*');

            // Audit session termination
            $this->audit(
                'session.terminated',
                'admin',
                $session['admin_id'],
                null,
                ['terminated_at' => Time::now()->toDateTimeString()],
                [
                    'session_id' => $sessionId,
                    'reason' => $reason,
                    'terminated_by' => $this->getCurrentAdminId()
                ]
            );

            return true;
        }, 'auth_terminate_session');
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupExpiredSessions(int $olderThanSeconds = 86400): int
    {
        $this->authorize('auth.cleanup_sessions');

        $cutoffTime = Time::now()->subSeconds($olderThanSeconds)->toDateTimeString();
        $cleanedCount = 0;

        foreach ($this->sessions as $sessionId => $session) {
            $lastActivity = new \DateTime($session['last_activity']);
            $cutoff = new \DateTime($cutoffTime);
            
            if ($lastActivity < $cutoff) {
                try {
                    $this->removeSession($sessionId);
                    $cleanedCount++;
                } catch (\Throwable $e) {
                    log_message('error', sprintf(
                        'Failed to cleanup session %s: %s',
                        $sessionId,
                        $e->getMessage()
                    ));
                }
            }
        }

        // Clear all session cache
        $this->queueCacheOperation('auth:session:*');
        $this->queueCacheOperation('auth:sessions:*');

        // Audit cleanup
        $this->audit(
            'sessions.cleanup',
            'system',
            0,
            null,
            ['cleaned_count' => $cleanedCount],
            ['older_than_seconds' => $olderThanSeconds]
        );

        return $cleanedCount;
    }

    // ==================== PASSWORD MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function changePassword(
        int $adminId,
        string $currentPassword,
        string $newPassword,
        bool $logoutOtherDevices = false
    ): bool {
        return $this->transaction(function () use ($adminId, $currentPassword, $newPassword, $logoutOtherDevices) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Verify current password
            if (!$admin->verifyPassword($currentPassword)) {
                throw new ValidationException(
                    'Current password is incorrect',
                    'INVALID_CURRENT_PASSWORD'
                );
            }

            // Validate new password strength
            $passwordStrength = $this->validatePasswordStrength($newPassword, $adminId);
            if (!$passwordStrength['valid']) {
                throw new ValidationException(
                    'Password does not meet requirements',
                    'WEAK_PASSWORD',
                    $passwordStrength['feedback']
                );
            }

            // Check password reuse policy
            if ($this->configuration['prevent_reuse'] > 0) {
                // In production, check against password history
                // For MVP, we'll skip this check
            }

            // Set new password
            $admin->setPasswordWithHash($newPassword);
            $this->adminRepository->save($admin);

            // Logout other devices if requested
            if ($logoutOtherDevices) {
                $currentSessionId = $this->getCurrentSessionId($adminId);
                $sessions = $this->getAdminSessionsFromStorage($adminId);
                
                foreach ($sessions as $sessionId => $session) {
                    if ($sessionId !== $currentSessionId) {
                        $this->terminateSession($sessionId, 'Password changed');
                    }
                }
            }

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('auth:sessions:' . $adminId . ':*');

            // Audit password change
            $this->audit(
                'password.changed',
                'admin',
                $adminId,
                null,
                [
                    'changed_at' => Time::now()->toDateTimeString(),
                    'logout_other_devices' => $logoutOtherDevices
                ],
                ['changed_by' => $this->getCurrentAdminId()]
            );

            return true;
        }, 'auth_change_password');
    }

    /**
     * {@inheritDoc}
     */
    public function resetPassword(
        int $adminId,
        string $newPassword,
        bool $forceChangeOnLogin = true,
        ?string $reason = null
    ): bool {
        $this->authorize('auth.reset_password');

        return $this->transaction(function () use ($adminId, $newPassword, $forceChangeOnLogin, $reason) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Validate password strength
            $passwordStrength = $this->validatePasswordStrength($newPassword, $adminId);
            if (!$passwordStrength['valid']) {
                throw new ValidationException(
                    'Password does not meet requirements',
                    'WEAK_PASSWORD',
                    $passwordStrength['feedback']
                );
            }

            // Set new password
            $admin->setPasswordWithHash($newPassword);
            
            // Force change on next login if requested
            if ($forceChangeOnLogin) {
                // In production, set a flag or expiry
                // For MVP, we'll just record it
                $admin->setPassword($newPassword); // This will force rehash on next login
            }
            
            $this->adminRepository->save($admin);

            // Terminate all sessions
            $this->logoutAll($adminId, 'Password reset');

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('auth:sessions:' . $adminId . ':*');

            // Audit password reset
            $this->audit(
                'password.reset',
                'admin',
                $adminId,
                null,
                [
                    'reset_at' => Time::now()->toDateTimeString(),
                    'force_change_on_login' => $forceChangeOnLogin
                ],
                [
                    'reset_by' => $this->getCurrentAdminId(),
                    'reason' => $reason
                ]
            );

            return true;
        }, 'auth_reset_password');
    }

    /**
     * {@inheritDoc}
     */
    public function requestPasswordReset(string $identifier): array
    {
        $admin = $this->adminRepository->findByUsernameOrEmail($identifier);
        
        if ($admin === null) {
            // Don't reveal if admin exists (security)
            throw new NotFoundException(
                'If an account exists with that identifier, a password reset link has been sent.',
                'PASSWORD_RESET_REQUESTED'
            );
        }

        // Check if admin is active
        if (!$admin->isActive()) {
            throw new DomainException(
                'Account is inactive',
                'ACCOUNT_INACTIVE'
            );
        }

        // Generate reset token
        $token = $this->generateSecureToken(64);
        $expiresAt = Time::now()->addHours(2)->toDateTimeString();

        // Store token (in production, store in database)
        $this->passwordResetTokens[$token] = [
            'admin_id' => $admin->getId(),
            'identifier' => $identifier,
            'created_at' => Time::now()->toDateTimeString(),
            'expires_at' => $expiresAt,
            'used' => false,
            'ip_address' => service('request')->getIPAddress()
        ];

        // Clear old tokens
        $this->cleanupExpiredPasswordTokens();

        // In production, send email with reset link
        // For MVP, return token (in production, don't return token)
        
        // Audit password reset request
        $this->audit(
            'password.reset_requested',
            'admin',
            $admin->getId(),
            null,
            ['requested_at' => Time::now()->toDateTimeString()],
            ['delivery_method' => 'email']
        );

        return [
            'token' => $token, // In production, don't return token
            'expires_at' => $expiresAt,
            'delivery_method' => 'email'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function verifyPasswordResetToken(string $token): array
    {
        if (!isset($this->passwordResetTokens[$token])) {
            return [
                'valid' => false,
                'admin_id' => null,
                'expires_at' => null
            ];
        }

        $tokenData = $this->passwordResetTokens[$token];
        
        // Check if token is expired
        $expiresAt = new \DateTime($tokenData['expires_at']);
        $now = new \DateTime();
        
        if ($now > $expiresAt || $tokenData['used']) {
            return [
                'valid' => false,
                'admin_id' => null,
                'expires_at' => $tokenData['expires_at']
            ];
        }

        return [
            'valid' => true,
            'admin_id' => $tokenData['admin_id'],
            'expires_at' => $tokenData['expires_at']
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function completePasswordReset(string $token, string $newPassword): bool
    {
        $tokenVerification = $this->verifyPasswordResetToken($token);
        
        if (!$tokenVerification['valid']) {
            throw new ValidationException(
                'Invalid or expired reset token',
                'INVALID_RESET_TOKEN'
            );
        }

        return $this->transaction(function () use ($tokenVerification, $newPassword, $token) {
            $adminId = $tokenVerification['admin_id'];
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            // Validate password strength
            $passwordStrength = $this->validatePasswordStrength($newPassword, $adminId);
            if (!$passwordStrength['valid']) {
                throw new ValidationException(
                    'Password does not meet requirements',
                    'WEAK_PASSWORD',
                    $passwordStrength['feedback']
                );
            }

            // Set new password
            $admin->setPasswordWithHash($newPassword);
            $this->adminRepository->save($admin);

            // Mark token as used
            $this->passwordResetTokens[$token]['used'] = true;

            // Terminate all sessions
            $this->logoutAll($adminId, 'Password reset via token');

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);
            $this->queueCacheOperation('auth:sessions:' . $adminId . ':*');

            // Audit password reset completion
            $this->audit(
                'password.reset_completed',
                'admin',
                $adminId,
                null,
                ['completed_at' => Time::now()->toDateTimeString()],
                ['reset_method' => 'token']
            );

            return true;
        }, 'auth_complete_password_reset');
    }

    /**
     * {@inheritDoc}
     */
    public function validatePasswordStrength(string $password, ?int $adminId = null): array
    {
        $policy = $this->getPasswordPolicy();
        $errors = [];
        $score = 0;

        // Check length
        if (strlen($password) < $policy['min_length']) {
            $errors[] = sprintf(
                'Password must be at least %d characters long',
                $policy['min_length']
            );
        } else {
            $score += 20;
        }

        // Check uppercase
        if ($policy['require_uppercase'] && !preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter';
        } else {
            $score += 20;
        }

        // Check lowercase
        if ($policy['require_lowercase'] && !preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter';
        } else {
            $score += 20;
        }

        // Check numbers
        if ($policy['require_numbers'] && !preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number';
        } else {
            $score += 20;
        }

        // Check special characters
        if ($policy['require_special_chars'] && !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character';
        } else {
            $score += 20;
        }

        // Check against common passwords (basic check)
        $commonPasswords = ['password', '123456', 'admin', 'welcome', 'qwerty'];
        if (in_array(strtolower($password), $commonPasswords)) {
            $errors[] = 'Password is too common';
            $score = max(0, $score - 50);
        }

        // Check password history (for MVP, skip)
        // In production, check against admin's password history

        return [
            'valid' => empty($errors),
            'score' => $score,
            'feedback' => $errors,
            'meets_policy' => empty($errors)
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getPasswordPolicy(): array
    {
        return [
            'min_length' => $this->configuration['password_min_length'] ?? 8,
            'require_uppercase' => $this->configuration['password_require_uppercase'] ?? true,
            'require_lowercase' => $this->configuration['password_require_lowercase'] ?? true,
            'require_numbers' => $this->configuration['password_require_numbers'] ?? true,
            'require_special_chars' => $this->configuration['password_require_special_chars'] ?? false,
            'prevent_reuse' => $this->configuration['password_prevent_reuse'] ?? 5,
            'expiry_days' => $this->configuration['password_expiry_days'] ?? 90
        ];
    }

    // ==================== ACCOUNT LOCKOUT & SECURITY ====================

    /**
     * {@inheritDoc}
     */
    public function isAccountLocked(int $adminId): bool
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        $maxAttempts = $this->configuration['max_login_attempts'] ?? 5;
        return $admin->isLocked($maxAttempts);
    }

    /**
     * {@inheritDoc}
     */
    public function getAccountLockoutStatus(int $adminId): array
    {
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        $maxAttempts = $this->configuration['max_login_attempts'] ?? 5;
        $lockoutDuration = $this->configuration['lockout_duration'] ?? 900; // 15 minutes
        
        $locked = $admin->isLocked($maxAttempts);
        $remainingAttempts = max(0, $maxAttempts - $admin->getLoginAttempts());
        
        // Calculate lockout expiry
        $lockoutExpires = null;
        if ($locked && $admin->getLastLogin() !== null) {
            $lastLogin = $admin->getLastLogin();
            $lockoutExpires = $lastLogin->add(new \DateInterval('PT' . $lockoutDuration . 'S'));
        }

        return [
            'locked' => $locked,
            'remaining_attempts' => $remainingAttempts,
            'lockout_expires' => $lockoutExpires ? $lockoutExpires->format('Y-m-d H:i:s') : null,
            'last_failed_attempt' => $admin->getLastLogin() ? $admin->getLastLogin()->format('Y-m-d H:i:s') : null
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function unlockAccount(int $adminId, ?string $reason = null): bool
    {
        $this->authorize('auth.unlock_account');

        return $this->transaction(function () use ($adminId, $reason) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->resetLoginAttempts();
            $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);

            // Audit account unlock
            $this->audit(
                'account.unlocked',
                'admin',
                $adminId,
                null,
                ['unlocked_at' => Time::now()->toDateTimeString()],
                [
                    'unlocked_by' => $this->getCurrentAdminId(),
                    'reason' => $reason
                ]
            );

            return true;
        }, 'auth_unlock_account');
    }

    /**
     * {@inheritDoc}
     */
    public function recordFailedAttempt(int $adminId, array $attemptData = []): array
    {
        return $this->transaction(function () use ($adminId, $attemptData) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->recordFailedLogin();
            $this->adminRepository->save($admin);

            // Record failed attempt in audit log
            $this->audit(
                'login.failed',
                'admin',
                $adminId,
                null,
                [
                    'attempt_time' => Time::now()->toDateTimeString(),
                    'attempt_number' => $admin->getLoginAttempts()
                ],
                $attemptData
            );

            // Check lockout status
            $lockoutStatus = $this->getAccountLockoutStatus($adminId);
            
            if ($lockoutStatus['locked']) {
                $this->audit(
                    'account.locked',
                    'admin',
                    $adminId,
                    null,
                    [
                        'locked_at' => Time::now()->toDateTimeString(),
                        'lockout_expires' => $lockoutStatus['lockout_expires']
                    ],
                    $attemptData
                );
            }

            return $lockoutStatus;
        }, 'auth_record_failed_attempt');
    }

    /**
     * {@inheritDoc}
     */
    public function resetFailedAttempts(int $adminId): bool
    {
        return $this->transaction(function () use ($adminId) {
            $admin = $this->getEntity($this->adminRepository, $adminId);
            
            $admin->resetLoginAttempts();
            $this->adminRepository->save($admin);

            // Clear cache
            $this->queueCacheOperation('admin:' . $adminId);

            return true;
        }, 'auth_reset_failed_attempts');
    }

    /**
     * {@inheritDoc}
     */
    public function getFailedAttemptsHistory(int $adminId, int $limit = 10): array
    {
        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.view_security_events')) {
            throw new AuthorizationException(
                'Not authorized to view failed attempts history',
                'VIEW_FAILED_ATTEMPTS_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('failed_attempts_history', [
            'admin_id' => $adminId,
            'limit' => $limit
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $limit) {
            // Get failed login audit logs
            $logs = $this->auditLogRepository->findByAdminId($adminId, $limit, 0);
            
            $failedAttempts = array_filter($logs, function ($log) {
                return $log->getActionType() === 'login.failed';
            });

            return array_map(function ($log) {
                return [
                    'timestamp' => $log->getFormattedPerformedAt(),
                    'ip_address' => $log->getIpAddress(),
                    'user_agent' => $log->getUserAgent(),
                    'reason' => 'Invalid credentials'
                ];
            }, $failedAttempts);
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function requiresReauthentication(int $adminId, string $operation): bool
    {
        $sensitiveOperations = [
            'password.change',
            'email.change',
            'two_factor.disable',
            'api_token.generate',
            'account.delete',
            'payment.process'
        ];

        if (!in_array($operation, $sensitiveOperations)) {
            return false;
        }

        // Check last authentication time
        $currentSession = $this->getCurrentSession($adminId);
        if ($currentSession === null) {
            return true;
        }

        $lastAuthTime = new \DateTime($currentSession->lastActivity);
        $now = new \DateTime();
        $minutesSinceAuth = ($now->getTimestamp() - $lastAuthTime->getTimestamp()) / 60;

        // Require reauthentication after 15 minutes for sensitive operations
        return $minutesSinceAuth > 15;
    }

    // ==================== TWO-FACTOR AUTHENTICATION ====================

    /**
     * {@inheritDoc}
     */
    public function isTwoFactorEnabled(int $adminId): bool
    {
        $cacheKey = $this->getServiceCacheKey('two_factor_enabled', ['admin_id' => $adminId]);

        return $this->withCaching($cacheKey, function () use ($adminId) {
            // In production, check database for 2FA settings
            // For MVP, return false or check configuration
            return $this->configuration['require_2fa'] ?? false;
        }, 3600);
    }

    /**
     * {@inheritDoc}
     */
    public function enableTwoFactor(int $adminId, string $verificationCode, string $secret = ''): array
    {
        return $this->transaction(function () use ($adminId, $verificationCode, $secret) {
            // Generate secret if not provided
            if (empty($secret)) {
                $secret = $this->generateTwoFactorSecret($adminId)['secret'];
            }

            // Verify code (in production, use proper 2FA library)
            $isValid = $this->verifyTwoFactorCode($adminId, $verificationCode);
            
            if (!$isValid) {
                throw new ValidationException(
                    'Invalid verification code',
                    'INVALID_2FA_CODE'
                );
            }

            // In production, save 2FA settings to database
            // For MVP, store in cache or configuration
            
            // Generate backup codes
            $backupCodes = $this->generateBackupCodes($adminId, 10);
            $recoveryCodes = $this->generateBackupCodes($adminId, 5);

            // Clear cache
            $this->queueCacheOperation('auth:two_factor:' . $adminId);

            // Audit 2FA enable
            $this->audit(
                'two_factor.enabled',
                'admin',
                $adminId,
                null,
                ['enabled_at' => Time::now()->toDateTimeString()],
                ['enabled_by' => $this->getCurrentAdminId()]
            );

            return [
                'enabled' => true,
                'backup_codes' => $backupCodes,
                'recovery_codes' => $recoveryCodes
            ];
        }, 'auth_enable_two_factor');
    }

    /**
     * {@inheritDoc}
     */
    public function disableTwoFactor(int $adminId, string $verificationCode, ?string $reason = null): bool
    {
        return $this->transaction(function () use ($adminId, $verificationCode, $reason) {
            // Verify code before disabling
            $isValid = $this->verifyTwoFactorCode($adminId, $verificationCode);
            
            if (!$isValid) {
                throw new ValidationException(
                    'Invalid verification code',
                    'INVALID_2FA_CODE'
                );
            }

            // In production, remove 2FA settings from database
            // For MVP, remove from cache or configuration

            // Clear cache
            $this->queueCacheOperation('auth:two_factor:' . $adminId);

            // Audit 2FA disable
            $this->audit(
                'two_factor.disabled',
                'admin',
                $adminId,
                null,
                ['disabled_at' => Time::now()->toDateTimeString()],
                [
                    'disabled_by' => $this->getCurrentAdminId(),
                    'reason' => $reason
                ]
            );

            return true;
        }, 'auth_disable_two_factor');
    }

    /**
     * {@inheritDoc}
     */
    public function generateTwoFactorSecret(int $adminId): array
    {
        // In production, use proper 2FA library (like spomky-labs/otphp)
        // For MVP, generate a random secret
        
        $secret = $this->generateSecureToken(32, 'base32');
        $admin = $this->getEntity($this->adminRepository, $adminId);
        
        $issuer = $this->configuration['app_name'] ?? 'DevDaily';
        $accountName = $admin->getEmail();
        
        // Generate QR code URL (TOTP format)
        $qrCodeUrl = sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s',
            rawurlencode($issuer),
            rawurlencode($accountName),
            $secret,
            rawurlencode($issuer)
        );

        // Generate provisioning URL
        $provisioningUrl = $qrCodeUrl;

        return [
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'provisioning_url' => $provisioningUrl
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function verifyTwoFactorCode(int $adminId, string $code, bool $isBackupCode = false): bool
    {
        if ($isBackupCode) {
            return $this->validateBackupCode($adminId, $code);
        }

        // In production, verify TOTP code using secret from database
        // For MVP, basic validation
        return preg_match('/^\d{6}$/', $code) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function generateBackupCodes(int $adminId, int $count = 10): array
    {
        $backupCodes = [];
        for ($i = 0; $i < $count; $i++) {
            // Generate 8-character backup code with dashes for readability
            $code = substr($this->generateSecureToken(8), 0, 8);
            $formattedCode = implode('-', str_split(strtoupper($code), 4));
            $backupCodes[] = $formattedCode;
        }

        // In production, store hashed backup codes in database
        // For MVP, store in cache
        $cacheKey = 'auth:backup_codes:' . $adminId;
        $this->cache->set($cacheKey, $backupCodes, 86400 * 30); // 30 days

        return $backupCodes;
    }

    /**
     * {@inheritDoc}
     */
    public function validateBackupCode(int $adminId, string $code): bool
    {
        $cacheKey = 'auth:backup_codes:' . $adminId;
        $backupCodes = $this->cache->get($cacheKey);
        
        if (!is_array($backupCodes)) {
            return false;
        }

        // Normalize code (remove dashes, uppercase)
        $normalizedCode = strtoupper(str_replace('-', '', $code));
        
        // Check if code exists
        foreach ($backupCodes as $index => $backupCode) {
            if (strtoupper(str_replace('-', '', $backupCode)) === $normalizedCode) {
                // Remove used backup code
                unset($backupCodes[$index]);
                $this->cache->set($cacheKey, array_values($backupCodes), 86400 * 30);
                return true;
            }
        }

        return false;
    }

    // ==================== API TOKEN MANAGEMENT ====================

    /**
     * {@inheritDoc}
     */
    public function generateApiToken(
        int $adminId,
        string $name,
        array $scopes = [],
        ?\DateTimeInterface $expiresAt = null
    ): array {
        $this->authorize('auth.generate_api_token');

        // Validate scopes
        $validScopes = $this->getValidApiScopes();
        foreach ($scopes as $scope) {
            if (!in_array($scope, $validScopes)) {
                throw new ValidationException(
                    'Invalid scope: ' . $scope,
                    'INVALID_SCOPE'
                );
            }
        }

        return $this->transaction(function () use ($adminId, $name, $scopes, $expiresAt) {
            // Generate token
            $token = $this->generateSecureToken(64);
            $tokenId = 'api_' . uniqid();
            
            // Set default expiration (30 days)
            if ($expiresAt === null) {
                $expiresAt = Time::now()->addDays(30);
            }

            // Store token (in production, store in database)
            $this->apiTokens[$token] = [
                'id' => $tokenId,
                'admin_id' => $adminId,
                'name' => $name,
                'token' => $token,
                'scopes' => $scopes,
                'created_at' => Time::now()->toDateTimeString(),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'last_used' => null,
                'revoked' => false
            ];

            // Audit API token generation
            $this->audit(
                'api_token.generated',
                'admin',
                $adminId,
                null,
                [
                    'token_id' => $tokenId,
                    'name' => $name,
                    'scopes' => $scopes,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s')
                ],
                ['generated_by' => $this->getCurrentAdminId()]
            );

            return [
                'token' => $token,
                'token_id' => $tokenId,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'scopes' => $scopes
            ];
        }, 'auth_generate_api_token');
    }

    /**
     * {@inheritDoc}
     */
    public function revokeApiToken(string $token, ?string $reason = null): bool
    {
        if (!isset($this->apiTokens[$token])) {
            throw NotFoundException::forEntity('API Token', $token);
        }

        $tokenData = $this->apiTokens[$token];
        
        // Check permissions
        if ($tokenData['admin_id'] !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.manage_api_tokens')) {
            throw new AuthorizationException(
                'Not authorized to revoke this API token',
                'REVOKE_API_TOKEN_FORBIDDEN'
            );
        }

        $this->apiTokens[$token]['revoked'] = true;
        $this->apiTokens[$token]['revoked_at'] = Time::now()->toDateTimeString();
        $this->apiTokens[$token]['revoke_reason'] = $reason;

        // Clear cache
        $this->queueCacheOperation('auth:api_token:' . $token);

        // Audit API token revocation
        $this->audit(
            'api_token.revoked',
            'admin',
            $tokenData['admin_id'],
            null,
            ['revoked_at' => Time::now()->toDateTimeString()],
            [
                'token_id' => $tokenData['id'],
                'reason' => $reason,
                'revoked_by' => $this->getCurrentAdminId()
            ]
        );

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function revokeAllApiTokens(int $adminId, ?string $reason = null): int
    {
        $this->authorize('auth.manage_api_tokens');

        $revokedCount = 0;
        foreach ($this->apiTokens as $token => $tokenData) {
            if ($tokenData['admin_id'] === $adminId && !$tokenData['revoked']) {
                $this->revokeApiToken($token, $reason);
                $revokedCount++;
            }
        }

        // Clear all API token cache for admin
        $this->queueCacheOperation('auth:api_tokens:' . $adminId . ':*');

        return $revokedCount;
    }

    /**
     * {@inheritDoc}
     */
    public function getApiTokens(int $adminId, bool $includeRevoked = false): array
    {
        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.view_api_tokens')) {
            throw new AuthorizationException(
                'Not authorized to view API tokens',
                'VIEW_API_TOKENS_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('api_tokens', [
            'admin_id' => $adminId,
            'include_revoked' => $includeRevoked
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $includeRevoked) {
            $tokens = [];
            
            foreach ($this->apiTokens as $tokenData) {
                if ($tokenData['admin_id'] === $adminId && 
                    ($includeRevoked || !$tokenData['revoked'])) {
                    $tokens[] = [
                        'id' => $tokenData['id'],
                        'name' => $tokenData['name'],
                        'last_used' => $tokenData['last_used'],
                        'created_at' => $tokenData['created_at'],
                        'expires_at' => $tokenData['expires_at'],
                        'scopes' => $tokenData['scopes'],
                        'revoked' => $tokenData['revoked'] ?? false
                    ];
                }
            }

            return $tokens;
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function validateApiToken(string $token, array $requiredScopes = []): array
    {
        if (!isset($this->apiTokens[$token])) {
            return [
                'valid' => false,
                'admin' => null,
                'scopes' => [],
                'expires_at' => null
            ];
        }

        $tokenData = $this->apiTokens[$token];
        
        // Check if token is revoked
        if ($tokenData['revoked'] ?? false) {
            return [
                'valid' => false,
                'admin' => null,
                'scopes' => [],
                'expires_at' => null
            ];
        }

        // Check if token is expired
        $expiresAt = new \DateTime($tokenData['expires_at']);
        $now = new \DateTime();
        
        if ($now > $expiresAt) {
            return [
                'valid' => false,
                'admin' => null,
                'scopes' => [],
                'expires_at' => $tokenData['expires_at']
            ];
        }

        // Check scopes
        $tokenScopes = $tokenData['scopes'] ?? [];
        $hasRequiredScopes = true;
        
        foreach ($requiredScopes as $requiredScope) {
            if (!in_array($requiredScope, $tokenScopes)) {
                $hasRequiredScopes = false;
                break;
            }
        }

        if (!$hasRequiredScopes) {
            return [
                'valid' => false,
                'admin' => null,
                'scopes' => $tokenScopes,
                'expires_at' => $tokenData['expires_at']
            ];
        }

        // Update last used
        $tokenData['last_used'] = Time::now()->toDateTimeString();
        $this->apiTokens[$token] = $tokenData;

        // Get admin data
        $admin = $this->getEntity($this->adminRepository, $tokenData['admin_id']);

        return [
            'valid' => true,
            'admin' => [
                'id' => $admin->getId(),
                'username' => $admin->getUsername(),
                'email' => $admin->getEmail(),
                'name' => $admin->getName(),
                'role' => $admin->getRole()
            ],
            'scopes' => $tokenScopes,
            'expires_at' => $tokenData['expires_at']
        ];
    }

    // ==================== SECURITY AUDIT & MONITORING ====================

    /**
     * {@inheritDoc}
     */
    public function getSecurityEvents(int $adminId, int $days = 30): array
    {
        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.view_security_events')) {
            throw new AuthorizationException(
                'Not authorized to view security events',
                'VIEW_SECURITY_EVENTS_FORBIDDEN'
            );
        }

        $cacheKey = $this->getServiceCacheKey('security_events', [
            'admin_id' => $adminId,
            'days' => $days
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $days) {
            // Get relevant audit logs
            $startDate = date('Y-m-d', strtotime("-$days days"));
            $logs = $this->auditLogRepository->findByAdminId($adminId, 1000, 0);
            
            $securityEvents = [];
            $securityActions = [
                'admin.logged_in',
                'admin.logged_out',
                'login.failed',
                'account.locked',
                'account.unlocked',
                'password.changed',
                'password.reset',
                'two_factor.enabled',
                'two_factor.disabled',
                'api_token.generated',
                'api_token.revoked'
            ];

            foreach ($logs as $log) {
                if (in_array($log->getActionType(), $securityActions)) {
                    $logDate = $log->getPerformedAt();
                    if ($logDate >= new \DateTime($startDate)) {
                        $securityEvents[] = [
                            'timestamp' => $log->getFormattedPerformedAt(),
                            'event_type' => $log->getActionType(),
                            'ip_address' => $log->getIpAddress(),
                            'user_agent' => $log->getUserAgent(),
                            'location' => $this->extractLocationFromIp($log->getIpAddress()),
                            'success' => !in_array($log->getActionType(), ['login.failed', 'account.locked'])
                        ];
                    }
                }
            }

            return $securityEvents;
        }, 300);
    }

    /**
     * {@inheritDoc}
     */
    public function detectSuspiciousActivity(int $adminId, array $loginData): array
    {
        $riskScore = 0;
        $factors = [];
        $recommendations = [];

        // Check IP address
        if (isset($loginData['ip_address'])) {
            $ip = $loginData['ip_address'];
            
            // Check if IP is from known location
            $knownIps = $this->getAdminKnownIps($adminId);
            if (!in_array($ip, $knownIps)) {
                $riskScore += 30;
                $factors[] = 'New IP address: ' . $ip;
                $recommendations[] = 'Verify this is your usual location';
            }
        }

        // Check user agent
        if (isset($loginData['user_agent'])) {
            $userAgent = $loginData['user_agent'];
            
            // Check if device is known
            $knownDevices = $this->getAdminKnownDevices($adminId);
            $deviceHash = md5($userAgent);
            if (!in_array($deviceHash, $knownDevices)) {
                $riskScore += 25;
                $factors[] = 'New device/browser detected';
                $recommendations[] = 'Verify this is your usual device';
            }
        }

        // Check time of day
        $currentHour = (int) date('H');
        if ($currentHour < 6 || $currentHour > 22) {
            $riskScore += 15;
            $factors[] = 'Unusual login time: ' . $currentHour . ':00';
            $recommendations[] = 'Login during normal hours (6:00-22:00)';
        }

        // Check login frequency
        $recentLogins = $this->getRecentLoginCount($adminId, 1); // Last hour
        if ($recentLogins > 3) {
            $riskScore += 20;
            $factors[] = 'Multiple login attempts in short period';
            $recommendations[] = 'Wait before trying again';
        }

        // Check location (if available)
        if (isset($loginData['location'])) {
            $location = $loginData['location'];
            $lastLocation = $this->getLastLoginLocation($adminId);
            
            if ($lastLocation && $this->calculateDistance($location, $lastLocation) > 500) {
                // Distance in km, threshold 500km
                $riskScore += 35;
                $factors[] = 'Login from distant location';
                $recommendations[] = 'Enable two-factor authentication';
            }
        }

        // Check if 2FA is enabled
        if (!$this->isTwoFactorEnabled($adminId) && $riskScore >= 50) {
            $recommendations[] = 'Consider enabling two-factor authentication';
        }

        return [
            'suspicious' => $riskScore >= 40,
            'risk_score' => min(100, $riskScore),
            'factors' => $factors,
            'recommendations' => $recommendations
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getLoginStatistics(?int $adminId = null, int $days = 30): array
    {
        $this->authorize('auth.view_statistics');

        $cacheKey = $this->getServiceCacheKey('login_statistics', [
            'admin_id' => $adminId,
            'days' => $days
        ]);

        return $this->withCaching($cacheKey, function () use ($adminId, $days) {
            // Get login logs
            $startDate = date('Y-m-d', strtotime("-$days days"));
            $logs = $adminId !== null 
                ? $this->auditLogRepository->findByAdminId($adminId, 1000, 0)
                : $this->auditLogRepository->findRecent($days * 24, 1000, 0);

            $successfulLogins = 0;
            $failedAttempts = 0;
            $totalLogins = 0;
            $locations = [];
            $devices = [];
            $timeDistribution = array_fill(0, 24, 0);
            $loginTimes = [];

            foreach ($logs as $log) {
                $actionType = $log->getActionType();
                
                if ($actionType === 'admin.logged_in') {
                    $successfulLogins++;
                    $totalLogins++;
                    
                    // Track location
                    $ip = $log->getIpAddress();
                    if ($ip) {
                        $locations[$ip] = ($locations[$ip] ?? 0) + 1;
                    }
                    
                    // Track device
                    $userAgent = $log->getUserAgent();
                    if ($userAgent) {
                        $device = $this->extractDeviceInfo($userAgent);
                        $deviceKey = $device['browser'] . ' on ' . $device['platform'];
                        $devices[$deviceKey] = ($devices[$deviceKey] ?? 0) + 1;
                    }
                    
                    // Track time distribution
                    $hour = (int) $log->getPerformedAt()->format('H');
                    $timeDistribution[$hour]++;
                    
                    // Track login times for average calculation
                    $loginTimes[] = $log->getPerformedAt()->getTimestamp();
                    
                } elseif ($actionType === 'login.failed') {
                    $failedAttempts++;
                }
            }

            // Calculate average login time (in seconds from midnight)
            $avgLoginTime = 0;
            if (!empty($loginTimes)) {
                $totalSeconds = 0;
                foreach ($loginTimes as $timestamp) {
                    $totalSeconds += (int) date('H', $timestamp) * 3600 + 
                                   (int) date('i', $timestamp) * 60 + 
                                   (int) date('s', $timestamp);
                }
                $avgLoginTime = gmdate('H:i:s', $totalSeconds / count($loginTimes));
            }

            // Sort locations and devices by frequency
            arsort($locations);
            arsort($devices);
            
            // Format time distribution
            $formattedTimeDistribution = [];
            for ($i = 0; $i < 24; $i++) {
                $formattedTimeDistribution[sprintf('%02d:00', $i)] = $timeDistribution[$i];
            }

            return [
                'total_logins' => $totalLogins,
                'failed_attempts' => $failedAttempts,
                'successful_logins' => $successfulLogins,
                'avg_login_time' => $avgLoginTime,
                'common_locations' => array_slice($locations, 0, 5, true),
                'device_distribution' => array_slice($devices, 0, 5, true),
                'time_distribution' => $formattedTimeDistribution
            ];
        }, 600);
    }

    /**
     * {@inheritDoc}
     */
    public function generateSecurityReport(int $adminId, string $period = 'month'): array
    {
        $this->authorize('auth.generate_report');

        // Check permissions
        if ($adminId !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.generate_report')) {
            throw new AuthorizationException(
                'Not authorized to generate security report',
                'GENERATE_REPORT_FORBIDDEN'
            );
        }

        $reportId = 'SECURITY_REPORT_' . date('Ymd_His') . '_' . uniqid();
        
        // Determine date range based on period
        switch ($period) {
            case 'week':
                $startDate = date('Y-m-d', strtotime('-1 week'));
                break;
            case 'month':
                $startDate = date('Y-m-d', strtotime('-1 month'));
                break;
            case 'quarter':
                $startDate = date('Y-m-d', strtotime('-3 months'));
                break;
            case 'year':
                $startDate = date('Y-m-d', strtotime('-1 year'));
                break;
            default:
                $startDate = date('Y-m-d', strtotime('-1 month'));
        }

        $endDate = date('Y-m-d');

        // Get statistics
        $loginStats = $this->getLoginStatistics($adminId, $period === 'week' ? 7 : 30);
        $securityEvents = $this->getSecurityEvents($adminId, $period === 'week' ? 7 : 30);
        $sessions = $this->getAdminSessions($adminId, false);
        $apiTokens = $this->getApiTokens($adminId, false);

        // Analyze security posture
        $securityScore = $this->calculateSecurityScore($adminId);

        // Generate recommendations
        $recommendations = $this->generateSecurityRecommendations($adminId, $securityScore);

        $report = [
            'report_id' => $reportId,
            'generated_at' => Time::now()->toDateTimeString(),
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'summary' => [
                'security_score' => $securityScore,
                'active_sessions' => count($sessions),
                'active_api_tokens' => count($apiTokens),
                'failed_login_attempts' => $loginStats['failed_attempts'],
                'suspicious_activities' => count(array_filter($securityEvents, function ($event) {
                    return !$event['success'];
                }))
            ],
            'details' => [
                'login_statistics' => $loginStats,
                'security_events' => $securityEvents,
                'active_sessions' => $sessions,
                'api_tokens' => $apiTokens
            ],
            'recommendations' => $recommendations
        ];

        // Audit report generation
        $this->audit(
            'security_report.generated',
            'admin',
            $adminId,
            null,
            ['report_id' => $reportId],
            ['period' => $period]
        );

        return $report;
    }

    // ==================== IMPERSONATION & DELEGATION ====================

    /**
     * {@inheritDoc}
     */
    public function startImpersonation(int $impersonatorId, int $targetAdminId, string $reason): SessionResponse
    {
        $this->authorize('auth.impersonate');

        return $this->transaction(function () use ($impersonatorId, $targetAdminId, $reason) {
            // Verify impersonator is super admin
            $impersonator = $this->getEntity($this->adminRepository, $impersonatorId);
            if ($impersonator->getRole() !== 'super_admin') {
                throw new AuthorizationException(
                    'Only super admins can impersonate other users',
                    'IMPERSONATE_FORBIDDEN'
                );
            }

            // Verify target admin exists and is active
            $targetAdmin = $this->getEntity($this->adminRepository, $targetAdminId);
            if (!$targetAdmin->isActive()) {
                throw new DomainException(
                    'Cannot impersonate inactive admin',
                    'TARGET_ADMIN_INACTIVE'
                );
            }

            // Create impersonation session
            $sessionData = [
                'ip_address' => service('request')->getIPAddress(),
                'user_agent' => service('request')->getUserAgent()->getAgentString(),
                'device_info' => $this->extractDeviceInfo(service('request')->getUserAgent()->getAgentString()),
                'metadata' => [
                    'is_impersonated' => true,
                    'impersonator_id' => $impersonatorId,
                    'impersonation_started' => Time::now()->toDateTimeString(),
                    'reason' => $reason
                ]
            ];

            $session = $this->createSession($targetAdminId, $sessionData, false);
            
            // Mark session as impersonated
            $sessionData = $session->toArray();
            $sessionData['is_impersonated'] = true;
            $sessionData['impersonator_id'] = $impersonatorId;
            $sessionData['impersonation_started'] = Time::now()->toDateTimeString();
            
            $this->storeSession($session->sessionId, $sessionData);

            // Clear cache
            $this->queueCacheOperation('auth:session:' . $session->sessionId);

            // Audit impersonation start
            $this->audit(
                'impersonation.started',
                'admin',
                $targetAdminId,
                null,
                [
                    'started_at' => Time::now()->toDateTimeString(),
                    'session_id' => $session->sessionId
                ],
                [
                    'impersonator_id' => $impersonatorId,
                    'reason' => $reason
                ]
            );

            return $session;
        }, 'auth_start_impersonation');
    }

    /**
     * {@inheritDoc}
     */
    public function stopImpersonation(string $sessionId): SessionResponse
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            throw NotFoundException::forEntity('Session', $sessionId);
        }

        if (!$session['is_impersonated']) {
            throw new DomainException(
                'Session is not impersonated',
                'NOT_IMPERSONATED'
            );
        }

        // Check permissions
        if ($session['impersonator_id'] !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.manage_impersonation')) {
            throw new AuthorizationException(
                'Not authorized to stop this impersonation',
                'STOP_IMPERSONATION_FORBIDDEN'
            );
        }

        // Terminate impersonation session
        $this->terminateSession($sessionId, 'Impersonation ended');

        // Restore original admin session (if exists)
        $originalSession = null;
        if ($session['impersonator_id']) {
            $originalSessions = $this->getAdminSessionsFromStorage($session['impersonator_id']);
            foreach ($originalSessions as $originalSessionData) {
                if (!$originalSessionData['is_impersonated'] && !$this->isSessionExpired($originalSessionData)) {
                    $originalSession = $this->createSessionResponse($originalSessionData);
                    break;
                }
            }
        }

        // Audit impersonation stop
        $this->audit(
            'impersonation.stopped',
            'admin',
            $session['admin_id'],
            null,
            ['stopped_at' => Time::now()->toDateTimeString()],
            [
                'impersonator_id' => $session['impersonator_id'],
                'session_id' => $sessionId
            ]
        );

        return $originalSession ?? $this->createSession($session['impersonator_id'], []);
    }

    /**
     * {@inheritDoc}
     */
    public function checkImpersonation(string $sessionId): array
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            throw NotFoundException::forEntity('Session', $sessionId);
        }

        return [
            'impersonated' => $session['is_impersonated'] ?? false,
            'original_admin_id' => $session['is_impersonated'] ? $session['impersonator_id'] : null,
            'impersonator_admin_id' => $session['impersonator_id'] ?? null,
            'started_at' => $session['impersonation_started'] ?? null
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function delegateAuthentication(
        int $delegatorId,
        int $delegateId,
        array $permissions,
        \DateTimeInterface $expiresAt,
        string $reason
    ): array {
        $this->authorize('auth.delegate');

        return $this->transaction(function () use ($delegatorId, $delegateId, $permissions, $expiresAt, $reason) {
            // Verify delegator has permissions to delegate
            $delegator = $this->getEntity($this->adminRepository, $delegatorId);
            $delegate = $this->getEntity($this->adminRepository, $delegateId);

            // Validate permissions
            $validPermissions = $this->getValidDelegationPermissions();
            foreach ($permissions as $permission) {
                if (!in_array($permission, $validPermissions)) {
                    throw new ValidationException(
                        'Invalid delegation permission: ' . $permission,
                        'INVALID_DELEGATION_PERMISSION'
                    );
                }
            }

            // Generate delegation token
            $delegationId = 'DELEGATION_' . uniqid();
            $token = $this->generateSecureToken(64);

            // Store delegation (in production, store in database)
            $delegation = [
                'id' => $delegationId,
                'delegator_id' => $delegatorId,
                'delegate_id' => $delegateId,
                'token' => $token,
                'permissions' => $permissions,
                'created_at' => Time::now()->toDateTimeString(),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'reason' => $reason,
                'used' => false
            ];

            // Store in cache for MVP
            $this->cache->set('auth:delegation:' . $token, $delegation, strtotime($expiresAt->format('Y-m-d H:i:s')) - time());

            // Audit delegation
            $this->audit(
                'authentication.delegated',
                'admin',
                $delegatorId,
                null,
                [
                    'delegation_id' => $delegationId,
                    'delegate_id' => $delegateId,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s')
                ],
                [
                    'permissions' => $permissions,
                    'reason' => $reason
                ]
            );

            return [
                'delegation_id' => $delegationId,
                'token' => $token,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s')
            ];
        }, 'auth_delegate_authentication');
    }

    // ==================== SESSION VALIDATION & HEALTH ====================

    /**
     * {@inheritDoc}
     */
    public function validateSessionHealth(string $sessionId): array
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            return [
                'valid' => false,
                'health_score' => 0,
                'issues' => ['Session not found'],
                'recommendations' => ['Login again']
            ];
        }

        $healthScore = 100;
        $issues = [];
        $recommendations = [];

        // Check expiration
        if ($this->isSessionExpired($session)) {
            $healthScore -= 50;
            $issues[] = 'Session expired';
            $recommendations[] = 'Refresh or login again';
        }

        // Check last activity
        $lastActivity = new \DateTime($session['last_activity']);
        $now = new \DateTime();
        $inactiveMinutes = ($now->getTimestamp() - $lastActivity->getTimestamp()) / 60;

        if ($inactiveMinutes > 30) {
            $healthScore -= 20;
            $issues[] = 'Session inactive for ' . round($inactiveMinutes) . ' minutes';
            $recommendations[] = 'Consider refreshing session';
        }

        // Check IP consistency (if enabled)
        if ($this->configuration['check_ip_consistency'] ?? false) {
            $currentIp = service('request')->getIPAddress();
            if ($session['ip_address'] !== $currentIp) {
                $healthScore -= 15;
                $issues[] = 'IP address changed';
                $recommendations[] = 'Verify this is expected';
            }
        }

        // Check device consistency (if enabled)
        if ($this->configuration['check_device_consistency'] ?? false) {
            $currentUserAgent = service('request')->getUserAgent()->getAgentString();
            $currentDevice = $this->extractDeviceInfo($currentUserAgent);
            $sessionDevice = $session['device_info'] ?? [];
            
            if ($currentDevice['browser'] !== ($sessionDevice['browser'] ?? '') ||
                $currentDevice['platform'] !== ($sessionDevice['platform'] ?? '')) {
                $healthScore -= 15;
                $issues[] = 'Device/browser changed';
                $recommendations[] = 'Verify this is expected';
            }
        }

        // Check impersonation status
        if ($session['is_impersonated'] ?? false) {
            $healthScore -= 10;
            $issues[] = 'Session is impersonated';
            $recommendations[] = 'Monitor impersonation activities';
        }

        return [
            'valid' => $healthScore >= 70,
            'health_score' => $healthScore,
            'issues' => $issues,
            'recommendations' => $recommendations
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function checkSessionExpiration(string $sessionId): array
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            throw NotFoundException::forEntity('Session', $sessionId);
        }

        $expiresAt = new \DateTime($session['expires_at']);
        $now = new \DateTime();
        $expiresIn = $expiresAt->getTimestamp() - $now->getTimestamp();

        // Calculate renewal deadline (15 minutes before expiration)
        $renewalDeadline = clone $expiresAt;
        $renewalDeadline->sub(new \DateInterval('PT15M'));
        $needsRenewal = $now > $renewalDeadline;

        return [
            'expires_in' => max(0, $expiresIn),
            'needs_renewal' => $needsRenewal,
            'renewal_deadline' => $renewalDeadline->format('Y-m-d H:i:s')
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function rotateSessionTokens(string $sessionId, bool $invalidateOldTokens = true): SessionResponse
    {
        $session = $this->getSessionFromStorage($sessionId);
        
        if ($session === null) {
            throw NotFoundException::forEntity('Session', $sessionId);
        }

        // Check permissions
        if ($session['admin_id'] !== $this->getCurrentAdminId() && 
            !$this->checkPermission($this->getCurrentAdminId(), 'auth.manage_sessions')) {
            throw new AuthorizationException(
                'Not authorized to rotate session tokens',
                'ROTATE_TOKENS_FORBIDDEN'
            );
        }

        // Generate new tokens
        $newAccessToken = $this->generateSecureToken(32);
        $newRefreshToken = $this->generateSecureToken(32);

        // Store old tokens for invalidation if needed
        if ($invalidateOldTokens) {
            $oldAccessToken = $session['access_token'];
            $oldRefreshToken = $session['refresh_token'];
            
            // In production, add to blacklist
            $this->cache->set('auth:blacklist:access:' . $oldAccessToken, true, 3600);
            $this->cache->set('auth:blacklist:refresh:' . $oldRefreshToken, true, 86400);
        }

        // Update session with new tokens
        $session['access_token'] = $newAccessToken;
        $session['refresh_token'] = $newRefreshToken;
        $session['last_activity'] = Time::now()->toDateTimeString();

        // Save session
        $this->storeSession($sessionId, $session);

        // Clear cache
        $this->queueCacheOperation('auth:session:' . $sessionId);

        // Audit token rotation
        $this->audit(
            'session.tokens_rotated',
            'admin',
            $session['admin_id'],
            null,
            ['rotated_at' => Time::now()->toDateTimeString()],
            [
                'session_id' => $sessionId,
                'invalidate_old_tokens' => $invalidateOldTokens
            ]
        );

        return $this->createSessionResponse($session);
    }

    // ==================== CONFIGURATION & SETTINGS ====================

    /**
     * {@inheritDoc}
     */
    public function getAuthConfiguration(): array
    {
        return [
            'session_timeout' => $this->configuration['session_timeout'] ?? 3600,
            'max_login_attempts' => $this->configuration['max_login_attempts'] ?? 5,
            'lockout_duration' => $this->configuration['lockout_duration'] ?? 900,
            'require_2fa' => $this->configuration['require_2fa'] ?? false,
            'password_policy' => $this->getPasswordPolicy(),
            'allowed_ip_ranges' => $this->configuration['allowed_ip_ranges'] ?? [],
            'device_management' => [
                'check_device_consistency' => $this->configuration['check_device_consistency'] ?? false,
                'max_devices_per_admin' => $this->configuration['max_devices_per_admin'] ?? 10
            ]
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function updateAuthConfiguration(array $config): array
    {
        $this->authorize('auth.configure');

        return $this->transaction(function () use ($config) {
            $oldConfig = $this->configuration;
            $changes = [];

            // Validate configuration
            $validationErrors = $this->validateAuthConfiguration($config);
            if (!empty($validationErrors)) {
                throw new ValidationException(
                    'Configuration validation failed',
                    'CONFIG_VALIDATION_FAILED',
                    $validationErrors
                );
            }

            // Apply changes
            foreach ($config as $key => $value) {
                if (isset($oldConfig[$key]) && $oldConfig[$key] !== $value) {
                    $changes[$key] = [
                        'old' => $oldConfig[$key],
                        'new' => $value
                    ];
                    $this->configuration[$key] = $value;
                } elseif (!isset($oldConfig[$key])) {
                    $changes[$key] = [
                        'old' => null,
                        'new' => $value
                    ];
                    $this->configuration[$key] = $value;
                }
            }

            // Save configuration
            $this->saveConfiguration($this->configuration);

            // Clear cache
            $this->queueCacheOperation('auth_config:*');

            // Audit configuration change
            $this->audit(
                'auth.configuration_updated',
                'system',
                0,
                $oldConfig,
                $this->configuration,
                [
                    'performed_by' => $this->getCurrentAdminId(),
                    'changes' => $changes
                ]
            );

            return [
                'updated' => !empty($changes),
                'changes' => $changes
            ];
        }, 'auth_update_configuration');
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthMethods(): array
    {
        return [
            'password' => 'Username/Password',
            'two_factor' => 'Two-Factor Authentication',
            'api_token' => 'API Token',
            'delegation' => 'Delegated Access',
            'impersonation' => 'Impersonation'
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function isAuthMethodEnabled(string $method): bool
    {
        $enabledMethods = $this->configuration['enabled_auth_methods'] ?? ['password', 'api_token'];
        return in_array($method, $enabledMethods);
    }

    // ==================== UTILITY & HELPER METHODS ====================

    /**
     * {@inheritDoc}
     */
    public function generateSecureToken(int $length = 32, string $type = 'alphanumeric'): string
    {
        switch ($type) {
            case 'numeric':
                $characters = '0123456789';
                break;
            case 'hex':
                $characters = '0123456789abcdef';
                break;
            case 'base32':
                $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
                break;
            case 'alphanumeric':
            default:
                $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
        }

        $token = '';
        $max = strlen($characters) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $token .= $characters[random_int(0, $max)];
        }

        return $token;
    }

    /**
     * {@inheritDoc}
     */
    public function hashData(string $data, array $options = []): string
    {
        $algorithm = $options['algorithm'] ?? PASSWORD_DEFAULT;
        $cost = $options['cost'] ?? 12;

        return password_hash($data, $algorithm, ['cost' => $cost]);
    }

    /**
     * {@inheritDoc}
     */
    public function verifyHash(string $data, string $hash): bool
    {
        return password_verify($data, $hash);
    }

    /**
     * {@inheritDoc}
     */
    public function getAuthHealthStatus(): array
    {
        $activeSessions = count($this->sessions);
        
        // Get failed logins in last hour
        $oneHourAgo = Time::now()->subHours(1)->toDateTimeString();
        $failedLogins = $this->auditLogRepository->count([
            'action_type' => 'login.failed',
            'performed_at >=' => $oneHourAgo
        ]);

        // Get active lockouts
        $lockedAccounts = $this->adminRepository->count(['login_attempts >=' => $this->configuration['max_login_attempts'] ?? 5]);

        // Check token expiration
        $tokenExpirationCheck = true;
        foreach ($this->apiTokens as $tokenData) {
            if (!$tokenData['revoked']) {
                $expiresAt = new \DateTime($tokenData['expires_at']);
                $now = new \DateTime();
                if ($now > $expiresAt) {
                    $tokenExpirationCheck = false;
                    break;
                }
            }
        }

        $warnings = [];
        if ($failedLogins > 10) {
            $warnings[] = 'High number of failed logins in last hour: ' . $failedLogins;
        }
        
        if ($lockedAccounts > 5) {
            $warnings[] = 'Multiple accounts are locked: ' . $lockedAccounts;
        }
        
        if (!$tokenExpirationCheck) {
            $warnings[] = 'Some API tokens may be expired';
        }

        return [
            'status' => empty($warnings) ? 'healthy' : 'warning',
            'active_sessions' => $activeSessions,
            'failed_logins_last_hour' => $failedLogins,
            'lockouts_active' => $lockedAccounts,
            'token_expiration_check' => $tokenExpirationCheck,
            'warnings' => $warnings
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function clearAuthCache(?int $adminId = null): bool
    {
        if ($adminId !== null) {
            $this->queueCacheOperation('auth:*:' . $adminId . ':*');
            $this->queueCacheOperation('admin:' . $adminId);
        } else {
            $this->queueCacheOperation('auth:*');
        }

        return true;
    }

    // ==================== ABSTRACT METHOD IMPLEMENTATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        return $this->authValidator->validate($dto, $context);
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'AuthService';
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Load authentication configuration
     *
     * @return array<string, mixed>
     */
    private function loadConfiguration(): array
    {
        // Default configuration
        $defaultConfig = [
            'session_timeout' => 3600, // 1 hour
            'max_login_attempts' => 5,
            'lockout_duration' => 900, // 15 minutes
            'require_2fa' => false,
            'password_min_length' => 8,
            'password_require_uppercase' => true,
            'password_require_lowercase' => true,
            'password_require_numbers' => true,
            'password_require_special_chars' => false,
            'password_prevent_reuse' => 5,
            'password_expiry_days' => 90,
            'max_session_extension' => 86400, // 24 hours
            'check_ip_consistency' => false,
            'check_device_consistency' => false,
            'max_devices_per_admin' => 10,
            'allowed_ip_ranges' => [],
            'enabled_auth_methods' => ['password', 'api_token'],
            'app_name' => 'DevDaily'
        ];

        // In production, load from database or config file
        return $defaultConfig;
    }

    /**
     * Save configuration
     *
     * @param array<string, mixed> $config
     * @return void
     */
    private function saveConfiguration(array $config): void
    {
        // In production, save to database or config file
        $this->configuration = $config;
    }

    /**
     * Validate authentication configuration
     *
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function validateAuthConfiguration(array $config): array
    {
        $errors = [];

        if (isset($config['session_timeout'])) {
            if (!is_int($config['session_timeout']) || $config['session_timeout'] < 300) {
                $errors['session_timeout'] = 'Session timeout must be at least 300 seconds';
            }
        }

        if (isset($config['max_login_attempts'])) {
            if (!is_int($config['max_login_attempts']) || $config['max_login_attempts'] < 1) {
                $errors['max_login_attempts'] = 'Max login attempts must be at least 1';
            }
        }

        if (isset($config['lockout_duration'])) {
            if (!is_int($config['lockout_duration']) || $config['lockout_duration'] < 60) {
                $errors['lockout_duration'] = 'Lockout duration must be at least 60 seconds';
            }
        }

        if (isset($config['password_min_length'])) {
            if (!is_int($config['password_min_length']) || $config['password_min_length'] < 6) {
                $errors['password_min_length'] = 'Password minimum length must be at least 6';
            }
        }

        return $errors;
    }

    /**
     * Get session from storage
     *
     * @param string $sessionId
     * @return array|null
     */
    private function getSessionFromStorage(string $sessionId): ?array
    {
        // In production, get from Redis/database
        // For MVP, use in-memory storage
        return $this->sessions[$sessionId] ?? null;
    }

    /**
     * Get all sessions for admin from storage
     *
     * @param int $adminId
     * @return array<string, array>
     */
    private function getAdminSessionsFromStorage(int $adminId): array
    {
        // In production, query database/Redis
        // For MVP, filter in-memory storage
        $adminSessions = [];
        foreach ($this->sessions as $sessionId => $session) {
            if ($session['admin_id'] === $adminId) {
                $adminSessions[$sessionId] = $session;
            }
        }
        return $adminSessions;
    }

    /**
     * Store session in storage
     *
     * @param string $sessionId
     * @param array $sessionData
     * @return void
     */
    private function storeSession(string $sessionId, array $sessionData): void
    {
        // In production, store in Redis/database
        // For MVP, store in memory
        $this->sessions[$sessionId] = $sessionData;
    }

    /**
     * Remove session from storage
     *
     * @param string $sessionId
     * @return void
     */
    private function removeSession(string $sessionId): void
    {
        // In production, remove from Redis/database
        // For MVP, remove from memory
        unset($this->sessions[$sessionId]);
    }

    /**
     * Check if session is expired
     *
     * @param array $session
     * @return bool
     */
    private function isSessionExpired(array $session): bool
    {
        $expiresAt = new \DateTime($session['expires_at']);
        $now = new \DateTime();
        return $now > $expiresAt;
    }

    /**
     * Create SessionResponse from session data
     *
     * @param array $session
     * @return SessionResponse
     */
    private function createSessionResponse(array $session): SessionResponse
    {
        return SessionResponse::fromArray([
            'sessionId' => $session['session_id'],
            'adminId' => $session['admin_id'],
            'accessToken' => $session['access_token'],
            'refreshToken' => $session['refresh_token'],
            'createdAt' => $session['created_at'],
            'expiresAt' => $session['expires_at'],
            'lastActivity' => $session['last_activity'],
            'ipAddress' => $session['ip_address'],
            'userAgent' => $session['user_agent'],
            'deviceInfo' => $session['device_info'] ?? [],
            'location' => $session['location'] ?? null,
            'metadata' => $session['metadata'] ?? [],
            'isImpersonated' => $session['is_impersonated'] ?? false,
            'impersonatorId' => $session['impersonator_id'] ?? null
        ]);
    }

    /**
     * Get current session ID for admin
     *
     * @param int $adminId
     * @return string|null
     */
    private function getCurrentSessionId(int $adminId): ?string
    {
        $sessions = $this->getAdminSessionsFromStorage($adminId);
        foreach ($sessions as $sessionId => $session) {
            if (!$this->isSessionExpired($session)) {
                return $sessionId;
            }
        }
        return null;
    }

    /**
     * Record anonymous failed attempt (admin not found)
     *
     * @param string $identifier
     * @param string|null $ipAddress
     * @return void
     */
    private function recordAnonymousFailedAttempt(string $identifier, ?string $ipAddress): void
    {
        // Audit anonymous failed attempt
        $this->audit(
            'login.failed_anonymous',
            'system',
            0,
            null,
            [
                'attempt_time' => Time::now()->toDateTimeString(),
                'identifier' => $identifier
            ],
            ['ip_address' => $ipAddress]
        );
    }

    /**
     * Handle suspicious login
     *
     * @param int $adminId
     * @param array $suspiciousActivity
     * @param LoginRequest $request
     * @return void
     */
    private function handleSuspiciousLogin(int $adminId, array $suspiciousActivity, LoginRequest $request): void
    {
        // Audit suspicious login
        $this->audit(
            'login.suspicious',
            'admin',
            $adminId,
            null,
            [
                'detected_at' => Time::now()->toDateTimeString(),
                'risk_score' => $suspiciousActivity['risk_score'],
                'factors' => $suspiciousActivity['factors']
            ],
            [
                'ip_address' => $request->ipAddress,
                'user_agent' => $request->userAgent,
                'location' => $request->location
            ]
        );

        // In production, send notification to admin
        // For MVP, just log it
        log_message('warning', sprintf(
            'Suspicious login detected for admin %d: %s',
            $adminId,
            implode(', ', $suspiciousActivity['factors'])
        ));
    }

    /**
     * Extract device info from user agent
     *
     * @param string $userAgent
     * @return array
     */
    private function extractDeviceInfo(string $userAgent): array
    {
        // Basic device detection for MVP
        // In production, use a proper library like DeviceDetector
        
        $browser = 'Unknown';
        $platform = 'Unknown';
        
        // Detect browser
        if (strpos($userAgent, 'Chrome') !== false) {
            $browser = 'Chrome';
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            $browser = 'Firefox';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            $browser = 'Safari';
        } elseif (strpos($userAgent, 'Edge') !== false) {
            $browser = 'Edge';
        } elseif (strpos($userAgent, 'Opera') !== false) {
            $browser = 'Opera';
        }
        
        // Detect platform
        if (strpos($userAgent, 'Windows') !== false) {
            $platform = 'Windows';
        } elseif (strpos($userAgent, 'Mac') !== false) {
            $platform = 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            $platform = 'Linux';
        } elseif (strpos($userAgent, 'Android') !== false) {
            $platform = 'Android';
        } elseif (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
            $platform = 'iOS';
        }

        return [
            'browser' => $browser,
            'platform' => $platform,
            'user_agent' => $userAgent,
            'device_hash' => md5($userAgent)
        ];
    }

    /**
     * Extract location from IP address
     *
     * @param string|null $ip
     * @return array|null
     */
    private function extractLocationFromIp(?string $ip): ?array
    {
        if (empty($ip) || $ip === '127.0.0.1') {
            return null;
        }

        // For MVP, return basic info
        // In production, use IP geolocation service
        return [
            'ip' => $ip,
            'country' => 'Unknown',
            'city' => 'Unknown',
            'timezone' => 'UTC'
        ];
    }

    /**
     * Get admin's known IPs
     *
     * @param int $adminId
     * @return array<string>
     */
    private function getAdminKnownIps(int $adminId): array
    {
        // Get last 10 successful logins
        $logs = $this->auditLogRepository->findByAdminId($adminId, 10, 0);
        
        $knownIps = [];
        foreach ($logs as $log) {
            if ($log->getActionType() === 'admin.logged_in' && $log->getIpAddress()) {
                $knownIps[] = $log->getIpAddress();
            }
        }
        
        return array_unique($knownIps);
    }

    /**
     * Get admin's known devices
     *
     * @param int $adminId
     * @return array<string>
     */
    private function getAdminKnownDevices(int $adminId): array
    {
        // Get last 10 successful logins
        $logs = $this->auditLogRepository->findByAdminId($adminId, 10, 0);
        
        $knownDevices = [];
        foreach ($logs as $log) {
            if ($log->getActionType() === 'admin.logged_in' && $log->getUserAgent()) {
                $knownDevices[] = md5($log->getUserAgent());
            }
        }
        
        return array_unique($knownDevices);
    }

    /**
     * Get recent login count
     *
     * @param int $adminId
     * @param int $hours
     * @return int
     */
    private function getRecentLoginCount(int $adminId, int $hours): int
    {
        $startTime = Time::now()->subHours($hours)->toDateTimeString();
        
        $logs = $this->auditLogRepository->findByAdminId($adminId, 50, 0);
        
        $count = 0;
        foreach ($logs as $log) {
            if ($log->getActionType() === 'admin.logged_in' && 
                $log->getPerformedAt() >= new \DateTime($startTime)) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Get last login location
     *
     * @param int $adminId
     * @return array|null
     */
    private function getLastLoginLocation(int $adminId): ?array
    {
        $logs = $this->auditLogRepository->findByAdminId($adminId, 1, 0);
        
        foreach ($logs as $log) {
            if ($log->getActionType() === 'admin.logged_in' && $log->getIpAddress()) {
                return $this->extractLocationFromIp($log->getIpAddress());
            }
        }
        
        return null;
    }

    /**
     * Calculate distance between two locations (Haversine formula)
     *
     * @param array $location1
     * @param array $location2
     * @return float
     */
    private function calculateDistance(array $location1, array $location2): float
    {
        // For MVP, return 0 (not implemented)
        // In production, calculate actual distance
        return 0.0;
    }

    /**
     * Cleanup expired password reset tokens
     *
     * @return void
     */
    private function cleanupExpiredPasswordTokens(): void
    {
        $now = new \DateTime();
        foreach ($this->passwordResetTokens as $token => $tokenData) {
            $expiresAt = new \DateTime($tokenData['expires_at']);
            if ($now > $expiresAt) {
                unset($this->passwordResetTokens[$token]);
            }
        }
    }

    /**
     * Validate JWT token
     *
     * @param string $token
     * @return Admin
     * @throws AuthorizationException
     */
    private function validateJwtToken(string $token): Admin
    {
        // For MVP, basic validation
        // In production, use proper JWT library
        
        // Check blacklist
        if ($this->cache->get('jwt:blacklist:' . md5($token))) {
            throw new AuthorizationException(
                'Token has been revoked',
                'TOKEN_REVOKED'
            );
        }

        // Parse token (simplified)
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new AuthorizationException(
                'Invalid JWT format',
                'INVALID_JWT_FORMAT'
            );
        }

        // Decode payload (for MVP, assume it contains admin_id)
        $payload = json_decode(base64_decode($parts[1]), true);
        if (!$payload || !isset($payload['admin_id'])) {
            throw new AuthorizationException(
                'Invalid JWT payload',
                'INVALID_JWT_PAYLOAD'
            );
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new AuthorizationException(
                'JWT token expired',
                'JWT_EXPIRED'
            );
        }

        return $this->getEntity($this->adminRepository, $payload['admin_id']);
    }

    /**
     * Get valid API scopes
     *
     * @return array<string>
     */
    private function getValidApiScopes(): array
    {
        return [
            'admin.read',
            'admin.write',
            'product.read',
            'product.write',
            'category.read',
            'category.write',
            'marketplace.read',
            'marketplace.write',
            'audit.read'
        ];
    }

    /**
     * Get valid delegation permissions
     *
     * @return array<string>
     */
    private function getValidDelegationPermissions(): array
    {
        return [
            'product.create',
            'product.update',
            'product.publish',
            'category.manage',
            'marketplace.view'
        ];
    }

    /**
     * Calculate security score for admin
     *
     * @param int $adminId
     * @return int
     */
    private function calculateSecurityScore(int $adminId): int
    {
        $score = 100;

        // Deduct for missing 2FA
        if (!$this->isTwoFactorEnabled($adminId)) {
            $score -= 30;
        }

        // Check password age (if implemented)
        // Check for weak password (if implemented)
        // Check for active sessions on multiple devices
        // Check for suspicious activity history

        return max(0, $score);
    }

    /**
     * Generate security recommendations
     *
     * @param int $adminId
     * @param int $securityScore
     * @return array<string>
     */
    private function generateSecurityRecommendations(int $adminId, int $securityScore): array
    {
        $recommendations = [];

        if ($securityScore < 70) {
            if (!$this->isTwoFactorEnabled($adminId)) {
                $recommendations[] = 'Enable two-factor authentication';
            }
            
            $recommendations[] = 'Use a strong, unique password';
            $recommendations[] = 'Review active sessions regularly';
        }

        if ($securityScore < 50) {
            $recommendations[] = 'Review recent security events';
            $recommendations[] = 'Consider changing your password';
            $recommendations[] = 'Review API token permissions';
        }

        return $recommendations;
    }
}