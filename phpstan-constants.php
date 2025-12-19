<?php

// Tentukan environment default
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'testing');
}

// Tentukan path folder utama CodeIgniter 4
$root = getcwd();

if (!defined('ROOTPATH')) {
    define('ROOTPATH', $root . DIRECTORY_SEPARATOR);
}

if (!defined('APPPATH')) {
    define('APPPATH', ROOTPATH . 'app' . DIRECTORY_SEPARATOR);
}

if (!defined('SYSTEMPATH')) {
    define('SYSTEMPATH', ROOTPATH . 'vendor/codeigniter4/framework/system' . DIRECTORY_SEPARATOR);
}

if (!defined('WRITEPATH')) {
    define('WRITEPATH', ROOTPATH . 'writable' . DIRECTORY_SEPARATOR);
}

if (!defined('FCPATH')) {
    define('FCPATH', ROOTPATH . 'public' . DIRECTORY_SEPARATOR);
}

if (!defined('TESTPATH')) {
    define('TESTPATH', ROOTPATH . 'tests' . DIRECTORY_SEPARATOR);
}
