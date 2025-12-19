<?php

namespace App\Validators;

/**
 * Validator khusus untuk validasi gambar
 */
class ImageValidator extends FileValidator
{
    /**
     * Validate that file is an image
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function is_image($value, ?string $param, array $data, ?string &$error = null): bool
    {
        if (!$this->uploaded_file($value, null, $data, $error)) {
            return false;
        }

        $allowedTypes = [
            'image/jpeg', 'image/jpg',
            'image/png', 'image/gif',
            'image/webp', 'image/svg+xml',
            'image/bmp', 'image/tiff'
        ];

        $mimeType = $this->getFileMimeType($value);

        if (!in_array($mimeType, $allowedTypes, true)) {
            $error = "File must be an image (JPEG, PNG, GIF, WebP, SVG, BMP, TIFF)";
            return false;
        }

        // Untuk keamanan, verifikasi dengan getimagesize
        $tempPath = $this->getTempPath($value);
        $imageInfo = @getimagesize($tempPath);

        if ($imageInfo === false) {
            $error = "File is not a valid image";
            return false;
        }

        return true;
    }

    /**
     * Validate image aspect ratio
     *
     * @param mixed $value
     * @param string $ratio
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_ratio($value, string $ratio, array $data, ?string &$error = null): bool
    {
        if (!$this->is_image($value, null, $data, $error)) {
            return false;
        }

        // Format: width:height atau decimal
        if (strpos($ratio, ':') !== false) {
            list($width, $height) = explode(':', $ratio);
            $expectedRatio = $width / $height;
        } else {
            $expectedRatio = (float) $ratio;
        }

        $imageInfo = @getimagesize($this->getTempPath($value));

        if ($imageInfo === false) {
            $error = "Cannot read image dimensions";
            return false;
        }

        list($actualWidth, $actualHeight) = $imageInfo;
        $actualRatio = $actualWidth / $actualHeight;

        $tolerance = 0.01; // 1% tolerance

        if (abs($actualRatio - $expectedRatio) > $tolerance) {
            $error = "Image aspect ratio must be {$ratio}";
            return false;
        }

        return true;
    }

    /**
     * Validate image is square
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_square($value, ?string $param, array $data, ?string &$error = null): bool
    {
        return $this->image_ratio($value, '1:1', $data, $error);
    }

    /**
     * Validate image is landscape orientation
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_landscape($value, ?string $param, array $data, ?string &$error = null): bool
    {
        if (!$this->is_image($value, null, $data, $error)) {
            return false;
        }

        $imageInfo = @getimagesize($this->getTempPath($value));

        if ($imageInfo === false) {
            $error = "Cannot read image dimensions";
            return false;
        }

        list($width, $height) = $imageInfo;

        if ($width <= $height) {
            $error = "Image must be landscape orientation (width > height)";
            return false;
        }

        return true;
    }

    /**
     * Validate image is portrait orientation
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_portrait($value, ?string $param, array $data, ?string &$error = null): bool
    {
        if (!$this->is_image($value, null, $data, $error)) {
            return false;
        }

        $imageInfo = @getimagesize($this->getTempPath($value));

        if ($imageInfo === false) {
            $error = "Cannot read image dimensions";
            return false;
        }

        list($width, $height) = $imageInfo;

        if ($width >= $height) {
            $error = "Image must be portrait orientation (height > width)";
            return false;
        }

        return true;
    }

    /**
     * Validate image max dimensions
     *
     * @param mixed $value
     * @param string $maxDimensions
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_max($value, string $maxDimensions, array $data, ?string &$error = null): bool
    {
        return $this->file_dimensions($value, "max_{$maxDimensions}", $data, $error);
    }

    /**
     * Validate image min dimensions
     *
     * @param mixed $value
     * @param string $minDimensions
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_min($value, string $minDimensions, array $data, ?string &$error = null): bool
    {
        return $this->file_dimensions($value, "min_{$minDimensions}", $data, $error);
    }

    /**
     * Validate image exact dimensions
     *
     * @param mixed $value
     * @param string $dimensions
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_exact($value, string $dimensions, array $data, ?string &$error = null): bool
    {
        return $this->file_dimensions($value, $dimensions, $data, $error);
    }

    /**
     * Validate image for web (optimized size)
     *
     * @param mixed $value
     * @param string $maxSize
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function image_web_optimized($value, string $maxSize, array $data, ?string &$error = null): bool
    {
        if (!$this->is_image($value, null, $data, $error)) {
            return false;
        }

        // Validasi size
        if (!$this->file_size($value, $maxSize, $data, $error)) {
            return false;
        }

        // Validasi format untuk web
        $mimeType = $this->getFileMimeType($value);
        $webFormats = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];

        if (!in_array($mimeType, $webFormats, true)) {
            $error = "Image format not optimized for web. Use JPEG, PNG, WebP, or GIF";
            return false;
        }

        return true;
    }

    /**
     * Get supported image formats
     *
     * @return array
     */
    public static function getSupportedFormats(): array
    {
        return [
            'jpeg' => 'JPEG/JPG',
            'png' => 'PNG',
            'gif' => 'GIF',
            'webp' => 'WebP',
            'svg' => 'SVG',
            'bmp' => 'BMP',
            'tiff' => 'TIFF'
        ];
    }

    /**
     * Get recommended image dimensions for different use cases
     *
     * @return array
     */
    public static function getRecommendedDimensions(): array
    {
        return [
            'thumbnail' => ['width' => 150, 'height' => 150, 'aspect' => '1:1'],
            'small' => ['width' => 300, 'height' => 300, 'aspect' => '1:1'],
            'medium' => ['width' => 600, 'height' => 400, 'aspect' => '3:2'],
            'large' => ['width' => 1200, 'height' => 800, 'aspect' => '3:2'],
            'hero' => ['width' => 1920, 'height' => 1080, 'aspect' => '16:9'],
            'banner' => ['width' => 1200, 'height' => 300, 'aspect' => '4:1'],
        ];
    }
}
