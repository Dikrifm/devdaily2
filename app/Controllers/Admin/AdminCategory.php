<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Category\CreateCategoryRequest;
use App\DTOs\Requests\Category\UpdateCategoryRequest;
use App\Exceptions\CategoryNotFoundException;
use App\Exceptions\ValidationException;
use App\Services\CategoryService;
use Config\Services;

/**
 * Class AdminCategory
 *
 * Controller Halaman Penuh untuk Manajemen Kategori.
 * Menangani CRUD Kategori dengan dukungan struktur hierarki (Parent-Child).
 */
class AdminCategory extends BaseAdmin
{
    private CategoryService $categoryService;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load CategoryService dari Container
        $this->categoryService = Services::category();
    }

    /**
     * Halaman List Kategori (Index)
     * GET /admin/categories
     */
    public function index()
    {
        // Ambil struktur tree lengkap (termasuk yang tidak aktif) untuk ditampilkan di tabel admin
        // Parameter false = include inactive categories
        $categoryTree = $this->categoryService->getCategoryTree(false);

        // Ambil statistik penggunaan untuk dashboard mini di atas tabel (opsional)
        // $stats = $this->categoryService->getStatistics();

        return view('admin/category/index', [
            'title'        => 'Manajemen Kategori',
            'categories'   => $categoryTree, // Array tree hierarkis
            // 'stats'        => $stats
        ]);
    }

    /**
     * Halaman Form Tambah Kategori
     * GET /admin/categories/new
     */
    public function create()
    {
        // Untuk dropdown parent, kita butuh list kategori
        // Hanya tampilkan kategori aktif sebagai kandidat parent
        $parents = $this->categoryService->getCategoryTree(true);

        return view('admin/category/form_create', [
            'title'   => 'Tambah Kategori Baru',
            'parents' => $parents,
        ]);
    }

    /**
     * Proses Simpan Kategori Baru
     * POST /admin/categories
     */
    public function store()
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = CreateCategoryRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $response = $this->categoryService->createCategory($requestDTO);

            // 3. Sukses
            return $this->redirectSuccess(
                '/admin/categories', 
                "Kategori \"{$response->name}\" berhasil dibuat."
            );

        } catch (ValidationException $e) {
            // Error Validasi (misal: Slug duplikat)
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            $this->logger->error('[AdminCategory::store] ' . $e->getMessage());
            return $this->redirectError('/admin/categories/new', 'Gagal membuat kategori: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Form Edit Kategori
     * GET /admin/categories/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            // 1. Ambil Data Kategori
            $category = $this->categoryService->getCategory($id);

            // 2. Ambil Kandidat Parent (Tree)
            // Penting: Service harus menangani agar kategori ini tidak bisa menjadi parent bagi dirinya sendiri (Circular reference logic usually in Service)
            $parents = $this->categoryService->getCategoryTree(true);

            return view('admin/category/form_edit', [
                'title'    => 'Edit Kategori: ' . $category->name,
                'category' => $category,
                'parents'  => $parents,
            ]);

        } catch (CategoryNotFoundException $e) {
            return $this->redirectError('/admin/categories', 'Kategori tidak ditemukan.');
        }
    }

    /**
     * Proses Update Kategori
     * PUT/POST /admin/categories/{id}
     */
    public function update(int $id)
    {
        try {
            // 1. Transform Request ke DTO
            $requestDTO = UpdateCategoryRequest::fromRequest(
                $id,
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $response = $this->categoryService->updateCategory($requestDTO);

            return $this->redirectSuccess(
                '/admin/categories', 
                "Kategori \"{$response->name}\" berhasil diperbarui."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (CategoryNotFoundException $e) {
            return $this->redirectError('/admin/categories', 'Kategori tidak ditemukan.');

        } catch (\Exception $e) {
            $this->logger->error('[AdminCategory::update] ' . $e->getMessage());
            return $this->redirectError("/admin/categories/{$id}/edit", 'Gagal update kategori: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Action Delete (Soft Delete)
     * DELETE /admin/categories/{id}
     * * Biasanya dipanggil via Form dengan _method=DELETE atau via HTMX
     */
    public function delete(int $id)
    {
        try {
            // Cek apakah ada parameter force delete
            $force = $this->request->getPost('force') === '1';

            $this->categoryService->deleteCategory($id, $force);

            return $this->redirectSuccess('/admin/categories', 'Kategori berhasil dihapus.');

        } catch (ValidationException $e) {
            // Biasanya terjadi jika kategori masih punya anak atau produk (Referential Integrity)
            return $this->redirectError('/admin/categories', $e->getMessage());

        } catch (CategoryNotFoundException $e) {
            return $this->redirectError('/admin/categories', 'Kategori tidak ditemukan.');
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
