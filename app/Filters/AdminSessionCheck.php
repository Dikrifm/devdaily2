<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use App\Services\AuthService;
use App\Exceptions\AdminNotFoundException;

class AdminSessionCheck implements FilterInterface
{
    /**
     * Auth service
     * 
     * @var AuthService
     */
    private $authService;
    
    /**
     * Konfigurasi auth
     * 
     * @var array
     */
    private $config;
    
    /**
     * Routes yang dikecualikan dari validasi database
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
     * Constructor
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
        $this->authService = Services::authService(); // Dari Container Layer 0
        // Get current route
        $currentRoute = $request->getUri()->getPath();
        $router = Services::router();
        $currentRoute = $router->getMatchedRoute() ?? $currentRoute;
        
        // Skip untuk routes yang dikecualikan
        if ($this->shouldSkip($currentRoute)) {
            return null;
        }
        
        // Jika tidak ada admin data dari filter sebelumnya, skip
        if (!isset($request->admin)) {
            return $request;
        }
        
        // Dapatkan admin ID dari request
        $adminId = $this->getAdminId($request);
        
        if (!$adminId) {
            return $this->handleNoAdminId($request);
        }
        
        try {
            // Validasi 1: Admin exists dan active di database
            if (!$this->authService->validateAdmin($adminId)) {
                return $this->handleInvalidAdmin($request, $adminId);
            }
            
            // Validasi 2: Session/token valid di database
            if (!$this->validateSessionOrToken($request, $adminId)) {
                return $this->handleInvalidSession($request, $adminId);
            }
            
            // Validasi 3: Admin tidak terkunci atau suspended
            if ($this->authService->isAdminLocked($adminId)) {
                return $this->handleLockedAdmin($request, $adminId);
            }
            
            // Validasi 4: Cek permissions untuk route ini (opsional)
            if (!$this->hasRoutePermission($request, $adminId)) {
                return $this->handleInsufficientPermissions($request, $adminId);
            }
            
            // Update last activity di database
            $this->authService->updateLastActivity($adminId, $request);
            
            // Load full admin data dan attach ke request
            $this->attachFullAdminData($request, $adminId);
            
        } catch (AdminNotFoundException $e) {
            return $this->handleAdminNotFound($request, $e);
        } catch (\Exception $e) {
            return $this->handleValidationError($request, $e);
        }
        
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
        // Tambahkan audit logging untuk admin actions
        $this->logAdminAction($request, $response);
        
        return $response;
    }
    
    /**
     * Dapatkan admin ID dari request
     * 
     * @param RequestInterface $request
     * @return int|null
     */
    private function getAdminId(RequestInterface $request): ?int
    {
        // Prioritas 1: Dari request attribute (dari AdminAuth filter)
        if (isset($request->admin) && isset($request->admin->id)) {
            return (int) $request->admin->id;
        }
        
        // Prioritas 2: Dari session
        if (session()->has('admin_id')) {
            return (int) session()->get('admin_id');
        }
        
        // Prioritas 3: Dari token
        $token = $this->extractToken($request);
        if ($token) {
            return $this->extractAdminIdFromToken($token);
        }
        
        // Prioritas 4: Dari remember me cookie
        $rememberCookie = $this->getRememberMeCookie();
        if ($rememberCookie) {
            return $this->extractAdminIdFromRememberCookie($rememberCookie);
        }
        
        return null;
    }
    
    /**
     * Validasi session atau token di database
     * 
     * @param RequestInterface $request
     * @param int $adminId
     * @return bool
     */
    private function validateSessionOrToken(RequestInterface $request, int $adminId): bool
    {
        $authMethod = $request->admin->authenticated_via ?? 'session';
        
        switch ($authMethod) {
            case 'session':
                return $this->validateDatabaseSession($adminId);
                
            case 'token':
                $token = $this->extractToken($request);
                return $this->validateDatabaseToken($adminId, $token);
                
            case 'remember':
                $cookie = $this->getRememberMeCookie();
                return $this->validateRememberMeCookie($adminId, $cookie);
                
            default:
                return false;
        }
    }
    
    /**
     * Validasi session di database
     * 
     * @param int $adminId
     * @return bool
     */
    private function validateDatabaseSession(int $adminId): bool
    {
        $sessionId = session_id();
        
        if (empty($sessionId)) {
            return false;
        }
        
        return $this->authService->validateSession($adminId, $sessionId);
    }
    
    /**
     * Validasi token di database
     * 
     * @param int $adminId
     * @param string $token
     * @return bool
     */
    private function validateDatabaseToken(int $adminId, string $token): bool
    {
        if (empty($token)) {
            return false;
        }
        
        return $this->authService->validateToken($adminId, $token);
    }
    
    /**
     * Validasi remember me cookie di database
     * 
     * @param int $adminId
     * @param string $cookie
     * @return bool
     */
    private function validateRememberMeCookie(int $adminId, string $cookie): bool
    {
        if (empty($cookie)) {
            return false;
        }
        
        return $this->authService->validateRememberMe($adminId, $cookie);
    }
    
