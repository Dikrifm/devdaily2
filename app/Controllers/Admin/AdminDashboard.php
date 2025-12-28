<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use Config\Services;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Class AdminDashboard
 *
 * Controller untuk Halaman Dashboard Utama.
 * Mengorkestrasi pengambilan data ringkasan dari berbagai Service Domain.
 */
class AdminDashboard extends BaseAdmin
{
    /**
     * Menampilkan halaman dashboard utama.
     * GET /admin/dashboard
     */
    public function index(): string
    {
        // 1. Load Services yang dibutuhkan (Layer 0 via Container)
        $productMaintenance = Services::productMaintenance();
        $auditService       = Services::auditLog();
        $linkService        = Services::link();

        // 2. Siapkan Data Statistik (Layer 5)
        // Kita gunakan try-catch terpisah agar satu error tidak mematikan seluruh dashboard (Fail Safe)
        
        // Widget A: Statistik Produk & Kinerja
        try {
            // Mengambil statistik ringkas untuk bulan ini
            $productStats = $productMaintenance->getDashboardStatistics(['period' => 'month']);
        } catch (\Exception $e) {
            $productStats = [];
            $this->logger->error('[Dashboard] Gagal memuat Product Stats: ' . $e->getMessage());
        }

        // Widget B: Aktivitas Terbaru (Audit Log)
        try {
            // Mengambil 10 log terakhir dalam 24 jam
            $recentActivity = $auditService->getRecentLogs(24, null); // null pagination object returns array
            
            // Karena getRecentLogs mungkin mengembalikan array logs, kita potong manual jika perlu 
            // atau bergantung pada implementasi service untuk limit.
            if (is_array($recentActivity)) {
                $recentActivity = array_slice($recentActivity, 0, 8);
            }
        } catch (\Exception $e) {
            $recentActivity = [];
            $this->logger->error('[Dashboard] Gagal memuat Audit Log: ' . $e->getMessage());
        }

        // Widget C: Link dengan Performa Tertinggi
        try {
            // Mengambil 5 link teratas berdasarkan revenue bulan ini
            $topLinks = $linkService->getTopPerformingLinks('revenue', 'month', 5);
        } catch (\Exception $e) {
            $topLinks = [];
            $this->logger->error('[Dashboard] Gagal memuat Top Links: ' . $e->getMessage());
        }

        // 3. Render View (Layer 4 Egress)
        // Data dikirim ke View untuk dirender. Controller tidak peduli bagaimana bentuk HTML-nya.
        return view('admin/dashboard/index', [
            'title'          => 'Dashboard Ikhtisar',
            'productStats'   => $productStats,
            'recentActivity' => $recentActivity,
            'topLinks'       => $topLinks,
            // $currentAdmin sudah otomatis tersedia berkat BaseAdmin
        ]);
    }
}
