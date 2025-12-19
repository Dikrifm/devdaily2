<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// ======================================================
// KONFIGURASI DASAR
// ======================================================

$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Home');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
/*$routes->set404Override(function () {
    return service('response')
        ->setStatusCode(404)
        ->setJSON([
            'status' => 'error',
            'message' => 'Endpoint not found',
            'code' => 404
        ]);
});*/

$routes->setAutoRoute(false);

// ======================================================
// ROUTES WEB (FRONTEND)
// ======================================================

$routes->get('/', 'Home::index');
$routes->get('health', 'Health::index');

// ======================================================
// GRUP API V1
// ======================================================

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], function ($routes) {

    // ==================================================
    // AUTHENTICATION & ADMIN MANAGEMENT
    // ==================================================

    // Public auth endpoints
    $routes->post('auth/login', 'AuthController::login');
    $routes->post('auth/refresh-token', 'AuthController::refreshToken');
    $routes->post('auth/forgot-password', 'AuthController::forgotPassword');
    $routes->post('auth/reset-password', 'AuthController::resetPassword');

    // Protected admin routes
    $routes->group('admin', ['filter' => 'auth:admin'], function ($routes) {
        // Admin management
        $routes->get('admins', 'AdminController::index');
        $routes->get('admins/(:num)', 'AdminController::show/$1');
        $routes->post('admins', 'AdminController::create');
        $routes->put('admins/(:num)', 'AdminController::update/$1');
        $routes->patch('admins/(:num)/status', 'AdminController::updateStatus/$1');
        $routes->delete('admins/(:num)', 'AdminController::delete/$1');
        $routes->post('admins/(:num)/restore', 'AdminController::restore/$1');
        $routes->post('admins/(:num)/promote', 'AdminController::promote/$1');
        $routes->post('admins/(:num)/demote', 'AdminController::demote/$1');
        $routes->post('admins/(:num)/reset-password', 'AdminController::resetPassword/$1');

        // Admin profile
        $routes->get('profile', 'AdminController::profile');
        $routes->put('profile', 'AdminController::updateProfile');
        $routes->put('profile/password', 'AdminController::changePassword');

        // Admin sessions
        $routes->get('sessions', 'AdminController::sessions');
        $routes->delete('sessions/(:any)', 'AdminController::revokeSession/$1');

        // Audit logs
        $routes->get('audit-logs', 'AuditLogController::index');
        $routes->get('audit-logs/(:num)', 'AuditLogController::show/$1');
        $routes->get('audit-logs/entity/(:any)/(:num)', 'AuditLogController::entityLogs/$1/$2');
        $routes->get('audit-logs/admin/(:num)', 'AuditLogController::adminLogs/$1');
        $routes->post('audit-logs/export', 'AuditLogController::export');
        $routes->delete('audit-logs/cleanup', 'AuditLogController::cleanup');
    });

    // ==================================================
    // PRODUCT MANAGEMENT
    // ==================================================

    // Public product endpoints
    $routes->get('products', 'ProductController::index');
    $routes->get('products/(:num)', 'ProductController::show/$1');
    $routes->get('products/slug/(:segment)', 'ProductController::showBySlug/$1');
    $routes->get('products/(:num)/links', 'ProductController::links/$1');
    $routes->get('products/(:num)/related', 'ProductController::related/$1');
    $routes->get('products/popular', 'ProductController::popular');
    $routes->get('products/trending', 'ProductController::trending');
    $routes->post('products/(:num)/view', 'ProductController::incrementView/$1');

    // Protected product endpoints (admin only)
    $routes->group('products', ['filter' => 'auth:admin'], function ($routes) {
        $routes->post('/', 'ProductController::create');
        $routes->put('(:num)', 'ProductController::update/$1');
        $routes->patch('(:num)/status', 'ProductController::updateStatus/$1');
        $routes->post('(:num)/publish', 'ProductController::publish/$1');
        $routes->post('(:num)/verify', 'ProductController::verify/$1');
        $routes->post('(:num)/archive', 'ProductController::archive/$1');
        $routes->post('(:num)/restore', 'ProductController::restore/$1');
        $routes->delete('(:num)', 'ProductController::delete/$1');
        $routes->delete('(:num)/force', 'ProductController::forceDelete/$1');

        // Bulk operations
        $routes->post('bulk/publish', 'ProductController::bulkPublish');
        $routes->post('bulk/archive', 'ProductController::bulkArchive');
        $routes->post('bulk/restore', 'ProductController::bulkRestore');
        $routes->post('bulk/delete', 'ProductController::bulkDelete');
        $routes->post('bulk/update', 'ProductController::bulkUpdate');

        // Product management
        $routes->get('needs-update', 'ProductController::needsUpdate');
        $routes->get('needs-validation', 'ProductController::needsValidation');
        $routes->post('(:num)/check-price', 'ProductController::checkPrice/$1');
        $routes->post('(:num)/check-links', 'ProductController::checkLinks/$1');

        // Product badges
        $routes->get('(:num)/badges', 'ProductController::badges/$1');
        $routes->post('(:num)/badges', 'ProductController::assignBadge/$1');
        $routes->delete('(:num)/badges/(:num)', 'ProductController::removeBadge/$1/$2');
        $routes->put('(:num)/badges', 'ProductController::updateBadges/$1');
    });

    // ==================================================
    // LINK MANAGEMENT
    // ==================================================

    // Public link endpoints
    $routes->get('links/(:num)', 'LinkController::show/$1');
    $routes->post('links/(:num)/click', 'LinkController::recordClick/$1');

    // Protected link endpoints (admin only)
    $routes->group('links', ['filter' => 'auth:admin'], function ($routes) {
        $routes->get('/', 'LinkController::index');
        $routes->post('/', 'LinkController::create');
        $routes->put('(:num)', 'LinkController::update/$1');
        $routes->patch('(:num)/status', 'LinkController::updateStatus/$1');
        $routes->post('(:num)/activate', 'LinkController::activate/$1');
        $routes->post('(:num)/deactivate', 'LinkController::deactivate/$1');
        $routes->delete('(:num)', 'LinkController::delete/$1');
        $routes->post('(:num)/restore', 'LinkController::restore/$1');

        // Link operations
        $routes->post('(:num)/validate', 'LinkController::validate/$1');
        $routes->post('(:num)/update-price', 'LinkController::updatePrice/$1');
        $routes->post('(:num)/check-accessibility', 'LinkController::checkAccessibility/$1');
        $routes->post('(:num)/record-sale', 'LinkController::recordSale/$1');

        // Bulk operations
        $routes->post('bulk/validate', 'LinkController::bulkValidate');
        $routes->post('bulk/update-prices', 'LinkController::bulkUpdatePrices');
        $routes->post('bulk/activate', 'LinkController::bulkActivate');
        $routes->post('bulk/deactivate', 'LinkController::bulkDeactivate');
        $routes->post('bulk/delete', 'LinkController::bulkDelete');

        // Analytics
        $routes->get('(:num)/analytics', 'LinkController::analytics/$1');
        $routes->get('top-performers', 'LinkController::topPerformers');
        $routes->get('needs-update', 'LinkController::needsPriceUpdate');
        $routes->get('needs-validation', 'LinkController::needsValidation');
    });

    // ==================================================
    // CATEGORY MANAGEMENT
    // ==================================================

    // Public category endpoints
    $routes->get('categories', 'CategoryController::index');
    $routes->get('categories/tree', 'CategoryController::tree');
    $routes->get('categories/(:num)', 'CategoryController::show/$1');
    $routes->get('categories/slug/(:segment)', 'CategoryController::showBySlug/$1');
    $routes->get('categories/(:num)/products', 'CategoryController::products/$1');
    $routes->get('categories/(:num)/children', 'CategoryController::children/$1');

    // Protected category endpoints (admin only)
    $routes->group('categories', ['filter' => 'auth:admin'], function ($routes) {
        $routes->post('/', 'CategoryController::create');
        $routes->put('(:num)', 'CategoryController::update/$1');
        $routes->patch('(:num)/status', 'CategoryController::updateStatus/$1');
        $routes->post('(:num)/activate', 'CategoryController::activate/$1');
        $routes->post('(:num)/deactivate', 'CategoryController::deactivate/$1');
        $routes->delete('(:num)', 'CategoryController::delete/$1');
        $routes->post('(:num)/restore', 'CategoryController::restore/$1');

        // Category operations
        $routes->post('reorder', 'CategoryController::reorder');
        $routes->post('(:num)/move', 'CategoryController::move/$1');
        $routes->get('(:num)/statistics', 'CategoryController::statistics/$1');

        // Bulk operations
        $routes->post('bulk/activate', 'CategoryController::bulkActivate');
        $routes->post('bulk/deactivate', 'CategoryController::bulkDeactivate');
        $routes->post('bulk/delete', 'CategoryController::bulkDelete');
        $routes->post('bulk/restore', 'CategoryController::bulkRestore');
    });

    // ==================================================
    // MARKETPLACE MANAGEMENT
    // ==================================================

    // Public marketplace endpoints
    $routes->get('marketplaces', 'MarketplaceController::index');
    $routes->get('marketplaces/(:num)', 'MarketplaceController::show/$1');
    $routes->get('marketplaces/slug/(:segment)', 'MarketplaceController::showBySlug/$1');
    $routes->get('marketplaces/(:num)/products', 'MarketplaceController::products/$1');
    $routes->get('marketplaces/(:num)/links', 'MarketplaceController::links/$1');

    // Protected marketplace endpoints (admin only)
    $routes->group('marketplaces', ['filter' => 'auth:admin'], function ($routes) {
        $routes->post('/', 'MarketplaceController::create');
        $routes->put('(:num)', 'MarketplaceController::update/$1');
        $routes->patch('(:num)/status', 'MarketplaceController::updateStatus/$1');
        $routes->post('(:num)/activate', 'MarketplaceController::activate/$1');
        $routes->post('(:num)/deactivate', 'MarketplaceController::deactivate/$1');
        $routes->delete('(:num)', 'MarketplaceController::delete/$1');
        $routes->post('(:num)/restore', 'MarketplaceController::restore/$1');

        // Marketplace operations
        $routes->get('(:num)/statistics', 'MarketplaceController::statistics/$1');
        $routes->get('(:num)/top-products', 'MarketplaceController::topProducts/$1');

        // Bulk operations
        $routes->post('bulk/activate', 'MarketplaceController::bulkActivate');
        $routes->post('bulk/deactivate', 'MarketplaceController::bulkDeactivate');
        $routes->post('bulk/delete', 'MarketplaceController::bulkDelete');
    });

    // ==================================================
    // BADGE MANAGEMENT
    // ==================================================

    // Public badge endpoints
    $routes->get('badges', 'BadgeController::index');
    $routes->get('badges/(:num)', 'BadgeController::show/$1');

    // Protected badge endpoints (admin only)
    $routes->group('badges', ['filter' => 'auth:admin'], function ($routes) {
        $routes->post('/', 'BadgeController::create');
        $routes->put('(:num)', 'BadgeController::update/$1');
        $routes->patch('(:num)/status', 'BadgeController::updateStatus/$1');
        $routes->delete('(:num)', 'BadgeController::delete/$1');
        $routes->post('(:num)/restore', 'BadgeController::restore/$1');

        // Badge operations
        $routes->get('(:num)/products', 'BadgeController::products/$1');
        $routes->get('common', 'BadgeController::common');
    });

    // ==================================================
    // MARKETPLACE BADGE MANAGEMENT
    // ==================================================

    // Public marketplace badge endpoints
    $routes->get('marketplace-badges', 'MarketplaceBadgeController::index');
    $routes->get('marketplace-badges/(:num)', 'MarketplaceBadgeController::show/$1');

    // Protected marketplace badge endpoints (admin only)
    $routes->group('marketplace-badges', ['filter' => 'auth:admin'], function ($routes) {
        $routes->post('/', 'MarketplaceBadgeController::create');
        $routes->put('(:num)', 'MarketplaceBadgeController::update/$1');
        $routes->patch('(:num)/status', 'MarketplaceBadgeController::updateStatus/$1');
        $routes->delete('(:num)', 'MarketplaceBadgeController::delete/$1');
        $routes->post('(:num)/restore', 'MarketplaceBadgeController::restore/$1');

        // Marketplace badge operations
        $routes->get('(:num)/links', 'MarketplaceBadgeController::links/$1');
        $routes->get('common', 'MarketplaceBadgeController::common');
    });

    // ==================================================
    // SYSTEM & UTILITY ENDPOINTS
    // ==================================================

    // Public system endpoints
    $routes->get('health', 'SystemController::health');
    $routes->get('stats', 'SystemController::stats');
    $routes->get('config', 'SystemController::config');

    // Protected system endpoints (admin only)
    $routes->group('system', ['filter' => 'auth:admin'], function ($routes) {
        $routes->get('cache-stats', 'SystemController::cacheStats');
        $routes->post('cache/clear', 'SystemController::clearCache');
        $routes->post('cache/clear-tag', 'SystemController::clearCacheByTag');
        $routes->get('database-stats', 'SystemController::databaseStats');
        $routes->post('database/backup', 'SystemController::backupDatabase');
        $routes->get('logs', 'SystemController::logs');
        $routes->post('logs/clear', 'SystemController::clearLogs');
        $routes->get('queue-stats', 'SystemController::queueStats');
        $routes->post('queue/process', 'SystemController::processQueue');
    });

    // ==================================================
    // REPORTING & ANALYTICS
    // ==================================================

    $routes->group('reports', ['filter' => 'auth:admin'], function ($routes) {
        $routes->get('dashboard', 'ReportController::dashboard');
        $routes->get('sales', 'ReportController::sales');
        $routes->get('products', 'ReportController::products');
        $routes->get('links', 'ReportController::links');
        $routes->get('marketplaces', 'ReportController::marketplaces');
        $routes->get('categories', 'ReportController::categories');
        $routes->post('export', 'ReportController::export');
        $routes->get('export/(:any)', 'ReportController::downloadExport/$1');
    });
});

// ======================================================
// ROUTES CATCH-ALL FOR SPA (Single Page Application)
// ======================================================

// Jika menggunakan frontend SPA, semua route selain API diarahkan ke Home
$routes->get('(:any)', 'Home::index');

// ======================================================
// ROUTES FOR PREFLIGHT (CORS SUPPORT)
// ======================================================
/*
$routes->options('(:any)', function() {
    return service('response')->setStatusCode(200);
});
*/
// ======================================================
// ERROR HANDLING ROUTES
// ======================================================
/*
$routes->get('errors/403', function () {
    return service('response')
        ->setStatusCode(403)
        ->setJSON([
            'status' => 'error',
            'message' => 'Forbidden: You do not have permission to access this resource',
            'code' => 403
        ]);
});
*/
/*
$routes->get('errors/500', function () {
    return service('response')
        ->setStatusCode(500)
        ->setJSON([
            'status' => 'error',
            'message' => 'Internal Server Error',
            'code' => 500
        ]);
});*/
