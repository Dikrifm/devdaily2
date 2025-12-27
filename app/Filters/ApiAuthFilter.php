<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use App\Services\ApiKeyService;
use App\Services\RateLimiterService;

class ApiAuthFilter implements FilterInterface
{
    /**
     * ApiKeyService instance
     * 
     * @var ApiKeyService
     */
    private $apiKeyService;
    
    /**
     * RateLimiterService instance
     * 
     * @var RateLimiterService
     */
    private $rateLimiter;
    
    /**
     * Konfigurasi API
     * 
     * @var array
     */
    private $config;
    
    /**
     * Routes yang dikecualikan dari API key auth
     * 
     * @var array
     */
    private $exceptRoutes = [
        'api/v1/health',
        'api/v1/status',
        'api/v1/public/*',
        'api/v1/auth/login',
        'api/v1/auth/register',
    ];
    
    /**
     * Routes yang membutuhkan API key tetapi tidak memerlukan rate limiting
     * 
     * @var array
     */
    private $noRateLimitRoutes = [
        'api/v1/auth/*',
    ];
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->apiKeyService = Services::apiKeyService(); // Dari Container Layer 0
        $this->rateLimiter = Services::rateLimiter(); // Dari Container Layer 0
        $this->config = config('Api');
    }
    
    /**
     * Before filter - API key validation
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
        
        // Extract API key dari request
        $apiKey = $this->extractApiKey($request);
        
        // Validasi 1: API key harus ada
        if (empty($apiKey)) {
            return $this->handleMissingApiKey($request);
        }
        
        // Validasi 2: API key format valid
        if (!$this->isValidApiKeyFormat($apiKey)) {
            return $this->handleInvalidApiKeyFormat($request, $apiKey);
        }
        
        // Validasi 3: API key valid di database
        $apiKeyData = $this->apiKeyService->validate($apiKey);
        
        if (!$apiKeyData) {
            return $this->handleInvalidApiKey($request, $apiKey);
        }
        
        // Validasi 4: API key tidak expired
        if ($this->apiKeyService->isExpired($apiKeyData)) {
            return $this->handleExpiredApiKey($request, $apiKeyData);
        }
        
        // Validasi 5: API key tidak revoked
        if ($this->apiKeyService->isRevoked($apiKeyData)) {
            return $this->handleRevokedApiKey($request, $apiKeyData);
        }
        
        // Validasi 6: Rate limiting (kecuali untuk routes tertentu)
        if (!$this->shouldSkipRateLimit($currentRoute)) {
            $rateLimitResult = $this->checkRateLimit($apiKeyData, $request);
            
            if (!$rateLimitResult['allowed']) {
                return $this->handleRateLimitExceeded($request, $rateLimitResult);
            }
        }
        
        // Validasi 7: IP restrictions (jika ada)
        if (!$this->checkIpRestrictions($apiKeyData, $request)) {
            return $this->handleIpRestriction($request, $apiKeyData);
        }
        
        // Validasi 8: Allowed routes/endpoints (scope)
        if (!$this->checkRouteScope($apiKeyData, $currentRoute, $request->getMethod())) {
            return $this->handleRouteScopeViolation($request, $apiKeyData);
        }
        
        // Update last used timestamp
        $this->apiKeyService->updateLastUsed($apiKeyData['id'], $request);
        
        // Attach API key data ke request untuk digunakan di controller
        $this->attachApiKeyData($request, $apiKeyData);
        
        // Log API request untuk analytics
        $this->logApiRequest($request, $apiKeyData);
        
        return $request;
    }
    
    /**
     * After filter
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array|null $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tambahkan rate limit headers ke response
        $this->addRateLimitHeaders($response, $request);
        
        // Tambahkan API version header
        $response->setHeader('X-API-Version', $this->config->version ?? '1.0.0');
        
        return $response;
    }
    
    /**
     * Extract API key dari request
     * 
     * @param RequestInterface $request
     * @return string|null
     */
    private function extractApiKey(RequestInterface $request): ?string
    {
        // Prioritas 1: Header X-API-KEY
        $apiKey = $request->getHeaderLine('X-API-KEY');
        if (!empty($apiKey)) {
            return trim($apiKey);
        }
        
        // Prioritas 2: Authorization header (Bearer token style)
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/^ApiKey\s+(.+)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        
        // Prioritas 3: Query parameter
        $apiKey = $request->getGet('api_key');
        if (!empty($apiKey)) {
            return trim($apiKey);
        }
        
        // Prioritas 4: POST parameter (jika request adalah POST)
        if ($request->getMethod() === 'POST') {
            $apiKey = $request->getPost('api_key');
            if (!empty($apiKey)) {
                return trim($apiKey);
            }
        }
        
        return null;
    }
    
    /**
     * Validasi format API key
     * 
     * @param string $apiKey
     * @return bool
     */
    private function isValidApiKeyFormat(string $apiKey): bool
    {
        // Format: sk_live_xxx atau sk_test_xxx
        if (preg_match('/^sk_(live|test)_[a-zA-Z0-9]{24,}$/', $apiKey)) {
            return true;
        }
        
        // Format: pk_live_xxx atau pk_test_xxx (public key)
        if (preg_match('/^pk_(live|test)_[a-zA-Z0-9]{24,}$/', $apiKey)) {
            return true;
        }
        
        // Legacy format: 32 karakter hex
        if (preg_match('/^[a-fA-F0-9]{32}$/', $apiKey)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Cek rate limit untuk API key
     * 
     * @param array $apiKeyData
     * @param RequestInterface $request
     * @return array
     */
    private function checkRateLimit(array $apiKeyData, RequestInterface $request): array
    {
        $apiKeyId = $apiKeyData['id'];
        $plan = $apiKeyData['plan'] ?? 'free';
        
        // Dapatkan rate limit configuration berdasarkan plan
        $rateLimitConfig = $this->getRateLimitConfig($plan);
        
        // Check rate limit
        $result = $this->rateLimiter->check(
            $apiKeyId,
            $rateLimitConfig['limit'],
            $rateLimitConfig['window']
        );
        
        return [
            'allowed' => $result['allowed'],
            'limit' => $rateLimitConfig['limit'],
            'remaining' => $result['remaining'],
            'reset' => $result['reset'],
            'plan' => $plan,
        ];
    }
    
    /**
     * Cek IP restrictions
     * 
     * @param array $apiKeyData
     * @param RequestInterface $request
     * @return bool
     */
    private function checkIpRestrictions(array $apiKeyData, RequestInterface $request): bool
    {
        // Jika tidak ada IP restrictions, selalu true
        if (empty($apiKeyData['allowed_ips'])) {
            return true;
        }
        
        $clientIp = $request->getIPAddress();
        $allowedIps = is_array($apiKeyData['allowed_ips']) 
            ? $apiKeyData['allowed_ips'] 
            : explode(',', $apiKeyData['allowed_ips']);
        
        // Trim whitespace
        $allowedIps = array_map('trim', $allowedIps);
        
        // Check jika client IP ada di allowed list
        foreach ($allowedIps as $allowedIp) {
            // Support untuk CIDR notation
            if ($this->ipMatches($clientIp, $allowedIp)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Cek route scope (endpoint permissions)
     * 
     * @param array $apiKeyData
     * @param string $route
     * @param string $method
     * @return bool
     */
    private function checkRouteScope(array $apiKeyData, string $route, string $method): bool
    {
        // Jika tidak ada scope restrictions, selalu true
        if (empty($apiKeyData['scopes'])) {
            return true;
        }
        
        $scopes = is_array($apiKeyData['scopes']) 
            ? $apiKeyData['scopes'] 
            : json_decode($apiKeyData['scopes'], true) ?? [];
        
        // Jika scopes kosong, izinkan semua
        if (empty($scopes)) {
            return true;
        }
        
        // Normalize scopes: ubah dari format "products:read" ke pattern
        foreach ($scopes as $scope) {
            if ($this->scopeMatches($scope, $route, $method)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Dapatkan rate limit configuration berdasarkan plan
     * 
     * @param string $plan
     * @return array
     */
    private function getRateLimitConfig(string $plan): array
    {
        $defaultConfig = [
            'limit' => 100,  // requests
            'window' => 3600, // seconds (1 hour)
        ];
        
        $planConfigs = $this->config->rateLimits ?? [
            'free' => ['limit' => 100, 'window' => 3600],
            'basic' => ['limit' => 1000, 'window' => 3600],
            'pro' => ['limit' => 10000, 'window' => 3600],
            'enterprise' => ['limit' => 100000, 'window' => 3600],
        ];
        
        return $planConfigs[$plan] ?? $defaultConfig;
    }
    
    /**
     * Attach API key data ke request
     * 
     * @param RequestInterface $request
     * @param array $apiKeyData
     */
    private function attachApiKeyData(RequestInterface $request, array $apiKeyData): void
    {
        // Filter sensitive data
        $safeData = [
            'id' => $apiKeyData['id'],
            'name' => $apiKeyData['name'] ?? null,
            'user_id' => $apiKeyData['user_id'] ?? null,
            'plan' => $apiKeyData['plan'] ?? 'free',
            'scopes' => $apiKeyData['scopes'] ?? [],
            'environment' => $apiKeyData['environment'] ?? 'production',
            'created_at' => $apiKeyData['created_at'] ?? null,
        ];
        
        $request->apiKey = (object) $safeData;
    }
    
    /**
     * Log API request untuk analytics
     * 
     * @param RequestInterface $request
     * @param array $apiKeyData
     */
    private function logApiRequest(RequestInterface $request, array $apiKeyData): void
    {
        if (!($this->config->enableRequestLogging ?? true)) {
            return;
        }
        
        try {
            $logData = [
                'api_key_id' => $apiKeyData['id'],
                'user_id' => $apiKeyData['user_id'] ?? null,
                'method' => $request->getMethod(),
                'endpoint' => $request->getUri()->getPath(),
                'ip_address' => $request->getIPAddress(),
                'user_agent' => $request->getUserAgent()->getAgentString(),
                'timestamp' => date('Y-m-d H:i:s'),
            ];
            
            // Log ke database atau file
            $this->apiKeyService->logRequest($logData);
            
        } catch (\Exception $e) {
            // Jangan gagal request karena logging error
            log_message('debug', 'Failed to log API request: ' . $e->getMessage());
        }
    }
    
    /**
     * Tambahkan rate limit headers ke response
     * 
     * @param ResponseInterface $response
     * @param RequestInterface $request
     */
    private function addRateLimitHeaders(ResponseInterface $response, RequestInterface $request): void
    {
        if (!isset($request->apiKey)) {
            return;
        }
        
        $apiKeyId = $request->apiKey->id;
        $plan = $request->apiKey->plan;
        
        $rateLimitConfig = $this->getRateLimitConfig($plan);
        $rateLimitInfo = $this->rateLimiter->getInfo($apiKeyId, $rateLimitConfig['window']);
        
        $response->setHeader('X-RateLimit-Limit', (string) $rateLimitConfig['limit']);
        $response->setHeader('X-RateLimit-Remaining', (string) $rateLimitInfo['remaining']);
        $response->setHeader('X-RateLimit-Reset', (string) $rateLimitInfo['reset']);
        $response->setHeader('X-RateLimit-Plan', $plan);
    }
    
    /**
     * Cek apakah IP matches dengan pattern (support CIDR)
     * 
     * @param string $ip
     * @param string $pattern
     * @return bool
     */
    private function ipMatches(string $ip, string $pattern): bool
    {
        // Jika pattern mengandung slash, itu CIDR notation
        if (strpos($pattern, '/') !== false) {
            list($subnet, $bits) = explode('/', $pattern);
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - $bits);
            
            return ($ip & $mask) == ($subnet & $mask);
        }
        
        // Exact match
        return $ip === $pattern;
    }
    
    /**
     * Cek apakah scope matches dengan route dan method
     * 
     * @param string $scope
     * @param string $route
     * @param string $method
     * @return bool
     */
    private function scopeMatches(string $scope, string $route, string $method): bool
    {
        // Scope format: "method:path" atau "path" (wildcard method)
        // Contoh: "GET:/api/v1/products" atau "/api/v1/products/*"
        
        if (strpos($scope, ':') !== false) {
            list($scopeMethod, $scopePath) = explode(':', $scope, 2);
            
            // Method harus match (case-insensitive)
            if (strtoupper($scopeMethod) !== strtoupper($method)) {
                return false;
            }
            
            // Path match dengan wildcard support
            return $this->pathMatches($scopePath, $route);
        }
        
        // Jika tidak ada method, hanya match path
        return $this->pathMatches($scope, $route);
    }
    
    /**
     * Cek apakah path matches dengan pattern (wildcard support)
     * 
     * @param string $pattern
     * @param string $path
     * @return bool
     */
    private function pathMatches(string $pattern, string $path): bool
    {
        // Convert wildcard * to regex
        $pattern = str_replace('\*', '.*', preg_quote($pattern, '/'));
        return (bool) preg_match('/^' . $pattern . '$/i', $path);
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
            if ($this->pathMatches($pattern, $route)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Cek apakah route dikecualikan dari rate limiting
     * 
     * @param string $route
     * @return bool
     */
    private function shouldSkipRateLimit(string $route): bool
    {
        foreach ($this->noRateLimitRoutes as $pattern) {
            if ($this->pathMatches($pattern, $route)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Handle missing API key
     * 
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    private function handleMissingApiKey(RequestInterface $request): ResponseInterface
    {
        $response = Services::response();
        
        return $response->setStatusCode(401)->setJSON([
            'error' => [
                'code' => 'API_KEY_REQUIRED',
                'message' => 'API key is required',
                'documentation' => 'https://api.devdaily.com/docs/authentication',
                'type' => 'AUTHENTICATION_REQUIRED'
            ]
        ]);
    }
    
    /**
     * Handle invalid API key format
     * 
     * @param RequestInterface $request
     * @param string $apiKey
     * @return ResponseInterface
     */
    private function handleInvalidApiKeyFormat(RequestInterface $request, string $apiKey): ResponseInterface
    {
        $response = Services::response();
        
        log_message('warning', "Invalid API key format: " . substr($apiKey, 0, 10) . '...');
        
        return $response->setStatusCode(401)->setJSON([
            'error' => [
                'code' => 'INVALID_API_KEY_FORMAT',
                'message' => 'Invalid API key format',
                'hint' => 'API key should be in format: sk_live_xxx or pk_live_xxx',
                'type' => 'AUTHENTICATION_INVALID'
            ]
        ]);
    }
    
    /**
     * Handle invalid API key
     * 
     * @param RequestInterface $request
     * @param string $apiKey
     * @return ResponseInterface
     */
    private function handleInvalidApiKey(RequestInterface $request, string $apiKey): ResponseInterface
    {
        $response = Services::response();
        
        // Log failed attempt untuk security monitoring
        log_message('warning', "Invalid API key attempt from IP: " . $request->getIPAddress());
        
        return $response->setStatusCode(401)->setJSON([
            'error' => [
                'code' => 'INVALID_API_KEY',
                'message' => 'Invalid API key',
                'documentation' => 'https://api.devdaily.com/docs/authentication',
                'type' => 'AUTHENTICATION_INVALID'
            ]
        ]);
    }
    
    /**
     * Handle expired API key
     * 
     * @param RequestInterface $request
     * @param array $apiKeyData
     * @return ResponseInterface
     */
    private function handleExpiredApiKey(RequestInterface $request, array $apiKeyData): ResponseInterface
    {
        $response = Services::response();
        
        return $response->setStatusCode(401)->setJSON([
            'error' => [
                'code' => 'API_KEY_EXPIRED',
                'message' => 'API key has expired',
                'expired_at' => $apiKeyData['expires_at'] ?? null,
                'type' => 'AUTHENTICATION_EXPIRED'
            ]
        ]);
    }
    
    /**
     * Handle revoked API key
     * 
     * @param RequestInterface $request
     * @param array $apiKeyData
     * @return ResponseInterface
     */
    private function handleRevokedApiKey(RequestInterface $request, array $apiKeyData): ResponseInterface
    {
        $response = Services::response();
        
        return $response->setStatusCode(401)->setJSON([
            'error' => [
                'code' => 'API_KEY_REVOKED',
                'message' => 'API key has been revoked',
                'revoked_at' => $apiKeyData['revoked_at'] ?? null,
                'reason' => $apiKeyData['revocation_reason'] ?? null,
                'type' => 'AUTHENTICATION_REVOKED'
            ]
        ]);
    }
    
    /**
     * Handle rate limit exceeded
     * 
     * @param RequestInterface $request
     * @param array $rateLimitInfo
     * @return ResponseInterface
     */
    private function handleRateLimitExceeded(RequestInterface $request, array $rateLimitInfo): ResponseInterface
    {
        $response = Services::response();
        
        $resetTime = date('Y-m-d H:i:s', $rateLimitInfo['reset']);
        
        return $response->setStatusCode(429)->setJSON([
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Rate limit exceeded',
                'limit' => $rateLimitInfo['limit'],
                'remaining' => $rateLimitInfo['remaining'],
                'reset' => $rateLimitInfo['reset'],
                'reset_time' => $resetTime,
                'plan' => $rateLimitInfo['plan'],
                'type' => 'RATE_LIMIT'
            ]
        ]);
    }
    
    /**
     * Handle IP restriction
     * 
     * @param RequestInterface $request
     * @param array $apiKeyData
     * @return ResponseInterface
     */
    private function handleIpRestriction(RequestInterface $request, array $apiKeyData): ResponseInterface
    {
        $response = Services::response();
        
        $clientIp = $request->getIPAddress();
        $allowedIps = $apiKeyData['allowed_ips'] ?? [];
        
        return $response->setStatusCode(403)->setJSON([
            'error' => [
                'code' => 'IP_RESTRICTION',
                'message' => 'Access denied from this IP address',
                'client_ip' => $clientIp,
                'allowed_ips' => is_array($allowedIps) ? $allowedIps : explode(',', $allowedIps),
                'type' => 'ACCESS_DENIED'
            ]
        ]);
    }
    
    /**
     * Handle route scope violation
     * 
     * @param RequestInterface $request
     * @param array $apiKeyData
     * @return ResponseInterface
     */
    private function handleRouteScopeViolation(RequestInterface $request, array $apiKeyData): ResponseInterface
    {
        $response = Services::response();
        
        $route = $request->getUri()->getPath();
        $method = $request->getMethod();
        $scopes = $apiKeyData['scopes'] ?? [];
        
        return $response->setStatusCode(403)->setJSON([
            'error' => [
                'code' => 'INSUFFICIENT_SCOPE',
                'message' => 'API key does not have permission to access this endpoint',
                'endpoint' => $method . ' ' . $route,
                'required_scope' => $method . ':' . $route,
                'available_scopes' => is_array($scopes) ? $scopes : json_decode($scopes, true),
                'type' => 'PERMISSION_DENIED'
            ]
        ]);
    }
}