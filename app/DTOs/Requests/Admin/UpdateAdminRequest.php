<?php

namespace App\DTOs\Requests\Admin;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Class UpdateAdminRequest
 *
 * DTO (Layer 6) untuk menampung data pembaruan Admin.
 * Mendukung partial update (hanya field yang dikirim yang akan diupdate).
 * Digunakan oleh AdminService::updateAdmin()
 */
class UpdateAdminRequest
{
    public int $id;
    public ?string $username = null;
    public ?string $email    = null;
    public ?string $name     = null;
    public ?string $role     = null;
    public ?bool $active     = null;

    /**
     * @var array Menyimpan daftar field yang benar-benar dikirim di request
     * Berguna untuk membedakan antara "nilai null" dan "tidak dikirim"
     */
    private array $presentFields = [];

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    /**
     * Factory method
     * * @param int $id ID Admin yang akan diedit (biasanya dari URL segment)
     * @param RequestInterface $request
     * @return self
     */
    public static function fromRequest(int $id, RequestInterface $request): self
    {
        $dto = new self($id);
        
        // Mengambil JSON body atau POST data
        $data = $request->getJSON(true) ?? $request->getPost();

        if (isset($data['username'])) {
            $dto->username = trim($data['username']);
            $dto->presentFields['username'] = true;
        }

        if (isset($data['email'])) {
            $dto->email = strtolower(trim($data['email']));
            $dto->presentFields['email'] = true;
        }

        if (isset($data['name'])) {
            $dto->name = trim($data['name']);
            $dto->presentFields['name'] = true;
        }

        if (isset($data['role'])) {
            $dto->role = $data['role'];
            $dto->presentFields['role'] = true;
        }

        if (isset($data['active'])) {
            $dto->active = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
            $dto->presentFields['active'] = true;
        }

        return $dto;
    }

    /**
     * Mengecek apakah field tertentu ada dalam request pembaruan
     * Digunakan secara intensif oleh AdminService (baris 110, 113, 116, dst)
     */
    public function has(string $field): bool
    {
        return isset($this->presentFields[$field]);
    }

    public function toArray(): array
    {
        // Hanya mengembalikan field yang diset
        $data = ['id' => $this->id];
        
        foreach ($this->presentFields as $field => $present) {
            $data[$field] = $this->$field;
        }

        return $data;
    }
}
