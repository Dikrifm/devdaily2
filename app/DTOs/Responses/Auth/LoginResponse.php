<?php

namespace App\DTOs\Responses\Auth;

use App\DTOs\Responses\AdminResponse;

/**
 * Class LoginResponse
 *
 * DTO (Layer 6) yang membungkus hasil dari proses login.
 * DTO ini unik karena menangani dua kemungkinan hasil:
 * 1. Sukses: Mengembalikan Data Admin + Session (Token).
 * 2. 2FA Required: Mengembalikan Data Parsial + Instruksi 2FA.
 *
 * Referensi penggunaan di AuthService:
 * - createSuccess: baris 138, 160
 * - createForTwoFactorRequired: baris 128
 */
class LoginResponse
{
    /**
     * @var bool Apakah login berhasil sepenuhnya atau butuh langkah tambahan
     */
    private bool $success;

    /**
     * @var bool Apakah user perlu memasukkan kode 2FA
     */
    private bool $requiresTwoFactor;

    /**
     * @var AdminResponse|null Data Admin (Hanya ada jika sukses)
     */
    private ?AdminResponse $admin = null;

    /**
     * @var SessionResponse|null Data Session/Token (Hanya ada jika sukses)
     */
    private ?SessionResponse $session = null;

    /**
     * @var array|null Data konteks untuk 2FA (Hanya ada jika butuh 2FA)
     */
    private ?array $twoFactorData = null;

    /**
     * Constructor private untuk memaksa penggunaan Factory Method
     */
    private function __construct() {}

    /**
     * Factory Method untuk Login Berhasil
     * Digunakan di AuthService baris 138, 160
     *
     * @param AdminResponse $admin
     * @param SessionResponse $session
     * @param bool $requiresTwoFactor (Biasanya false di sini, kecuali logic khusus)
     * @return self
     */
    public static function createSuccess(AdminResponse $admin, SessionResponse $session, bool $requiresTwoFactor = false): self
    {
        $response = new self();
        $response->success = true;
        $response->admin = $admin;
        $response->session = $session;
        $response->requiresTwoFactor = $requiresTwoFactor;

        return $response;
    }

    /**
     * Factory Method untuk Login yang Membutuhkan 2FA
     * Digunakan di AuthService baris 128
     *
     * @param int $adminId
     * @param int $riskScore
     * @param array $factors Faktor penyebab 2FA (misal: 'New Device', 'High Risk IP')
     * @return self
     */
    public static function createForTwoFactorRequired(int $adminId, int $riskScore, array $factors): self
    {
        $response = new self();
        $response->success = false; // Belum sukses sepenuhnya
        $response->requiresTwoFactor = true;
        
        $response->twoFactorData = [
            'temp_user_id' => $adminId, // ID sementara untuk verifikasi langkah kedua
            'risk_score'   => $riskScore,
            'reasons'      => $factors
        ];

        return $response;
    }

    /**
     * Cek apakah butuh 2FA
     */
    public function requiresTwoFactor(): bool
    {
        return $this->requiresTwoFactor;
    }

    /**
     * Ambil Data Admin (Getter)
     */
    public function getAdmin(): ?AdminResponse
    {
        return $this->admin;
    }

    /**
     * Ambil Data Session (Getter)
     */
    public function getSession(): ?SessionResponse
    {
        return $this->session;
    }

    /**
     * Ambil Data 2FA (Getter)
     */
    public function getTwoFactorFactors(): array
    {
        return $this->twoFactorData['reasons'] ?? [];
    }

    /**
     * Ambil ID Admin Sementara (untuk form 2FA)
     */
    public function getAdminId(): int
    {
        if ($this->admin) {
            return $this->admin->getId();
        }
        
        return $this->twoFactorData['temp_user_id'] ?? 0;
    }

    /**
     * Konversi ke Array untuk Respon API
     * Memudahkan Controller me-return JSON
     *
     * @return array
     */
    public function toArray(): array
    {
        if ($this->requiresTwoFactor) {
            return [
                'status' => '2fa_required',
                'message' => 'Autentikasi dua faktor diperlukan.',
                'data' => [
                    'verification_context' => $this->twoFactorData
                ]
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Login berhasil.',
            'data' => [
                'user' => $this->admin ? $this->admin->toArray() : null,
                'token' => $this->session ? $this->session->accessToken : null, // Mengambil token dari SessionResponse
                'refresh_token' => $this->session ? $this->session->refreshToken : null,
                'expires_at' => $this->session ? $this->session->expiresAt : null,
            ]
        ];
    }
}
