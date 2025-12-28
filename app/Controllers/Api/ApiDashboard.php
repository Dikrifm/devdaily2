<?php

namespace App\Controllers\Api;

use App\Enums\ProductStatus;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class ApiDashboard
 *
 * Controller API untuk Data Dashboard & Monitoring (External/Mobile).
 * * Layer 4: The Plug & Play Layer (API Context)
 *
 * Endpoint:
 * - GET /api/dashboard/stats  (Statistik ringkas)
 * - GET /api/dashboard/health (Status kesehatan sistem)
 */
class ApiDashboard extends BaseApi
{
    private ProductOrchestrator $orchestrator;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load Orchestrator
        $this->orchestrator = Services::productOrchestrator();
    }

    /**
     * Dashboard Summary Stats
     * GET /api/dashboard/stats
     *
     * Mengembalikan ringkasan jumlah data untuk widget dashboard.
     */
    public function stats()
    {
        try {
            // Menggunakan method count dari Orchestrator
            // Data ini berguna untuk widget "Total Produk Aktif", "Perlu Review", dll.
            $stats = [
                'products' => [
                    'active'   => $this->orchestrator->countProductsByStatus(ProductStatus::PUBLISHED->value),
                    'draft'    => $this->orchestrator->countProductsByStatus(ProductStatus::DRAFT->value),
                    'archived' => $this->orchestrator->countProductsByStatus(ProductStatus::ARCHIVED->value),
                ],
                // Kedepannya bisa ditambah: 'users', 'links', 'clicks' jika service terkait sudah siap
                'generated_at' => date('Y-m-d H:i:s')
            ];

            return $this->respond([
                'status'  => 'success',
                'message' => 'Data statistik dashboard berhasil diambil.',
                'data'    => $stats
            ]);

        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }

    /**
     * System Health Check
     * GET /api/dashboard/health
     *
     * Endpoint untuk monitoring uptime/kesehatan modul produk.
     * Biasanya dipanggil oleh Uptime Robot atau Status Page.
     */
    public function health()
    {
        try {
            // Mengambil status kesehatan komponen internal (DB, Cache, dll)
            // Orchestrator akan mengecek koneksi ke dependencies-nya
            $healthData = $this->orchestrator->getServiceHealth();

            // Tentukan HTTP Code: 200 jika sehat, 503 jika ada yang critical down
            $statusCode = ($healthData['status'] ?? 'ok') === 'ok' ? 200 : 503;

            return $this->respond([
                'status'  => $healthData['status'] ?? 'unknown',
                'message' => 'System health check completed.',
                'data'    => $healthData
            ], $statusCode);

        } catch (\Throwable $th) {
            // Jika health check meledak, berarti sistem benar-benar down
            return $this->failServerError('Critical: Health check failed. ' . $th->getMessage());
        }
    }
}
