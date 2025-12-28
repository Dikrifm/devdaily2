<?php

namespace App\Controllers\Htmx;

use App\Controllers\BaseAdmin;
use App\Exceptions\ValidationException;
use App\Services\LinkService;
use Config\Services;

/**
 * Class Link
 *
 * Controller HTMX untuk interaksi dinamis pada modul Link Afiliasi.
 * * Layer 4: The Plug & Play Layer (HTMX Context)
 *
 * Menangani:
 * 1. Validasi URL Real-time (saat user paste/ketik)
 * 2. Pengecekan Kesehatan Link (Health Check) on-demand
 */
class Link extends BaseAdmin
{
    private LinkService $linkService;

    /**
     * Inisialisasi Service
     */
    public function initController(\CodeIgniter\HTTP\RequestInterface $request, \CodeIgniter\HTTP\ResponseInterface $response, \Psr\Log\LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
        
        // Load LinkService
        $this->linkService = Services::link();
    }

    /**
     * Validasi URL Afiliasi (Real-time)
     * POST /htmx/links/validate-url
     *
     * Request Body:
     * - url: string
     * - marketplace_id: int (opsional, untuk validasi domain spesifik)
     */
    public function validateUrl()
    {
        try {
            $url = (string) $this->request->getPost('url');
            $marketplaceId = $this->request->getPost('marketplace_id');
            
            if (empty($url)) {
                return $this->response->setBody(''); // Reset validasi jika kosong
            }

            // Panggil Service untuk validasi logika bisnis
            // validateUrl mengembalikan array ['is_valid' => bool, 'message' => string, 'normalized_url' => string]
            $result = $this->linkService->validateUrl($url, $marketplaceId ? (int)$marketplaceId : null);

            if ($result['is_valid']) {
                // Render feedback sukses (Icon Ceklis + Pesan)
                $html = <<<HTML
                    <div class="mt-1 text-sm text-green-600 flex items-center gap-1">
                        <i class="fas fa-check-circle"></i>
                        <span>URL Valid: {$result['message']}</span>
                    </div>
HTML;
                // Jika URL dinormalisasi (dibersihkan), kita juga bisa update input value via OOB swap
                // tapi untuk UX sederhana, cukup beri feedback visual.
                return $this->response->setBody($html);
            } else {
                // Render feedback error
                $html = <<<HTML
                    <div class="mt-1 text-sm text-red-500 flex items-center gap-1">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>{$result['message']}</span>
                    </div>
HTML;
                return $this->response->setBody($html);
            }

        } catch (\Exception $e) {
            $this->logger->error('[HtmxLink::validateUrl] ' . $e->getMessage());
            return $this->response->setStatusCode(200)->setBody('<div class="text-red-500 text-xs">Gagal memvalidasi URL</div>');
        }
    }

    /**
     * Cek Kesehatan Link (Manual Trigger)
     * POST /htmx/links/{id}/check
     * * Digunakan di tabel list link untuk tombol "Cek Sekarang"
     */
    public function check(int $id)
    {
        try {
            // Lakukan pengecekan real (biasanya cURL ke URL target)
            $health = $this->linkService->checkLinkHealth($id);
            
            // Siapkan UI berdasarkan status
            $statusColor = $health['is_accessible'] ? 'text-green-600' : 'text-red-600';
            $icon = $health['is_accessible'] ? 'fa-check' : 'fa-times';
            $text = $health['is_accessible'] ? 'Aktif' : 'Mati';
            
            // Render tombol/badge status yang baru
            $html = <<<HTML
                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 {$statusColor}">
                    <i class="fas {$icon}"></i> {$text}
                </span>
                <div class="text-[10px] text-gray-400 mt-1">Dicek: Barusan</div>
HTML;

            return $this->response->setBody($html);

        } catch (\Exception $e) {
            return $this->response->setBody('<span class="text-red-500 text-xs">Error</span>');
        }
    }
}
