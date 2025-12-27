<?php

namespace App\Contracts;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Image Service Interface
 * * Business Orchestrator Layer (Layer 5)
 * Defines the contract for handling image uploads, processing, and cleanup
 * within the Enterprise/Sultan specification.
 *
 * @package App\Contracts
 */
interface ImageServiceInterface extends BaseInterface
{
    /**
     * Process an uploaded file into multi-variant WebP images.
     * * @param UploadedFile $file The raw uploaded file from the request
     * @return string The relative base path (without extension) for database storage
     * Example: "2025/12/173599_random"
     * @throws \RuntimeException If file validation fails
     * @throws \Exception If processing fails
     */
    public function process(UploadedFile $file): string;

    /**
     * Remove all generated variants of an image.
     * * @param string|null $basePath The relative base path stored in database
     * Example: "2025/12/173599_random"
     * @return void
     */
    public function delete(?string $basePath): void;
}
