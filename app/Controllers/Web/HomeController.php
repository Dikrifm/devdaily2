<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\DTOs\Queries\ProductQuery;
use App\Services\CategoryService;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class HomeController
 *
 * Controller untuk Halaman Depan (Landing Page) Publik.
 * * Layer 4: The Plug & Play Layer (Web Context)
 *
 * Halaman ini adalah "Wajah" aplikasi. Harus cepat, responsif, dan menarik.
 * Menggabungkan data Kategori Utama dan Produk Terbaru.
 */
class HomeController extends BaseController
{
    private ProductOrchestrator $productOrchestrator;
    private CategoryService $categoryService;

    public function __construct()
    {
        // Load Services via Container
        // Kita menggunakan wrapper Services::...() untuk menjamin Singleton
        $this->productOrchestrator = Services::productOrchestrator();
        $this->categoryService     = Services::category();
    }

    /**
     * Menampilkan Landing Page
     * GET /
     */
    public function index()
    {
        // 1. Ambil Kategori Utama (Root Categories)
        // Parameter true = hanya yang statusnya Active[span_4](end_span)
        // Service ini sudah memiliki caching internal (L2 Cache), jadi aman dipanggil berulang kali
        $rootCategories = $this->categoryService->getRootCategories(true);

        // 2. Ambil Produk Terbaru (Latest Arrivals)
        // Kita susun Query DTO manual untuk kasus ini
        $latestQuery = new ProductQuery(
            page: 1,
            perPage: 8,            // Tampilkan 8 produk terbaru
            sort: 'created_at',    // Urutkan dari yang paling baru
            order: 'desc',
            status: 'active',      // Wajib: Hanya produk aktif
            search: null,
            categoryId: null
        );

        // Ambil data via Orchestrator
        $latestProductsResult = $this->productOrchestrator->listProducts($latestQuery);
        $latestProducts = $latestProductsResult['data'] ?? [];

        // 3. Ambil Produk Populer (Opsional - Misal berdasarkan view_count jika ada, atau random)
        // Untuk MVP, kita bisa gunakan logika 'random' atau sort by 'price' sementara
        $popularQuery = new ProductQuery(
            page: 1,
            perPage: 4,
            sort: 'random',        // Asumsi Orchestrator support 'random' atau kita ganti logic lain
            order: 'desc',
            status: 'active'
        );
        
        // Fallback jika random tidak didukung, gunakan default
        try {
            $popularProductsResult = $this->productOrchestrator->listProducts($popularQuery);
            $popularProducts = $popularProductsResult['data'] ?? [];
        } catch (\Exception $e) {
            $popularProducts = []; // Fail silent untuk widget tambahan
        }

        // 4. Render View
        return view('web/home/index', [
            'title'           => 'DevDaily - Temukan Deal Terbaik',
            'rootCategories'  => $rootCategories,
            'latestProducts'  => $latestProducts,
            'popularProducts' => $popularProducts,
        ]);
    }

    /**
     * Halaman Statis (Tentang Kami, Kebijakan Privasi, dll)
     * GET /pages/{slug}
     */
    public function page(string $slug)
    {
        // Jika Anda belum punya PageService, kita bisa hardcode view sederhana
        // atau mapping slug ke file view
        
        if (!is_file(APPPATH . 'Views/web/pages/' . $slug . '.php')) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('web/pages/' . $slug, [
            'title' => ucfirst(str_replace('-', ' ', $slug))
        ]);
    }
}
