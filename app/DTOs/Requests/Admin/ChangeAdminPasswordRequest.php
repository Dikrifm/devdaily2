<?php

namespace App\DTOs\Requests\Admin;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Class ChangeAdminPasswordRequest
 *
 * DTO (Layer 6) untuk menangani perubahan password.
 * Digunakan oleh AdminService::changePassword()
 */
class ChangeAdminPasswordRequest
{
    public int $adminId;
    public string $newPassword;
    
    /**
     * @var string|null Password saat ini (Wajib jika user mengganti passwordnya sendiri)
     */
    public ?string $currentPassword;

    public function __construct(int $adminId, string $newPassword, ?string $currentPassword = null)
    {
        $this->adminId = $adminId;
        $this->newPassword = $newPassword;
        $this->currentPassword = $currentPassword;
    }

    /**
     * Factory method
     *
     * @param int $adminId ID target admin yang akan diganti passwordnya
     * @param RequestInterface $request
     * @return self
     */
    public static function fromRequest(int $adminId, RequestInterface $request): self
    {
        // Mengambil JSON body atau POST data
        $data = $request->getJSON(true) ?? $request->getPost();

        return new self(
            $adminId,
            (string) ($data['new_password'] ?? $data['password'] ?? ''),
            isset($data['current_password']) ? (string) $data['current_password'] : null
        );
    }

    /**
     * Konversi ke Array untuk Audit Log
     * PENTING: Password wajib disensor (Redacted)
     */
    public function toArray(): array
    {
        return [
            'admin_id' => $this->adminId,
            'current_password' => $this->currentPassword ? '***REDACTED***' : null,
            'new_password' => '***REDACTED***'
        ];
    }
}
