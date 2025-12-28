<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\DTOs\Queries\ProductQuery;
use App\Enums\ProductStatus;
use App\Exceptions\ProductNotFoundException;
use App\Services\Product\ProductOrchestrator;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Services;

/**
 * Class ProductController
 *
 * Controller untuk Halaman Detail Produk (Publik).
 * * Layer 4: The Plug & Play Layer (Web Context)
 *
 * Menangani tampilan detail produk, galeri gambar, dan list link afiliasi.
 */
class ProductController extends BaseController
{
    private ProductOrchestrator $productOrchestrator;

    public function __construct()
    {
        // Load Service via Container
        $this->productOrchestrator = Services::productOrchestrator();
    }

    /**
     * Menampilkan Detail Produk berdasarkan Slug
     * GET /product/{slug}
     */
    public function detail(string $slug)
    {
        try {
            // 1. Ambil Data Detail Produk
            // Parameter kedua 'true' mengaktifkan increment view count (statistik)
            // Mengembalikan ProductDetailResponse DTO
            $product = $this->productOrchestrator->getProductBySlug($slug, true); [span_1](start_span)//[span_1](end_span)

            // 2. Ambil Produk Terkait (Related Products)
            // Strategi: Ambil produk lain dari kategori yang sama
            $relatedProducts = [];
            
            // Kita pastikan produk punya kategori sebelum query related
            if ($product->getCategoryId()) {
                // Buat Query untuk Related
                // Kita gunakan method forPublic() agar aman
                [span_2](start_span)$relatedQuery = ProductQuery::forPublic() //[span_2](end_span)
                    ->with([
                        'categoryIds' => [$product->getCategoryId()],
                        'perPage'     => 4,
                        'sort_by'     => 'random' // Atau 'created_at' jika random belum support
                    ]);

                // Eksekusi
                $relatedResult = $this->productOrchestrator->listProducts($relatedQuery);
                
                // Filter manual untuk exclude produk yang sedang dilihat (jika muncul di list)
                // Karena ProductQuery tidak punya exclude_id filter
                $relatedProducts = array_filter($relatedResult['data'] ?? [], function ($item) use ($product) {
                    return $item->getId() !== $product->getId();
                });
            }

            // 3. Render View
            return view('web/product/detail', [
                'title'   => $product->getName() . ' - Spesifikasi & Harga Termurah',
                'product' => $product,
                'related' => $relatedProducts
            ]);

        } catch (ProductNotFoundException $e) {
            // Konversi Exception Domain ke Exception HTTP 404 CI4
            throw PageNotFoundException::forPageNotFound("Produk tidak ditemukan.");
        } catch (\Exception $e) {
            // Error tak terduga
            log_message('error', '[ProductController::detail] ' . $e->getMessage());
            throw PageNotFoundException::forPageNotFound("Terjadi kesalahan saat memuat produk.");
        }
    }
}
