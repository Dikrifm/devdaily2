<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Class BaseApi
 *
 * Controller induk khusus untuk Endpoint API.
 * Menyediakan helper untuk format JSON standar dan mapping Exception ke HTTP Status.
 */
abstract class BaseApi extends BaseController
{
    use ResponseTrait;

    /**
     * Override method respond dari ResponseTrait
     * untuk memastikan format konsisten via ResponseFormatter Service jika diperlukan,
     * atau menggunakan standar CI4.
     */
    public function respond($data = null, int $status = 200, string $message = ''): ResponseInterface
    {
        // Kita bisa menggunakan ResponseFormatter service yang sudah di-load di BaseController
        // untuk menyusun struktur amplop (envelope) JSON yang seragam.
        $formatted = $this->formatter->success($data, $message, $status);
        
        return $this->response->setStatusCode($status)->setJSON($formatted);
    }

    /**
     * Helper untuk response Created (201)
     */
    public function respondCreated($data = null, string $message = 'Resource created'): ResponseInterface
    {
        $formatted = $this->formatter->created($data, $message);
        return $this->response->setStatusCode(201)->setJSON($formatted);
    }

    /**
     * Helper untuk response No Content (204)
     */
    public function respondNoContent(): ResponseInterface
    {
        return $this->response->setStatusCode(204);
    }

    /**
     * Menangani Exception secara global untuk API.
     * Method ini bisa dipanggil di catch block controller anak.
     */
    protected function handleException(\Throwable $th): ResponseInterface
    {
        $message = $th->getMessage();
        $code = $th->getCode();

        // Mapping Exception Project ke HTTP Status Code
        switch (true) {
            case $th instanceof \App\Exceptions\ValidationException:
                // Mengambil errors detail jika ada method getErrors()
                $errors = method_exists($th, 'getErrors') ? $th->getErrors() : [];
                return $this->failValidationErrors($errors, $message);

            case $th instanceof \App\Exceptions\NotFoundException:
            case $th instanceof \App\Exceptions\ProductNotFoundException:
            case $th instanceof \App\Exceptions\CategoryNotFoundException:
                return $this->failNotFound($message);

            case $th instanceof \App\Exceptions\AuthorizationException:
                return $this->failForbidden($message);

            case $th instanceof \App\Exceptions\DomainException:
                // Domain Error biasanya konflik aturan bisnis (409 Conflict atau 422 Unprocessable)
                return $this->fail($message, 409, 'DOMAIN_ERROR');

            default:
                // Error tidak terduga (500)
                // Log error asli untuk debugging
                log_message('error', '[API Error] ' . $message . "\n" . $th->getTraceAsString());
                
                // Jangan tampilkan detail error server di production
                $displayMessage = (ENVIRONMENT === 'production') 
                    ? 'An unexpected error occurred.' 
                    : $message;
                    
                return $this->failServerError($displayMessage);
        }
    }

    /**
     * Override failValidationErrors agar formatnya sesuai standar ResponseFormatter
     */
    public function failValidationErrors($errors, ?string $message = null, ?string $code = null, string $customMessage = ''): ResponseInterface
    {
        $response = $this->formatter->validationError(
            is_array($errors) ? $errors : ['error' => $errors],
            $message ?? 'Validation Failed'
        );
        
        return $this->response->setStatusCode(400)->setJSON($response);
    }
}
