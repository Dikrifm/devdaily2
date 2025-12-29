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
     */
    public array $aliases = [
        // Filter bawaan CodeIgniter
        'csrf'           => \CodeIgniter\Filters\CSRF::class,
        'toolbar'        => \CodeIgniter\Filters\DebugToolbar::class,
        'honeypot'       => \CodeIgniter\Filters\Honeypot::class,
        'invalidchars'   => \CodeIgniter\Filters\InvalidChars::class,
        'secureheaders'  => \CodeIgniter\Filters\SecureHeaders::class,
        'forcehttps'     => \CodeIgniter\Filters\ForceHTTPS::class,
        'pagecache'      => \CodeIgniter\Filters\PageCache::class,
        'performance'    => \CodeIgniter\Filters\PerformanceMetrics::class,
        
        // Filter kustom DevDaily
        'cors'           => \App\Filters\CorsFilter::class,
        'admin-auth'     => \App\Filters\AdminAuth::class,
        'admin-session'  => \App\Filters\AdminSessionCheck::class,
        
        // Pipeline kombinasi
        'admin-pipeline' => [
            'admin-auth',
            'admin-session',
        ],
    ];

    /**
     * Konfigurasi filter global (dijalankan di setiap request).
     */
    public array $globals = [
        'before' => [
            'cors',
            // 'forcehttps',
            // 'csrf', 
            'invalidchars',
        ],
        'after' => [
            'toolbar',
            // 'honeypot',
            'secureheaders',
        ],
    ];

    /**
     * Konfigurasi filter berdasarkan method HTTP.
     */
    public array $methods = [
        'post'   => ['csrf'],
        'put'    => ['csrf'],
        'patch'  => ['csrf'],
        'delete' => ['csrf'],
    ];

    /**
     * Konfigurasi filter untuk route spesifik.
     * FORMAT YANG BENAR: 'alias_filter' => ['before' => ['url/path/*']]
     */
    public array $filters = [
        // Terapkan 'admin-pipeline' hanya pada URL yang diawali 'admin/'
        // Kecuali halaman login (biasanya ditangani di Routes.php atau di dalam filter itu sendiri)
        'admin-pipeline' => [
            'before' => ['admin/*'], 
        ],
        
        // CONTOH: Jika ingin cache halaman home
        // 'pagecache' => [
        //     'before' => ['/'],
        // ],
    ];
    
    // Properti $never dan $required tidak standar di CI4 basic, 
    // tapi jika ada ekstensi yang memakainya, biarkan kosong atau sesuai kebutuhan.
    // Untuk saat ini kita hapus $never yang menyebabkan kebingungan konfigurasi.
}
