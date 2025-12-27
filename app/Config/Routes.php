<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

// 1. KONFIGURASI DASAR
$routes->setDefaultNamespace('App\Controllers');
$routes->setDefaultController('Web\HomeController');
$routes->setDefaultMethod('index');
$routes->setTranslateURIDashes(false);
$routes->set404Override();
$routes->setAutoRoute(false);

// --- 2. ROUTE HOME (KITA MATIKAN SEMENTARA) ---
// $routes->get('/', 'Web\HomeController::index', ['as' => 'home']);

$routes->get('/', function() {
    return "<h1>Home Sedang Diperbaiki</h1><p>Silakan coba akses <a href='/auth/login'>/auth/login</a></p>";
});
// 2. ROUTE HOME (Public Web)
// Sesuai struktur folder: app/Controllers/Web/HomeController.php
//$routes->get('/', 'Web\HomeController::index', ['as' => 'home']);
$routes->get('about', 'Web\HomeController::about', ['as' => 'about']);

// 3. ROUTE AUTH (Login/Register)
// Sesuai struktur folder: app/Controllers/Auth.php
$routes->group('auth', ['namespace' => 'App\Controllers'], function($routes) {
    $routes->get('/', 'Auth::index', ['as' => 'auth.index']);
    $routes->get('login', 'Auth::login', ['as' => 'auth.login']);
    $routes->post('login', 'Auth::attemptLogin', ['as' => 'auth.attempt']);
    $routes->get('logout', 'Auth::logout', ['as' => 'auth.logout']);
    $routes->get('register', 'Auth::register', ['as' => 'auth.register']);
});

// Shortcut Login (Agar /login bisa diakses langsung)
$routes->get('login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

// 4. ROUTE ADMIN (Kita aktifkan bertahap)
// Sesuai struktur folder: app/Controllers/Admin/...
$routes->group('admin', ['namespace' => 'App\Controllers\Admin'], function($routes) {
    $routes->get('/', 'AdminDashboard::index', ['as' => 'admin.dashboard']);
    $routes->get('dashboard', 'AdminDashboard::index');
    
    // Admin Products
    $routes->get('products', 'AdminProduct::index', ['as' => 'admin.products.index']);
});

// 5. ROUTE API (⚠️ SEMENTARA DIMATIKAN ⚠️)
// Kita matikan dulu karena folder Api/V1 tidak ditemukan di ls -R Anda.
// Jika diaktifkan sekarang, server akan crash (looping) lagi.
/*
$routes->group('api/v1', ... );
*/
