<?php

namespace App\Controllers\Api;

use App\DTOs\Queries\ProductQuery;
use App\Enums\ProductStatus;
use App\Exceptions\ProductNotFoundException;
use App\Services\Product\ProductOrchestrator;
use Config\Services;

/**
 * Class ApiProduct
 *
 * Controller API untuk Katalog Produk (Public).
 * * Layer 4: The Plug & Play Layer (API Context)
 *
 * Endpoint:
 * - GET /api/products        (List & Search)
 * - GET /api/products/{slug} (Detail Lengkap)
 */
class ApiProduct extends BaseApi
{
    private ProductOrchestrator $orchestrator;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load ProductOrchestrator
        $this->orchestrator = Services::productOrchestrator();
    }

    /**
     * List Produk (Search, Filter, Pagination)
     * GET /api/products
     *
     * Query Params Supported:
     * - page, per_page
     * - search (keyword)
     * - category (slug atau id)
     * - price_min, price_max
     * - sort (price_asc, price_desc, latest, popular)
     */
    public function index()
    {
        try {
            // 1. Ambil Parameter & Sanitasi Keamanan
            $params = $this->request->getGet();

            // Hapus parameter internal/sensitif agar tidak di-override user
            // Kita paksa mode publik
            unset($params['status'], $params['admin_mode'], $params['include_trashed']);

            // 2. Buat Query DTO
            // Parameter kedua false = Public Mode (otomatis set status=published di logic service, tapi kita double check)
            $queryDTO = ProductQuery::fromRequest($params, false);

            // Pertegas filter untuk API Publik
            $queryDTO = $queryDTO->with([
                'status' => [ProductStatus::PUBLISHED->value],
                'hasActiveLinks' => true // Opsional: Hanya tampilkan jika ada link beli
            ]);

            // 3. Eksekusi via Orchestrator
            // Return: ['data' => ProductResponse[], 'pager' => Pager]
            $result = $this->orchestrator->listProducts($queryDTO);

            // 4. Transformasi Data Response
            /** @var \App\DTOs\Responses\ProductResponse[] $products */
            $products = $result['data'] ?? [];
            $pager = $result['pager'] ?? null;

            // Kita mapping setiap entity ke array publik yang aman
            [span_2](start_span)// Menggunakan method toPublicArray() dari DTO[span_2](end_span)
            $data = array_map(fn($product) => $product->toPublicArray(), $products);

            // 5. Susun Metadata Pagination
            $meta = [];
            if ($pager) {
                $meta = [
                    'current_page' => $pager->getCurrentPage(),
                    'per_page'     => $pager->getPerPage(),
                    'total_data'   => $pager->getTotal(),
                    'total_pages'  => $pager->getPageCount(),
                    'next_page'    => $pager->getNextPageURI(),
                    'prev_page'    => $pager->getPreviousPageURI()
                ];
            }

            // 6. Return JSON
            return $this->respond([
                'status'  => 'success',
                'message' => 'Data produk berhasil diambil.',
                'data'    => $data,
                'meta'    => $meta
            ]);

        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }

    /**
     * Detail Produk Lengkap
     * GET /api/products/{slug}
     *
     * Mengembalikan data produk beserta relasi (Marketplace, Links, Badges)
     */
    public function show(string $slug)
    {
        try {
            // 1. Ambil Detail Produk via Orchestrator
            // Parameter true = Increment View Count (Statistik kunjungan)
            // Mengembalikan object App\DTOs\Responses\ProductDetailResponse
            $productDetail = $this->orchestrator->getProductBySlug($slug, true);

            // 2. Transformasi ke JSON
            [span_3](start_span)// Menggunakan method toDetailArray()[span_3](end_span) [span_4](start_span)yang mencakup 'relations' dan 'price_range'[span_4](end_span)
            // Ini jauh lebih lengkap daripada toPublicArray() biasa.
            $data = $productDetail->toDetailArray();

            return $this->respond([
                'status'  => 'success',
                'message' => 'Detail produk ditemukan.',
                'data'    => $data
            ]);

        } catch (ProductNotFoundException $e) {
            return $this->failNotFound('Produk tidak ditemukan atau belum dipublikasikan.');
            
        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }
}
