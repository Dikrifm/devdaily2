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
// 1. WEB ROUTES (Public Storefront)
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
    $routes->get('p/(:segment)', 'ProductController::detail/$1'); // Short URL
    $routes->get('product/(:segment)', 'ProductController::detail/$1'); // Canonical

    // Category Navigation
    $routes->get('category/(:segment)', 'CategoryController::detail/$1');
});

// ====================================================================
// 2. ADMIN ROUTES (Backoffice)
// Namespace: App\Controllers\Admin
// ====================================================================
$routes->group('admin', ['namespace' => 'App\Controllers\Admin'], function ($routes) {

    // --- Guest Routes (Login/Auth) ---
    $routes->group('', function($routes) {
        $routes->get('login', 'Auth::index'); // Method index menampilkan login view
        $routes->post('auth/login', 'Auth::login'); // Method login memproses POST
        $routes->get('logout', 'Auth::logout');
        $routes->get('forgot-password', 'Auth::forgotPassword');
    });

    // --- Protected Routes (The Fortress) ---
    // Filter: admin-pipeline (AdminAuth + AdminSessionCheck)
    $routes->group('', ['filter' => 'admin-pipeline'], function ($routes) {
        
        // Dashboard
        $routes->get('', 'Dashboard::index');
        $routes->get('dashboard', 'Dashboard::index');

        // Product Management
        $routes->group('products', function($routes) {
            $routes->get('', 'AdminProduct::index');
            // FIX: Mengarah ke create() untuk form, store() untuk simpan
            $routes->get('new', 'AdminProduct::create'); 
            $routes->post('', 'AdminProduct::store');
            
            $routes->get('(:num)/edit', 'AdminProduct::edit/$1');
            $routes->post('(:num)', 'AdminProduct::update/$1');
            $routes->post('(:num)/delete', 'AdminProduct::delete/$1'); // Delete biasanya POST/DELETE method
            
            // Sub-resources (Prices & Links)
            $routes->get('(:num)/prices', 'AdminProduct::prices/$1'); // Jika ada method ini di controller
            $routes->post('(:num)/verify', 'AdminProduct::verify/$1');
        });

        // Category Management
        $routes->group('categories', function($routes) {
            $routes->get('', 'AdminCategory::index');
            // FIX: POST ke store(), bukan create()
            $routes->post('', 'AdminCategory::store'); 
            $routes->get('(:num)/edit', 'AdminCategory::edit/$1'); 
            $routes->post('(:num)', 'AdminCategory::update/$1');
            $routes->post('(:num)/delete', 'AdminCategory::delete/$1');
        });

        // Link Management
        $routes->group('links', function($routes) {
            $routes->get('', 'AdminLink::index');
            // FIX: POST ke store(), bukan create()
            $routes->post('', 'AdminLink::store');
            $routes->post('(:num)/update', 'AdminLink::update/$1');
            $routes->get('(:num)/validate', 'AdminLink::validateUrl/$1'); // Tambahan fitur validasi
            $routes->post('(:num)/delete', 'AdminLink::delete/$1');
        });

        // User/Team Management
        $routes->group('users', function($routes) {
            $routes->get('', 'AdminUser::index');
            $routes->get('new', 'AdminUser::new'); // AdminUser punya method new()
            $routes->post('', 'AdminUser::create'); // AdminUser pakai create() untuk simpan (unik)
            $routes->get('(:num)/edit', 'AdminUser::edit/$1');
            $routes->post('(:num)', 'AdminUser::update/$1');
            $routes->post('(:num)/password', 'AdminUser::changePassword/$1');
            $routes->post('(:num)/delete', 'AdminUser::delete/$1');
        });

        // My Profile
        $routes->get('profile', 'AdminProfile::index');
        $routes->post('profile/update', 'AdminProfile::update');
        $routes->post('profile/change-password', 'AdminProfile::changePassword'); // Sesuaikan nama method view

        // ----------------------------------------------------------------
        // ENTERPRISE MODULES ROUTES
        // ----------------------------------------------------------------

        // 1. Audit Logs (Keamanan & Monitoring)
        $routes->group('audit-logs', function($routes) {
            $routes->get('', 'AdminAuditLog::index');
            $routes->get('export', 'AdminAuditLog::export');
            $routes->get('(:num)', 'AdminAuditLog::show/$1'); // Detail Log
        });

        // 2. Authorization (Role & Permissions)
        $routes->group('authorization', function($routes) {
            $routes->get('', 'AdminAuthorization::index');
            // Role Management
            $routes->post('roles', 'AdminAuthorization::storeRole'); 
            $routes->post('roles/(:num)/delete', 'AdminAuthorization::deleteRole/$1');
            // Permission Management
            $routes->get('permissions/(:num)', 'AdminAuthorization::editPermissions/$1');
            $routes->get('roles/(:num)/permissions', 'AdminAuthorization::editPermissions/$1');
            $routes->post('roles/(:num)/permissions', 'AdminAuthorization::updatePermissions/$1');
        });

        // 3. Product Badges (Label: Hot, New, Sale)
        $routes->group('badges', function($routes) {
            $routes->get('', 'AdminBadge::index');
            $routes->get('new', 'AdminBadge::create');
            $routes->post('', 'AdminBadge::store');
            $routes->get('(:num)/edit', 'AdminBadge::edit/$1');
            $routes->post('(:num)', 'AdminBadge::update/$1');
            $routes->post('(:num)/delete', 'AdminBadge::delete/$1');
        });

        // 4. Marketplaces (Tokopedia, Shopee, dll)
        $routes->group('marketplaces', function($routes) {
            $routes->get('', 'AdminMarketplace::index');
            $routes->get('new', 'AdminMarketplace::create');
            $routes->post('', 'AdminMarketplace::store');
            $routes->get('(:num)/edit', 'AdminMarketplace::edit/$1');
            $routes->post('(:num)', 'AdminMarketplace::update/$1');
            $routes->post('(:num)/delete', 'AdminMarketplace::delete/$1');
            $routes->post('(:num)/toggle-status', 'AdminMarketplace::toggleStatus/$1');
        });

        // 5. Marketplace Badges (Official Store, Star Seller)
        $routes->group('marketplace-badges', function($routes) {
            $routes->get('', 'AdminMarketplaceBadge::index');
            $routes->post('', 'AdminMarketplaceBadge::store'); // Create via modal/inline
            $routes->get('(:num)/edit', 'AdminMarketplaceBadge::edit/$1');
            $routes->post('(:num)', 'AdminMarketplaceBadge::update/$1');
            $routes->post('(:num)/delete', 'AdminMarketplaceBadge::delete/$1');
        });
    });
});


// ====================================================================
// 3. API ROUTES (Mobile/External)
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
 */
if (is_file(APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php')) {
    require APPPATH . 'Config/' . ENVIRONMENT . '/Routes.php';
}
