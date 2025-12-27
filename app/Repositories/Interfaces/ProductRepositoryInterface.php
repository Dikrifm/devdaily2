<?php

namespace App\Repositories\Interfaces;

use App\Repositories\BaseRepositoryInterface;
use App\Entities\Product;
use App\Enums\ProductStatus;

/**
 * ProductRepositoryInterface - Kontrak untuk repository Product
 * 
 * @extends App\Repositories\BaseRepositoryInterface<Product>
 */
interface ProductRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Temukan produk berdasarkan slug
     * 
     * @param string $slug
     * @param bool $activeOnly Hanya produk aktif (tidak di-archive)
     * @param bool $useCache
     * @return Product|null
     */
    public function findBySlug(string $slug, bool $activeOnly = true, bool $useCache = true): ?Product;

    /**
     * Temukan semua produk yang dipublikasi
     * 
     * @param int|null $limit
     * @param int $offset
     * @param array $orderBy [field => direction]
     * @param bool $useCache
     * @return array<Product>
     */
    public function findPublished(
        ?int $limit = null,
        int $offset = 0,
        array $orderBy = ['published_at' => 'DESC'],
        bool $useCache = true
    ): array;

    /**
     * Temukan produk populer berdasarkan view count
     * 
     * @param int $limit
     * @param int $offset
     * @param bool $publishedOnly Hanya produk yang dipublikasi
     * @param bool $useCache
     * @return array<Product>
     */
    public function findPopular(
        int $limit = 10,
        int $offset = 0,
        bool $publishedOnly = true,
        bool $useCache = true
    ): array;

    /**
     * Cari produk dengan keyword
     * 
     * @param string $keyword
     * @param array $filters Filter tambahan [status, category_id, dll]
     * @param int|null $limit
     * @param int $offset
     * @param array $orderBy
     * @param bool $useCache
     * @return array<Product>
     */
    public function search(
        string $keyword,
        array $filters = [],
        ?int $limit = null,
        int $offset = 0,
        array $orderBy = ['name' => 'ASC'],
        bool $useCache = true
    ): array;

    /**
     * Publikasikan produk
     * 
     * @param int $productId
     * @param int|null $publishedBy Admin ID yang mempublikasi
     * @return bool
     */
    public function publish(int $productId, ?int $publishedBy = null): bool;

    /**
     * Verifikasi produk
     * 
     * @param int $productId
     * @param int $verifiedBy Admin ID yang memverifikasi
     * @return bool
     */
    public function verify(int $productId, int $verifiedBy): bool;

    /**
     * Archive produk (soft delete)
     * 
     * @param int $productId
     * @param int|null $archivedBy Admin ID yang meng-archive
     * @return bool
     */
    public function archive(int $productId, ?int $archivedBy = null): bool;

    /**
     * Restore produk dari archive
     * 
     * @param int $productId
     * @param int|null $restoredBy Admin ID yang merestore
     * @return bool
     */
    public function restore(int $productId, ?int $restoredBy = null): bool;

    /**
     * Update status produk
     * 
     * @param int $productId
     * @param ProductStatus $status
     * @param int|null $changedBy Admin ID yang mengubah status
     * @return bool
     */
    public function updateStatus(int $productId, ProductStatus $status, ?int $changedBy = null): bool;

    /**
     * Increment view count produk
     * 
     * @param int $productId
     * @return bool
     */
    public function incrementViewCount(int $productId): bool;

    /**
     * Mark price sebagai sudah di-check
     * 
     * @param int $productId
     * @return bool
     */
    public function markPriceChecked(int $productId): bool;

    /**
     * Mark links sebagai sudah di-check
     * 
     * @param int $productId
     * @return bool
     */
    public function markLinksChecked(int $productId): bool;

    /**
     * Temukan produk yang membutuhkan update harga
     * (last_price_check lebih dari X hari)
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<Product>
     */
    public function findNeedsPriceUpdate(
        int $daysThreshold = 7,
        int $limit = 50,
        bool $publishedOnly = true
    ): array;

    /**
     * Temukan produk yang membutuhkan validasi link
     * (last_link_check lebih dari X hari)
     * 
     * @param int $daysThreshold
     * @param int $limit
     * @param bool $publishedOnly
     * @return array<Product>
     */
    public function findNeedsLinkValidation(
        int $daysThreshold = 14,
        int $limit = 50,
        bool $publishedOnly = true
    ): array;

    /**
     * Hitung produk berdasarkan status
     * 
     * @param ProductStatus|null $status Jika null, hitung semua
     * @param bool $includeArchived Termasuk yang di-archive?
     * @return int
     */
    public function countByStatus(?ProductStatus $status = null, bool $includeArchived = false): int;

    /**
     * Hitung produk berdasarkan kategori
     * 
     * @param int|null $categoryId Jika null, hitung semua kategori
     * @param bool $publishedOnly Hanya yang dipublikasi
     * @return int|array Jika categoryId null, return array [category_id => count]
     */
    public function countByCategory(?int $categoryId = null, bool $publishedOnly = false);

    /**
     * Dapatkan statistik produk
     * 
     * @param string $period Periode: 'day', 'week', 'month', 'year'
     * @return array
     */
    public function getStatistics(string $period = 'month'): array;

    /**
     * Update produk dengan data
     * 
     * @param int $productId
     * @param array $data
     * @return bool
     */
    public function updateProduct(int $productId, array $data): bool;

    /**
     * Bulk update status produk
     * 
     * @param array<int> $productIds
     * @param ProductStatus $status
     * @param int|null $changedBy
     * @return int Jumlah produk yang berhasil diupdate
     */
    public function bulkUpdateStatus(array $productIds, ProductStatus $status, ?int $changedBy = null): int;

    /**
     * Bulk archive produk
     * 
     * @param array<int> $productIds
     * @param int|null $archivedBy
     * @return int Jumlah produk yang berhasil di-archive
     */
    public function bulkArchive(array $productIds, ?int $archivedBy = null): int;

    /**
     * Temukan produk dengan kategori
     * 
     * @param int $categoryId
     * @param bool $includeSubcategories Termasuk sub-kategori?
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @param bool $useCache
     * @return array<Product>
     */
    public function findByCategory(
        int $categoryId,
        bool $includeSubcategories = false,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true,
        bool $useCache = true
    ): array;

    /**
     * Temukan produk dengan marketplace tertentu
     * (melalui relasi links)
     * 
     * @param int $marketplaceId
     * @param bool $activeLinksOnly Hanya link yang aktif
     * @param int|null $limit
     * @param int $offset
     * @param bool $publishedOnly
     * @return array<Product>
     */
    public function findByMarketplace(
        int $marketplaceId,
        bool $activeLinksOnly = true,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array;

    /**
     * Dapatkan produk rekomendasi
     * 
     * @param int $currentProductId ID produk saat ini (untuk di-exclude)
     * @param int $limit
     * @param array $criteria Kriteria rekomendasi: 'category', 'popular', 'recent'
     * @return array<Product>
     */
    public function getRecommendations(
        int $currentProductId,
        int $limit = 4,
        array $criteria = ['category', 'popular']
    ): array;
}