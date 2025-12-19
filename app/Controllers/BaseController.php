<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;
use CodeIgniter\Validation\Validation;
use Config\Services;
use Psr\Log\LoggerInterface;

/**
 * BaseController
 *
 * Semua controller harus extend BaseController untuk mendapatkan
 * fungsionalitas dasar dan standarisasi response.
 */
class BaseController extends Controller
{
    /**
     * Instance dari Request
     *
     * @var \CodeIgniter\HTTP\IncomingRequest
     */
    protected $request;

    /**
     * Instance dari Response
     *
     * @var \CodeIgniter\HTTP\Response
     */
    protected $response;

    /**
     * Instance dari Logger
     *
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * Session instance
     *
     * @var Session
     */
    protected $session;

    /**
     * Validation instance
     *
     * @var Validation
     */
    protected $validation;

    /**
     * Array of helpers to be loaded automatically
     *
     * @var array
     */
    protected $helpers = ['url', 'form', 'text'];

    /**
     * Data yang akan dikirim ke view
     *
     * @var array
     */
    protected $viewData = [];

    /**
     * Layout template untuk view
     *
     * @var string|null
     */
    protected $layout = 'template/default';

    /**
     * Konfigurasi CSRF protection
     *
     * @var array
     */
    protected $csrfConfig = [
        'token_name' => 'csrf_test_name',
        'header_name' => 'X-CSRF-TOKEN',
        'cookie_name' => 'csrf_cookie_name',
    ];

    /**
     * Constructor
     */
    public function __construct()
    {
        // Inisialisasi sebelum initController
        $this->session = Services::session();
        $this->validation = Services::validation();

        //parent::__construct();
    }

    /**
     * Initialize controller
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param LoggerInterface $logger
     * @return void
     */
    public function initController(
        RequestInterface $request,
        ResponseInterface $response,
        LoggerInterface $logger
    ) {
        // Do Not Edit This Line
        parent::initController($request, $response, $logger);

        // Assign properties
        $this->request = $request;
        $this->response = $response;
        $this->logger = $logger;

        // Preload common helpers
        helper($this->helpers);

        // Set default timezone
        date_default_timezone_set(config('App')->appTimezone ?? 'UTC');

        // Initialize CSRF protection
        $this->initCsrfProtection();

        // Set default view data
        $this->viewData['title'] = config('App')->appName ?? 'My Application';
        $this->viewData['site_name'] = config('App')->appName ?? 'My Application';
        $this->viewData['base_url'] = base_url();
        $this->viewData['current_url'] = current_url();
        $this->viewData['session'] = $this->session;

        // Load authentication jika ada
        if (service('auth')->isLoggedIn()) {
            $this->viewData['current_user'] = service('auth')->user();
        }
    }

    /**
     * Initialize CSRF protection
     *
     * @return void
     */
    protected function initCsrfProtection(): void
    {
        $security = Services::security();

        // Set CSRF token cookie
        if (config('Security')->csrfProtection === 'cookie') {
            $security->setHash(config('Security')->tokenRandomize ?? false)
                    ->setTokenName($this->csrfConfig['token_name'])
                    ->setHeaderName($this->csrfConfig['header_name'])
                    ->setCookieName($this->csrfConfig['cookie_name'])
                    ->setExpires(config('Security')->expires ?? 7200)
                    ->setRegenerate(config('Security')->regenerate ?? true)
                    ->setRedirect((ENVIRONMENT === 'production') || config('Security')->redirect ?? true);
        }
    }

    /**
     * Render view dengan layout
     *
     * @param string $view
     * @param array $data
     * @param bool $return
     * @return string|void
     */
    protected function render(string $view, array $data = [], bool $return = false)
    {
        // Merge dengan viewData
        $data = array_merge($this->viewData, $data);

        if ($this->layout) {
            $data['content'] = view($view, $data, ['saveData' => false]);
            $output = view($this->layout, $data, ['saveData' => false]);
        } else {
            $output = view($view, $data, ['saveData' => false]);
        }

        if ($return) {
            return $output;
        }

        echo $output;
    }

    /**
     * Set flash message
     *
     * @param string $type success|error|warning|info
     * @param string $message
     * @return void
     */
    protected function setFlash(string $type, string $message): void
    {
        $this->session->setFlashdata('flash', [
            'type' => $type,
            'message' => $message
        ]);
    }

    /**
     * Get flash message
     *
     * @return array|null
     */
    protected function getFlash(): ?array
    {
        return $this->session->getFlashdata('flash');
    }

    /**
     * Set page title
     *
     * @param string $title
     * @return self
     */
    protected function setTitle(string $title): self
    {
        $this->viewData['title'] = $title . ' - ' . $this->viewData['site_name'];
        return $this;
    }

    /**
     * Redirect dengan flash message
     *
     * @param string $route
     * @param string|null $message
     * @param string $type
     * @return \CodeIgniter\HTTP\RedirectResponse
     */
    protected function redirectWithMessage(string $route, ?string $message = null, string $type = 'success')
    {
        if ($message) {
            $this->setFlash($type, $message);
        }

        return redirect()->to($route);
    }

