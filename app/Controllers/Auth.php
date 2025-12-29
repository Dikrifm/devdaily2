<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\AuthService; // Asumsi Service Backend sudah siap
use CodeIgniter\HTTP\RedirectResponse;

class Auth extends BaseController
{
    protected AuthService $authService;

    public function __construct()
    {
        // Injeksi AuthService (atau panggil via Services::auth() jika menggunakan Factory)
        $this->authService = service('auth'); 
    }

    /**
     * Menampilkan Halaman Login
     * URL: /admin/login atau /admin/auth/login
     */
    public function index()
    {
        // Jika sudah login, lempar ke dashboard
        if (session()->get('is_logged_in')) {
            return redirect()->to('admin/dashboard');
        }

        return view('pages/auth/login');
    }

    /**
     * Proses Login (POST)
     */
    public function login(): RedirectResponse
    {
        // 1. Validasi Input
        $rules = [
            'login_id' => [
                'rules'  => 'required',
                'errors' => [
                    'required' => 'Email atau Username wajib diisi.'
                ]
            ],
            'password' => [
                'rules'  => 'required',
                'errors' => [
                    'required' => 'Password tidak boleh kosong.'
                ]
            ]
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('error', $this->validator->getErrors());
        }

        // 2. Ambil Data Input
        $loginId  = $this->request->getPost('login_id');
        $password = $this->request->getPost('password');
        $remember = (bool) $this->request->getPost('remember');

        // 3. Panggil Logic Service (Try-Catch untuk menangkap Exception dari Service)
        try {
            $user = $this->authService->attemptLogin($loginId, $password, $remember);

            if ($user) {
                // Set Session Data (Logic session detail bisa juga ditaruh di Service)
                $sessionData = [
                    'admin_id'      => $user->id,
                    'admin_name'    => $user->name,
                    'admin_role'    => $user->role,
                    'is_logged_in'  => true
                ];
                session()->set($sessionData);

                return redirect()->to('admin/dashboard')->with('success', 'Selamat datang kembali, ' . $user->name);
            } else {
                return redirect()->back()->withInput()->with('error', 'Kombinasi akun tidak ditemukan.');
            }

        } catch (\Exception $e) {
            // Menangkap error logic (misal: Akun disuspend)
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Proses Logout
     */
    public function logout(): RedirectResponse
    {
        // Hapus session
        session()->destroy();
        return redirect()->to('admin/login')->with('success', 'Anda telah berhasil keluar.');
    }
    
    /**
     * Halaman Lupa Password
     */
    public function forgotPassword()
    {
        return view('pages/auth/forgot_password');
    }
}
