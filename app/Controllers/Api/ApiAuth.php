<?php

namespace App\Controllers\Api;

use App\DTOs\Requests\Auth\LoginRequest;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use Config\Services;

/**
 * Class ApiAuth
 *
 * Controller API untuk Autentikasi (Stateless).
 * * Layer 4: The Plug & Play Layer (API Context)
 *
 * Endpoint:
 * - POST /api/auth/login
 * - POST /api/auth/logout
 */
class ApiAuth extends BaseApi
{
    private AuthService $authService;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load AuthService
        $this->authService = Services::authentication();
    }

    /**
     * Login User (Admin)
     * POST /api/auth/login
     *
     * Body JSON:
     * {
     * "email": "admin@devdaily.com",
     * "password": "secret_password",
     * "device_name": "Mobile App Samsung S23"
     * }
     */
    public function login()
    {
        try {
            // 1. Ambil Payload JSON
            $json = $this->request->getJSON(true); // true = associative array

            if (!$json) {
                throw new ValidationException('Body request harus berupa JSON valid.');
            }

            // 2. Transform ke DTO (Layer 6)
            // Validasi format email & password terjadi di sini (di dalam DTO)
            $loginRequest = LoginRequest::fromArray($json);

            // 3. Eksekusi Login via Service
            // Mengembalikan object LoginResponse (Sukses / Butuh 2FA)
            $loginResult = $this->authService->login($loginRequest);

            // 4. Return JSON
            // LoginResponse::toArray() sudah memformat struktur:
            // - Jika sukses: { status: 'success', data: { token: '...', user: ... } }
            // - Jika butuh 2FA: { status: '2fa_required', data: { verification_context: ... } }
            
            // Kita gunakan helper respond() dari BaseApi
            return $this->respond($loginResult->toArray());

        } catch (\Throwable $th) {
            // Delegasikan semua error ke handler global BaseApi
            // Ini akan menangani ValidationException (400), AuthException (401), dll.
            return $this->handleException($th);
        }
    }

    /**
     * Logout (Invalidate Token)
     * POST /api/auth/logout
     *
     * Headers:
     * Authorization: Bearer <token>
     */
    public function logout()
    {
        try {
            // 1. Ambil Token dari Header
            $authHeader = $this->request->getHeaderLine('Authorization');
            
            if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
                // Jika tidak ada token, kita anggap logout sukses (idempotent) atau return 401
                // Untuk keamanan, return 200 saja (no content info)
                return $this->respondNoContent();
            }

            // Extract token string
            $token = substr($authHeader, 7);

            // 2. Eksekusi Logout di Service
            // Service akan menghapus/revoke token tersebut dari database
            $this->authService->logout($token);

            // 3. Response Sukses
            return $this->respond(['message' => 'Logout berhasil. Token telah hangus.']);

        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }

    /**
     * Cek Profile Saya (Who Am I)
     * GET /api/auth/me
     *
     * Headers:
     * Authorization: Bearer <token>
     */
    public function me()
    {
        try {
            // Mengambil user saat ini.
            // Asumsi: Ada Filter/Middleware API yang sudah memvalidasi token 
            // dan menaruh user object di service atau request.
            // Jika filter belum ada, kita bisa validasi manual via AuthService:
            
            $authHeader = $this->request->getHeaderLine('Authorization');
            $token = substr($authHeader, 7);
            
            // Validasi token & ambil user
            $user = $this->authService->validateToken($token);

            if (!$user) {
                return $this->failUnauthorized('Token tidak valid atau kadaluwarsa.');
            }

            return $this->respond([
                'user' => $user->toArray()
            ]);

        } catch (\Throwable $th) {
            return $this->handleException($th);
        }
    }
}
