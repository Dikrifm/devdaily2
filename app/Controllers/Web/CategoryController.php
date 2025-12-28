<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\DTOs\Queries\ProductQuery;
use App\Enums\ProductStatus;
use App\Exceptions\NotFoundException;
use App\Services\CategoryService;
use App\Services\Product\ProductOrchestrator;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;

/**
 * Class CategoryController
 *
 * Controller untuk Halaman Listing Produk per Kategori (Publik).
 * * Layer 4: The Plug & Play Layer (Web Context)
 *
 * Menangani tampilan halaman kategori, subkategori, dan produk di dalamnya.
 */
class CategoryController extends BaseController
{
    private CategoryService $categoryService;
    private ProductOrchestrator $productOrchestrator;

    public function __construct()
    {
        // Load Services via Container
        $this->categoryService     = Services::category();
        $this->productOrchestrator = Services::productOrchestrator();
    }

    /**
     * Menampilkan Halaman Kategori berdasarkan Slug
     * GET /category/{slug}
     */
    public function detail(string $slug)
    {
        try {
            // 1. Ambil Detail Kategori (Fail-fast jika tidak ditemukan)
            // Menggunakan method getCategoryBySlug sesuai kontrak Service
            $category = $this->categoryService->getCategoryBySlug($slug);

            // 2. Ambil Subkategori (Active Only)
            // Fitur drill-down: User bisa melihat anak kategori di atas list produk
            $subcategories = $this->categoryService->getSubcategories($category->getId(), true);

            // 3. Siapkan Query Produk
            $params = $this->request->getGet();
            
            // Bersihkan params agar user tidak bisa override filter kritis via URL
            unset($params['status'], $params['admin_mode'], $params['category_id']);

            // Transform ke DTO
            $queryDTO = ProductQuery::fromRequest($params, false); // false = Public Mode

            // Terapkan Filter Konteks Kategori & Security
            // Kita gunakan array [$id] karena filter categoryIds menerima array
            $queryDTO = $queryDTO->with([
                'categoryIds'    => [$category->getId()],
                'status'         => ['active'], // Hardcode 'active' atau ProductStatus::PUBLISHED->value
                'hasActiveLinks' => true        // Opsional: Hanya produk yang siap dibeli
            ]);

            // 4. Eksekusi Pencarian Produk
            $result = $this->productOrchestrator->listProducts($queryDTO);

            // 5. Render View
            return view('web/category/detail', [
                'title'         => $category->getName() . ' - Katalog Produk',
                'category'      => $category,
                'subcategories' => $subcategories,
                'products'      => $result['data'] ?? [],
                'pager'         => $result['pager'] ?? null,
                'filters'       => $params, // Untuk repopulate dropdown sort/filter di UI
            ]);

        } catch (NotFoundException $e) {
            // Konversi error service menjadi 404 standar CI4
            throw PageNotFoundException::forPageNotFound("Kategori \"{$slug}\" tidak ditemukan.");
        } catch (\Exception $e) {
            log_message('error', '[CategoryController::detail] ' . $e->getMessage());
            throw PageNotFoundException::forPageNotFound("Terjadi kesalahan memuat kategori.");
        }
    }
}
