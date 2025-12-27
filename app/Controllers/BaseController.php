<?php

namespace App\Controllers;

use App\DTOs\BaseDTO;
use App\Exceptions\AuthorizationException;
use App\Exceptions\ValidationException;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use Psr\Log\LoggerInterface;

/**
 * BASE CONTROLLER - PURE THIN CONTROLLER
 * 
 * PRINSIP: "Controller adalah 1:1 adapter antara HTTP dan Application Layer"
 * - HANYA menerima request
 * - HANYA memanggil Service
 * - HANYA mengembalikan response
 * - TIDAK ADA business logic
 * - TIDAK ADA validation logic
 * - TIDAK ADA data transformation
 * 
 * PROTOTYPE: Request → [Controller] → Service → Response
 * 
 * @package App\Controllers
 */
abstract class BaseController extends Controller
{
    // ==================================================================
    // CORE DEPENDENCIES (DI dari CI4 Container)
    // ==================================================================
    
    /**
     * @var IncomingRequest
     */
    protected $request;
    
    /**
     * @var ResponseInterface
     */
    protected $response;
    
    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * Request ID untuk tracing
     */
    protected string $requestId;
    
    // ==================================================================
    // CONTEXT-SPECIFIC SERVICES (DI via child constructor)
    // ==================================================================
    
    /**
     * Service hanya di-inject di child class yang membutuhkan
     * Contoh: ProductController akan inject ProductService
     */
    
    // ==================================================================
    // INITIALIZATION - CI4 LIFECYCLE
    // ==================================================================
    
    /**
     * Constructor - KOSONG (DI via child constructor)
     */
    public function __construct()
    {
        // Dependency injection dilakukan di child class
        // Sesuai prinsip: "Inject what you need"
    }
    
    /**
     * Initialize controller - CI4 lifecycle
     */
    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;
        
        // Generate request ID
        $this->requestId = $this->generateRequestId();
        
