<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cors;
use CodeIgniter\HTTP\Response;

class CorsFilter implements FilterInterface
{
    /**
     * Konfigurasi CORS
     * 
     * @var Cors
     */
    private $corsConfig;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->corsConfig = config('Cors');
    }
    
    /**
     * Sebelum request diproses
     * 
     * @param RequestInterface $request
     * @param array|null $arguments
     * @return RequestInterface|ResponseInterface|null
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Jika CORS tidak diaktifkan, skip
        if (!$this->corsConfig->enabled) {
            return;
        }
        
        $origin = $request->getHeaderLine('Origin');
        
        // Tangani preflight request (OPTIONS)
        if ($request->is('options')) {
            return $this->handlePreflightRequest($request, $origin);
        }
        
        // Untuk request biasa, set CORS headers nanti di after()
        return $request;
    }
    
    /**
     * Setelah request diproses
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array|null $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Jika CORS tidak diaktifkan, return response asli
        if (!$this->corsConfig->enabled) {
            return $response;
        }
        
        $origin = $request->getHeaderLine('Origin');
        
        // Jika tidak ada Origin header, bukan CORS request
        if (empty($origin)) {
            return $response;
        }
        
        // Validasi origin
        if (!$this->corsConfig->isOriginAllowed($origin)) {
            // Log violation jika diaktifkan
            if ($this->corsConfig->logViolations) {
                log_message('warning', "CORS violation: Origin {$origin} not allowed for {$request->getMethod()} {$request->getUri()}");
            }
            
            // Tetap return response tanpa header CORS
            return $response;
        }
        
        // Set CORS headers
        $headers = $this->corsConfig->generateHeaders($origin);
        
        foreach ($headers as $header => $value) {
            $response->setHeader($header, $value);
        }
        
        // Tambahkan exposed headers jika ada
        if (!empty($this->corsConfig->exposedHeaders)) {
            $response->setHeader('Access-Control-Expose-Headers', implode(', ', $this->corsConfig->exposedHeaders));
        }
        
        return $response;
    }
    
    /**
     * Handle preflight OPTIONS request
     * 
     * @param RequestInterface $request
     * @param string $origin
     * @return ResponseInterface
     */
    private function handlePreflightRequest(RequestInterface $request, string $origin): ResponseInterface
    {
        $response = service('response');
        
        // Jika tidak ada Origin, bukan CORS preflight
        if (empty($origin)) {
            return $response->setStatusCode(400)->setJSON([
                'error' => 'INVALID_PREFLIGHT',
                'message' => 'Missing Origin header'
            ]);
        }
        
        // Validasi origin
        if (!$this->corsConfig->isOriginAllowed($origin)) {
            // Log violation
            if ($this->corsConfig->logViolations) {
                log_message('warning', "CORS preflight violation: Origin {$origin} not allowed");
            }
            
            return $response->setStatusCode(403)->setJSON([
                'error' => 'CORS_ORIGIN_NOT_ALLOWED',
                'message' => 'Origin not allowed by CORS policy'
            ]);
        }
        
        // Ambil requested method dan headers
        $requestMethod = $request->getHeaderLine('Access-Control-Request-Method');
        $requestHeaders = $request->getHeaderLine('Access-Control-Request-Headers');
        
        // Validasi requested method
        if ($requestMethod && !in_array(strtoupper($requestMethod), $this->corsConfig->allowedMethods, true)) {
            return $response->setStatusCode(405)->setJSON([
                'error' => 'CORS_METHOD_NOT_ALLOWED',
                'message' => "Method {$requestMethod} not allowed by CORS policy"
            ]);
        }
        
        // Set preflight headers
        $headers = $this->corsConfig->generatePreflightHeaders($origin);
        
        foreach ($headers as $header => $value) {
            $response->setHeader($header, $value);
        }
        
        // Set Vary header untuk caching
        $response->setHeader('Vary', 'Origin');
        
        // Return empty response dengan 204 No Content
        return $response->setStatusCode(204);
    }
    
    /**
     * Validasi apakah request method diizinkan
     * 
     * @param string $method
     * @return bool
     */
    private function isMethodAllowed(string $method): bool
    {
        return in_array(strtoupper($method), $this->corsConfig->allowedMethods, true);
    }
    
    /**
     * Parse Access-Control-Request-Headers header
     * 
     * @param string $headersString
     * @return array
     */
    private function parseRequestHeaders(string $headersString): array
    {
        if (empty($headersString)) {
            return [];
        }
        
        return array_map('trim', explode(',', $headersString));
    }
    
    /**
     * Validasi apakah requested headers diizinkan
     * 
     * @param array $requestedHeaders
     * @return bool
     */
    private function areHeadersAllowed(array $requestedHeaders): bool
    {
        foreach ($requestedHeaders as $header) {
            $header = trim($header);
            $allowed = false;
            
            foreach ($this->corsConfig->allowedHeaders as $allowedHeader) {
                // Case-insensitive comparison
                if (strcasecmp($header, $allowedHeader) === 0) {
                    $allowed = true;
                    break;
                }
            }
            
            if (!$allowed) {
                return false;
            }
        }
        
        return true;
    }
}