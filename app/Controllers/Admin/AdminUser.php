<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Queries\PaginationQuery;
use App\DTOs\Requests\Admin\ChangeAdminPasswordRequest;
use App\DTOs\Requests\Admin\CreateAdminRequest;
use App\DTOs\Requests\Admin\UpdateAdminRequest;
use App\Services\AdminService;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Services;

/**
 * Class AdminUser
 *
 * Controller untuk Manajemen Pengguna (Admin & Staff).
 * * Layer 4: The Plug & Play Layer (Admin Context)
 *
 * Menangani CRUD user, manajemen role, dan reset password.
 */
class AdminUser extends BaseAdmin
{
    private AdminService $adminService;

    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load AdminService
        $this->adminService = Services::admin();
    }

    /**
     * Halaman List User
     * GET /admin/users
     */
    public function index()
    {
        // Ambil query parameter untuk pagination & filter
        $page = (int) ($this->request->getGet('page') ?? 1);
        $search = $this->request->getGet('search');

        $pagination = new PaginationQuery($page, 10);

        if ($search) {
            $result = $this->adminService->searchAdmins($search, $pagination);
        } else {
            $result = $this->adminService->listAdmins($pagination);
        }

        return view('admin/users/index', [
            'title' => 'Manajemen Pengguna',
            'users' => $result['items'],
            'pager' => $this->makePager($result['pagination']),
            'search' => $search
        ]);
    }

    /**
     * Halaman Tambah User Baru
     * GET /admin/users/new
     */
    public function new()
    {
        return view('admin/users/form', [
            'title' => 'Tambah User Baru',
            'user'  => null, // Mode Create
            'roles' => ['admin' => 'Admin Biasa', 'super_admin' => 'Super Admin']
        ]);
    }

    /**
     * Proses Simpan User Baru
     * POST /admin/users
     */
    public function create()
    {
        try {
            // Transform Request ke DTO
            $dto = CreateAdminRequest::fromRequest($this->request);

            // Eksekusi Service
            $this->adminService->createAdmin($dto);

            return redirect()->to('/admin/users')->with('success', 'User berhasil ditambahkan.');

        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Halaman Edit User
     * GET /admin/users/{id}/edit
     */
    public function edit(int $id)
    {
        try {
            $user = $this->adminService->getAdmin($id);

            return view('admin/users/form', [
                'title' => 'Edit User: ' . $user->getName(),
                'user'  => $user,
                'roles' => ['admin' => 'Admin Biasa', 'super_admin' => 'Super Admin']
            ]);

        } catch (\Exception $e) {
            return redirect()->to('/admin/users')->with('error', 'User tidak ditemukan.');
        }
    }

    /**
     * Proses Update User
     * POST /admin/users/{id}
     */
    public function update(int $id)
    {
        try {
            // Transform Request ke DTO
            $dto = UpdateAdminRequest::fromRequest($id, $this->request);

            // Eksekusi Service
            $this->adminService->updateAdmin($dto);

            return redirect()->to('/admin/users')->with('success', 'Data user berhasil diperbarui.');

        } catch (\Exception $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Proses Ganti Password (Reset oleh Admin lain)
     * POST /admin/users/{id}/password
     */
    public function changePassword(int $id)
    {
        try {
            // Validasi input password simple
            $newPassword = $this->request->getPost('password');
            if (strlen($newPassword) < 8) {
                throw new \Exception('Password minimal 8 karakter.');
            }

            // Buat DTO (Current password null karena ini reset paksa)
            $dto = new ChangeAdminPasswordRequest($id, $newPassword, null);

            $this->adminService->changePassword($dto);

            return redirect()->back()->with('success', 'Password user berhasil direset.');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Proses Arsip/Hapus User
     * POST /admin/users/{id}/delete
     */
    public function delete(int $id)
    {
        try {
            // Kita coba archive dulu (Soft Delete)
            $this->adminService->archiveAdmin($id, 'Dihapus via Admin Panel');

            return redirect()->to('/admin/users')->with('success', 'User berhasil dinonaktifkan (Arsip).');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Helper Manual Pager (Karena Service mengembalikan array pagination manual)
     */
    private function makePager(array $paginationInfo)
    {
        $pager = \Config\Services::pager();
        return $pager->makeLinks(
            $paginationInfo['current_page'], 
            $paginationInfo['per_page'], 
            $paginationInfo['total'], 
            'default_full'
        );
    }
}
