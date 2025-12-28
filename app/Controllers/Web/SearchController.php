<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\DTOs\Queries\ProductQuery;
use App\Enums\ProductStatus;
use App\Services\CategoryService;
use App\Services\MarketplaceService;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class SearchController
 *
 * Controller untuk Halaman Pencarian & Listing Produk (Publik).
 * * Layer 4: The Plug & Play Layer (Web Context)
 *
 * Menangani request pencarian global, filter kategori, dan sortasi.
 * Memastikan hanya produk PUBLISHED yang dapat diakses.
 */
class SearchController extends BaseController
{
    private ProductOrchestrator $productOrchestrator;
    private CategoryService $categoryService;
    private MarketplaceService $marketplaceService;

    public function __construct()
    {
        // Load Services via Container
        $this->productOrchestrator = Services::productOrchestrator();
        $this->categoryService     = Services::category();
        $this->marketplaceService  = Services::marketplace();
    }

    /**
     * Halaman Hasil Pencarian / Katalog
     * GET /search
     * GET /products (bisa di-route ke sini juga)
     */
    public function index()
    {
        // 1. Tangkap Parameter URL
        $params = $this->request->getGet();

        // 2. Bersihkan Parameter Sensitif (Security)
        // Kita hapus parameter 'status' dan 'admin_mode' jika user iseng mengirimkannya via URL
        // agar mereka tidak bisa membypass filter produk aktif.
        unset($params['status'], $params['admin_mode'], $params['include_trashed']);

        // 3. Buat DTO Query Dasar dari Request
        [span_0](start_span)// Parameter kedua 'false' menandakan ini bukan Admin Mode[span_0](end_span)
        $queryDTO = ProductQuery::fromRequest($params, false);

        // 4. Terapkan Batasan Publik (Hard Constraint)
        [span_1](start_span)[span_2](start_span)// Kita gunakan method 'with' (Immutable modifier) untuk memaksa status PUBLISHED[span_1](end_span)[span_2](end_span)
        $queryDTO = $queryDTO->with([
            [span_3](start_span)[span_4](start_span)'status'         => [ProductStatus::PUBLISHED->value], //[span_3](end_span)[span_4](end_span)
            [span_5](start_span)'hasActiveLinks' => true,                              // Hanya produk yang berafiliasi aktif[span_5](end_span)
            'includeTrashed' => false
        ]);

        // 5. Eksekusi Pencarian via Orchestrator
        [span_6](start_span)// listProducts mengembalikan array ['data' => ProductResponse[], 'pager' => Pager][span_6](end_span)
        $result = $this->productOrchestrator->listProducts($queryDTO);

        // 6. Ambil Data Pendukung untuk UI Filter
        // - Kategori Tree untuk Sidebar
        $categories = $this->categoryService->getCategoryTree(true); // true = hanya aktif
        
        // - List Marketplace untuk Filter Checkbox
        $marketplaces = $this->marketplaceService->getActiveMarketplaces();

        // 7. Render View
        return view('web/search/index', [
            'title'        => $this->generateTitle($params),
            'products'     => $result['data'] ?? [],
            'pager'        => $result['pager'] ?? null,
            'categories'   => $categories,
            'marketplaces' => $marketplaces,
            'filters'      => $params, // Kirim balik input user untuk repopulate form filters
        ]);
    }

    /**
     * Helper: Generate Judul Halaman Dinamis
     */
    private function generateTitle(array $params): string
    {
        if (!empty($params['search'])) {
            return 'Pencarian: "' . esc($params['search']) . '"';
        }
        
        if (!empty($params['category_id'])) {
            // Opsional: Bisa fetch nama kategori by ID jika mau judul lebih spesifik
            return 'Kategori Produk'; 
        }

        return 'Katalog Produk';
    }
}
