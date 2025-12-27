<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;

class Filters extends BaseFilters
{
    /**
     * Konfigurasi filter aliases.
     * 
     * Daftar nama alias untuk filter yang tersedia.
     * Ini memungkinkan kita menggunakan nama yang sederhana
     * daripada harus menggunakan nama kelas lengkap.
     * 
     * Contoh:
     *   'csrf' => \CodeIgniter\Filters\CSRF::class
     * 
     * @var array<string, class-string|list<class-string>>
     */
    public array $aliases = [
        // Filter bawaan CodeIgniter
        'csrf'               => \CodeIgniter\Filters\CSRF::class,
        'toolbar'            => \CodeIgniter\Filters\DebugToolbar::class,
        'honeypot'           => \CodeIgniter\Filters\Honeypot::class,
        'invalidchars'       => \CodeIgniter\Filters\InvalidChars::class,
        'secureheaders'      => \CodeIgniter\Filters\SecureHeaders::class,
        'forcehttps'         => \CodeIgniter\Filters\ForceHTTPS::class,
        'pagecache'          => \CodeIgniter\Filters\PageCache::class,
        'performance'        => \CodeIgniter\Filters\PerformanceMetrics::class,
        
        // Filter kustom DevDaily - Layer 0.5: Security Pipeline
        'cors'               => \App\Filters\CorsFilter::class,           // CORS headers
        'admin-auth'         => \App\Filters\AdminAuth::class,            // The Bouncer - session/token check
        'admin-session'      => \App\Filters\AdminSessionCheck::class,    // The Validator - database validation
        //'api-auth'           => \App\Filters\ApiAuthFilter::class,        // API key authentication
        //'api-admin'          => \App\Filters\ApiAdminFilter::class,       // JWT admin authentication
        
        // Pipeline kombinasi (menggunakan multiple filters)
        'admin-pipeline'     => [
            'admin-auth',
            'admin-session',
        ],
        /*
        'api-public-pipeline' => [
            'api-auth',
        ],
        'api-admin-pipeline'  => [
            'api-admin',
        ],
        */
    ];

    /**
     * Konfigurasi filter yang dijalankan sebelum dan sesudah setiap request.
     * 
     * @var array<string, array<string, array<string, string|array<string>>>> 
     */
    public array $globals = [
        'before' => [
            // CORS harus selalu dijalankan pertama untuk preflight requests
            'cors',
            
            // Force HTTPS di production
            // 'forcehttps',
            
            // CSRF protection untuk semua POST, PUT, DELETE requests
            // 'csrf',
            
            // Invalid characters filter
            'invalidchars',
            
            // Honeypot untuk form spam protection
            // 'honeypot',
        ],
        'after' => [
            // Toolbar untuk development
            'toolbar',
            
            // Performance metrics
            // 'performance',
            
            // Secure headers
            'secureheaders',
            
            // CORS headers untuk response (dijalankan di after)
            // Note: CorsFilter sudah menangani di before dan after
        ],
    ];

    /**
     * Konfigurasi filter berdasarkan method request.
     * 
     * Contoh:
     *   'post' => ['csrf', 'throttle']
     * 
     * @var array<string, list<string>>
     */
    public array $methods = [
        'post' => ['csrf'],  // CSRF untuk semua POST requests
        'put'  => ['csrf'],  // CSRF untuk semua PUT requests
        'patch' => ['csrf'], // CSRF untuk semua PATCH requests
        'delete' => ['csrf'], // CSRF untuk semua DELETE requests
    ];

