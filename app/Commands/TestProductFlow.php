<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\DTOs\Requests\Product\CreateProductRequest;
use App\Enums\ProductStatus;

class TestProductFlow extends BaseCommand
{
    // Nama command untuk dipanggil di terminal
    protected $group       = 'Testing';
    protected $name        = 'test:product-create';
    protected $description = 'Tes integrasi Create Product tanpa PHPUnit';

    public function run(array $params)
    {
        CLI::write('🚀 Memulai Tes Integrasi: Create Product...', 'yellow');

        try {
            // 1. Ambil Service (Ini akan memicu Eager Loading, tapi AMAN karena cuma 1 proses)
            $productService = service('productService');
            CLI::write('✅ Service & Dependencies Loaded', 'green');

            // 2. Simulasi Data Input (DTO)
            // Kita bypass Controller dan langsung tembak Service
            // karena Controller intinya cuma parsing Request ke DTO.
            $dto = new CreateProductRequest(
                name: 'Tes Termux Product ' . time(), // Nama unik
                description: 'Dibuat via Spark Command',
                marketPrice: 50000,
                status: ProductStatus::DRAFT,
                categoryId: 1 // Pastikan ID kategori ini ada di DB Anda
            );
            
            CLI::write('🔄 Mengirim data ke Service...', 'yellow');

            // 3. Eksekusi
            $startTime = microtime(true);
            $productId = $productService->create($dto);
            $endTime = microtime(true);

            // 4. Verifikasi Hasil
            if ($productId) {
                CLI::write("🎉 SUKSES! Produk ID: {$productId}", 'green');
                CLI::write("⏱️ Waktu Eksekusi: " . round($endTime - $startTime, 4) . " detik", 'cyan');
                
                // Cek Database sekalian (Validasi Integrasi DB)
                $db = \Config\Database::connect();
                $row = $db->table('products')->where('id', $productId)->get()->getRow();
                
                if ($row) {
                    CLI::write("💾 Konfirmasi DB: Data ditemukan di tabel 'products'.", 'green');
                    CLI::write("   - Nama: " . $row->name);
                    CLI::write("   - Status: " . $row->status);
                } else {
                    CLI::error("❌ GAGAL: ID kembali tapi data tidak ada di DB (Transaction Rollback?)");
                }
            } else {
                CLI::error('❌ GAGAL: Service tidak mengembalikan ID.');
            }

        } catch (\Exception $e) {
            // Tangkap Error Integrasi (Salah kolom, salah tipe data, dll)
            CLI::error('💥 ERROR INTEGRASI TERDETEKSI:');
            CLI::write($e->getMessage(), 'red');
            CLI::write($e->getTraceAsString(), 'dark_gray');
        }
    }
}
