<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\Exceptions\NotFoundException;
use App\Services\AdminService;
use App\Services\AuditLogService;
use Config\Services;

/**
 * Class AdminAuditLog
 *
 * Controller Halaman Penuh untuk Audit & Keamanan Sistem.
 * Bersifat READ-ONLY. Tidak ada operasi Create/Update/Delete di sini.
 * Digunakan untuk pemantauan aktivitas staff, debugging isu data, dan forensik keamanan.
 */
class AdminAuditLog extends BaseAdmin
{
    private AuditLogService $auditService;
    private AdminService $adminService;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load Services
        $this->auditService = Services::auditLog();
        $this->adminService = Services::admin();
    }

    /**
     * Halaman List Log Aktivitas (Index)
     * GET /admin/audit-logs
     */
    public function index()
    {
        // 1. Tangkap Filter dari URL
        $filters = $this->request->getGet();
        $page    = (int) ($filters['page'] ?? 1);
        $perPage = 50; // Tampilkan lebih banyak baris untuk log

        // Sanitasi filter tanggal default (Hari ini - 30 hari)
        if (empty($filters['date_start'])) {
            $filters['date_start'] = date('Y-m-d', strtotime('-30 days'));
        }
        if (empty($filters['date_end'])) {
            $filters['date_end'] = date('Y-m-d');
        }

        // 2. Ambil Data Log via Service
        // Service akan mengembalikan array ['data' => AuditLogResponse[], 'pager' => Pager]
        $result = $this->auditService->searchLogs($filters, $page, $perPage);

        // 3. Ambil List Admin untuk Dropdown Filter "Actor"
        // Kita butuh list simple [id => name] untuk select option
        $admins = $this->adminService->getAdminListForDropdown();

        // 4. List Action Types yang unik (Hardcoded atau dari Enum)
        // Sebaiknya dari Enum ActionType jika ada, atau hardcoded list umum sistem
        $actionTypes = [
            'AUTH_LOGIN', 'AUTH_LOGOUT', 'AUTH_FAILED',
            'PRODUCT_CREATE', 'PRODUCT_UPDATE', 'PRODUCT_DELETE',
            'ORDER_PROCESS', 'SYSTEM_CONFIG'
        ];

        return view('admin/audit/index', [
            'title'       => 'Audit Log & Aktivitas',
            'logs'        => $result['data'] ?? [],
            'pager'       => $result['pager'] ?? null,
            'filters'     => $filters,
            'admins'      => $admins,
            'actionTypes' => $actionTypes
        ]);
    }

    /**
     * Detail Log Spesifik
     * GET /admin/audit-logs/{id}
     * * Biasanya dipanggil via AJAX untuk ditampilkan di Modal
     */
    public function show(int $id)
    {
        try {
            $log = $this->auditService->getLog($id);

            if ($this->request->isAJAX()) {
                return view('admin/audit/_detail_modal', ['log' => $log]);
            }

            return view('admin/audit/show', [
                'title' => 'Detail Aktivitas #' . $id,
                'log'   => $log
            ]);

        } catch (NotFoundException $e) {
            if ($this->request->isAJAX()) {
                return $this->response->setStatusCode(404)->setBody('Log tidak ditemukan');
            }
            return $this->redirectError('/admin/audit-logs', 'Log aktivitas tidak ditemukan.');
        }
    }

    /**
     * Export Log ke CSV
     * GET /admin/audit-logs/export
     */
    public function export()
    {
        try {
            // 1. Tangkap Filter (sama seperti index)
            $filters = $this->request->getGet();
            
            // Limit export untuk mencegah memory overflow (misal max 1000 baris)
            $limit = 1000;
            
            // 2. Ambil Data Mentah
            $logs = $this->auditService->exportLogs($filters, $limit);

            // 3. Generate CSV
            $filename = 'audit_log_' . date('Ymd_His') . '.csv';
            
            // Set Header Response agar browser mendownload
            $this->response->setHeader('Content-Type', 'text/csv');
            $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
            
            // Buka output stream php
            $handle = fopen('php://output', 'w');
            
            // Header CSV
            fputcsv($handle, ['ID', 'Timestamp', 'Actor', 'IP Address', 'Action', 'Entity Type', 'Entity ID', 'Details']);

            // Isi Data
            foreach ($logs as $log) {
                // Konversi Log Response ke array sederhana
                fputcsv($handle, [
                    $log->getId(),
                    $log->getCreatedAt(),
                    $log->getActorName() . ' (' . $log->getActorId() . ')',
                    $log->getIpAddress(),
                    $log->getAction(),
                    $log->getEntityType(),
                    $log->getEntityId(),
                    // Ratakan JSON details menjadi string agar muat di 1 sel CSV
                    json_encode($log->getDetails()) 
                ]);
            }
            
            fclose($handle);
            
            // Return response object untuk mengakhiri eksekusi dengan benar di CI4
            return $this->response;

        } catch (\Exception $e) {
            $this->logger->error('[AdminAuditLog::export] ' . $e->getMessage());
            return $this->redirectError('/admin/audit-logs', 'Gagal melakukan ekspor data.');
        }
    }
}