    /**
     * Cek permissions untuk route
     * 
     * @param RequestInterface $request
     * @param int $adminId
     * @return bool
     */
    private function hasRoutePermission(RequestInterface $request, int $adminId): bool
    {
        // Jika permission checking diaktifkan
        if (!($this->config->enableRoutePermissions ?? false)) {
            return true;
        }
        
        $route = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        // Dapatkan required permission untuk route ini
        $requiredPermission = $this->getRequiredPermission($route, $method);
        
        if (empty($requiredPermission)) {
            return true; // Tidak ada requirement khusus
        }
        
        return $this->authService->hasPermission($adminId, $requiredPermission);
    }
    
    /**
     * Attach full admin data ke request
     * 
     * @param RequestInterface $request
     * @param int $adminId
     */
    private function attachFullAdminData(RequestInterface $request, int $adminId): void
    {
        try {
            $adminData = $this->authService->getAdminData($adminId);
            
            // Replace basic admin data dengan full data
            $request->admin = (object) array_merge((array) $request->admin, $adminData);
            
            // Tambahkan permissions ke request
            $request->admin->permissions = $this->authService->getPermissions($adminId);
            
            // Tambahkan role info
            $request->admin->role_info = $this->authService->getRoleInfo($adminId);
            
        } catch (\Exception $e) {
            log_message('error', 'Failed to load admin data: ' . $e->getMessage());
        }
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
     * Extract admin ID dari token
     * 
     * @param string $token
     * @return int|null
     */
    private function extractAdminIdFromToken(string $token): ?int
    {
        try {
            // Decode token tanpa validasi signature (validasi nanti di database)
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            
            $payload = json_decode(base64_decode($parts[1]), true);
            return $payload['admin_id'] ?? null;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Dapatkan remember me cookie
     * 
     * @return string|null
     */
    private function getRememberMeCookie(): ?string
    {
        $cookieName = $this->config->rememberCookieName ?? 'remember_admin';
        return Services::request()->getCookie($cookieName);
    }
    
    /**
     * Extract admin ID dari remember cookie
     * 
     * @param string $cookie
     * @return int|null
     */
    private function extractAdminIdFromRememberCookie(string $cookie): ?int
    {
        $parts = explode(':', $cookie);
        if (count($parts) !== 2) {
            return null;
        }
        
        $selector = $parts[0];
        
        // Query database untuk mendapatkan admin_id dari selector
        try {
            return $this->authService->getAdminIdFromRememberSelector($selector);
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Dapatkan required permission untuk route
     * 
     * @param string $route
     * @param string $method
     * @return string|null
     */
    private function getRequiredPermission(string $route, string $method): ?string
    {
        $routePermissions = $this->config->routePermissions ?? [];
        
        foreach ($routePermissions as $pattern => $permission) {
            if ($this->routeMatches($route, $pattern)) {
                // Jika permission adalah array, cek berdasarkan method
                if (is_array($permission)) {
                    $method = strtolower($method);
                    return $permission[$method] ?? $permission['*'] ?? null;
                }
                
                return $permission;
            }
        }
        
        return null;
    }
    
    /**
     * Cek apakah route dikecualikan
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
     * Cek apakah route match pattern
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
    
    /**
     * Handle ketika tidak ada admin ID
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function handleNoAdminId(RequestInterface $request): ResponseInterface
    {
        $response = Services::response();
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(401)->setJSON([
                'error' => [
                    'code' => 'NO_ADMIN_ID',
                    'message' => 'Unable to identify admin',
                    'type' => 'IDENTIFICATION_FAILED'
                ]
            ]);
        }
        
        session()->destroy();
        session()->setFlashdata('error', 'Session validation failed. Please login again.');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Handle admin tidak valid
     * 
     * @param RequestInterface $request
     * @param int $adminId
     * @return ResponseInterface
     */
    private function handleInvalidAdmin(RequestInterface $request, int $adminId): ResponseInterface
    {
        $response = Services::response();
        
        // Clear session dan token
        session()->destroy();
        $this->authService->invalidateAllSessions($adminId);
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(401)->setJSON([
                'error' => [
                    'code' => 'INVALID_ADMIN',
                    'message' => 'Admin account is invalid or inactive',
                    'type' => 'ACCOUNT_INVALID'
                ]
            ]);
        }
        
        session()->setFlashdata('error', 'Your account is no longer active.');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Handle session/token tidak valid
     * 
     * @param RequestInterface $request
     * @param int $adminId
     * @return ResponseInterface
     */
    private function handleInvalidSession(RequestInterface $request, int $adminId): ResponseInterface
    {
        $response = Services::response();
        
        // Invalidate session/token yang bermasalah
        $this->authService->invalidateSession($adminId, session_id());
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(401)->setJSON([
                'error' => [
                    'code' => 'INVALID_SESSION',
                    'message' => 'Your session or token is no longer valid',
                    'type' => 'SESSION_INVALIDATED'
                ]
            ]);
        }
        
        session()->destroy();
        session()->setFlashdata('error', 'Your session is no longer valid. Please login again.');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Handle admin terkunci
     * 
     * @param RequestInterface $request
     * @param int $adminId
     * @return ResponseInterface
     */
    private function handleLockedAdmin(RequestInterface $request, int $adminId): ResponseInterface
    {
        $response = Services::response();
        
        $lockInfo = $this->authService->getLockInfo($adminId);
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(423)->setJSON([
                'error' => [
                    'code' => 'ACCOUNT_LOCKED',
                    'message' => 'Your account has been locked',
                    'details' => $lockInfo,
                    'type' => 'ACCOUNT_LOCKED'
                ]
            ]);
        }
        
        session()->destroy();
        session()->setFlashdata('error', 
            'Your account has been locked. ' . 
            ($lockInfo['reason'] ?? 'Please contact administrator.')
        );
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Handle insufficient permissions
     * 
     * @param RequestInterface $request
     * @param int $adminId
     * @return ResponseInterface
     */
    private function handleInsufficientPermissions(RequestInterface $request, int $adminId): ResponseInterface
    {
        $response = Services::response();
        
        $route = $request->getUri()->getPath();
        $requiredPermission = $this->getRequiredPermission($route, $request->getMethod());
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(403)->setJSON([
                'error' => [
                    'code' => 'INSUFFICIENT_PERMISSIONS',
                    'message' => 'You do not have permission to access this resource',
                    'required_permission' => $requiredPermission,
                    'type' => 'PERMISSION_DENIED'
                ]
            ]);
        }
        
        session()->setFlashdata('error', 'You do not have permission to access this page.');
        
        return redirect()->to($this->config->dashboardUrl ?? '/admin/dashboard');
    }
    
    /**
     * Handle admin not found
     * 
     * @param RequestInterface $request
     * @param AdminNotFoundException $e
     * @return ResponseInterface
     */
    private function handleAdminNotFound(RequestInterface $request, AdminNotFoundException $e): ResponseInterface
    {
        $response = Services::response();
        
        // Clear semua session data
        session()->destroy();
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(404)->setJSON([
                'error' => [
                    'code' => 'ADMIN_NOT_FOUND',
                    'message' => 'Admin account not found',
                    'details' => $e->getDetails(),
                    'type' => 'ACCOUNT_NOT_FOUND'
                ]
            ]);
        }
        
        session()->setFlashdata('error', 'Admin account not found.');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Handle validation error
     * 
     * @param RequestInterface $request
     * @param \Exception $e
     * @return ResponseInterface
     */
    private function handleValidationError(RequestInterface $request, \Exception $e): ResponseInterface
    {
        log_message('error', 'AdminSessionCheck validation error: ' . $e->getMessage());
        
        $response = Services::response();
        
        if ($this->isApiRequest($request)) {
            return $response->setStatusCode(500)->setJSON([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'An error occurred during session validation',
                    'type' => 'INTERNAL_ERROR'
                ]
            ]);
        }
        
        session()->setFlashdata('error', 'An error occurred during session validation.');
        
        return redirect()->to($this->config->loginUrl ?? '/admin/login');
    }
    
    /**
     * Log admin action untuk audit
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     */
    private function logAdminAction(RequestInterface $request, ResponseInterface $response): void
    {
        // Hanya log untuk admin routes dan method yang signifikan
        if (!$this->isAdminRoute($request) || !$this->shouldLogRequest($request)) {
            return;
        }
        
        try {
            $adminId = $this->getAdminId($request);
            
            if ($adminId) {
                $this->authService->logAdminAction(
                    $adminId,
                    $request->getMethod(),
                    $request->getUri()->getPath(),
                    $request->getIPAddress(),
                    $response->getStatusCode()
                );
            }
        } catch (\Exception $e) {
            // Jangan gagal request karena logging error
            log_message('debug', 'Failed to log admin action: ' . $e->getMessage());
        }
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
     * Cek apakah route adalah admin route
     * 
     * @param RequestInterface $request
     * @return bool
     */
    private function isAdminRoute(RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        return strpos($path, '/admin/') === 0 && !strpos($path, '/admin/login');
    }
    
    /**
     * Cek apakah request harus di-log
     * 
     * @param RequestInterface $request
     * @return bool
     */
    private function shouldLogRequest(RequestInterface $request): bool
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        
        // Jangan log GET requests untuk static assets atau polling
        if ($method === 'GET') {
            $excludedPaths = ['/admin/dashboard', '/admin/notifications'];
            foreach ($excludedPaths as $excluded) {
                if (strpos($path, $excluded) === 0) {
                    return false;
                }
            }
        }
        
        // Log semua non-GET requests
        if ($method !== 'GET') {
            return true;
        }
        
        // Untuk GET, hanya log routes penting
        $importantRoutes = [
            '/admin/users',
            '/admin/products',
            '/admin/categories',
            '/admin/settings',
        ];
        
        foreach ($importantRoutes as $important) {
            if (strpos($path, $important) === 0) {
                return true;
            }
        }
        
        return false;
    }
}