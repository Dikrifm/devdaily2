<?php

namespace App\Controllers\Htmx;

use App\Controllers\BaseAdmin;
use App\DTOs\Queries\ProductQuery;
use App\DTOs\Requests\Product\ProductToggleStatusRequest;
use App\Exceptions\ProductNotFoundException;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class Product
 *
 * Controller HTMX untuk interaksi dinamis pada modul Produk.
 * * Layer 4: The Plug & Play Layer (HTMX Context)
 *
 * Bertanggung jawab merender potongan HTML (Fragments) untuk:
 * 1. Tabel Produk (Pencarian & Pagination)
 * 2. Update Status Cepat (Switch)
 * 3. Hapus Baris
 */
class Product extends BaseAdmin
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
     * Menangani Live Search & Pagination
     * GET /htmx/products/search
     *
     * Request Headers: HX-Request: true
     * Response: HTML Fragment (_table_rows.php + _pagination.php)
     */
    public function search()
    {
        try {
            // 1. Tangkap Query Parameters
            $queryParams = $this->request->getGet();
            
            // 2. Buat Query DTO
            // Admin mode = true (bisa lihat draft/archived)
            $queryDTO = ProductQuery::fromRequest($queryParams, true);

            // 3. Ambil Data via Orchestrator
            // Return: ['data' => ProductResponse[], 'pager' => Pager]
            $result = $this->orchestrator->listProducts($queryDTO, true);

            // 4. Render Fragment Baris Tabel
            // Kita gabungkan baris tabel dan pagination baru dalam satu respon
            // HTMX akan menukar innerHTML dari target container
            $html  = view('admin/product/components/_table_rows', ['products' => $result['data']]);
            
            // Jika request meminta pagination terpisah (biasanya OOB Swap), kita bisa append
            // Tapi untuk simplifikasi, kita asumsikan view _table_rows sudah handle logic "No Data"
            // dan kita kirim pagination sebagai update OOB (Out of Band) jika container terpisah
            
            $paginationHtml = view('admin/product/components/_pagination', ['pager' => $result['pager']]);
            
            // Gabungkan output: Baris Tabel + Update Pagination (via OOB Swap technique recommended, or simple concat)
            // Disini kita kirim string concat, view di client harus punya container yang pas.
            // Strategi: Kembalikan rows, dan selipkan div pagination dengan hx-swap-oob="true"
            
            return $this->response->setBody($html . $paginationHtml);

        } catch (\Exception $e) {
            $this->logger->error('[HtmxProduct::search] ' . $e->getMessage());
            // Kembalikan baris error yang user-friendly
            return $this->response->setBody('<tr><td colspan="7" class="text-center text-red-500 py-4">Terjadi kesalahan memuat data.</td></tr>');
        }
    }

    /**
     * Toggle Status Produk (Aktif/Nonaktif)
     * POST /htmx/products/{id}/toggle-status
     */
    public function toggleStatus(int $id)
    {
        try {
            // 1. Tentukan Status Baru (Logic toggle sederhana atau terima dari post)
            // Idealnya service handle logic "flip", tapi controller bisa kirim request spesifik
            // Kita asumsikan UI mengirim status tujuan via POST body, atau kita cek status skrg lalu balik.
            // Untuk efisiensi HTMX, kita terima status yang diinginkan dari parameter post 'status'
            
            $targetStatus = $this->request->getPost('status'); // 'active' or 'draft'
            
            // Jika tidak dikirim, fetch dulu (agak mahal query-nya), jadi sebaiknya UI kirim.
            // Fallback logic jika UI tidak kirim:
            if (!$targetStatus) {
                $product = $this->orchestrator->getProduct($id);
                $targetStatus = ($product->getStatus() === 'active') ? 'draft' : 'active';
            }

            // 2. Buat DTO
            $dto = new ProductToggleStatusRequest(
                id: $id,
                status: $targetStatus,
                actorId: $this->getCurrentAdminId()
            );

            // 3. Eksekusi via Orchestrator (akan memanggil WorkflowService)
            $updatedProduct = $this->orchestrator->updateProductStatus($dto);

            // 4. Response
            // Mengembalikan HTML badge status yang baru
            $responseHtml = view('admin/product/components/_status_badge', ['product' => $updatedProduct]);
            
            // Tambahkan HX-Trigger header untuk memunculkan Toast Notification di Client
            return $this->response
                ->setBody($responseHtml)
                ->setHeader('HX-Trigger', json_encode([
                    'showToast' => [
                        'type' => 'success',
                        'message' => "Status produk \"{$updatedProduct->getName()}\" diubah menjadi {$updatedProduct->getStatusLabel()}"
                    ]
                ]));

        } catch (\Exception $e) {
            // Jika gagal, kembalikan UI status awal (revert) + Toast Error
            return $this->response
                ->setStatusCode(422) // Unprocessable Entity agar HTMX mentrigger error event
                ->setHeader('HX-Trigger', json_encode([
                    'showToast' => [
                        'type' => 'error',
                        'message' => 'Gagal mengubah status: ' . $e->getMessage()
                    ]
                ]));
        }
    }

    /**
     * Hapus Produk (Soft Delete)
     * DELETE /htmx/products/{id}
     */
    public function delete(int $id)
    {
        try {
            // 1. Eksekusi Hapus
            $this->orchestrator->deleteProduct($id, $this->getCurrentAdminId());

            // 2. Response
            // Kembalikan string kosong atau 200 OK. 
            // HTMX akan menghapus elemen target (baris tr) jika dikonfigurasi dengan hx-swap="outerHTML" -> empty
            return $this->response
                ->setBody('')
                ->setHeader('HX-Trigger', json_encode([
                    'showToast' => [
                        'type' => 'success',
                        'message' => 'Produk berhasil dihapus.'
                    ],
                    'refreshStats' => true // Trigger event lain untuk refresh widget statistik jika ada
                ]));

        } catch (ProductNotFoundException $e) {
             return $this->response
                ->setStatusCode(404)
                ->setHeader('HX-Trigger', json_encode(['showToast' => ['type' => 'error', 'message' => 'Produk sudah tidak ada.']]));
        } catch (\Exception $e) {
            return $this->response
                ->setStatusCode(500)
                ->setHeader('HX-Trigger', json_encode(['showToast' => ['type' => 'error', 'message' => 'Gagal menghapus: ' . $e->getMessage()]]));
        }
    }
    
    /**
     * Helper: Get Current Admin ID
     */
    private function getCurrentAdminId(): int
    {
        return $this->currentAdmin ? $this->currentAdmin->getId() : 0;
    }
}
