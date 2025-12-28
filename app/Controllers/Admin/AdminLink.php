<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Link\CreateLinkRequest;
use App\DTOs\Requests\Link\UpdateLinkRequest;
use App\Exceptions\LinkNotFoundException;
use App\Exceptions\ValidationException;
use App\Services\LinkService;
use App\Services\MarketplaceService;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class AdminLink
 *
 * Controller Halaman Penuh untuk Manajemen Tautan Afiliasi (Links).
 * Menangani CRUD Link, update harga manual, dan monitoring validitas link.
 */
class AdminLink extends BaseAdmin
{
    private LinkService $linkService;
    private MarketplaceService $marketplaceService;
    private ProductOrchestrator $productOrchestrator;

    /**
     * Inisialisasi Service via Dependency Injection
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load Services dari Container
        $this->linkService         = Services::link();
        $this->marketplaceService  = Services::marketplace();
        // Kita butuh orchestrator untuk memvalidasi/mengambil info produk terkait link
        $this->productOrchestrator = Services::productOrchestrator();
    }

    /**
     * Halaman List Link (Index)
     * GET /admin/links
     */
    public function index()
    {
        // 1. Ambil Parameter Filter (page, search, marketplace_id, product_id)
        $filters = $this->request->getGet();
        $page    = (int) ($filters['page'] ?? 1);
        $perPage = 20;

        // 2. Ambil Data Link via Service
        // searchLinks mengembalikan array struktur ['data' => LinkResponse[], 'pager' => Pager, 'metadata' => ...]
        $result = $this->linkService->searchLinks($filters, $page, $perPage);

        // 3. Ambil Data Pendukung untuk Filter UI
        $marketplaces = $this->marketplaceService->getActiveMarketplaces();

        return view('admin/link/index', [
            'title'        => 'Manajemen Tautan Afiliasi',
            'links'        => $result['data'] ?? [],
            'pager'        => $result['pager'] ?? null,
            'metadata'     => $result['metadata'] ?? [],
            'marketplaces' => $marketplaces,
            'filters'      => $filters,
        ]);
    }

    /**
     * Halaman Form Tambah Link
     * GET /admin/links/new
     * * Opsional: Menerima param ?product_id=X untuk pre-fill produk
     */
    public function create()
    {
        $productId = $this->request->getGet('product_id');
        $preselectedProduct = null;

        // Jika ada product_id, ambil detailnya untuk ditampilkan di UI (readonly input/card)
        if ($productId) {
            try {
                $preselectedProduct = $this->productOrchestrator->getProduct((int)$productId);
            } catch (\Exception $e) {
                // Ignore invalid product id in param
            }
        }

        return view('admin/link/form_create', [
            'title'              => 'Tambah Tautan Baru',
            'marketplaces'       => $this->marketplaceService->getActiveMarketplaces(),
            'preselectedProduct' => $preselectedProduct,
        ]);
    }

    /**
     * Proses Simpan Link Baru
     * POST /admin/links
     */
    public function store()
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = CreateLinkRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $response = $this->linkService->createLink($requestDTO);

            // 3. Redirect Sukses
            // Jika request datang dari halaman detail produk, kembalikan ke sana
            $referer = $this->request->getServer('HTTP_REFERER');
            if ($referer && strpos($referer, '/admin/products/') !== false) {
                 return redirect()->to($referer)->with('success', "Link berhasil ditambahkan ke produk.");
            }

            return $this->redirectSuccess(
                '/admin/links', 
                "Tautan ke \"{$response->store_name}\" berhasil dibuat."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            $this->logger->error('[AdminLink::store] ' . $e->getMessage());
            return $this->redirectError('/admin/links/new', 'Gagal membuat tautan: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Form Edit Link
     * GET /admin/links/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            // 1. Ambil Data Link
            $link = $this->linkService->getLink($id);

            // 2. Ambil Data Produk Terkait (untuk konteks UI)
            $product = $this->productOrchestrator->getProduct($link->product_id);

            return view('admin/link/form_edit', [
                'title'        => 'Edit Tautan: ' . $link->store_name,
                'link'         => $link,
                'product'      => $product,
                'marketplaces' => $this->marketplaceService->getActiveMarketplaces(),
            ]);

        } catch (LinkNotFoundException $e) {
            return $this->redirectError('/admin/links', 'Tautan tidak ditemukan.');
        } catch (\Exception $e) {
            // Fallback jika produk terkait sudah terhapus atau error lain
            return $this->redirectError('/admin/links', 'Gagal memuat data tautan: ' . $e->getMessage());
        }
    }

    /**
     * Proses Update Link
     * PUT/POST /admin/links/{id}
     */
    public function update(int $id)
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = UpdateLinkRequest::fromRequest(
                $id,
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $response = $this->linkService->updateLink($requestDTO);

            return $this->redirectSuccess(
                '/admin/links', 
                "Tautan \"{$response->store_name}\" berhasil diperbarui."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (LinkNotFoundException $e) {
            return $this->redirectError('/admin/links', 'Tautan tidak ditemukan.');

        } catch (\Exception $e) {
            $this->logger->error('[AdminLink::update] ' . $e->getMessage());
            return $this->redirectError("/admin/links/{$id}/edit", 'Gagal update tautan: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Proses Hapus Link
     * DELETE /admin/links/{id}
     */
    public function delete(int $id)
    {
        try {
            $force = $this->request->getPost('force') === '1';

            $this->linkService->deleteLink($id, $force);

            return $this->redirectSuccess('/admin/links', 'Tautan berhasil dihapus.');

        } catch (LinkNotFoundException $e) {
            return $this->redirectError('/admin/links', 'Tautan tidak ditemukan.');
        } catch (\Exception $e) {
            return $this->redirectError('/admin/links', 'Gagal menghapus tautan: ' . $e->getMessage());
        }
    }

    /**
     * Validasi URL Manual (Trigger Check)
     * POST /admin/links/{id}/validate
     */
    public function validateUrl(int $id)
    {
        try {
            // Logic validasi manual (misal cek HTTP status code link)
            // Ini akan memanggil logic di Service yang mungkin melakukan cURL request
            $result = $this->linkService->markAsValidated($id);

            if ($result) {
                return $this->redirectSuccess("/admin/links/{$id}/edit", 'URL Tautan valid dan aktif.');
            } else {
                return $this->redirectError("/admin/links/{$id}/edit", 'URL Tautan tidak dapat dijangkau.');
            }

        } catch (LinkNotFoundException $e) {
             return $this->redirectError('/admin/links', 'Tautan tidak ditemukan.');
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
