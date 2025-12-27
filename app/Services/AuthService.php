<?php

namespace App\Services;

use App\Entities\Admin;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Exceptions\ValidationException;
use App\Exceptions\DomainException;
use CodeIgniter\Session\Session;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

class AuthService
{
    private AdminRepositoryInterface $adminRepository;
    private Session $session;
    private RequestInterface $request;
    private CacheService $cacheService;
    private AuditService $auditService;
    
    // Configuration constants
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION = 900; // 15 minutes in seconds
    private const SESSION_DURATION = 7200; // 2 hours
    private const REMEMBER_ME_DURATION = 2592000; // 30 days
    
    // Session keys
    private const SESSION_ADMIN_ID = 'admin_id';
    private const SESSION_ADMIN_DATA = 'admin_data';
    private const SESSION_LAST_ACTIVITY = 'last_activity';
    private const SESSION_REMEMBER_ME = 'remember_me';
    
    // Cache keys
    private const CACHE_PREFIX_LOGIN_ATTEMPTS = 'login_attempts_';
    private const CACHE_PREFIX_LOCKOUT = 'lockout_';
    private const CACHE_PREFIX_SESSION = 'session_';
    
    public function __construct(
        AdminRepositoryInterface $adminRepository,
        Session $session,
        RequestInterface $request,
        CacheService $cacheService,
        AuditService $auditService
    ) {
        $this->adminRepository = $adminRepository;
        $this->session = $session;
        $this->request = $request;
        $this->cacheService = $cacheService;
        $this->auditService = $auditService;
    }

    /**
     * Authenticate admin with credentials
     * 
     * @param string $identifier Email or username
     * @param string $password Plain text password
     * @param bool $rememberMe Remember login
     * @return array Authentication result with admin data
     * @throws ValidationException|DomainException
     */
    public function login(string $identifier, string $password, bool $rememberMe = false): array
    {
        // 1. Validate input
        $this->validateLoginInput($identifier, $password);
        
        // 2. Check lockout status
        $lockoutKey = $this->getLockoutCacheKey($identifier);
        $lockoutData = $this->cacheService->get($lockoutKey);
        
        if ($lockoutData && $lockoutData['locked_until'] > time()) {
            $remaining = $lockoutData['locked_until'] - time();
            throw new DomainException(
                sprintf('Account is locked. Try again in %s.', $this->formatLockoutTime($remaining)),
                ['locked_until' => $lockoutData['locked_until'], 'remaining_seconds' => $remaining]
            );
        }
        
        // 3. Find admin
        $admin = $this->findAdminByIdentifier($identifier);
        if (!$admin) {
            $this->recordFailedLoginAttempt($identifier);
            throw new ValidationException('Invalid credentials', ['field' => 'identifier']);
        }
        
        // 4. Verify password
        if (!$this->verifyPassword($password, $admin->getPasswordHash())) {
            $this->recordFailedLoginAttempt($identifier, $admin->getId());
            throw new ValidationException('Invalid credentials', ['field' => 'password']);
        }
        
        // 5. Check if admin is active
        if (!$admin->isActive()) {
            throw new DomainException('Account is deactivated', ['admin_id' => $admin->getId()]);
        }
        
        // 6. Reset login attempts on successful login
        $this->resetLoginAttempts($identifier);
        
        // 7. Update admin login info
        $this->updateAdminLoginInfo($admin);
        
        // 8. Create session
        $sessionData = $this->createSession($admin, $rememberMe);
        
        // 9. Log audit trail
        $this->auditService->logAuthentication(
            $admin->getId(),
            true,
            'login',
            $this->request->getIPAddress(),
            $this->request->getUserAgent()
        );
        
        return [
            'success' => true,
            'admin' => $this->prepareAdminData($admin),
            'session' => $sessionData,
            'remember_me' => $rememberMe,
        ];
    }

    /**
     * Logout current admin
     */
    public function logout(?int $adminId = null): bool
    {
        if (!$adminId) {
            $adminId = $this->getCurrentAdminId();
        }
        
        if ($adminId) {
            $this->auditService->logAuthentication(
                $adminId,
                true,
                'logout',
                $this->request->getIPAddress(),
                $this->request->getUserAgent()
            );
        }
        
        // Destroy session
        $this->destroySession();
        
        return true;
    }

    /**
     * Check if admin is logged in
     */
    public function isLoggedIn(): bool
    {
        return $this->getCurrentAdminId() !== null;
    }

