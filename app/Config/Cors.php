<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Cors extends BaseConfig
{
    /**
     * Daftar origin yang diizinkan
     * - ['*'] untuk mengizinkan semua origin (tidak direkomendasikan untuk produksi)
     * - ['https://example.com', 'https://api.example.com'] untuk origin spesifik
     * 
     * @var array
     */
    public $allowedOrigins = ['*'];
    
    /**
     * Methods HTTP yang diizinkan
     * 
     * @var array
     */
    public $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'];
    
    /**
     * Headers yang diizinkan dalam request
     * 
     * @var array
     */
    public $allowedHeaders = [
        'X-API-KEY',
        'Origin',
        'X-Requested-With',
        'Content-Type',
        'Accept',
        'Authorization',
        'X-CSRF-TOKEN',
        'X-Request-ID'
    ];
    
    /**
     * Headers yang diekspos ke client
     * 
     * @var array
     */
    public $exposedHeaders = [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
        'X-Request-ID'
    ];
    
    /**
     * Apakah mengizinkan credentials (cookies, auth headers)
     * 
     * @var bool
     */
    public $allowCredentials = false;
    
    /**
     * Max age untuk preflight request (dalam detik)
     * 
     * @var int
     */
    public $maxAge = 86400; // 24 jam
    
    /**
     * Enable CORS untuk semua routes
     * 
     * @var bool
     */
    public $enabled = true;
    
    /**
     * Log CORS violations untuk debugging
     * 
     * @var bool
     */
    public $logViolations = false;
    
    /**
     * Origin yang selalu diizinkan (bypass validation)
     * Berguna untuk development/localhost
     * 
     * @var array
     */
    public $alwaysAllowed = [
        'http://localhost:3000',
        'http://localhost:8080',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:8080'
    ];
    
    /**
     * Validasi origin berdasarkan pattern
     * 
     * @var array
     */
    public $allowedPatterns = [
        // Contoh: '~^https://(.+\.)?example\.com$~'
    ];
    
    /**
     * Konfigurasi berdasarkan environment
     * 
     * @return array
     */
    public function getConfig(): array
    {
        $config = [
            'allowedOrigins'     => $this->allowedOrigins,
            'allowedMethods'     => $this->allowedMethods,
            'allowedHeaders'     => $this->allowedHeaders,
            'exposedHeaders'     => $this->exposedHeaders,
            'allowCredentials'   => $this->allowCredentials,
            'maxAge'             => $this->maxAge,
        ];
        
        // Environment-specific overrides
        if (ENVIRONMENT === 'production') {
            $config['allowCredentials'] = true;
            $config['allowedOrigins'] = $this->getProductionOrigins();
            $config['logViolations'] = true;
        } elseif (ENVIRONMENT === 'development') {
            $config['allowedOrigins'] = array_merge(
                $config['allowedOrigins'],
                $this->alwaysAllowed
            );
            $config['allowCredentials'] = true;
        }
        
        return $config;
    }
    
    /**
     * Ambil origin untuk production
     * 
     * @return array
     */
    private function getProductionOrigins(): array
    {
        // Untuk production, jangan gunakan wildcard
        if ($this->allowedOrigins === ['*']) {
            return [];
        }
        
        return $this->allowedOrigins;
    }
    
    /**
     * Validasi apakah origin diizinkan
     * 
     * @param string $origin
     * @return bool
     */
    public function isOriginAllowed(string $origin): bool
    {
        // Selalu izinkan jika CORS tidak enabled
        if (!$this->enabled) {
            return true;
        }
        
        // Origin kosong (non-browser request)
        if (empty($origin)) {
            return true;
        }
        
        // Cek origin yang selalu diizinkan
        if (in_array($origin, $this->alwaysAllowed, true)) {
            return true;
        }
        
        // Wildcard untuk semua origin
        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }
        
        // Cek exact match
        if (in_array($origin, $this->allowedOrigins, true)) {
            return true;
        }
        
        // Cek pattern match
        foreach ($this->allowedPatterns as $pattern) {
            if (preg_match($pattern, $origin)) {
                return true;
            }
        }
        
        // Log violation jika diaktifkan
        if ($this->logViolations) {
            log_message('info', "CORS violation: Origin {$origin} not allowed");
        }
        
        return false;
    }
    
    /**
     * Generate headers CORS untuk response
     * 
     * @param string $origin
     * @return array
     */
    public function generateHeaders(string $origin): array
    {
        $headers = [];
        
        if ($this->isOriginAllowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            
            if ($this->allowCredentials) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }
        
        return $headers;
    }
    
    /**
     * Generate headers untuk preflight request
     * 
     * @param string $origin
     * @return array
     */
    public function generatePreflightHeaders(string $origin): array
    {
        $headers = $this->generateHeaders($origin);
        
        $headers['Access-Control-Allow-Methods'] = implode(', ', $this->allowedMethods);
        $headers['Access-Control-Allow-Headers'] = implode(', ', $this->allowedHeaders);
        
        if (!empty($this->exposedHeaders)) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $this->exposedHeaders);
        }
        
        if ($this->maxAge > 0) {
            $headers['Access-Control-Max-Age'] = (string) $this->maxAge;
        }
        
        return $headers;
    }
}