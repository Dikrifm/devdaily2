<?php

// File ini dijalankan SEBELUM semua test
// Tujuannya: menyiapkan environment testing

// 1. Load path configuration CodeIgniter
require_once __DIR__ . '/../app/Config/Paths.php';
$paths = new Config\Paths();

// 2. Load bootstrap CodeIgniter
require_once rtrim($paths->systemDirectory, '\\/ ') . '/bootstrap.php';

echo "🚀 Environment testing siap!\n";
