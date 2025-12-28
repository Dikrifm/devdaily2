<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Queries\ProductQuery;
use App\DTOs\Requests\Product\CreateProductRequest;
use App\DTOs\Requests\Product\UpdateProductRequest;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\ValidationException;
use App\Services\Product\ProductOrchestrator;
use App\Services\CategoryService;
use App\Services\BadgeService;
use Config\Services;

/**
 * Class AdminProduct
 *
 * Controller Halaman Penuh (Full Page) untuk Manajemen Produk.
 * Bertanggung jawab merender kerangka halaman (Layout, Sidebar, Container)
 * dan menangani Form Submission (Create/Update).
 *
 * Interaksi tabel dinamis (Search, Filter, Toggle) ditangani oleh Htmx\Product.
 */
class AdminProduct extends BaseAdmin
{
    private ProductOrchestrator $orchestrator;
    private CategoryService $categoryService;
    private BadgeService $badgeService;

    /**
     * Inisialisasi Service via Dependency Injection Container
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load Service Utama
        // Kita menggunakan wrapper Services::...() untuk menjamin Singleton
        // Asumsi: Anda sudah mendaftarkan productOrchestrator di Config/Services.php
        $this->orchestrator    = Services::productOrchestrator(); 
        $this->categoryService = Services::category();
        $this->badgeService    = Services::badge();
    }

    /**
     * Halaman List Produk (Index)
     * GET /admin/products
     */
    public function index()
    {
        // 1. Tangkap Query Parameters untuk Initial State (jika user refresh halaman dengan filter)
        $queryParams = $this->request->getGet();
        
        // 2. Buat Query DTO
        // Kita set adminMode = true agar bisa melihat produk draft/archived
        $queryDTO = ProductQuery::fromRequest($queryParams, true);

        // 3. Ambil Data Awal (Optional - bisa juga kosong dan biarkan HTMX yang load)
        // Disini kita load data awal agar SEO/Non-JS user tetap bisa melihat konten
        $products = $this->orchestrator->listProducts($queryDTO, true);

        // 4. Siapkan Data Pendukung untuk Filter Sidebar/Dropdown
        // Kita gunakan cacheable method dari service
        $categories = $this->categoryService->getCategoryTree(false); // false = include inactive
        $badges     = $this->badgeService->getActiveBadges();

        return view('admin/product/index', [
            'title'       => 'Manajemen Produk',
            'products'    => $products['data'] ?? [], // Array of ProductResponse
            'pager'       => $products['pager'] ?? null,
            'categories'  => $categories,
            'badges'      => $badges,
            'filters'     => $queryDTO->toArray()
        ]);
    }

    /**
     * Halaman Form Tambah Produk
     * GET /admin/products/new
     */
    public function create()
    {
        return view('admin/product/form_create', [
            'title'      => 'Tambah Produk Baru',
            'categories' => $this->categoryService->getCategoryTree(true), // Hanya kategori aktif
            'badges'     => $this->badgeService->getActiveBadges(),
        ]);
    }

    /**
     * Proses Simpan Produk Baru
     * POST /admin/products
     */
    public function store()
    {
        try {
            // 1. Transform Request ke DTO
            // fromRequest akan otomatis memvalidasi format dasar
            $requestDTO = CreateProductRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId() // Dari BaseAdmin
            );

            // 2. Eksekusi via Orchestrator
            $productResponse = $this->orchestrator->createProduct($requestDTO);

            // 3. Sukses -> Redirect ke Edit atau Index
            return $this->redirectSuccess(
                '/admin/products', 
                "Produk \"{$productResponse->getName()}\" berhasil dibuat."
            );

        } catch (ValidationException $e) {
            // Error Validasi -> Kembali ke Form dengan Input & Error
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            // Error Domain/System Lainnya
            $this->logger->error('[AdminProduct::store] ' . $e->getMessage());
            return $this->redirectError('/admin/products/new', 'Gagal membuat produk: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Form Edit Produk
     * GET /admin/products/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            // 1. Ambil Data Produk (Throws NotFoundException jika tidak ada)
            $product = $this->orchestrator->getProduct($id, true); // true = admin mode

            return view('admin/product/form_edit', [
                'title'      => 'Edit Produk: ' . $product->getName(),
                'product'    => $product,
                'categories' => $this->categoryService->getCategoryTree(false),
                'badges'     => $this->badgeService->getActiveBadges(),
            ]);

        } catch (ProductNotFoundException $e) {
            return $this->redirectError('/admin/products', 'Produk tidak ditemukan.');
        }
    }

    /**
     * Proses Update Produk
     * PUT/POST /admin/products/{id}
     */
    public function update(int $id)
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = UpdateProductRequest::fromRequest(
                $id,
                $this->request->getPost(), // CodeIgniter otomatis handle PUT jika spoofing digunakan
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Update
            $productResponse = $this->orchestrator->updateProduct($requestDTO);

            return $this->redirectSuccess(
                "/admin/products/{$id}/edit", 
                "Perubahan pada produk \"{$productResponse->getName()}\" berhasil disimpan."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (ProductNotFoundException $e) {
            return $this->redirectError('/admin/products', 'Produk tidak ditemukan.');

        } catch (\Exception $e) {
            $this->logger->error('[AdminProduct::update] ' . $e->getMessage());
            return $this->redirectError("/admin/products/{$id}/edit", 'Gagal update produk: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Helper untuk mendapatkan ID Admin saat ini
     * Wrapper aman jika $currentAdmin null (walau seharusnya terfilter)
     */
    private function getCurrentAdminId(): int
    {
        return $this->currentAdmin ? $this->currentAdmin->getId() : 0;
    }
}