    /**
     * Get current logged in admin ID
     */
    public function getCurrentAdminId(): ?int
    {
        return $this->session->get(self::SESSION_ADMIN_ID);
    }

    /**
     * Get current logged in admin data
     */
    public function getCurrentAdmin(): ?array
    {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $adminId = $this->getCurrentAdminId();
        $cachedData = $this->session->get(self::SESSION_ADMIN_DATA);
        
        if ($cachedData && $cachedData['id'] === $adminId) {
            // Refresh last activity
            $this->session->set(self::SESSION_LAST_ACTIVITY, time());
            return $cachedData;
        }
        
        // Fetch fresh data
        $admin = $this->adminRepository->find($adminId);
        if (!$admin || !$admin->isActive()) {
            $this->logout();
            return null;
        }
        
        $adminData = $this->prepareAdminData($admin);
        $this->session->set(self::SESSION_ADMIN_DATA, $adminData);
        $this->session->set(self::SESSION_LAST_ACTIVITY, time());
        
        return $adminData;
    }

    /**
     * Validate session and refresh if needed
     */
    public function validateSession(): bool
    {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $lastActivity = $this->session->get(self::SESSION_LAST_ACTIVITY);
        $rememberMe = $this->session->get(self::SESSION_REMEMBER_ME);
        $sessionTimeout = $rememberMe ? self::REMEMBER_ME_DURATION : self::SESSION_DURATION;
        
        // Check session expiration
        if (!$lastActivity || (time() - $lastActivity) > $sessionTimeout) {
            $this->logout();
            return false;
        }
        
        // Refresh activity timestamp if not using remember me
        if (!$rememberMe && (time() - $lastActivity) > 300) { // Refresh every 5 minutes
            $this->session->set(self::SESSION_LAST_ACTIVITY, time());
        }
        
        return true;
    }

    /**
     * Check login attempts and lockout status
     */
    public function getLoginAttempts(string $identifier): array
    {
        $attemptsKey = $this->getLoginAttemptsCacheKey($identifier);
        $attemptsData = $this->cacheService->get($attemptsKey) ?? [
            'count' => 0,
            'first_attempt' => null,
            'last_attempt' => null,
        ];
        
        $lockoutKey = $this->getLockoutCacheKey($identifier);
        $lockoutData = $this->cacheService->get($lockoutKey);
        
        return [
            'attempts' => $attemptsData['count'],
            'remaining_attempts' => max(0, self::MAX_LOGIN_ATTEMPTS - $attemptsData['count']),
            'is_locked' => $lockoutData !== null && $lockoutData['locked_until'] > time(),
            'locked_until' => $lockoutData['locked_until'] ?? null,
            'next_reset' => $attemptsData['first_attempt'] ? 
                $attemptsData['first_attempt'] + (24 * 3600) : null, // 24-hour window
        ];
    }

    /**
     * Reset password (MVP: simple implementation)
     */
    public function requestPasswordReset(string $email): bool
    {
        $admin = $this->adminRepository->findByEmail($email);
        if (!$admin || !$admin->isActive()) {
            // Don't reveal if email exists for security
            return true;
        }
        
        // Generate reset token (simplified for MVP)
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = time() + 3600; // 1 hour
        
        $cacheKey = 'password_reset_' . hash('sha256', $resetToken);
        $this->cacheService->set($cacheKey, [
            'admin_id' => $admin->getId(),
            'email' => $email,
            'expires_at' => $expiresAt,
            'created_at' => time(),
        ], 3600);
        
        // In production: Send email with reset link
        // For MVP, we'll just log it
        log_message('info', sprintf(
            'Password reset requested for admin #%d (%s). Token: %s',
            $admin->getId(),
            $email,
            $resetToken
        ));
        
        return true;
    }

    /**
     * Validate reset token
     */
    public function validateResetToken(string $token): ?array
    {
        $cacheKey = 'password_reset_' . hash('sha256', $token);
        $data = $this->cacheService->get($cacheKey);
        
        if (!$data || $data['expires_at'] < time()) {
            return null;
        }
        
        return $data;
    }

