<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Marketplace\CreateMarketplaceRequest;
use App\DTOs\Requests\Marketplace\UpdateMarketplaceRequest;
use App\Exceptions\MarketplaceNotFoundException;
use App\Exceptions\ValidationException;
use App\Services\MarketplaceService;
use Config\Services;

/**
 * Class AdminMarketplace
 *
 * Controller Halaman Penuh untuk Manajemen Marketplace/Platform.
 * Menangani CRUD Marketplace, termasuk konfigurasi API dan parameter afiliasi.
 */
class AdminMarketplace extends BaseAdmin
{
    private MarketplaceService $marketplaceService;

    /**
     * Inisialisasi Service via Dependency Injection
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load MarketplaceService dari Container
        $this->marketplaceService = Services::marketplace();
    }

    /**
     * Halaman List Marketplace (Index)
     * GET /admin/marketplaces
     */
    public function index()
    {
        // 1. Ambil Parameter Filter dari URL (misal: ?status=active)
        $filters = $this->request->getGet();

        // 2. Ambil Data Marketplace
        // Menggunakan getAllMarketplaces untuk mendapatkan list lengkap (termasuk inactive/archived jika perlu)
        // Service akan mengembalikan array of MarketplaceResponse
        $marketplaces = $this->marketplaceService->getAllMarketplaces($filters);

        // 3. Ambil Statistik Ringkas (Opsional, untuk widget di atas tabel)
        $stats = [];
        try {
            $stats = $this->marketplaceService->getMarketplaceStatistics();
        } catch (\Exception $e) {
            // Silently fail untuk statistik agar tidak memblokir halaman utama
            $this->logger->warning('[AdminMarketplace::index] Gagal memuat statistik: ' . $e->getMessage());
        }

        return view('admin/marketplace/index', [
            'title'        => 'Manajemen Marketplace',
            'marketplaces' => $marketplaces,
            'stats'        => $stats,
            'filters'      => $filters,
        ]);
    }

    /**
     * Halaman Form Tambah Marketplace
     * GET /admin/marketplaces/new
     */
    public function create()
    {
        return view('admin/marketplace/form_create', [
            'title' => 'Tambah Marketplace Baru',
        ]);
    }

    /**
     * Proses Simpan Marketplace Baru
     * POST /admin/marketplaces
     */
    public function store()
    {
        try {
            // 1. Transform Request ke DTO (Layer 6)
            // Validasi format dasar (required fields, tipe data) terjadi di sini
            $requestDTO = CreateMarketplaceRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service (Layer 5)
            // Service menangani validasi bisnis (slug unik, domain valid) dan persistensi
            $response = $this->marketplaceService->createMarketplace($requestDTO);

            // 3. Redirect Sukses
            return $this->redirectSuccess(
                '/admin/marketplaces',
                "Marketplace \"{$response->getName()}\" berhasil ditambahkan."
            );

        } catch (ValidationException $e) {
            // Tangkap error validasi dan kembalikan ke form
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            // Tangkap error tidak terduga
            $this->logger->error('[AdminMarketplace::store] ' . $e->getMessage());
            return $this->redirectError('/admin/marketplaces/new', 'Gagal menambahkan marketplace: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Form Edit Marketplace
     * GET /admin/marketplaces/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            // 1. Ambil Data Detail Marketplace
            // Parameter true = include deleted/trashed (jika fitur soft delete aktif dan ingin diedit)
            $marketplace = $this->marketplaceService->getMarketplace($id, true);

            return view('admin/marketplace/form_edit', [
                'title'       => 'Edit Marketplace: ' . $marketplace->getName(),
                'marketplace' => $marketplace,
            ]);

        } catch (MarketplaceNotFoundException $e) {
            return $this->redirectError('/admin/marketplaces', 'Marketplace tidak ditemukan.');
        }
    }

    /**
     * Proses Update Marketplace
     * PUT/POST /admin/marketplaces/{id}
     */
    public function update(int $id)
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = UpdateMarketplaceRequest::fromRequest(
                $id,
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Update via Service
            $response = $this->marketplaceService->updateMarketplace($id, $requestDTO);

            return $this->redirectSuccess(
                '/admin/marketplaces',
                "Marketplace \"{$response->getName()}\" berhasil diperbarui."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (MarketplaceNotFoundException $e) {
            return $this->redirectError('/admin/marketplaces', 'Marketplace tidak ditemukan.');

        } catch (\Exception $e) {
            $this->logger->error('[AdminMarketplace::update] ' . $e->getMessage());
            return $this->redirectError("/admin/marketplaces/{$id}/edit", 'Gagal update marketplace: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Proses Hapus Marketplace (Soft/Hard Delete)
     * DELETE /admin/marketplaces/{id}
     */
    public function delete(int $id)
    {
        try {
            // Ambil parameter force delete jika ada (biasanya checkbox di modal konfirmasi)
            $force = $this->request->getPost('force') === '1';
            $reason = (string) $this->request->getPost('reason');

            // Eksekusi Delete
            $this->marketplaceService->deleteMarketplace($id, $force, $reason ?: null);

            return $this->redirectSuccess('/admin/marketplaces', 'Marketplace berhasil dihapus.');

        } catch (ValidationException $e) {
            // Error jika marketplace masih memiliki link aktif (Referential Integrity di level Service)
            return $this->redirectError('/admin/marketplaces', $e->getMessage());

        } catch (MarketplaceNotFoundException $e) {
            return $this->redirectError('/admin/marketplaces', 'Marketplace tidak ditemukan.');
        }
    }

    /**
     * Proses Toggle Status Aktif/Nonaktif
     * POST /admin/marketplaces/{id}/toggle-status
     */
    public function toggleStatus(int $id)
    {
        try {
            $marketplace = $this->marketplaceService->getMarketplace($id);
            
            if ($marketplace->isActive()) {
                $this->marketplaceService->deactivateMarketplace($id, 'User toggled status');
                $message = "Marketplace \"{$marketplace->getName()}\" dinonaktifkan.";
            } else {
                $this->marketplaceService->activateMarketplace($id);
                $message = "Marketplace \"{$marketplace->getName()}\" diaktifkan.";
            }

            return $this->redirectSuccess('/admin/marketplaces', $message);

        } catch (MarketplaceNotFoundException $e) {
            return $this->redirectError('/admin/marketplaces', 'Marketplace tidak ditemukan.');
        } catch (\Exception $e) {
             return $this->redirectError('/admin/marketplaces', 'Gagal mengubah status: ' . $e->getMessage());
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