        // Log initialization
        $this->logInitialization();
    }
    
    // ==================================================================
    // PROTOKOL 1: REQUEST INGESTION & VALIDATION
    // ==================================================================
    
    /**
     * Extract raw input dari request
     * HANYA mengambil data, TIDAK validasi
     * 
     * @return array Raw input data
     */
    protected function extractInput(): array
    {
        $method = $this->request->getMethod();
        
        switch ($method) {
            case 'GET':
                return $this->request->getGet() ?? [];
                
            case 'POST':
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                // Priority: JSON > Form > Raw
                if ($this->isJsonRequest()) {
                    $jsonData = json_decode($this->request->getBody(), true);
                    return is_array($jsonData) ? $jsonData : [];
                }
                
                return $this->request->getPost() ?? [];
                
            default:
                return [];
        }
    }
    
    /**
     * Create DTO dari request input
     * DTO bertanggung jawab untuk validasi format
     * 
     * @template T of BaseDTO
     * @param class-string<T> $dtoClass
     * @param array $context Additional context data
     * @return T
     * @throws ValidationException
     */
    protected function createRequestDto(string $dtoClass, array $context = []): BaseDTO
    {
        $input = $this->extractInput();
        
        // Merge dengan context (route params, etc)
        $data = array_merge($input, $context);
        
        // Delegate ke DTO factory method
        if (method_exists($dtoClass, 'fromRequest')) {
            return $dtoClass::fromRequest($data);
        }
        
        // Fallback ke fromArray
        return $dtoClass::fromArray($data);
    }
    
    /**
     * Create Query DTO untuk list operations
     * 
     * @template T of BaseDTO
     * @param class-string<T> $queryDtoClass
     * @param array $defaults Default values
     * @return T
     */
    protected function createQueryDto(string $queryDtoClass, array $defaults = []): BaseDTO
    {
        $input = $this->request->getGet() ?? [];
        $data = array_merge($defaults, $input);
        
        if (method_exists($queryDtoClass, 'fromRequest')) {
            return $queryDtoClass::fromRequest($data);
        }
        
        return $queryDtoClass::fromArray($data);
    }
    
    /**
     * Check if request is JSON
     */
    protected function isJsonRequest(): bool
    {
        $contentType = $this->request->getHeaderLine('Content-Type');
        return strpos($contentType, 'application/json') !== false;
    }
    
    // ==================================================================
    // PROTOKOL 2: AUTHORIZATION & AUTHENTICATION
    // ==================================================================
    
     /**
     * Get authenticated user dari Security Pipeline
     * Sinkron dengan AdminAuth Filter ($request->admin)
     * * @return array|null
     */
    protected function getAuthenticatedUser(): ?array
    {
        // Cek properti yang di-inject oleh Filter AdminAuth
        if (isset($this->request->admin)) {
            return (array) $this->request->admin;
        }

        // Cek properti user biasa (jika nanti ada)
        if (isset($this->request->user)) {
            return (array) $this->request->user;
        }

        return null;
    }

    
    /**
     * Get current user ID
     */
    protected function getCurrentUserId(): ?int
    {
        $user = $this->getAuthenticatedUser();
        return $user['id'] ?? null;
    }
    
    /**
     * Get current user role
     */
    protected function getCurrentUserRole(): ?string
    {
        $user = $this->getAuthenticatedUser();
        return $user['role'] ?? null;
    }
    
    /**
     * Require authentication - throw jika tidak authenticated
     * 
     * @throws AuthorizationException
     */
    protected function requireAuthentication(): void
    {
        if (!$this->getAuthenticatedUser()) {
            throw AuthorizationException::forAdminAccess();
        }
    }
    
    /**
     * Require specific role - throw jika tidak authorized
     * 
     * @param string|array $roles
     * @throws AuthorizationException
     */
    protected function requireRole($roles): void
    {
        $this->requireAuthentication();
        
        $currentRole = $this->getCurrentUserRole();
        $requiredRoles = is_array($roles) ? $roles : [$roles];
        
        if (!in_array($currentRole, $requiredRoles, true)) {
            throw AuthorizationException::forRole($currentRole, $requiredRoles);
        }
    }
    
    // ==================================================================
    // PROTOKOL 5: RESPONSE FORMATION
    // ==================================================================
    
    /**
     * Format success response (API Context)
     * 
     * @param mixed $data Response DTO atau array
     * @param string $message
     * @param int $code
     * @param array $meta
     */
    protected function respondSuccess(
        $data = null,
        string $message = 'Success',
        int $code = 200,
        array $meta = []
    ): ResponseInterface {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge($this->buildMeta(), $meta),
            'timestamp' => date('c'),
        ];
        
        return $this->response
            ->setStatusCode($code)
            ->setJSON($response);
    }
    
    /**
     * Format error response (API Context)
     * 
     * @param string $message
     * @param int $code
     * @param array $errors
     */
    protected function respondError(
        string $message = 'An error occurred',
        int $code = 500,
        array $errors = []
    ): ResponseInterface {
        $response = [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'meta' => $this->buildMeta(),
            'timestamp' => date('c'),
        ];
        
        return $this->response
            ->setStatusCode($code)
            ->setJSON($response);
    }
    
    /**
     * Format validation error response
     * 
     * @param ValidationException $exception
     */
    protected function respondValidationError(ValidationException $exception): ResponseInterface
    {
        return $this->respondError(
            $exception->getMessage(),
            400,
            $exception->getErrors()
        );
    }
    
    /**
     * Format not found response
     * 
     * @param string $message
     * @param string|null $resource
     */
    protected function respondNotFound(
        string $message = 'Resource not found',
        ?string $resource = null
    ): ResponseInterface {
        $errors = $resource ? ["{$resource} not found"] : [];
        
        return $this->respondError($message, 404, $errors);
    }
    
    /**
     * Format unauthorized response
     * 
     * @param AuthorizationException $exception
     */
    protected function respondUnauthorized(AuthorizationException $exception): ResponseInterface
    {
        return $this->respondError($exception->getMessage(), 401);
    }
    
    /**
     * Format forbidden response
     * 
     * @param AuthorizationException $exception
     */
    protected function respondForbidden(AuthorizationException $exception): ResponseInterface
    {
        return $this->respondError($exception->getMessage(), 403);
    }
    
    /**
     * Format created response (201)
     * 
     * @param mixed $data
     * @param string|null $location
     */
    protected function respondCreated($data = null, ?string $location = null): ResponseInterface
    {
        $response = $this->respondSuccess($data, 'Resource created', 201);
        
        if ($location) {
            $response->setHeader('Location', $location);
        }
        
        return $response;
    }
    
    /**
     * Format paginated response
     * 
     * @param array $data
     * @param int $total
     * @param int $page
     * @param int $perPage
     */
    protected function respondPaginated(
        array $data,
        int $total,
        int $page,
        int $perPage
    ): ResponseInterface {
        $totalPages = ceil($total / $perPage);
        
        $meta = [
            'pagination' => [
                'total' => $total,
                'count' => count($data),
                'per_page' => $perPage,
                'current_page' => $page,
                'total_pages' => $totalPages,
                'links' => $this->buildPaginationLinks($page, $totalPages),
            ],
        ];
        
        return $this->respondSuccess($data, 'Data retrieved', 200, $meta);
    }
    
    /**
     * Render HTML view (Web Context)
     * 
     * @param string $view
     * @param array $data
     */
    protected function renderView(string $view, array $data = []): string
    {
        // Tambahkan global data
        $data['_user'] = $this->getAuthenticatedUser();
        $data['_requestId'] = $this->requestId;
        $data['_csrf'] = csrf_hash();
        
        return view($view, $data);
    }
    
    /**
     * Render HTMX partial (HTMX Context)
     * 
     * @param string $partial
     * @param array $data
     */
    protected function renderPartial(string $partial, array $data = []): string
    {
        // Hanya render partial tanpa layout
        $data['_user'] = $this->getAuthenticatedUser();
        
        return view("partials/{$partial}", $data);
    }
    
    // ==================================================================
    // PROTOKOL 6: ERROR HANDLING
    // ==================================================================
    
    /**
     * Handle exception secara konsisten
     * 
     * @param \Throwable $exception
     */
    protected function handleException(\Throwable $exception): ResponseInterface
    {
        // Log berdasarkan exception type
        $this->logException($exception);
        
        // Format response berdasarkan exception type
        if ($exception instanceof ValidationException) {
            return $this->respondValidationError($exception);
        }
        
        if ($exception instanceof AuthorizationException) {
            $code = $exception->getCode();
            return $code === 401 
                ? $this->respondUnauthorized($exception)
                : $this->respondForbidden($exception);
        }
        
        if ($exception->getCode() === 404) {
            return $this->respondNotFound($exception->getMessage());
        }
        
        // Default error response
        $message = ENVIRONMENT === 'production'
            ? 'An error occurred. Please try again later.'
            : $exception->getMessage();
        
        return $this->respondError($message, $exception->getCode() ?: 500);
    }
    
    // ==================================================================
    // UTILITY METHODS
    // ==================================================================
    
    /**
     * Generate unique request ID
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true) . '_' . bin2hex(random_bytes(4));
    }
    
    /**
     * Build meta data untuk response
     */
    private function buildMeta(): array
    {
        return [
            'request_id' => $this->requestId,
            'timestamp' => time(),
            'version' => '1.0',
        ];
    }
    
    /**
     * Build pagination links
     */
    private function buildPaginationLinks(int $currentPage, int $totalPages): array
    {
        $links = [];
        $baseUrl = current_url();
        
        if ($currentPage > 1) {
            $links['first'] = $baseUrl . '?page=1';
            $links['prev'] = $baseUrl . '?page=' . ($currentPage - 1);
        }
        
        $links['current'] = $baseUrl . '?page=' . $currentPage;
        
        if ($currentPage < $totalPages) {
            $links['next'] = $baseUrl . '?page=' . ($currentPage + 1);
            $links['last'] = $baseUrl . '?page=' . $totalPages;
        }
        
        return $links;
    }
    
    // ==================================================================
    // LOGGING
    // ==================================================================
    
    /**
     * Log controller initialization
     */
    private function logInitialization(): void
    {
        $this->logger->info('Controller initialized', [
            'controller' => static::class,
            'method' => $this->request->getMethod(),
            'uri' => $this->request->getUri()->getPath(),
            'request_id' => $this->requestId,
            'user_id' => $this->getCurrentUserId(),
        ]);
    }
    
    /**
     * Log exception
     */
    private function logException(\Throwable $exception): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'request_id' => $this->requestId,
            'user_id' => $this->getCurrentUserId(),
            'uri' => $this->request->getUri()->getPath(),
            'method' => $this->request->getMethod(),
        ];
        
        // Log level berdasarkan exception type
        if ($exception instanceof ValidationException) {
            $this->logger->warning('Validation failed', $context);
        } elseif ($exception instanceof AuthorizationException) {
            $this->logger->notice('Authorization failed', $context);
        } else {
            $this->logger->error('Unhandled exception', $context);
        }
    }
}