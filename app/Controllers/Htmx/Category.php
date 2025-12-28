<?php

namespace App\Controllers\Htmx;

use App\Controllers\BaseAdmin;
use App\Services\CategoryService;
use Config\Services;

/**
 * Class Category
 *
 * Controller HTMX untuk interaksi dinamis pada modul Kategori.
 * * Layer 4: The Plug & Play Layer (HTMX Context)
 *
 * Menangani:
 * 1. Live Search (untuk dropdown Parent di form produk/kategori)
 * 2. Tree Expansion (Lazy loading anak kategori di view index)
 */
class Category extends BaseAdmin
{
    private CategoryService $categoryService;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load CategoryService via Container
        $this->categoryService = Services::category();
    }

    /**
     * Live Search untuk Dropdown/Select
     * GET /htmx/categories/search?q=keyword
     *
     * Digunakan pada: Form Produk (pilih kategori), Form Kategori (pilih parent)
     */
    public function search()
    {
        try {
            $term = (string) $this->request->getGet('q');
            
            // Default limit 20 sesuai signature service
            $results = $this->categoryService->searchCategories($term, 20);

            // Jika hasil kosong dan term pendek, mungkin kembalikan instruksi atau kosong
            if (empty($results) && strlen($term) < 2) {
                return $this->response->setBody('<option value="" disabled>Ketik minimal 2 karakter...</option>');
            }

            if (empty($results)) {
                return $this->response->setBody('<option value="" disabled>Tidak ada kategori ditemukan</option>');
            }

            // Render opsi dropdown
            // Kita render manual string HTML loop untuk performa maksimal pada fragmen kecil
            // Atau gunakan view fragment jika layout kompleks. Di sini kita pakai view fragment.
            return view('admin/category/components/_search_options', ['categories' => $results]);

        } catch (\Exception $e) {
            $this->logger->error('[HtmxCategory::search] ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setBody('<option value="" disabled>Error memuat data</option>');
        }
    }

    /**
     * Lazy Load Anak Kategori (Tree Branch)
     * GET /htmx/categories/{id}/children
     *
     * Digunakan pada: Tabel/List Kategori Utama (saat klik expand +)
     */
    public function expand(int $parentId)
    {
        try {
            // Ambil subkategori (termasuk yang inactive jika di admin panel)
            // Parameter false = include inactive
            $children = $this->categoryService->getSubcategories($parentId, false);

            if (empty($children)) {
                // Kembalikan indikator kosong atau hapus ikon expand di sisi klien via OOB swap (opsional)
                return $this->response->setBody('<div class="pl-4 text-sm text-gray-500 italic">Tidak ada sub-kategori</div>');
            }

            // Render fragment tree branch
            // View ini berisi <ul> atau kumpulan <div> yang mewakili anak-anak kategori
            return view('admin/category/components/_tree_branch', ['categories' => $children]);

        } catch (\Exception $e) {
            $this->logger->error('[HtmxCategory::expand] ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setBody('<div class="text-red-500 text-xs">Gagal memuat sub-kategori</div>');
        }
    }

    /**
     * Render Baris Tabel Kategori (Refresh Row)
     * GET /htmx/categories/{id}/row
     * * Digunakan setelah update/edit untuk me-refresh tampilan satu baris saja
     */
    public function row(int $id)
    {
        try {
            $category = $this->categoryService->getCategory($id);
            
            // Render ulang baris tabel <tr>
            return view('admin/category/components/_table_row', ['category' => $category]);

        } catch (\Exception $e) {
            return $this->response->setStatusCode(404);
        }
    }
}
