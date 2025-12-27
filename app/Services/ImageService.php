<?php

namespace App\Services;

use App\Contracts\ImageServiceInterface;
use App\Libraries\ImageProcessor;
use CodeIgniter\HTTP\Files\UploadedFile;
use Exception;

/**
 * Image Service
 * * Application Service Layer (Layer 4).
 * Orchestrates image processing logic by delegating raw manipulation 
 * to the ImageProcessor library.
 * * @package App\Services
 */
class ImageService extends BaseService implements ImageServiceInterface
{
    protected ImageProcessor $processor;

    public function __construct()
    {
        // Menginisialisasi Library Processor (Infrastructure Layer)
        // Dalam implementasi CI4 yang lebih advanced, ini bisa via Dependency Injection di Config/Services.php
        $this->processor = new ImageProcessor();
    }

    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return 'image_service';
    }

    /**
     * @inheritDoc
     */
    public function process(UploadedFile $file): string
    {
        // Menggunakan wrapper transaction dari BaseInterface (jika diperlukan logic DB lain)
        // atau sekadar try-catch block untuk error handling terpusat.
        
        return $this->transaction(function () use ($file) {
            try {
                // Delegasi ke Library Processor
                $path = $this->processor->process($file);

                // Opsional: Audit Log sukses upload (via BaseService)
                // $this->audit('create', 'image', 0, null, ['path' => $path]);

                return $path;
            } catch (Exception $e) {
                // Log error ke sistem monitoring (Sentry/Log file)
                log_message('error', '[ImageService] Processing failed: ' . $e->getMessage());
                throw $e;
            }
        }, 'image_upload_process');
    }

    /**
     * @inheritDoc
     */
    public function delete(?string $basePath): void
    {
        if (empty($basePath)) {
            return;
        }

        // Operasi delete biasanya tidak perlu Transaction DB ketat, 
        // tapi kita tangkap errornya agar tidak mematikan flow utama.
        try {
            $this->processor->delete($basePath);
        } catch (Exception $e) {
            log_message('error', '[ImageService] Delete failed for path ' . $basePath . ': ' . $e->getMessage());
            // Kita suppress error delete agar user tidak gagal update produk 
            // hanya karena file lama gagal dihapus (misal permission error).
        }
    }
}
