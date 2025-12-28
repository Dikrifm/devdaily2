<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\DTOs\Requests\Auth\LoginRequest;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use Config\Services;

/**
 * Class Auth
 *
 * Controller untuk menangani autentikasi Admin.
 * Berperan sebagai jembatan antara AuthService (Stateless) dan Browser Session (Stateful).
 */
class Auth extends BaseController
{
    /**
     * Menampilkan halaman login.
     * GET /admin/login
     */
    public function index()
    {
        // 1. Cek apakah sudah login
        if (session()->has('admin_session_id')) {
            return redirect()->to('/admin/dashboard');
        }

        return view('auth/login', [
            'title' => 'Login Administrator'
        ]);
    }

    /**
     * Memproses data login.
     * POST /admin/login
     */
    public function login()
    {
        try {
            // 1. Buat DTO dari Request (Layer 6)
            // Validasi format dasar terjadi di sini
            $loginRequest = LoginRequest::fromRequest(
                $this->request->getPost(),
                $this->request->getIPAddress(),
                (string) $this->request->getUserAgent()
            );

            // 2. Panggil AuthService (Layer 5)
            // AuthService akan menangani logic: Cek DB, Hash Password, Cek Lockout, Audit Log
            $authService = Services::auth();
            $response = $authService->login($loginRequest);

            // 3. Cek apakah butuh 2FA
            if ($response->requiresTwoFactor()) {
                // Simpan state sementara untuk verifikasi 2FA
                session()->setFlashdata('2fa_user_id', $response->getAdminId());
                session()->setFlashdata('2fa_factors', $response->getTwoFactorFactors());
                
                return redirect()->to('/admin/login/verify-2fa')
                    ->with('info', 'Autentikasi dua faktor diperlukan.');
            }

            // 4. Login Sukses -> Set Session CodeIgniter (Stateful)
            // Kita simpan Session ID yang digenerate oleh AuthService ke cookie browser
            $sessionData = [
                'admin_session_id' => $response->getSession()->sessionId,
                'admin_id'         => $response->getAdmin()->getId(),
                'admin_name'       => $response->getAdmin()->getName(),
                'admin_role'       => $response->getAdmin()->getRole(),
            ];

            session()->set($sessionData);

            // 5. Redirect ke Dashboard atau URL yang diminta sebelumnya
            $redirectUrl = session('redirect_url') ?? '/admin/dashboard';
            session()->remove('redirect_url');

            return redirect()->to($redirectUrl)->with('success', 'Selamat datang kembali, ' . $sessionData['admin_name']);

        } catch (ValidationException $e) {
            // Error Validasi Input (Format salah)
            return redirect()->back()->withInput()->with('errors', $e->getErrors());

        } catch (AuthorizationException $e) {
            // Error Logika Bisnis (Password salah, Akun terkunci, dll)
            return redirect()->back()->withInput()->with('error', $e->getMessage());

        } catch (\Exception $e) {
            // Error Tidak Terduga
            $this->logger->error('[Auth Login] Unexpected error: ' . $e->getMessage());
            return redirect()->back()->withInput()->with('error', 'Terjadi kesalahan sistem. Silakan coba lagi.');
        }
    }

    /**
     * Proses Logout.
     * GET/POST /admin/logout
     */
    public function logout()
    {
        $sessionId = session('admin_session_id');

        if ($sessionId) {
            try {
                // Hapus sesi di database/cache via Service
                Services::auth()->logout($sessionId);
            } catch (\Exception $e) {
                // Abaikan error saat logout (misal sesi sudah expired di server)
                $this->logger->warning('[Auth Logout] ' . $e->getMessage());
            }
        }

        // Hapus sesi browser
        session()->destroy();

        return redirect()->to('/admin/login')->with('info', 'Anda telah keluar dari sistem.');
    }
}
