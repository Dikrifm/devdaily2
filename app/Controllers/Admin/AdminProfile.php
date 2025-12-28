<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Admin\ChangeAdminPasswordRequest;
use App\Services\AdminService;
use App\Services\AuthService;
use Config\Services;

/**
 * Class AdminProfile
 *
 * Controller untuk Manajemen Profil Diri Sendiri.
 * * Layer 4: The Plug & Play Layer (Admin Context)
 *
 * Menangani update biodata dan ganti password akun yang sedang login.
 */
class AdminProfile extends BaseAdmin
{
    private AdminService $adminService;
    private AuthService $authService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load Services
        $this->adminService = Services::admin();
        $this->authService  = Services::authentication();
    }

    /**
     * Halaman Profil Saya
     * GET /admin/profile
     */
    public function index()
    {
        // Ambil data user yang sedang login via AuthService
        // Asumsi: method user() mengembalikan entity admin yang sedang login
        $currentUser = $this->authService->user();

        if (!$currentUser) {
            return redirect()->to('/admin/login');
        }

        return view('admin/profile/index', [
            'title' => 'Profil Saya',
            'user'  => $currentUser,
        ]);
    }

    /**
     * Update Biodata (Nama & Email)
     * POST /admin/profile/update
     */
    public function update()
    {
        try {
            $currentUser = $this->authService->user();
            if (!$currentUser) {
                throw new \Exception('Sesi Anda telah berakhir.');
            }

            // Ambil input
            $data = [
                'name'  => $this->request->getPost('name'),
                'email' => $this->request->getPost('email'),
            ];

            // Validasi sederhana di controller sebelum ke service
            if (empty($data['name']) || empty($data['email'])) {
                throw new \Exception('Nama dan Email wajib diisi.');
            }

            // Eksekusi Service updateProfile
            // Service akan memvalidasi duplikasi email secara internal
            $this->adminService->updateProfile($currentUser->getId(), $data);

            return redirect()->back()->with('success', 'Profil berhasil diperbarui.');

        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Ganti Password Mandiri
     * POST /admin/profile/password
     */
    public function changePassword()
    {
        try {
            $currentUser = $this->authService->user();
            if (!$currentUser) {
                throw new \Exception('Sesi Anda telah berakhir.');
            }

            // Validasi Input
            $rules = [
                'current_password'      => 'required',
                'new_password'          => 'required|min_length[8]',
                'new_password_confirm'  => 'required|matches[new_password]'
            ];

            if (!$this->validate($rules)) {
                return redirect()->back()->withInput()->with('error', $this->validator->getErrors());
            }

            // Ambil data
            $currentPassword = (string) $this->request->getPost('current_password');
            $newPassword     = (string) $this->request->getPost('new_password');

            // Buat DTO ChangeAdminPasswordRequest
            // Parameter ke-3 diisi $currentPassword karena ini Self-Service (Wajib verifikasi pass lama)
            $dto = new ChangeAdminPasswordRequest(
                $currentUser->getId(),
                $newPassword,
                $currentPassword
            );

            // Eksekusi Service
            $this->adminService->changePassword($dto);

            return redirect()->back()->with('success', 'Password berhasil diubah. Silakan login ulang jika diperlukan.');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Gagal mengubah password: ' . $e->getMessage());
        }
    }
}
