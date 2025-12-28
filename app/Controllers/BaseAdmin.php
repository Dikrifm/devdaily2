<?php

namespace App\Controllers;

use App\Entities\Admin;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Config\Services;

/**
 * Class BaseAdmin
 *
 * Controller induk khusus untuk Halaman Admin (Web & Htmx).
 * Bertugas menjembatani hasil kerja Filter Auth dengan Controller logic.
 */
abstract class BaseAdmin extends BaseController
{
    /**
     * Entity Admin yang sedang login (Fully Hydrated).
     *
     * @var Admin|null
     */
    protected ?Admin $currentAdmin = null;

    /**
     * Inisialisasi Controller
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // 1. Hydrate Admin Data dari Filter
        // Filter 'admin-auth' dan 'admin-session' telah menempelkan data mentah ke $request->admin
        // Kita ubah menjadi Entity asli agar bisa menggunakan method bisnisnya (getInitials, dll).
        if (isset($request->admin)) {
            $this->hydrateAdminEntity($request->admin);
        }

        // 2. Share Data ke Semua View
        // Agar di layout sidebar/header kita bisa langsung pakai $currentAdmin
        if ($this->currentAdmin instanceof Admin) {
            Services::renderer()->setVar('currentAdmin', $this->currentAdmin);
        }
    }

    /**
     * Mengubah object stdClass dari Filter menjadi Admin Entity.
     * * @param object $rawAdminData
     */
    private function hydrateAdminEntity(object $rawAdminData): void
    {
        $data = (array) $rawAdminData;

        // Pastikan field mandatory ada sebelum hydrate (sesuai Admin::fromArray)
        if (isset($data['username'], $data['email'], $data['name'])) {
            try {
                $this->currentAdmin = Admin::fromArray($data);
                
                // Set ID manual karena fromArray mungkin menganggapnya optional atau string
                if (isset($data['id'])) {
                    $this->currentAdmin->setId((int)$data['id']);
                }
                
                // Jika permissions ada (dari AdminSessionCheck), kita bisa simpan juga
                // Note: Entity Admin belum tentu punya properti permissions, 
                // tapi kita bisa akses via $rawAdminData->permissions jika perlu logic otorisasi manual.
                
            } catch (\Exception $e) {
                // Fallback log jika data sesi korup
                $this->logger->error('[BaseAdmin] Gagal hydrate Admin Entity: ' . $e->getMessage());
            }
        }
    }

    // =========================================================================
    // HELPER METHODS (FLASH MESSAGES)
    // =========================================================================

    /**
     * Redirect dengan pesan Sukses standar.
     */
    protected function redirectSuccess(string $route, string $message)
    {
        return redirect()->to($route)->with('success', $message);
    }

    /**
     * Redirect dengan pesan Error standar.
     */
    protected function redirectError(string $route, string $message)
    {
        return redirect()->to($route)->with('error', $message);
    }

    /**
     * Redirect Back dengan Input dan Error (biasanya untuk ValidationException).
     */
    protected function redirectBackWithInput(array $errors = [], ?string $flashError = null)
    {
        $redirect = redirect()->back()->withInput();
        
        if (!empty($errors)) {
            // CI4 Validation Error format
            foreach ($errors as $field => $error) {
                $redirect->with('errors', $errors); 
                break; // Session flash 'errors' usually takes the whole array
            }
        }

        if ($flashError) {
            $redirect->with('error', $flashError);
        }

        return $redirect;
    }
}
