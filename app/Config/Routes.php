<?php

namespace Config;

// Create a new instance of our RouteCollection class.
$routes = Services::routes();

/*
 * --------------------------------------------------------------------
 * Router Setup
 * --------------------------------------------------------------------
 */
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Web\HomeController');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
// Penting: Nonaktifkan AutoRoute untuk keamanan (Strict Routing)
$routes->setAutoRoute(false);

/*
 * --------------------------------------------------------------------
 * Route Definitions
 * --------------------------------------------------------------------
 */

// ====================================================================
// 1. WEB ROUTES (Public Storefront)[span_0](end_span)
// Namespace: App\Controllers\Web
// ====================================================================
$routes->group('/', ['namespace' => 'App\Controllers\Web'], function ($routes) {
    // Landing Page
    $routes->get('', 'HomeController::index');
    
    // Static Pages (Tentang Kami, dll)
    $routes->get('pages/(:segment)', 'HomeController::page/$1');

    // Product Discovery
    $routes->get('search', 'SearchController::index');
    $routes->get('products', 'SearchController::index'); // Alias
    $routes->get('product/(:segment)', 'ProductController::detail/$1');

    // Category Navigation
    $routes->get('category/(:segment)', 'CategoryController::detail/$1');
});


// ====================================================================
// 2. ADMIN ROUTES (Backoffice)[span_1](end_span)[span_2](end_span)
// Namespace: App\Controllers\Admin
// ====================================================================
$routes->group('admin', ['namespace' => 'App\Controllers\Admin'], function ($routes) {

    // --- Guest Routes (Login/Auth) ---
    // Note: Filter AdminAuth memiliki logika 'guestOnlyRoutes' internal
    // tapi kita pisahkan grupnya agar lebih bersih.
    $routes->group('', function($routes) {
        $routes->get('login', 'Auth::loginView');
        $routes->post('auth/login', 'Auth::loginAction');
        $routes->get('logout', 'Auth::logout');
        
        // 2FA Routes (Login Step 2)
        $routes->get('auth/2fa', 'Auth::twoFactorView');
        $routes->post('auth/2fa', 'Auth::twoFactorVerify');
    });

    // --- Protected Routes (The Fortress) ---
    // Filter: admin-pipeline (AdminAuth + AdminSessionCheck)
    $routes->group('', ['filter' => 'admin-pipeline'], function ($routes) {
        // Dashboard
        $routes->get('', 'Dashboard::index'); // Redirect /admin -> /admin/dashboard
        $routes->get('dashboard', 'Dashboard::index');

        // Product Management
        $routes->group('products', function($routes) {
            $routes->get('', 'AdminProduct::index');
            $routes->get('new', 'AdminProduct::new');
            $routes->post('', 'AdminProduct::create');
            $routes->get('(:num)/edit', 'AdminProduct::edit/$1');
            $routes->post('(:num)', 'AdminProduct::update/$1');
            $routes->post('(:num)/delete', 'AdminProduct::delete/$1');
            // Sub-resources
            $routes->get('(:num)/prices', 'AdminProduct::prices/$1');
            $routes->post('(:num)/verify', 'AdminProduct::verify/$1');
        });

        // Category Management
        $routes->group('categories', function($routes) {
            $routes->get('', 'AdminCategory::index');
            $routes->post('', 'AdminCategory::create'); // Modal form usually
            $routes->get('(:num)/edit', 'AdminCategory::edit/$1'); // Or fetch JSON for modal
            $routes->post('(:num)', 'AdminCategory::update/$1');
            $routes->post('(:num)/delete', 'AdminCategory::delete/$1');
        });

        // Link Management
        $routes->group('links', function($routes) {
            $routes->get('', 'AdminLink::index');
            $routes->post('', 'AdminLink::create');
            $routes->post('(:num)/update', 'AdminLink::update/$1');
            $routes->post('(:num)/delete', 'AdminLink::delete/$1');
            $routes->post('extract-bulk', 'AdminLink::extractBulk'); // Fitur khusus
        });

        // User/Team Management
        $routes->group('users', function($routes) {
            $routes->get('', 'AdminUser::index');
            $routes->get('new', 'AdminUser::new');
            $routes->post('', 'AdminUser::create');
            $routes->get('(:num)/edit', 'AdminUser::edit/$1');
            $routes->post('(:num)', 'AdminUser::update/$1');
            $routes->post('(:num)/password', 'AdminUser::changePassword/$1'); // Reset by admin
            $routes->post('(:num)/delete', 'AdminUser::delete/$1');
        });

        // My Profile
        $routes->get('profile', 'AdminProfile::index');
        $routes->post('profile/update', 'AdminProfile::update');
        $routes->post('profile/password', 'AdminProfile::changePassword');
    });
});


// ====================================================================
// 3. API ROUTES (Mobile/External)[span_3](end_span)
// Namespace: App\Controllers\Api
// ====================================================================
$routes->group('api', ['namespace' => 'App\Controllers\Api'], function ($routes) {
    
    // --- Public API ---
    $routes->post('auth/login', 'ApiAuth::login');
    
    // Katalog Produk & Kategori (Public Read)
    $routes->get('products', 'ApiProduct::index');
    $routes->get('products/(:segment)', 'ApiProduct::show/$1');
    $routes->get('categories', 'ApiCategory::index');
    $routes->get('categories/(:segment)', 'ApiCategory::show/$1');

    // --- Protected API ---
    // Menggunakan admin-pipeline karena filter ini support Token Inspection
    // lihat AdminAuth.php baris 88 (Header Authorization)
    $routes->group('', ['filter' => 'admin-pipeline'], function($routes) {
        $routes->post('auth/logout', 'ApiAuth::logout');
        $routes->get('auth/me', 'ApiAuth::me');
        
        $routes->get('dashboard/stats', 'ApiDashboard::stats');
        $routes->get('dashboard/health', 'ApiDashboard::health');
    });
});


// ====================================================================
// 4. HTMX ROUTES (Interactive Fragments)
// Namespace: App\Controllers\Htmx
// ====================================================================
// Note: Biasanya diproteksi session admin, tapi validasi URL bisa public
// tergantung kebutuhan. Kita proteksi demi keamanan resources.
$routes->group('htmx', ['namespace' => 'App\Controllers\Htmx', 'filter' => 'admin-pipeline'], function ($routes) {
    
    // Link Validation (Realtime)
    $routes->post('links/validate-url', 'Link::validateUrl');
    
    // Link Health Check
    $routes->post('links/(:num)/check', 'Link::check/$1');
});


/*
 * --------------------------------------------------------------------
 * Additional Routing
 * --------------------------------------------------------------------
 *
 * There will often be times that you need additional routing and you
 * need it to be able to override any defaults in this file. Environment
 * based routes is one such time. require() additional route files here
 * to make that happen.
 */
if (is_file(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