    /**
     * Reset password with token
     */
    public function resetPassword(string $token, string $newPassword): bool
    {
        $data = $this->validateResetToken($token);
        if (!$data) {
            throw new ValidationException('Invalid or expired reset token');
        }
        
        $admin = $this->adminRepository->find($data['admin_id']);
        if (!$admin || !$admin->isActive()) {
            throw new DomainException('Admin account not found or inactive');
        }
        
        // Update password
        $this->adminRepository->updatePassword($admin->getId(), $newPassword);
        
        // Invalidate token
        $cacheKey = 'password_reset_' . hash('sha256', $token);
        $this->cacheService->delete($cacheKey);
        
        // Logout all sessions for this admin (optional, for MVP)
        $this->invalidateAllSessions($admin->getId());
        
        // Log audit trail
        $this->auditService->logAuthentication(
            $admin->getId(),
            true,
            'password_reset',
            $this->request->getIPAddress(),
            $this->request->getUserAgent()
        );
        
        return true;
    }

    /**
     * Update current admin's password
     */
    public function updateCurrentPassword(string $currentPassword, string $newPassword): bool
    {
        $adminId = $this->getCurrentAdminId();
        if (!$adminId) {
            throw new DomainException('Not authenticated');
        }
        
        $admin = $this->adminRepository->find($adminId);
        if (!$admin) {
            throw new DomainException('Admin not found');
        }
        
        // Verify current password
        if (!$this->verifyPassword($currentPassword, $admin->getPasswordHash())) {
            throw new ValidationException('Current password is incorrect', ['field' => 'current_password']);
        }
        
        // Update to new password
        $this->adminRepository->updatePassword($adminId, $newPassword);
        
        // Log audit trail
        $this->auditService->logAuthentication(
            $adminId,
            true,
            'password_change',
            $this->request->getIPAddress(),
            $this->request->getUserAgent()
        );
        
        return true;
    }

    /**
     * Get all active sessions for current admin (MVP: simplified)
     */
    public function getActiveSessions(): array
    {
        $adminId = $this->getCurrentAdminId();
        if (!$adminId) {
            return [];
        }
        
        // For MVP, just return current session info
        $sessionId = $this->session->get('ci_session');
        
        return [
            [
                'session_id' => $sessionId ? substr($sessionId, 0, 10) . '...' : null,
                'ip_address' => $this->request->getIPAddress(),
                'user_agent' => substr($this->request->getUserAgent(), 0, 50),
                'last_activity' => $this->session->get(self::SESSION_LAST_ACTIVITY),
                'current' => true,
            ]
        ];
    }

    /**
     * Terminate all other sessions (MVP: placeholder)
     */
    public function terminateAllOtherSessions(): bool
    {
        // For MVP with file-based sessions, we can't easily track all sessions
        // This would require database sessions or Redis
        log_message('info', 'Terminate all other sessions requested for admin #' . $this->getCurrentAdminId());
        return true;
    }

    /**
     * ==================== PRIVATE METHODS ====================
     */

    private function validateLoginInput(string $identifier, string $password): void
    {
        $errors = [];
        
        if (empty($identifier)) {
            $errors['identifier'] = 'Identifier is required';
        }
        
        if (empty($password)) {
            $errors['password'] = 'Password is required';
        }
        
        if (!empty($errors)) {
            throw new ValidationException('Validation failed', $errors);
        }
    }

    private function findAdminByIdentifier(string $identifier): ?Admin
    {
        // Try email first, then username
        $admin = $this->adminRepository->findByEmail($identifier);
        if (!$admin) {
            $admin = $this->adminRepository->findByUsername($identifier);
        }
        
        return $admin;
    }

    private function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    private function recordFailedLoginAttempt(string $identifier, ?int $adminId = null): void
    {
        $attemptsKey = $this->getLoginAttemptsCacheKey($identifier);
        $attemptsData = $this->cacheService->get($attemptsKey) ?? [
            'count' => 0,
            'first_attempt' => null,
            'last_attempt' => null,
        ];
        
        $now = time();
        
        // Reset if first attempt was more than 24 hours ago
        if ($attemptsData['first_attempt'] && ($now - $attemptsData['first_attempt']) > 86400) {
            $attemptsData = [
                'count' => 0,
                'first_attempt' => null,
                'last_attempt' => null,
            ];
        }
        
        // Increment attempt count
        if ($attemptsData['count'] === 0) {
            $attemptsData['first_attempt'] = $now;
        }
        
        $attemptsData['count']++;
        $attemptsData['last_attempt'] = $now;
        
        // Store for 24 hours
        $this->cacheService->set($attemptsKey, $attemptsData, 86400);
        
        // Check for lockout
        if ($attemptsData['count'] >= self::MAX_LOGIN_ATTEMPTS) {
            $this->setLockout($identifier);
        }
        
        // Log audit trail
        if ($adminId) {
            $this->auditService->logAuthentication(
                $adminId,
                false,
                'login_failed',
                $this->request->getIPAddress(),
                $this->request->getUserAgent(),
                ['attempts' => $attemptsData['count'], 'identifier' => $identifier]
            );
        }
    }

