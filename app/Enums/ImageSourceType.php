<?php

namespace App\Enums;

/**
 * Image Source Type Enumeration
 * 
 * Defines how product images are sourced in the system.
 * Business Rule: Manual curation allows both uploaded screenshots and external URLs.
 * 
 * @package App\Enums
 */
enum ImageSourceType: string
{
    /**
     * Image uploaded by admin via system
     * Stored locally in the server filesystem
     */
    case UPLOAD = 'upload';

    /**
     * Image referenced by external URL
     * Loaded directly from marketplace or CDN
     */
    case URL = 'url';

    /**
     * Get all source types as array
     * Useful for validation and form select options
     * 
     * @return array
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if this source type requires local file storage
     * 
     * @return bool
     */
    public function requiresLocalStorage(): bool
    {
        return $this === self::UPLOAD;
    }

    /**
     * Check if this source type requires URL validation
     * 
     * @return bool
     */
    public function requiresUrlValidation(): bool
    {
        return $this === self::URL;
    }

    /**
     * Get the storage path for uploaded images
     * 
     * @return string
     */
    public function getStoragePath(): string
    {
        return match ($this) {
            self::UPLOAD => 'uploads/products/',
            self::URL => '',
        };
    }

    /**
     * Get the maximum file size allowed for this source type
     * Returns in bytes for UPLOAD, null for URL
     * 
     * @return int|null
     */
    public function getMaxFileSize(): ?int
    {
        return match ($this) {
            self::UPLOAD => 5 * 1024 * 1024, // 5MB
            self::URL => null,
        };
    }

    /**
     * Get allowed file extensions for this source type
     * Returns array for UPLOAD, empty array for URL
     * 
     * @return array
     */
    public function getAllowedExtensions(): array
    {
        return match ($this) {
            self::UPLOAD => ['jpg', 'jpeg', 'png', 'webp'],
            self::URL => [],
        };
    }

    /**
     * Get validation rules for this source type
     * Useful for form validation in admin interface
     * 
     * @return array
     */
    public function getValidationRules(): array
    {
        return match ($this) {
            self::UPLOAD => [
                'max_size' => $this->getMaxFileSize(),
                'ext_in' => $this->getAllowedExtensions(),
                'is_image' => true,
            ],
            self::URL => [
                'valid_url' => true,
                'url_active' => true,
            ],
        };
    }

    /**
     * Get display label for UI
     * 
     * @return string
     */
    public function label(): string
    {
        return match ($this) {
            self::UPLOAD => 'Uploaded Image',
            self::URL => 'External URL',
        };
    }

    /**
     * Get description for UI tooltips
     * 
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::UPLOAD => 'Image uploaded directly to our servers. Recommended for screenshots.',
            self::URL => 'Image hosted externally (marketplace, CDN). Use for product photos.',
        };
    }

    /**
     * Get FontAwesome icon for display
     * 
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::UPLOAD => 'fas fa-upload',
            self::URL => 'fas fa-link',
        };
    }

    /**
     * Get Tailwind CSS color class
     * 
     * @return string
     */
    public function colorClass(): string
    {
        return match ($this) {
            self::UPLOAD => 'bg-blue-100 text-blue-800',
            self::URL => 'bg-purple-100 text-purple-800',
        };
    }

    /**
     * Check if this source type is recommended for verification screenshots
     * Business Rule: Screenshot proof should be uploaded, not external URL
     * 
     * @return bool
     */
    public function isRecommendedForVerification(): bool
    {
        return $this === self::UPLOAD;
    }

    /**
     * Generate a filename pattern for uploaded images
     * 
     * @param string $productSlug
     * @param string $extension
     * @return string
     */
    public function generateFilename(string $productSlug, string $extension): string
    {
        if ($this !== self::UPLOAD) {
            throw new \LogicException('Filename generation only available for UPLOAD source type');
        }

        $timestamp = time();
        $hash = substr(md5($productSlug . $timestamp), 0, 8);
        
        return sprintf('%s-%s.%s', $productSlug, $hash, $extension);
    }

    /**
     * Get the full image path/URL for display
     * 
     * @param string $identifier Image path or URL
     * @return string
     */
    public function getDisplaySource(string $identifier): string
    {
        return match ($this) {
            self::UPLOAD => base_url($this->getStoragePath() . $identifier),
            self::URL => $identifier,
        };
    }

    /**
     * Validate an image source against this type's requirements
     * 
     * @param string $source
     * @return bool
     */
    public function validateSource(string $source): bool
    {
        return match ($this) {
            self::UPLOAD => file_exists(ROOTPATH . 'public/' . $this->getStoragePath() . $source),
            self::URL => filter_var($source, FILTER_VALIDATE_URL) !== false,
        };
    }

    /**
     * Get the recommended dimensions for this source type
     * 
     * @return array{width: int, height: int}
     */
    public function getRecommendedDimensions(): array
    {
        return match ($this) {
            self::UPLOAD => ['width' => 800, 'height' => 600],
            self::URL => ['width' => 1200, 'height' => 800],
        };
    }

    /**
     * Check if resizing is recommended for this source type
     * 
     * @return bool
     */
    public function shouldResize(): bool
    {
        return $this === self::UPLOAD;
    }
}