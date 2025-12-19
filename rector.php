<?php

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    // 1. TARGET: Folder yang akan diperbaiki otomatis
    // Sesuai struktur audit_backend.txt Anda
    $rectorConfig->paths([
        __DIR__ . '/app/Contracts',
     //   __DIR__ . '/app/Controllers',
        __DIR__ . '/app/Enums',
        __DIR__ . '/app/Entities',
        __DIR__ . '/app/Exceptions',
        __DIR__ . '/app/Models',
        __DIR__ . '/app/Services',
        __DIR__ . '/app/Repositories',
        __DIR__ . '/app/DTOs',
        __DIR__ . '/app/Validators'
    ]);

    // 2. SAFETY: Folder yang JANGAN disentuh
    // Views dan Config CI4 sering error jika diubah otomatis
    $rectorConfig->skip([
        __DIR__ . '/app/Views',
        __DIR__ . '/app/Config',
        __DIR__ . '/app/Database',
        __DIR__ . '/app/Common.php',
    ]);

    // 3. RULES: Aturan perbaikan
    $rectorConfig->sets([
        // Membersihkan kode mati (Dead Code)
        SetList::DEAD_CODE,
        // Menyederhanakan logika code (Code Quality)
        SetList::CODE_QUALITY,
        // Modernisasi syntax (sesuaikan dengan versi PHP Termux Anda, biasanya 8.1/8.2)
        SetList::PHP_81,
    ]);
};
