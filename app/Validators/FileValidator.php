<?php

namespace App\Validators;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Validator untuk validasi file upload
 */
class FileValidator
{
    /**
     * Validate uploaded file
     *
     * @param mixed $value
     * @param string|null $param
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function uploaded_file($value, ?string $param, array $data, ?string &$error = null): bool
    {
        // Jika tidak ada file, biarkan rule required menangani
        if ($value === null || (is_array($value) && empty($value['name']))) {
            return true;
        }

        // Handle single file
        if ($value instanceof UploadedFile) {
            if (!$value->isValid()) {
                $error = $value->getErrorString();
                return false;
            }
            return true;
        }

        // Handle array dari $_FILES
        if (is_array($value)) {
            if (isset($value['error']) && $value['error'] !== UPLOAD_ERR_OK) {
                $error = $this->getUploadErrorMessage($value['error']);
                return false;
            }

            if (empty($value['tmp_name']) || !is_uploaded_file($value['tmp_name'])) {
                $error = "File upload failed";
                return false;
            }

            return true;
        }

        $error = "Invalid file upload";
        return false;
    }

    /**
     * Validate file extension
     *
     * @param mixed $value
     * @param string $allowedExtensions
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function file_extension($value, string $allowedExtensions, array $data, ?string &$error = null): bool
    {
        if (!$this->uploaded_file($value, null, $data, $error)) {
            return false;
        }

        $extensions = array_map('trim', explode(',', $allowedExtensions));
        $extensions = array_map('strtolower', $extensions);

        $fileExtension = $this->getFileExtension($value);

        if (!in_array($fileExtension, $extensions, true)) {
            $error = "File extension must be one of: " . implode(', ', $extensions);
            return false;
        }

        return true;
    }

    /**
     * Validate file mime type
     *
     * @param mixed $value
     * @param string $allowedMimes
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function file_mime($value, string $allowedMimes, array $data, ?string &$error = null): bool
    {
        if (!$this->uploaded_file($value, null, $data, $error)) {
            return false;
        }

        $mimes = array_map('trim', explode(',', $allowedMimes));
        $mimes = array_map('strtolower', $mimes);

        $fileMime = $this->getFileMimeType($value);

        if (!in_array($fileMime, $mimes, true)) {
            $error = "File type must be one of: " . implode(', ', $mimes);
            return false;
        }

        return true;
    }

    /**
     * Validate file size
     *
     * @param mixed $value
     * @param string $maxSize
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function file_size($value, string $maxSize, array $data, ?string &$error = null): bool
    {
        if (!$this->uploaded_file($value, null, $data, $error)) {
            return false;
        }

        $fileSize = $this->getFileSize($value);
        $maxSizeBytes = $this->parseSize($maxSize);

        if ($fileSize > $maxSizeBytes) {
            $error = "File size must not exceed {$maxSize}";
            return false;
        }

        return true;
    }

    /**
     * Validate file dimensions (for images)
     *
     * @param mixed $value
     * @param string $dimensions
     * @param array $data
     * @param string|null $error
     * @return bool
     */
    public function file_dimensions($value, string $dimensions, array $data, ?string &$error = null): bool
    {
        if (!$this->uploaded_file($value, null, $data, $error)) {
            return false;
        }

        // Format: max_width,max_height atau min_width,min_height
        $parts = array_map('trim', explode(',', $dimensions));

        if (count($parts) !== 2) {
            $error = "Invalid dimensions format. Use: width,height";
            return false;
        }

        list($width, $height) = $parts;

        // Coba dapatkan dimensi gambar
        $imageInfo = @getimagesize($this->getTempPath($value));

        if ($imageInfo === false) {
            $error = "Cannot read image dimensions";
            return false;
        }

        list($actualWidth, $actualHeight) = $imageInfo;

        // Cek maksimum dimensions
        if (str_starts_with($width, 'max_')) {
            $maxWidth = (int) substr($width, 4);
            $maxHeight = (int) substr($height, 5);

            if ($actualWidth > $maxWidth || $actualHeight > $maxHeight) {
                $error = "Image dimensions must not exceed {$maxWidth}x{$maxHeight} pixels";
                return false;
            }
        }
        // Cek minimum dimensions
        elseif (str_starts_with($width, 'min_')) {
            $minWidth = (int) substr($width, 4);
            $minHeight = (int) substr($height, 5);

            if ($actualWidth < $minWidth || $actualHeight < $minHeight) {
                $error = "Image dimensions must be at least {$minWidth}x{$minHeight} pixels";
                return false;
            }
        }
        // Exact dimensions
        else {
            if ($actualWidth != $width || $actualHeight != $height) {
                $error = "Image dimensions must be exactly {$width}x{$height} pixels";
                return false;
            }
        }

        return true;
    }

    /**
     * Get file extension
     *
     * @param mixed $file
     * @return string
     */
    protected function getFileExtension($file): string
    {
        if ($file instanceof UploadedFile) {
            return strtolower($file->getClientExtension());
        }

        if (is_array($file) && isset($file['name'])) {
            return strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        }

        return '';
    }

    /**
     * Get file mime type
     *
     * @param mixed $file
     * @return string
     */
    protected function getFileMimeType($file): string
    {
        if ($file instanceof UploadedFile) {
            return strtolower($file->getClientMimeType());
        }

        if (is_array($file) && isset($file['tmp_name'])) {
            $mime = mime_content_type($file['tmp_name']);
            return $mime ? strtolower($mime) : '';
        }

        return '';
    }

    /**
     * Get file size
     *
     * @param mixed $file
     * @return int
     */
    protected function getFileSize($file): int
    {
        if ($file instanceof UploadedFile) {
            return $file->getSize();
        }

        if (is_array($file) && isset($file['size'])) {
            return $file['size'];
        }

        return 0;
    }

    /**
     * Get temporary file path
     *
     * @param mixed $file
     * @return string
     */
    protected function getTempPath($file): string
    {
        if ($file instanceof UploadedFile) {
            return $file->getTempName();
        }

        if (is_array($file) && isset($file['tmp_name'])) {
            return $file['tmp_name'];
        }

        return '';
    }

    /**
     * Parse size string to bytes
     *
     * @param string $size
     * @return int
     */
    protected function parseSize(string $size): int
    {
        $unit = strtolower(substr($size, -1));
        $value = (int) substr($size, 0, -1);

        switch ($unit) {
            case 'k':
                return $value * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'g':
                return $value * 1024 * 1024 * 1024;
            default:
                return (int) $size;
        }
    }

    /**
     * Get upload error message
     *
     * @param int $errorCode
     * @return string
     */
    protected function getUploadErrorMessage(int $errorCode): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];

        return $errors[$errorCode] ?? 'Unknown upload error';
    }
}
