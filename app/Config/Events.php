<?php

namespace Config;

use CodeIgniter\Events\Events;
use CodeIgniter\Exceptions\FrameworkException;

/*
 * --------------------------------------------------------------------
 * Application Events
 * --------------------------------------------------------------------
 * Events allow you to tap into the execution of the program without
 * modifying or extending core files. This file provides a central
 * location to define your events, though they can always be defined
 * elsewhere.
 */

Events::on('pre_system', function () {
    // Inisialisasi Bugsnag satu kali untuk seluruh siklus aplikasi
    $apiKey = env('bugsnag.apiKey');
    
    if (!empty($apiKey)) {
        $bugsnag = \Bugsnag\Client::make($apiKey);
        
        // Daftarkan Handler Otomatis (Menangkap Exception & Fatal Error)
        \Bugsnag\Handler::register($bugsnag);
        
        $bugsnag->setReleaseStage(env('CI_ENVIRONMENT'));
    }
});


/*
 * --------------------------------------------------------------------
 * Debug Toolbar Listeners.
 * --------------------------------------------------------------------
 * If you delete, they will no longer be collected.
 */
if (CI_DEBUG && ! is_cli()) {
    Events::on('DBQuery', 'CodeIgniter\Debug\Toolbar\Collectors\Database::collect');
    Services::toolbar()->respond();
}
