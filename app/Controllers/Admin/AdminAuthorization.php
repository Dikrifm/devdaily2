<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseAdmin;
use App\DTOs\Requests\Authorization\CreateRoleRequest;
use App\DTOs\Requests\Authorization\UpdateRolePermissionsRequest;
use App\DTOs\Requests\Authorization\UpdateRoleRequest;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\AuthorizationService;
use Config\Services;

/**
 * Class AdminAuthorization
 *
 * Controller Halaman Penuh untuk Manajemen Otorisasi (RBAC).
 * Mengelola Peran (Roles) dan Izin (Permissions) dalam sistem.
 * * Flow:
 * 1. Admin membuat Role (misal: "Editor Konten").
 * 2. Admin melihat daftar Permission tersedia (misal: "product.create", "product.publish").
 * 3. Admin menugaskan Permission ke Role tersebut.
 * * * Layer 4: The Plug & Play Layer
 */
class AdminAuthorization extends BaseAdmin
{
    private AuthorizationService $authService;

    /**
     * Inisialisasi Service via Container (Layer 0)
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Load AuthorizationService
        $this->authService = Services::authorization();
    }

    /**
     * Halaman List Role & Matrix Izin (Index)
     * GET /admin/authorization
     */
    public function index()
    {
        // 1. Ambil Semua Role yang ada di sistem
        // Result: Collection of RoleEntity / RoleResponse
        $roles = $this->authService->getAllRoles();

        // 2. Ambil Semua Permission yang terdaftar di sistem
        // Ini digunakan untuk menampilkan ringkasan hak akses di tabel
        $permissions = $this->authService->getAllAvailablePermissions();

        // 3. Render View
        return view('admin/authorization/index', [
            'title'       => 'Manajemen Peran & Izin Akses',
            'roles'       => $roles,
            'permissions' => $permissions
        ]);
    }

    /**
     * Halaman Form Tambah Role Baru
     * GET /admin/authorization/roles/new
     */
    public function createRole()
    {
        return view('admin/authorization/role_create', [
            'title' => 'Buat Peran Baru'
        ]);
    }

    /**
     * Proses Simpan Role Baru
     * POST /admin/authorization/roles
     */
    public function storeRole()
    {
        try {
            // 1. Transform Request ke DTO
            // Validasi nama role (alphanumeric, unique) terjadi di sini
            $requestDTO = CreateRoleRequest::fromRequest(
                $this->request->getPost(),
                $this->getCurrentAdminId()
            );

            // 2. Eksekusi Service
            $role = $this->authService->createRole($requestDTO);

            // 3. Redirect ke Halaman Edit Izin
            // Setelah role dibuat, admin biasanya langsung ingin setting izin-nya
            return $this->redirectSuccess(
                "/admin/authorization/roles/{$role->id}/permissions",
                "Peran \"{$role->name}\" berhasil dibuat. Silakan atur izin aksesnya."
            );

        } catch (ValidationException $e) {
            return $this->redirectBackWithInput($e->getErrors());

        } catch (\Exception $e) {
            $this->logger->error('[AdminAuth::storeRole] ' . $e->getMessage());
            return $this->redirectError('/admin/authorization/roles/new', 'Gagal membuat peran: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Halaman Edit Izin untuk Role Tertentu (Permission Matrix)
     * GET /admin/authorization/roles/{id}/permissions
     */
    public function editPermissions(int $roleId)
    {
        try {
            // 1. Ambil Detail Role
            $role = $this->authService->getRole($roleId);

            // 2. Ambil Semua Permission Grouped by Context (Product, Order, System, dll)
            // Struktur return array: ['Product' => [p1, p2], 'System' => [s1, s2]]
            $groupedPermissions = $this->authService->getGroupedPermissions();

            // 3. Ambil Izin yang SUDAH dimiliki Role ini (untuk checked state)
            $currentPermissions = $this->authService->getPermissionsByRole($roleId);
            $currentPermissionIds = array_column($currentPermissions, 'id');

            return view('admin/authorization/role_permissions', [
                'title'              => 'Atur Izin Akses: ' . $role->name,
                'role'               => $role,
                'groupedPermissions' => $groupedPermissions,
                'currentPermissions' => $currentPermissionIds,
            ]);

        } catch (NotFoundException $e) {
            return $this->redirectError('/admin/authorization', 'Peran tidak ditemukan.');
        }
    }

    /**
     * Proses Update Izin Role
     * PUT/POST /admin/authorization/roles/{id}/permissions
     */
    public function updatePermissions(int $roleId)
    {
        try {
            // 1. Ambil array permission_ids dari checkbox form
            $permissionIds = $this->request->getPost('permissions') ?? [];

            // 2. Buat DTO
            $requestDTO = new UpdateRolePermissionsRequest(
                roleId: $roleId,
                permissionIds: array_map('intval', $permissionIds),
                actorId: $this->getCurrentAdminId()
            );

            // 3. Eksekusi Service
            // Service akan menangani logic sync (hapus yang lama, insert yang baru)
            // Dan memvalidasi proteksi (misal: Super Admin permissions tidak boleh dikurangi sampai habis)
            $this->authService->syncRolePermissions($requestDTO);

            return $this->redirectSuccess(
                "/admin/authorization/roles/{$roleId}/permissions",
                "Izin akses untuk peran ini berhasil diperbarui."
            );

        } catch (DomainException $e) {
            // Error aturan bisnis (misal: Mencoba menghapus akses 'settings' dari Super Admin)
            return $this->redirectError("/admin/authorization/roles/{$roleId}/permissions", $e->getMessage());

        } catch (AuthorizationException $e) {
            return $this->redirectError('/admin/authorization', 'Anda tidak memiliki otoritas untuk mengubah peran ini.');

        } catch (\Exception $e) {
            return $this->redirectError(
                "/admin/authorization/roles/{$roleId}/permissions", 
                'Gagal memperbarui izin: ' . $e->getMessage()
            );
        }
    }

    /**
     * Hapus Role
     * DELETE /admin/authorization/roles/{id}
     */
    public function deleteRole(int $roleId)
    {
        try {
            // Cek apakah role sedang dipakai user?
            // Biasanya Service akan melempar DomainException jika role masih memiliki user aktif.
            $this->authService->deleteRole($roleId, $this->getCurrentAdminId());

            return $this->redirectSuccess('/admin/authorization', 'Peran berhasil dihapus.');

        } catch (DomainException $e) {
            // Role sedang digunakan user, atau Role System (Super Admin)
            return $this->redirectError('/admin/authorization', $e->getMessage());

        } catch (NotFoundException $e) {
            return $this->redirectError('/admin/authorization', 'Peran tidak ditemukan.');
            
        } catch (\Exception $e) {
            return $this->redirectError('/admin/authorization', 'Gagal menghapus peran: ' . $e->getMessage());
        }
    }

    /**
     * Helper: Get Current Admin ID
     */
    private function getCurrentAdminId(): int
    {
        return $this->currentAdmin ? $this->currentAdmin->getId() : 0;
    }
}