    private function resetLoginAttempts(string $identifier): void
    {
        $attemptsKey = $this->getLoginAttemptsCacheKey($identifier);
        $lockoutKey = $this->getLockoutCacheKey($identifier);
        
        $this->cacheService->delete($attemptsKey);
        $this->cacheService->delete($lockoutKey);
    }

    private function setLockout(string $identifier): void
    {
        $lockoutKey = $this->getLockoutCacheKey($identifier);
        $lockedUntil = time() + self::LOCKOUT_DURATION;
        
        $this->cacheService->set($lockoutKey, [
            'locked_until' => $lockedUntil,
            'locked_at' => time(),
            'identifier' => $identifier,
        ], self::LOCKOUT_DURATION);
        
        log_message('warning', sprintf(
            'Account locked for identifier: %s until %s',
            $identifier,
            date('Y-m-d H:i:s', $lockedUntil)
        ));
    }

    private function updateAdminLoginInfo(Admin $admin): void
    {
        $admin->recordLogin();
        
        // Update via repository
        $this->adminRepository->save($admin);
    }

    private function createSession(Admin $admin, bool $rememberMe = false): array
    {
        $sessionData = [
            self::SESSION_ADMIN_ID => $admin->getId(),
            self::SESSION_LAST_ACTIVITY => time(),
            self::SESSION_REMEMBER_ME => $rememberMe,
        ];
        
        // Set session with appropriate expiration
        $sessionExpiration = $rememberMe ? self::REMEMBER_ME_DURATION : self::SESSION_DURATION;
        
        foreach ($sessionData as $key => $value) {
            $this->session->set($key, $value);
        }
        
        // Also store basic admin data in session to reduce DB queries
        $adminData = $this->prepareAdminData($admin);
        $this->session->set(self::SESSION_ADMIN_DATA, $adminData);
        
        // Set cookie expiration for remember me
        if ($rememberMe) {
            $this->session->setCookieParams(['lifetime' => self::REMEMBER_ME_DURATION]);
        }
        
        return [
            'session_id' => session_id(),
            'expires_in' => $sessionExpiration,
            'remember_me' => $rememberMe,
        ];
    }

    private function destroySession(): void
    {
        $this->session->destroy();
    }

    private function prepareAdminData(Admin $admin): array
    {
        return [
            'id' => $admin->getId(),
            'username' => $admin->getUsername(),
            'email' => $admin->getEmail(),
            'name' => $admin->getName(),
            'role' => $admin->getRole(),
            'active' => $admin->isActive(),
            'last_login' => $admin->getLastLogin()?->format('Y-m-d H:i:s'),
            'initials' => $this->getInitials($admin->getName()),
        ];
    }

    private function getInitials(?string $name): string
    {
        if (!$name) {
            return '??';
        }
        
        $names = explode(' ', $name);
        $initials = '';
        
        foreach ($names as $n) {
            if (trim($n)) {
                $initials .= strtoupper(substr($n, 0, 1));
            }
        }
        
        return substr($initials, 0, 2);
    }

    private function invalidateAllSessions(int $adminId): void
    {
        // For MVP with file sessions, we can't easily invalidate all sessions
        // In a real implementation with database sessions, we would:
        // 1. Store session IDs in database with admin_id
        // 2. Delete all sessions for this admin except current
        log_message('info', 'Invalidate all sessions requested for admin #' . $adminId);
    }

    private function formatLockoutTime(int $seconds): string
    {
        if ($seconds >= 3600) {
            $hours = ceil($seconds / 3600);
            return sprintf('%d hour%s', $hours, $hours > 1 ? 's' : '');
        }
        
        $minutes = ceil($seconds / 60);
        return sprintf('%d minute%s', $minutes, $minutes > 1 ? 's' : '');
    }

    private function getLoginAttemptsCacheKey(string $identifier): string
    {
        return self::CACHE_PREFIX_LOGIN_ATTEMPTS . md5(strtolower($identifier));
    }

    private function getLockoutCacheKey(string $identifier): string
    {
        return self::CACHE_PREFIX_LOCKOUT . md5(strtolower($identifier));
    }

    private function getSessionCacheKey(string $sessionId): string
    {
        return self::CACHE_PREFIX_SESSION . $sessionId;
    }
}