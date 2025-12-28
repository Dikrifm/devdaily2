<?php

namespace App\Controllers\Api;

use App\Exceptions\NotFoundException;
use App\Services\CategoryService;
use Config\Services;

/**
 * Class ApiCategory
 *
 * Controller API untuk Data Kategori (Public).
 * * Layer 4: The Plug & Play Layer (API Context)
 *
 * Endpoint:
 * - GET /api/categories        (Tree Menu: Kategori Utama beserta anak-anaknya)
 * - GET /api/categories/{slug} (Detail Kategori spesifik + sub-kategorinya)
 */
class ApiCategory extends BaseApi
{
    private CategoryService $categoryService;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load CategoryService
        $this->categoryService = Services::category();
    }

    /**
     * Get All Categories (Tree Structure)
     * GET /api/categories
     *
     * Digunakan untuk Menu Navigasi, Sidebar, atau Pilihan Kategori.
     * Mengembalikan data hierarki (nested).
     */
    public function index()
    {
        try {
            // 1. Ambil Tree Kategori (Active Only)
            // Service diharapkan mengembalikan array of CategoryResponse yang sudah terstruktur (nested)
            $categoryTree = $this->categoryService->getCategoryTree(true);

            // 2. Transform ke Array JSON
            // Menggunakan method toArray() yang sudah kita siapkan di DTO untuk menangani rekursi children
            $data = array_map(fn($cat) => $cat->toArray(), $categoryTree);

            return $this->respond([
                'status'  => 'success',
                'message' => 'Struktur kategori berhasil diambil.',
                'data'    => $data
            ]);

        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }

    /**
     * Detail Kategori Spesifik
     * GET /api/categories/{slug}
     *
     * Mengembalikan detail kategori dan sub-kategori langsung di bawahnya.
     */
    public function show(string $slug)
    {
        try {
            // 1. Ambil Detail Kategori Utama
            $category = $this->categoryService->getCategoryBySlug($slug);

            // 2. Ambil Sub-kategori Langsung (Children)
            // Kita ingin menampilkan sub-kategori agar user bisa drill-down
            $children = $this->categoryService->getSubcategories($category->id, true); // true = active only

            // 3. Masukkan Children ke dalam Object Parent
            // DTO CategoryResponse kita sudah punya method setChildren()
            $category->setChildren($children);

            // 4. Return JSON
            return $this->respond([
                'status'  => 'success',
                'message' => 'Detail kategori ditemukan.',
                'data'    => $category->toArray()
            ]);

        } catch (NotFoundException $e) {
            return $this->failNotFound('Kategori tidak ditemukan.');
            
        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }
}
