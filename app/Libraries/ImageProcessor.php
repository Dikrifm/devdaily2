<?php

namespace App\Libraries;

use Config\Services;
use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;
use Exception;

/**
 * Image Processor Library
 * * Infrastructure Layer.
 * Handles low-level image manipulation using GD/Imagick via CI4 Services.
 * Implements "Enterprise Sultan Mode":
 * 1. Thumb (150px)
 * 2. Medium (800px)
 * 3. Large (1920px, 95% Quality)
 * All outputs are WebP.
 */
class ImageProcessor
{
    protected string $uploadRoot = 'uploads';

    /**
     * Memproses gambar mentah menjadi 3 varian Enterprise (WebP).
     *
     * @param UploadedFile $file File fisik dari request
     * @return string Base path relative (misal: '2025/12/173512_ax99')
     * @throws RuntimeException|Exception
     */
    public function process(UploadedFile $file): string
    {
        // 1. Validasi File Fisik Dasar
        if (! $file->isValid()) {
            throw new RuntimeException($file->getErrorString() . '(' . $file->getError() . ')');
        }

        // 2. Siapkan Struktur Folder (YYYY/MM)
        $year  = date('Y');
        $month = date('m');
        $relativePath = "{$year}/{$month}";
        $absolutePath = FCPATH . $this->uploadRoot . '/' . $relativePath;

        if (! is_dir($absolutePath)) {
            mkdir($absolutePath, 0755, true);
        }

        // 3. Generate Base Name (Tanpa Ekstensi) -> Random String aman
        // Format: timestamp_hexrandom
        $randomName = time() . '_' . bin2hex(random_bytes(4));
        
        // Pindahkan file mentah sementara untuk diproses
        // Kita beri suffix '_temp' agar mudah diidentifikasi
        $tempFilename = $randomName . '_temp.' . $file->getExtension();
        $file->move($absolutePath, $tempFilename);
        
        $sourcePath = $absolutePath . '/' . $tempFilename;

        // Load Image Service CI4
        $imageService = Services::image();

        try {
            // --- VARIANT 1: THUMBNAIL (150x150, Crop Center) ---
            // Penggunaan: Avatar, Admin Table, List Kecil
            $imageService->withFile($sourcePath)
                ->fit(150, 150, 'center')
                ->save($absolutePath . '/' . $randomName . '_thumb.webp', 70);

            // --- VARIANT 2: MEDIUM (Width 800, Ratio maintained) ---
            // Penggunaan: Mobile Feed, Card Artikel
            $imageService->withFile($sourcePath)
                ->resize(800, 0, true, 'width')
                ->save($absolutePath . '/' . $randomName . '_med.webp', 80);

            // --- VARIANT 3: LARGE SULTAN (Width 1920, Ratio maintained) ---
            // Penggunaan: Desktop Hero, Zoom, Detail Page.
            // Quality 95% = High Fidelity (>300KB expected for complex images)
            $imageService->withFile($sourcePath)
                ->resize(1920, 0, true, 'width')
                ->save($absolutePath . '/' . $randomName . '_large.webp', 95);

            // 4. Cleanup: Hapus file sumber (temp)
            // Kita tidak menyimpan file asli user demi keamanan & hemat space
            if (is_file($sourcePath)) {
                unlink($sourcePath);
            }

            // Return path relatif (tanpa ekstensi) untuk disimpan di DB
            return $relativePath . '/' . $randomName;

        } catch (Exception $e) {
            // Rollback: Jika error, hapus file sampah yang mungkin terbentuk
            if (is_file($sourcePath)) unlink($sourcePath);
            $this->cleanupFailedUpload($absolutePath, $randomName);
            
            throw new Exception('Image processing failed: ' . $e->getMessage());
        }
    }

    /**
     * Menghapus semua varian gambar berdasarkan base path.
     * Digunakan saat Delete Product atau Update Product (ganti gambar).
     * * @param string|null $basePath (ex: '2025/12/filename_acak')
     */
    public function delete(?string $basePath): void
    {
        if (empty($basePath)) return;

        $suffixes = ['_thumb.webp', '_med.webp', '_large.webp'];
        
        foreach ($suffixes as $suffix) {
            $path = FCPATH . $this->uploadRoot . '/' . $basePath . $suffix;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Helper untuk membersihkan file parsial jika proses gagal di tengah jalan
     */
    protected function cleanupFailedUpload(string $path, string $filename): void
    {
        $suffixes = ['_thumb.webp', '_med.webp', '_large.webp'];
        foreach ($suffixes as $suffix) {
            $f = $path . '/' . $filename . $suffix;
            if (is_file($f)) unlink($f);
        }
    }
}
