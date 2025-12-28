<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Badge\CreateBadgeRequest;
use App\DTOs\Requests\Badge\UpdateBadgeRequest;
use App\Exceptions\BadgeNotFoundException;
use App\Exceptions\ValidationException;
use App\Services\BadgeService;
use Config\Services;

/**
 * Class AdminBadge
 *
 * Controller Halaman Penuh untuk Manajemen Master Badge.
 * Badge adalah label visual yang ditempelkan pada produk (contoh: "Official", "Best Seller").
 * * Layer 4: The Plug & Play Layer
 */
class AdminBadge extends BaseAdmin
{
    private BadgeService $badgeService;

    /**
     * Inisialisasi Service via Container
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load BadgeService
        $this->badgeService = Services::badge();
    }

    /**
     * Halaman List Badge (Index)
     * GET /admin/badges
     */
    public function index()
    {
        // 1. Ambil Parameter Filter
        $filters = $this->request->getGet();
        
        // 2. Ambil Semua Badge (List sederhana, biasanya tidak terlalu banyak jadi tidak perlu pagination berat)
        // Kita asumsikan getAllBadges mengembalikan Collection/Array of BadgeResponse
        $badges = $this->badgeService->getAllBadges($filters);

        return view('admin/badge/index', [
            'title'   => 'Manajemen Badge Produk',
            'badges'  => $badges,
            'filters' => $filters,
        ]);
    }

    /**
     * Halaman Form Tambah Badge
     * GET /admin/badges/new
     */
    public function create()
    {
        return view('admin/badge/form_create', [
            'title' => 'Tambah Badge Baru',
            // Opsi warna preset untuk UI helper
            'colors' => ['red', 'blue', 'green', 'yellow', 'gray', 'indigo', 'purple', 'pink'] 
        ]);
    }

    /**
     * Proses Simpan Badge Baru
     * POST /admin/badges
     */
    public function store()
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = CreateBadgeRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $response = $this->badgeService->createBadge($requestDTO);

            return $this->redirectSuccess(
                '/admin/badges', 
                "Badge \"{$response->name}\" berhasil dibuat."
            );

        } catch (ValidationException $e) {
            // Error validasi (misal: Nama badge sudah ada)
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            $this->logger->error('[AdminBadge::store] ' . $e->getMessage());
            return $this->redirectError('/admin/badges/new', 'Gagal membuat badge: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Form Edit Badge
     * GET /admin/badges/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            $badge = $this->badgeService->getBadge($id);

            return view('admin/badge/form_edit', [
                'title' => 'Edit Badge: ' . $badge->name,
                'badge' => $badge,
                'colors' => ['red', 'blue', 'green', 'yellow', 'gray', 'indigo', 'purple', 'pink']
            ]);

        } catch (BadgeNotFoundException $e) {
            return $this->redirectError('/admin/badges', 'Badge tidak ditemukan.');
        }
    }

    /**
     * Proses Update Badge
     * PUT/POST /admin/badges/{id}
     */
    public function update(int $id)
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = UpdateBadgeRequest::fromRequest(
                $id,
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $response = $this->badgeService->updateBadge($id, $requestDTO);

            return $this->redirectSuccess(
                '/admin/badges', 
                "Badge \"{$response->name}\" berhasil diperbarui."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (BadgeNotFoundException $e) {
            return $this->redirectError('/admin/badges', 'Badge tidak ditemukan.');

        } catch (\Exception $e) {
            $this->logger->error('[AdminBadge::update] ' . $e->getMessage());
            return $this->redirectError("/admin/badges/{$id}/edit", 'Gagal update badge: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Hapus Badge (Soft/Hard Delete)
     * DELETE /admin/badges/{id}
     */
    public function delete(int $id)
    {
        try {
            $this->badgeService->deleteBadge($id);

            return $this->redirectSuccess('/admin/badges', 'Badge berhasil dihapus.');

        } catch (BadgeNotFoundException $e) {
            return $this->redirectError('/admin/badges', 'Badge tidak ditemukan.');
        } catch (\Exception $e) {
            return $this->redirectError('/admin/badges', 'Gagal menghapus badge: ' . $e->getMessage());
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