    /**
     * Konfigurasi filter untuk route atau group tertentu.
     * 
     * Format:
     *   'nama_filter' => [
     *       'before' => ['filter1', 'filter2'],
     *       'after'  => ['filter3']
     *   ]
     * 
     * Atau sederhana:
     *   'nama_filter' => ['filter1', 'filter2']
     * 
     * @var array<string, array<string, list<string>>|list<string>>
     */
    public array $filters = [
        // Pipeline untuk admin web routes
        'admin-pipeline' => [
            'before' => ['admin-auth', 'admin-session'],
        ],
        
        // Pipeline untuk public API routes
       /* 
       'api-public-pipeline' => [
            'before' => ['api-auth'],
        ],
       */ 
        
        // Pipeline untuk admin API routes
        /*
        'api-admin-pipeline' => [
            'before' => ['api-admin'],
        ],
        */
        
        // Cache untuk public pages
        'cache-public' => [
            'before' => ['pagecache' => ['cache_time' => 300]], // 5 menit
        ],
        
        // Rate limiting untuk API
        /*
        'rate-limit-api' => [
            'before' => ['throttle' => ['rate_limit' => 100, 'time' => 3600]], // 100 requests per jam
        ],
        */
    ];

    /**
     * Daftar filter yang WAJIB dijalankan.
     * 
     * Filter di sini akan selalu dijalankan bahkan jika
     * tidak disebutkan dalam route atau globals.
     * Berguna untuk filter yang critical untuk security.
     * 
     * @var list<string>
     */
    public array $required = [
        // 'cors', // Diaktifkan jika ingin memaksa CORS di semua routes
    ];

    /**
     * Filter yang tidak boleh dijalankan.
     * 
     @var list<string>
     */
    public array $never = [
        // Toolbar tidak dijalankan untuk API responses
        'toolbar' => [
            'api/*',
            'admin/api/*',
        ],
        
        // CSRF tidak diperlukan untuk API endpoints
        'csrf' => [
            'api/*',
            'admin/api/*',
        ],
    ];

    /**
     * Konfigurasi tambahan untuk environment tertentu.
     */
    public function __construct()
    {
        parent::__construct();
        
        // Environment-specific configuration
        $this->configureForEnvironment();
    }

    /**
     * Konfigurasi berdasarkan environment.
     */
    private function configureForEnvironment(): void
    {
        // Development environment
        if (ENVIRONMENT === 'development') {
            // Enable toolbar untuk development
            $this->aliases['toolbar'] = \CodeIgniter\Filters\DebugToolbar::class;
            
            // Tambahkan performance metrics
            $this->aliases['performance'] = \CodeIgniter\Filters\PerformanceMetrics::class;
            $this->globals['after'][] = 'performance';
            
            // Logging untuk CORS violations
            if (isset($this->aliases['cors'])) {
                // CorsFilter akan menggunakan config Cors yang memiliki logViolations
            }
        }
        
        // Production environment
        if (ENVIRONMENT === 'production') {
            // Force HTTPS di production
            $this->aliases['forcehttps'] = \CodeIgniter\Filters\ForceHTTPS::class;
            array_unshift($this->globals['before'], 'forcehttps');
            
            // Nonaktifkan toolbar
            unset($this->aliases['toolbar']);
            $this->globals['after'] = array_filter($this->globals['after'], function($filter) {
                return $filter !== 'toolbar';
            });
            
            // Aktifkan page cache untuk static pages
            $this->filters['cache-home'] = [
                'before' => ['pagecache' => ['cache_time' => 3600]], // 1 jam cache untuk homepage
            ];
        }
        
        // Testing environment
        if (ENVIRONMENT === 'testing') {
            // Nonaktifkan semua filter yang mengganggu testing
            $this->aliases = [
                'cors' => \App\Filters\CorsFilter::class, // Tetap butuh CORS untuk API tests
            ];
            
            $this->globals = [
                'before' => ['cors'],
                'after' => [],
            ];
            
            $this->methods = [];
            $this->filters = [];
        }
    }

    /**
     * Mendapatkan konfigurasi filter untuk route tertentu.
     * 
     * @param string $route
     * @param string $position 'before' atau 'after'
     * @return array
     */
    public function getFiltersForRoute(string $route, string $position = 'before'): array
    {
    }
    
    /**
     * Validasi apakah filter ada dan valid.
     * 
     * @param string $filterName
     * @return bool
     */
    public function isValidFilter(string $filterName): bool
    {
    }
    
    /**
     * Mendapatkan kelas filter dari alias.
     * 
     * @param string $filterName
     * @return string|array|null
     */
    public function getFilterClass(string $filterName)
    {
    }
}