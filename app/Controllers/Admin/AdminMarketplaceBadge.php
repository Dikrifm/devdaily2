<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\MarketplaceBadge\CreateMarketplaceBadgeRequest;
use App\DTOs\Requests\MarketplaceBadge\UpdateMarketplaceBadgeRequest;
use App\Exceptions\MarketplaceBadgeNotFoundException;
use App\Exceptions\ValidationException;
use App\Services\BadgeService;
use App\Services\MarketplaceBadgeService;
use App\Services\MarketplaceService;
use Config\Services;

/**
 * Class AdminMarketplaceBadge
 *
 * Controller Halaman Penuh untuk Manajemen Mapping Badge per Marketplace.
 * Mengatur badge apa saja yang valid atau ditampilkan khusus untuk marketplace tertentu.
 * * Layer 4: The Plug & Play Layer
 */
class AdminMarketplaceBadge extends BaseAdmin
{
    private MarketplaceBadgeService $marketplaceBadgeService;
    private MarketplaceService $marketplaceService;
    private BadgeService $badgeService;

    /**
     * Inisialisasi Services
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load Service Utama & Pendukung
        $this->marketplaceBadgeService = Services::marketplaceBadge();
        $this->marketplaceService      = Services::marketplace();
        $this->badgeService            = Services::badge();
    }

    /**
     * Halaman List Mapping Badge (Index)
     * GET /admin/marketplace-badges
     */
    public function index()
    {
        // 1. Ambil Parameter Filter (misal: filter by marketplace_id)
        $filters = $this->request->getGet();
        $page    = (int) ($filters['page'] ?? 1);
        $perPage = 20;

        // 2. Ambil Data Mapping
        // Mengembalikan data gabungan (Badge Name + Marketplace Name)
        $result = $this->marketplaceBadgeService->searchMarketplaceBadges($filters, $page, $perPage);

        // 3. Data Pendukung untuk Filter Dropdown
        $marketplaces = $this->marketplaceService->getActiveMarketplaces();

        return view('admin/marketplace_badge/index', [
            'title'        => 'Mapping Badge Marketplace',
            'mappings'     => $result['data'] ?? [],
            'pager'        => $result['pager'] ?? null,
            'filters'      => $filters,
            'marketplaces' => $marketplaces
        ]);
    }

    /**
     * Halaman Form Tambah Mapping
     * GET /admin/marketplace-badges/new
     */
    public function create()
    {
        return view('admin/marketplace_badge/form_create', [
            'title'        => 'Tambah Mapping Badge',
            'marketplaces' => $this->marketplaceService->getActiveMarketplaces(),
            'badges'       => $this->badgeService->getActiveBadges(), // Badge Master
        ]);
    }

    /**
     * Proses Simpan Mapping Baru
     * POST /admin/marketplace-badges
     */
    public function store()
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = CreateMarketplaceBadgeRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            // Service akan memvalidasi apakah pasangan marketplace_id + badge_id sudah ada
            $response = $this->marketplaceBadgeService->createMarketplaceBadge($requestDTO);

            return $this->redirectSuccess(
                '/admin/marketplace-badges', 
                "Badge berhasil dipetakan ke Marketplace."
            );

        } catch (ValidationException $e) {
            // Error validasi (misal: Duplikasi mapping)
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            $this->logger->error('[AdminMarketplaceBadge::store] ' . $e->getMessage());
            return $this->redirectError('/admin/marketplace-badges/new', 'Gagal mapping badge: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Form Edit Mapping
     * GET /admin/marketplace-badges/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            $mapping = $this->marketplaceBadgeService->getMarketplaceBadge($id);

            return view('admin/marketplace_badge/form_edit', [
                'title'        => 'Edit Mapping Badge',
                'mapping'      => $mapping,
                'marketplaces' => $this->marketplaceService->getActiveMarketplaces(),
                'badges'       => $this->badgeService->getActiveBadges(),
            ]);

        } catch (MarketplaceBadgeNotFoundException $e) {
            return $this->redirectError('/admin/marketplace-badges', 'Data mapping tidak ditemukan.');
        }
    }

    /**
     * Proses Update Mapping
     * PUT/POST /admin/marketplace-badges/{id}
     */
    public function update(int $id)
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = UpdateMarketplaceBadgeRequest::fromRequest(
                $id,
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $this->marketplaceBadgeService->updateMarketplaceBadge($id, $requestDTO);

            return $this->redirectSuccess(
                '/admin/marketplace-badges', 
                "Mapping badge berhasil diperbarui."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (MarketplaceBadgeNotFoundException $e) {
            return $this->redirectError('/admin/marketplace-badges', 'Data mapping tidak ditemukan.');

        } catch (\Exception $e) {
            $this->logger->error('[AdminMarketplaceBadge::update] ' . $e->getMessage());
            return $this->redirectError("/admin/marketplace-badges/{$id}/edit", 'Gagal update mapping: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Hapus Mapping (Detach)
     * DELETE /admin/marketplace-badges/{id}
     */
    public function delete(int $id)
    {
        try {
            $this->marketplaceBadgeService->deleteMarketplaceBadge($id);

            return $this->redirectSuccess('/admin/marketplace-badges', 'Mapping badge berhasil dihapus.');

        } catch (MarketplaceBadgeNotFoundException $e) {
            return $this->redirectError('/admin/marketplace-badges', 'Data mapping tidak ditemukan.');
        } catch (\Exception $e) {
            return $this->redirectError('/admin/marketplace-badges', 'Gagal menghapus mapping: ' . $e->getMessage());
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
