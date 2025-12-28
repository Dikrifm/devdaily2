<?php

namespace App\Controllers\Htmx;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Product\ProductBulkActionRequest;
use App\Enums\ProductBulkActionType;
use App\Exceptions\ValidationException;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class ProductBulk
 *
 * Controller HTMX Spesialis untuk Operasi Massal (Bulk Actions) Produk.
 * * Layer 4: The Plug & Play Layer (HTMX Bulk Context)
 *
 * Controller ini menangani form submission dari "Checkbox Select All" di tabel produk.
 * Karena operasi bulk bisa berat, logika bisnis diserahkan sepenuhnya ke Orchestrator -> BulkService.
 */
class ProductBulk extends BaseAdmin
{
    private ProductOrchestrator $orchestrator;

    /**
     * Inisialisasi Service via Dependency Injection
     * Menggunakan initController untuk konsistensi dengan BaseController CI4
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load Orchestrator (Single Entry Point untuk modul Produk)
        $this->orchestrator = Services::productOrchestrator();
    }

    /**
     * Menangani Request Aksi Massal
     * POST /htmx/products/bulk-action
     *
     * Payload yang diharapkan:
     * - ids[] : Array of integers (ID produk yang dipilih)
     * - action : String (delete, publish, draft, archive) - lihat ProductBulkActionType
     */
    public function handle()
    {
        try {
            // 1. Validasi Awal Input (Sanitasi Dasar)
            $rawIds = $this->request->getPost('ids');
            $rawAction = $this->request->getPost('action');

            if (empty($rawIds) || !is_array($rawIds)) {
                throw new ValidationException('Tidak ada produk yang dipilih.');
            }

            if (empty($rawAction)) {
                throw new ValidationException('Tipe aksi tidak valid.');
            }

            // 2. Konversi String Action ke Enum (Type Safety)
            // Ini mencegah serangan manipulasi action string
            try {
                $actionType = ProductBulkActionType::from($rawAction);
            } catch (\ValueError $e) {
                throw new ValidationException("Aksi '$rawAction' tidak dikenali sistem.");
            }

            // 3. Bungkus ke DTO Request (Layer 6)
            // DTO ini memastikan data yang masuk ke Service sudah terstruktur rapi
            $requestDTO = new ProductBulkActionRequest(
                productIds: array_map('intval', $rawIds),
                action: $actionType,
                actorId: $this->getCurrentAdminId()
            );

            // 4. Eksekusi via Orchestrator (Layer 5)
            // Orchestrator akan mendelegasikan ke ProductBulkService.
            // Return value adalah DTO BulkActionResult yang berisi summary (success count, fail count).
            $result = $this->orchestrator->bulkAction($requestDTO);

            // 5. Susun Pesan Feedback
            $toastType = 'success';
            $message = "Berhasil memproses {$result->successCount} produk.";

            // Jika ada kegagalan parsial (misal: 3 sukses, 2 gagal karena dikunci user lain)
            if ($result->hasFailures()) {
                $toastType = 'warning';
                $message .= " Namun, {$result->failCount} produk gagal diproses.";
                // Kita bisa log detail kegagalan di sini jika perlu
            }

            // 6. Response HTMX
            // Kita tidak mengembalikan HTML body karena aksi ini biasanya tombol di toolbar.
            // Kita mengandalkan HTTP Headers untuk memerintah Client melakukan refresh.
            
            // Event List untuk Client:
            $events = [
                'showToast' => [
                    'type'    => $toastType,
                    'message' => $message
                ],
                // Sinyal ke tabel untuk reload data (ajax reload) agar status checkbox ter-reset
                'refreshTable' => true,
                // Sinyal ke widget statistik dashboard/header untuk update angka count
                'refreshStats' => true 
            ];

            return $this->response
                ->setStatusCode(200)
                ->setHeader('HX-Trigger', json_encode($events));

        } catch (ValidationException $e) {
            // Error Validasi (User salah input / tidak pilih checkbox)
            return $this->response
                ->setStatusCode(422) // Unprocessable Entity
                ->setHeader('HX-Trigger', json_encode([
                    'showToast' => [
                        'type'    => 'error',
                        'message' => $e->getMessage()
                    ]
                ]));

        } catch (\Exception $e) {
            // Error Sistem / Domain Fatal
            $this->logger->error('[ProductBulk::handle] ' . $e->getMessage());
            
            return $this->response
                ->setStatusCode(500)
                ->setHeader('HX-Trigger', json_encode([
                    'showToast' => [
                        'type'    => 'error',
                        'message' => 'Terjadi kesalahan sistem saat memproses aksi massal.'
                    ]
                ]));
        }
    }

    /**
     * Helper: Mendapatkan ID Admin saat ini
     * * @return int
     */
    private function getCurrentAdminId(): int
    {
        // $this->currentAdmin diwarisi dari BaseAdmin dan sudah di-hydrate oleh Filter
        return $this->currentAdmin ? $this->currentAdmin->getId() : 0;
    }
}
