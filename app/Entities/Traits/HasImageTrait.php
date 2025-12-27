<?php

namespace App\Entities\Traits;

/**
 * Trait HasImageTrait
 * * Digunakan oleh Entity yang memiliki fitur gambar Enterprise (Sultan Mode).
 * Menyediakan method helper untuk mendapatkan URL lengkap dari varian gambar.
 *
 * @package App\Entities\Traits
 */
trait HasImageTrait
{
    /**
     * Mengambil URL Thumbnail (150px)
     * Cocok untuk: Tabel Admin, Avatar, Sidebar List.
     */
    public function getThumbUrl(): string
    {
        return $this->getImageVariantUrl('_thumb');
    }

    /**
     * Mengambil URL Medium (800px)
     * Cocok untuk: Card Produk, Mobile Feed.
     */
    public function getMediumUrl(): string
    {
        return $this->getImageVariantUrl('_med');
    }

    /**
     * Mengambil URL Large Sultan (1920px - High Fidelity)
     * Cocok untuk: Halaman Detail, Zoom, Desktop Banner.
     */
    public function getLargeUrl(): string
    {
        return $this->getImageVariantUrl('_large');
    }

    /**
     * Logic internal untuk menyusun URL.
     * Mengasumsikan kolom database bernama 'image' atau 'image_path'.
     *
     * @param string $suffix (ex: '_thumb', '_large')
     * @return string Full URL
     */
    protected function getImageVariantUrl(string $suffix): string
    {
        // 1. Cek atribut 'image' atau 'image_path' (sesuaikan dengan nama kolom DB Anda)
        $path = $this->attributes['image'] ?? $this->attributes['image_path'] ?? null;

        // 2. Jika tidak ada gambar, return placeholder default
        if (empty($path)) {
            // Pastikan Anda punya file ini di public/images/
            return base_url('assets/images/placeholder' . $suffix . '.webp');
        }

        // 3. Return URL lengkap ke folder uploads
        // Format path di DB: "2025/12/filename_acak"
        // Hasil: "https://domain.com/uploads/2025/12/filename_acak_thumb.webp"
        return base_url('uploads/' . $path . $suffix . '.webp');
    }
}