    /**
     * Validate request data
     *
     * @param array $rules
     * @param array $messages
     * @return bool
     */
    protected function validateRequest(array $rules, array $messages = []): bool
    {
        $this->validation->setRules($rules, $messages);

        if (!$this->validation->run($this->request->getPost() ?? [])) {
            return false;
        }

        return true;
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    protected function getValidationErrors(): array
    {
        return $this->validation->getErrors();
    }

    /**
     * Check if request is AJAX
     *
     * @return bool
     */
    protected function isAjax(): bool
    {
        return $this->request->isAJAX();
    }

    /**
     * Send JSON response
     *
     * @param mixed $data
     * @param int $statusCode
     * @return \CodeIgniter\HTTP\Response
     */
    protected function jsonResponse($data, int $statusCode = 200)
    {
        return $this->response
            ->setStatusCode($statusCode)
            ->setJSON($data);
    }

    /**
     * Send success JSON response
     *
     * @param mixed $data
     * @param string $message
     * @param int $statusCode
     * @return \CodeIgniter\HTTP\Response
     */
    protected function jsonSuccess($data = null, string $message = 'Success', int $statusCode = 200)
    {
        return $this->jsonResponse([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * Send error JSON response
     *
     * @param string $message
     * @param array $errors
     * @param int $statusCode
     * @return \CodeIgniter\HTTP\Response
     */
    protected function jsonError(string $message = 'Error', array $errors = [], int $statusCode = 400)
    {
        $response = [
            'success' => false,
            'message' => $message
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return $this->jsonResponse($response, $statusCode);
    }

    /**
     * Require authenticated user
     *
     * @param string|null $permission
     * @return mixed
     */
    protected function requireAuth(?string $permission = null)
    {
        // Cek jika user sudah login
        if (!service('auth')->isLoggedIn()) {
            return $this->redirectWithMessage('/login', 'Please login to continue', 'error');
        }

        // Cek permission jika diperlukan
        if ($permission && !service('auth')->can($permission)) {
            return $this->redirectWithMessage('/', 'You do not have permission to access this page', 'error');
        }

        return true;
    }

    /**
     * Get authenticated user ID
     *
     * @return int|null
     */
    protected function getUserId(): ?int
    {
        return service('auth')->userId();
    }

    /**
     * Get authenticated user data
     *
     * @return mixed
     */
    protected function getUser()
    {
        return service('auth')->user();
    }

    /**
     * Log activity
     *
     * @param string $action
     * @param string $description
     * @param array $context
     * @return void
     */
    protected function logActivity(string $action, string $description, array $context = []): void
    {
        $userId = $this->getUserId();
        $ipAddress = $this->request->getIPAddress();
        $userAgent = $this->request->getUserAgent()->getAgentString();

        log_message('info', "Activity: {$action} - {$description}", [
            'user_id' => $userId,
            'ip' => $ipAddress,
            'user_agent' => $userAgent,
            'context' => $context
        ]);
    }

    /**
     * Handle exception secara graceful
     *
     * @param \Throwable $e
     * @param string $message
     * @return mixed
     */
    protected function handleException(\Throwable $e, string $message = 'An error occurred')
    {
        // Log error
        log_message('error', $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isAjax()) {
            return $this->jsonError($message, [
                'error' => ENVIRONMENT === 'development' ? $e->getMessage() : null
            ], 500);
        }

        $this->setFlash('error', $message);
        return $this->redirectWithMessage('/', $message, 'error');
    }

    /**
     * Get pagination parameters dari request
     *
     * @return array
     */
    protected function getPaginationParams(): array
    {
        return [
            'page' => (int) $this->request->getGet('page') ?? 1,
            'per_page' => (int) $this->request->getGet('per_page') ?? 20,
            'search' => $this->request->getGet('search') ?? null,
            'sort' => $this->request->getGet('sort') ?? 'id',
            'order' => $this->request->getGet('order') ?? 'desc'
        ];
    }

    /**
     * Generate pagination links
     *
     * @param int $total
     * @param int $perPage
     * @param int $currentPage
     * @param string $baseUrl
     * @return array
     */
    protected function generatePagination(int $total, int $perPage, int $currentPage, string $baseUrl): array
    {
        $totalPages = ceil($total / $perPage);

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'first_page' => 1,
            'last_page' => $totalPages,
            'next_page' => $currentPage < $totalPages ? $currentPage + 1 : null,
            'prev_page' => $currentPage > 1 ? $currentPage - 1 : null,
            'links' => [
                'first' => $baseUrl . '?page=1',
                'last' => $baseUrl . '?page=' . $totalPages,
                'next' => $currentPage < $totalPages ? $baseUrl . '?page=' . ($currentPage + 1) : null,
                'prev' => $currentPage > 1 ? $baseUrl . '?page=' . ($currentPage - 1) : null
            ]
        ];
    }

    /**
     * Get request data dengan sanitization
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    protected function getInput(?string $key = null, $default = null)
    {
        $method = $this->request->getMethod();

        if ($method === 'GET') {
            $data = $this->request->getGet();
        } else {
            $data = $this->request->getPost();
        }

        if ($key === null) {
            return $data;
        }

        $value = $data[$key] ?? $default;

        // Sanitize input
        if (is_string($value)) {
            $value = trim($value);
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        return $value;
    }

    /**
     * Check if user has role
     *
     * @param string $role
     * @return bool
     */
    protected function hasRole(string $role): bool
    {
        return service('auth')->hasRole($role);
    }

    /**
     * Check if user has permission
     *
     * @param string $permission
     * @return bool
     */
    protected function can(string $permission): bool
    {
        return service('auth')->can($permission);
    }
}
