<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\Response;
use Config\Services;

class AdminAuth implements FilterInterface
{
    /**
     * Konfigurasi auth
     * 
     * @var array
     */
    private $config;
    
    /**
     * Routes yang dikecualikan dari auth
     * 
     * @var array
     */
    private $exceptRoutes = [
        'admin/login',
        'admin/logout',
        'admin/forgot-password',
        'admin/reset-password/*',
        'admin/auth/*',
    ];
    
    /**
     * Routes yang hanya memerlukan guest (tidak terautentikasi)
     * 
     * @var array
     */
    private $guestOnlyRoutes = [
        'admin/login',
        'admin/forgot-password',
        'admin/reset-password/*',
    ];
    
    /**
     * Constructor - Load config
     */
    public function __construct()
    {
        $this->config = config('Auth');
    }
    
    /**
     * Sebelum request diproses
     * 
     * @param RequestInterface $request
     * @param array|null $arguments
     * @return RequestInterface|ResponseInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Get current route
        $currentRoute = $request->getUri()->getPath();
        $router = Services::router();
        $currentRoute = $router->getMatchedRoute() ?? $currentRoute;
        
        // Skip untuk routes yang dikecualikan
        if ($this->shouldSkip($currentRoute)) {
            return null;
        }
        
        // Cek apakah request adalah untuk guest only routes
        if ($this->isGuestOnlyRoute($currentRoute) && $this->isLoggedIn()) {
            return $this->redirectToDashboard($request);
        }
        
        // Cek apakah user sudah login
        if (!$this->isLoggedIn()) {
            return $this->handleUnauthenticated($request);
        }
        
        // Cek apakah token valid (jika menggunakan token)
        if ($request->getHeader('Authorization') && !$this->isValidToken($request)) {
            return $this->handleInvalidToken($request);
        }
        
        // Cek apakah session masih valid (non-database check)
        if (!$this->isSessionValid()) {
            return $this->handleInvalidSession($request);
        }
        
        // Update last activity time
        $this->updateLastActivity();
        
        // Attach admin data ke request untuk filter berikutnya
        $this->attachAdminToRequest($request);
        
        return $request;
    }
    
    /**
     * Setelah request diproses
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array|null $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tambahkan security headers khusus untuk admin area
        $this->addAdminSecurityHeaders($response);
        
        return $response;
    }
    
    /**
     * Cek apakah route dikecualikan dari auth
     * 
     * @param string $route
     * @return bool
     */
    private function shouldSkip(string $route): bool
    {
        foreach ($this->exceptRoutes as $pattern) {
            if ($this->routeMatches($route, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Cek apakah route hanya untuk guest
     * 
     * @param string $route
     * @return bool
     */
    private function isGuestOnlyRoute(string $route): bool
    {
        foreach ($this->guestOnlyRoutes as $pattern) {
            if ($this->routeMatches($route, $pattern)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Cek apakah user sudah login (hanya cek session/token)
     * 
     * @return bool
     */
    private function isLoggedIn(): bool
    {
        // Cek session
        if (session()->has('admin_id')) {
            return true;
        }
        
        // Cek token dari Authorization header
        $request = Services::request();
        if ($request->getHeader('Authorization')) {
            $token = $this->extractToken($request);
            return $token !== null;
        }
        
        // Cek remember me cookie
        if ($this->hasValidRememberMeCookie()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Cek apakah token valid (format & signature)
     * 
     * @param RequestInterface $request
     * @return bool
     */
    private function isValidToken(RequestInterface $request): bool
    {
        $token = $this->extractToken($request);
        
        if (!$token) {
            return false;
        }
        
        // Validasi format token (JWT format: header.payload.signature)
        if (!$this->isValidTokenFormat($token)) {
            log_message('debug', 'Invalid token format: ' . substr($token, 0, 50));
            return false;
        }
        
        // Untuk sekarang, hanya cek format
        // Validasi penuh akan dilakukan di AdminSessionCheck (The Validator)
        return true;
    }
    
    /**
     * Cek apakah session valid (non-database check)
     * 
     * @return bool
     */
    private function isSessionValid(): bool
    {
        // Cek apakah session belum expired
        $lastActivity = session()->get('last_activity');
        if (!$lastActivity) {
            return false;
        }
        
        $sessionTimeout = $this->config->sessionTimeout ?? 7200; // 2 jam default
        if (time() - $lastActivity > $sessionTimeout) {
            return false;
        }
        
        // Cek user agent consistency
        if ($this->config->sessionCheckUserAgent ?? true) {
            $currentUserAgent = Services::request()->getUserAgent()->getAgentString();
            $storedUserAgent = session()->get('user_agent');
            
            if ($storedUserAgent !== $currentUserAgent) {
                log_message('warning', 'User agent mismatch in session');
                return false;
            }
        }
        
        // Cek IP address consistency (opsional, strict)
        if ($this->config->sessionCheckIP ?? false) {
            $currentIP = Services::request()->getIPAddress();
            $storedIP = session()->get('ip_address');
            
            if ($storedIP !== $currentIP) {
                log_message('warning', 'IP address mismatch in session');
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Extract token dari request
     * 
     * @param RequestInterface $request
     * @return string|null
     */
    private function extractToken(RequestInterface $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader)) {
            return null;
        }
        
        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
    
    /**
     * Cek format token
     * 
     * @param string $token
     * @return bool
     */
    private function isValidTokenFormat(string $token): bool
    {
        // Cek apakah token memiliki format JWT yang valid
        // JWT minimal: xxx.yyy.zzz
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            return false;
        }
        
        // Cek apakah setiap part adalah base64 encoded
        foreach ($parts as $part) {
            if (!preg_match('/^[A-Za-z0-9\-_]+$/', $part)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Cek remember me cookie
     * 
     * @return bool
     */
    private function hasValidRememberMeCookie(): bool
    {
        $cookieName = $this->config->rememberCookieName ?? 'remember_admin';
        $cookie = Services::request()->getCookie($cookieName);
        
        if (!$cookie) {
            return false;
        }
        
        // Format: selector:validator
        $parts = explode(':', $cookie);
        
        if (count($parts) !== 2) {
            return false;
        }
        
        // Hanya cek format di sini, validasi database di AdminSessionCheck
        return true;
    }
    
    /**
     * Handle unauthenticated request
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function handleUnauthenticated(RequestInterface $request): ResponseInterface
    {
        $response = Services::response();
        
        // API/JSON request
        if ($request->isAJAX() || $this->isApiRequest($request)) {
            return $response->setStatusCode(401)->setJSON([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required',
                    'type' => 'AUTH_REQUIRED'
                ]
            ]);
        }
        
        // Web request - redirect ke login
        session()->set('redirect_url', current_url());
        session()->setFlashdata('error', 'Please login to continue');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Handle invalid token
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function handleInvalidToken(RequestInterface $request): ResponseInterface
    {
        $response = Services::response();
        
        return $response->setStatusCode(401)->setJSON([
            'error' => [
                'code' => 'INVALID_TOKEN',
                'message' => 'Invalid or malformed authentication token',
                'type' => 'TOKEN_INVALID'
            ]
        ]);
    }
    
    /**
     * Handle invalid session
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function handleInvalidSession(RequestInterface $request): ResponseInterface
    {
        $response = Services::response();
        
        // Clear invalid session
        session()->destroy();
        
        // API/JSON request
        if ($request->isAJAX() || $this->isApiRequest($request)) {
            return $response->setStatusCode(401)->setJSON([
                'error' => [
                    'code' => 'SESSION_EXPIRED',
                    'message' => 'Your session has expired',
                    'type' => 'SESSION_INVALID'
                ]
            ]);
        }
        
        // Web request
        session()->setFlashdata('error', 'Your session has expired. Please login again.');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Redirect ke dashboard jika sudah login
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function redirectToDashboard(RequestInterface $request): ResponseInterface
    {
        // API request tidak boleh redirect
        if ($request->isAJAX() || $this->isApiRequest($request)) {
            $response = Services::response();
            return $response->setStatusCode(400)->setJSON([
                'error' => [
                    'code' => 'ALREADY_AUTHENTICATED',
                    'message' => 'You are already logged in',
                    'type' => 'AUTH_CONFLICT'
                ]
            ]);
        }
        
        // Web request - redirect ke dashboard
        return redirect()->to($this->config->dashboardUrl ?? '/admin/dashboard');
    }
    
    /**
     * Update last activity time
     */
    private function updateLastActivity(): void
    {
        session()->set('last_activity', time());
    }
    
    /**
     * Attach admin data ke request untuk filter berikutnya
     * 
     * @param RequestInterface $request
     */
    private function attachAdminToRequest(RequestInterface $request): void
    {
        // Attach basic admin info dari session
        $adminData = [
            'id' => session()->get('admin_id'),
            'username' => session()->get('admin_username'),
            'email' => session()->get('admin_email'),
            'role' => session()->get('admin_role'),
            'authenticated_via' => session()->has('admin_id') ? 'session' : 'token',
        ];
        
        // Simpan di request attribute untuk digunakan nanti
        $request->admin = (object) $adminData;
    }
    
    /**
     * Tambahkan security headers untuk admin area
     * 
     * @param ResponseInterface $response
     */
    private function addAdminSecurityHeaders(ResponseInterface $response): void
    {
        // HSTS untuk admin area
        $response->setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        
        // No cache untuk admin area
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        
        // Additional security headers
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'DENY');
        $response->setHeader('X-XSS-Protection', '1; mode=block');
        
        // Referrer policy
        $response->setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        
        // Permissions policy
        $response->setHeader('Permissions-Policy', 
            'camera=(), microphone=(), geolocation=(), interest-cohort=()'
        );
    }
    
    /**
     * Cek apakah request adalah API request
     * 
     * @param RequestInterface $request
     * @return bool
     */
    private function isApiRequest(RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        return strpos($path, '/api/') === 0 || strpos($path, '/admin/api/') === 0;
    }
    
    /**
     * Cek apakah route match dengan pattern
     * 
     * @param string $route
     * @param string $pattern
     * @return bool
     */
    private function routeMatches(string $route, string $pattern): bool
    {
        // Convert wildcard * to regex
        $pattern = str_replace('\*', '.*', preg_quote($pattern, '/'));
        return (bool) preg_match('/^' . $pattern . '$/i', $route);
    }
}