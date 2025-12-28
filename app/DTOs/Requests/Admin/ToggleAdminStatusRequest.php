<?php

namespace App\DTOs\Requests\Admin;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Class ToggleAdminStatusRequest
 *
 * DTO (Layer 6) untuk menangani aktivasi/deaktivasi akun Admin.
 * Digunakan oleh AdminService::toggleStatus()
 * * Referensi penggunaan di Service:
 * - AdminService baris 134-142 (Single toggle)
 * - AdminService baris 216 & 221 (Bulk operations)
 */
class ToggleAdminStatusRequest
{
    public int $adminId;
    public bool $active;
    public ?string $reason;

    public function __construct(int $adminId, bool $active, ?string $reason = null)
    {
        $this->adminId = $adminId;
        $this->active = $active;
        $this->reason = $reason ? trim($reason) : null;
    }

    /**
     * Factory method
     *
     * @param int $adminId ID target admin
     * @param RequestInterface $request
     * @return self
     */
    public static function fromRequest(int $adminId, RequestInterface $request): self
    {
        // Mengambil JSON body atau POST data
        $data = $request->getJSON(true) ?? $request->getPost();

        // Status aktif bisa dikirim eksplisit, atau di-infer dari endpoint controller
        // Default ke true jika tidak ada (hati-hati, controller harus handle ini)
        $isActive = isset($data['active']) 
            ? filter_var($data['active'], FILTER_VALIDATE_BOOLEAN) 
            : false; 

        return new self(
            $adminId,
            $isActive,
            $data['reason'] ?? null
        );
    }

    /**
     * Helper untuk membuat request secara manual (berguna untuk Bulk Action di Service)
     * Lihat AdminService baris 216 & 221
     */
    public static function create(int $adminId, bool $active, ?string $reason = null): self
    {
        return new self($adminId, $active, $reason);
    }

    public function toArray(): array
    {
        return [
            'admin_id' => $this->adminId,
            'active'   => $this->active,
            'reason'   => $this->reason
        ];
    }
}
