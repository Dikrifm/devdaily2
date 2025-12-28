<?php

namespace App\DTOs\Requests\Admin;

use CodeIgniter\HTTP\RequestInterface;

/**
 * Class CreateAdminRequest
 *
 * DTO (Layer 6) untuk menampung data pembuatan Admin baru.
 * Digunakan oleh AdminService::createAdmin()
 */
class CreateAdminRequest
{
    public string $username;
    public string $email;
    public string $password;
    public string $name;
    public string $role;
    public bool $active;

    public function __construct(
        string $username,
        string $email,
        string $password,
        string $name,
        string $role = 'admin',
        bool $active = true
    ) {
        $this->username = trim($username);
        $this->email    = strtolower(trim($email));
        $this->password = $password; // Password raw, hashing dilakukan di Service
        $this->name     = trim($name);
        $this->role     = $role;
        $this->active   = $active;
    }

    /**
     * Factory method untuk membuat instance dari HTTP Request
     *
     * @param RequestInterface $request
     * @return self
     */
    public static function fromRequest(RequestInterface $request): self
    {
        // Mengambil JSON body jika request API, atau POST data jika Form biasa
        $data = $request->getJSON(true) ?? $request->getPost();

        return new self(
            (string) ($data['username'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['role'] ?? 'admin'),
            filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN)
        );
    }

    /**
     * Konversi ke Array (untuk Audit Log)
     * Password disensor agar tidak masuk log
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'email'    => $this->email,
            'name'     => $this->name,
            'role'     => $this->role,
            'active'   => $this->active,
            'password' => '***REDACTED***'
        ];
    }
}
