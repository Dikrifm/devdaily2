<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\AuditService;
use App\Services\PaginationService;
use App\Services\ResponseFormatter;
use App\Services\TransactionService;
use App\Services\ValidationService;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class BaseApi extends BaseController
{
    use ResponseTrait;

    /**
     * Response formatter service
     *
     * @var ResponseFormatter|null
     */
    protected $responseFormatter;

    /**
     * Pagination service
     *
     * @var PaginationService|null
     */
    protected $paginationService;

    /**
     * Validation service
     *
     * @var ValidationService|null
     */
    protected $validationService;

    /**
     * Transaction service
     *
     * @var TransactionService|null
     */
    protected $transactionService;

    /**
     * Audit service
     *
     * @var AuditService|null
     */
    protected $auditService;

    /**
     * Validation rules for this controller
     *
     * @var array
     */
    protected $validationRules = [];

    /**
     * Validation messages for this controller
     *
     * @var array
     */
    protected $validationMessages = [];

    /**
     * Resource name for API responses
     *
     * @var string
     */
    protected $resourceName = '';

    /**
     * Model class for this resource
     *
     * @var string|null
     */
    protected $modelClass;

    /**
     * Service class for this resource
     *
     * @var string|null
     */
    protected $serviceClass;

    /**
     * Initialize controller
     *
     * @param \CodeIgniter\HTTP\RequestInterface $request
     * @param \CodeIgniter\HTTP\ResponseInterface $response
     * @param \Psr\Log\LoggerInterface $logger
     * @return void
     */
    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);

        // Initialize services dengan fallback
        $this->responseFormatter = service('responseFormatter') ?? new ResponseFormatter();
        $this->paginationService = service('paginationService') ?? new PaginationService();
        $this->validationService = service('validationService');
        $this->transactionService = service('transactionService');
        $this->auditService = service('auditService');

        // Set resource name from class name if not defined
        if (empty($this->resourceName)) {
            $className = (new \ReflectionClass($this))->getShortName();
            $this->resourceName = str_replace('Controller', '', $className);
        }

        // Set default validation messages
        $this->setDefaultValidationMessages();

        // Set JSON as default response format
        $this->response->setContentType('application/json');
    }

    /**
     * Format successful response
     *
     * @param mixed $data Response data
     * @param string $message Success message
     * @param int $code HTTP status code
     * @param array $meta Additional metadata
     * @return ResponseInterface
     */
    protected function success($data = null, string $message = 'Success', int $code = 200, array $meta = []): ResponseInterface
    {
        $response = $this->responseFormatter->success($data, $message, $code, $meta);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format error response
     *
     * @param string $message Error message
     * @param int $code HTTP status code
     * @param array $errors Validation errors
     * @param array $meta Additional metadata
     * @return ResponseInterface
     */
    protected function error(string $message = 'An error occurred', int $code = 500, array $errors = [], array $meta = []): ResponseInterface
    {
        $response = $this->responseFormatter->error($message, $code, $errors, $meta);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format validation error response
     *
     * @param array $validationErrors Validation errors
     * @param string $message Error message
     * @return ResponseInterface
     */
    protected function validationError(array $validationErrors, string $message = 'Validation failed'): ResponseInterface
    {
        $response = $this->responseFormatter->validationError($validationErrors, $message);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format not found response
     *
     * @param string $message Error message
     * @param mixed $identifier Resource identifier
     * @return ResponseInterface
     */
    protected function notFound(string $message = 'Resource not found', $identifier = null): ResponseInterface
    {
        $response = $this->responseFormatter->notFound(
            $message,
            $this->resourceName,
            $identifier
        );
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format unauthorized response
     *
     * @param string $message Error message
     * @param string|null $reason Reason for unauthorized
     * @return ResponseInterface
     */
    protected function unauthorized(string $message = 'Unauthorized', ?string $reason = null): ResponseInterface
    {
        $response = $this->responseFormatter->unauthorized($message, $reason);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format forbidden response
     *
     * @param string $message Error message
     * @param string|null $permission Required permission
     * @return ResponseInterface
     */
    protected function forbidden(string $message = 'Forbidden', ?string $permission = null): ResponseInterface
    {
        $response = $this->responseFormatter->forbidden($message, $permission);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format created response
     *
     * @param mixed $data Created resource data
     * @param string $message Success message
     * @param string|null $location Location header
     * @return ResponseInterface
     */
    protected function created($data = null, string $message = 'Resource created successfully', ?string $location = null): ResponseInterface
    {
        $response = $this->responseFormatter->created($data, $message, $location);

        // Add Location header if provided
        if ($location !== null) {
            $this->response->setHeader('Location', $location);
        }

        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format updated response
     *
     * @param mixed $data Updated resource data
     * @param string $message Success message
     * @return ResponseInterface
     */
    protected function updated($data = null, string $message = 'Resource updated successfully'): ResponseInterface
    {
        $response = $this->responseFormatter->updated($data, $message);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format deleted response
     *
     * @param string $message Success message
     * @return ResponseInterface
     */
    protected function deleted(string $message = 'Resource deleted successfully'): ResponseInterface
    {
        $response = $this->responseFormatter->deleted($message);
        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Format paginated response
     *
     * @param array $items Paginated items
     * @param mixed $paginationQuery Pagination query object
     * @param int $totalItems Total items count
     * @param string $message Success message
     * @param array $additionalMeta Additional metadata
     * @return ResponseInterface
     */
    protected function paginated(
        array $items,
        $paginationQuery,
        int $totalItems,
        string $message = 'Data retrieved successfully',
        array $additionalMeta = []
    ): ResponseInterface {
        $response = $this->responseFormatter->paginated(
            $items,
            $paginationQuery,
            $totalItems,
            $message,
            $additionalMeta
        );

        // Add pagination headers
        $this->addPaginationHeaders($paginationQuery, $totalItems);

        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Validate request data
     *
     * Override method dari parent untuk menggunakan default validation rules
     *
     * @param mixed $rules Validation rules (string atau array)
     * @param array $messages Validation messages
     * @return bool
     */
    protected function validate($rules, array $messages = []): bool
    {
        // Jika rules adalah array kosong, gunakan rules dari controller
        if (is_array($rules) && empty($rules)) {
            $rules = $this->validationRules;
        }

        // Jika messages kosong, gunakan messages dari controller
        if (empty($messages)) {
            $messages = $this->validationMessages;
        }

        // Panggil parent validate
        return parent::validateRequest($rules, $messages);
    }

    /**
     * Get request data based on content type
     *
     * @return array
     */
    protected function getRequestData(): array
    {
        $contentType = $this->request->getHeaderLine('Content-Type');

        if (strpos($contentType, 'application/json') !== false) {
            return (array) $this->request->getJSON(true);
        }

        return $this->request->getPost() ?: $this->request->getRawInput();
    }

    /**
     * Get pagination parameters from request
     *
     * @param array $config Pagination config
     * @return mixed
     */
    protected function getPagination(array $config = [])
    {
        $requestData = $this->request->getGet();
        return $this->paginationService->createFromRequest($requestData, $config);
    }

    /**
     * Execute operation within transaction
     *
     * @param callable $callback Callback to execute
     * @param array $options Transaction options
     * @return mixed
     */
    protected function transaction(callable $callback, array $options = [])
    {
        if ($this->transactionService !== null) {
            return $this->transactionService->execute($callback, $options);
        }

        // Fallback without transaction
        return call_user_func($callback);
    }

    /**
     * Get current admin ID from session/jwt
     *
     * @return int|null
     */
    protected function adminId(): ?int
    {
        // Implementation depends on your auth system
        $adminId = session('admin_id') ?? $this->request->getHeader('X-Admin-ID')?->getValue();

        return $adminId !== null ? (int) $adminId : null;
    }

    /**
     * Check if current user has permission
     *
     * @param string $permission Permission to check
     * @return bool
     */
    protected function can(string $permission): bool
    {
        $adminId = $this->adminId();

        if (!$adminId) {
            return false;
        }

        try {
            $adminRepository = service('adminRepository');
            return $adminRepository->hasPermission($adminId, $permission);
        } catch (\Exception $e) {
            log_message('error', 'Permission check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log admin action
     *
     * @param string $action Action type
     * @param string $entityType Entity type
     * @param int $entityId Entity ID
     * @param array $oldData Old data
     * @param array $newData New data
     * @return bool
     */
    protected function log(
        string $action,
        string $entityType,
        int $entityId,
        array $oldData = [],
        array $newData = []
    ): bool {
        if ($this->auditService === null) {
            return false;
        }

        $adminId = $this->adminId();

        if (!$adminId) {
            return false;
        }

        try {
            $this->auditService->logCrudOperation(
                $adminId,
                $action,
                $entityType,
                $entityId,
                $oldData,
                $newData,
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString()
            );

            return true;
        } catch (\Exception $e) {
            log_message('error', 'Failed to log action: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle exception and return formatted error response
     *
     * @param \Throwable $e Exception
     * @return ResponseInterface
     */
    protected function exception(\Throwable $e): ResponseInterface
    {
        log_message('error', sprintf(
            'Exception: %s in %s:%s',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));

        // Handle specific exception types
        if ($e instanceof \CodeIgniter\Exceptions\PageNotFoundException) {
            return $this->notFound();
        }

        if ($e instanceof \App\Exceptions\ValidationException) {
            return $this->validationError($e->getErrors(), $e->getMessage());
        }

        if ($e instanceof \App\Exceptions\DomainException) {
            return $this->error(
                $e->getMessage(),
                $e->getHttpStatusCode(),
                [],
                [
                    'error_code' => $e->getErrorCode(),
                    'details' => $e->getDetails()
                ]
            );
        }

        // Default error response
        $includeStackTrace = ENVIRONMENT !== 'production';
        $response = $this->responseFormatter->exception($e, $includeStackTrace);

        return $this->responseFormatter->sendJson($this->response, $response);
    }

    /**
     * Add pagination headers to response
     *
     * @param mixed $paginationQuery Pagination query object
     * @param int $totalItems Total items count
     * @return void
     */
    protected function addPaginationHeaders($paginationQuery, int $totalItems): void
    {
        // Generate pagination data
        $paginationData = $this->paginationService->createPagination($paginationQuery, $totalItems);

        // Add X-Pagination header
        $this->response->setHeader('X-Pagination', json_encode($paginationData));

        // Add Link header if available
        if (isset($paginationData['links'])) {
            $linkHeader = [];
            foreach ($paginationData['links'] as $rel => $link) {
                if ($link !== null) {
                    $linkHeader[] = sprintf('<%s>; rel="%s"', $link, $rel);
                }
            }

            if (!empty($linkHeader)) {
                $this->response->setHeader('Link', implode(', ', $linkHeader));
            }
        }
    }

    /**
     * Set default validation messages
     *
     * @return void
     */
    protected function setDefaultValidationMessages(): void
    {
        if ($this->validationMessages === []) {
            $this->validationMessages = [
                'required' => 'Field {field} wajib diisi.',
                'min_length' => 'Field {field} minimal {param} karakter.',
                'max_length' => 'Field {field} maksimal {param} karakter.',
                'integer' => 'Field {field} harus berupa angka bulat.',
                'decimal' => 'Field {field} harus berupa angka desimal.',
                'valid_email' => 'Field {field} harus berupa email yang valid.',
                'matches' => 'Field {field} tidak cocok dengan {param}.',
                'is_unique' => 'Field {field} sudah digunakan.',
                'greater_than_equal_to' => 'Field {field} minimal {param}.',
                'less_than_equal_to' => 'Field {field} maksimal {param}.',
                'in_list' => 'Field {field} harus salah satu dari: {param}.',
                'valid_url' => 'Field {field} harus berupa URL yang valid.',
                'valid_date' => 'Field {field} harus berupa tanggal yang valid.',
            ];
        }
    }

    /**
     * Get service instance
     *
     * @param string $serviceClass Service class name
     * @return object|null
     */
    protected function service(string $serviceClass): ?object
    {
        try {
            // Try to get from service container
            $serviceName = lcfirst((new \ReflectionClass($serviceClass))->getShortName());
            return service($serviceName);
        } catch (\Exception $e) {
            // Fallback to direct instantiation
            try {
                if (class_exists($serviceClass)) {
                    return new $serviceClass();
                }
            } catch (\Exception $e) {
                log_message('error', 'Failed to instantiate service: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Get model instance
     *
     * @param string $modelClass Model class name
     * @return object|null
     */
    protected function model(string $modelClass): ?object
    {
        try {
            // Try to get from service container
            $modelName = lcfirst((new \ReflectionClass($modelClass))->getShortName());
            return model($modelName);
        } catch (\Exception $e) {
            // Fallback to direct instantiation
            try {
                if (class_exists($modelClass)) {
                    return new $modelClass();
                }
            } catch (\Exception $e) {
                log_message('error', 'Failed to instantiate model: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Get repository instance
     *
     * @param string $repositoryClass Repository class name
     * @return object|null
     */
    protected function repository(string $repositoryClass): ?object
    {
        try {
            // Try to get from RepositoryService
            $repositoryService = service('repositoryService');
            if ($repositoryService && method_exists($repositoryService, 'get')) {
                $shortName = (new \ReflectionClass($repositoryClass))->getShortName();
                $name = str_replace('Repository', '', $shortName);
                $name = lcfirst($name);

                return $repositoryService->get($name);
            }
        } catch (\Exception $e) {
            // Fallback to direct instantiation
            try {
                if (class_exists($repositoryClass)) {
                    return new $repositoryClass();
                }
            } catch (\Exception $e) {
                log_message('error', 'Failed to instantiate repository: ' . $e->getMessage());
                return null;
            }
        }

        return null;
    }

    /**
     * Check if request is from API client
     *
     * @return bool
     */
    protected function isApi(): bool
    {
        $acceptHeader = $this->request->getHeaderLine('Accept');
        $contentType = $this->request->getHeaderLine('Content-Type');

        return strpos($acceptHeader, 'application/json') !== false ||
               strpos($contentType, 'application/json') !== false ||
               $this->request->isAJAX();
    }

    /**
     * Get request ID for tracking
     *
     * @return string
     */
    protected function requestId(): string
    {
        return $this->responseFormatter->getRequestId();
    }

    /**
     * Rate limiting check
     *
     * @param string $key Rate limit key
     * @param int $limit Requests per window
     * @param int $window Time window in seconds
     * @return array
     */
    protected function rateLimit(string $key, int $limit = 60, int $window = 60): array
    {
        $cache = Services::cache();
        $cacheKey = 'rate_limit_' . $key . '_' . $this->request->getIPAddress();

        $attempts = $cache->get($cacheKey) ?: [];
        $currentTime = time();

        // Remove old attempts
        $attempts = array_filter($attempts, function ($timestamp) use ($currentTime, $window) {
            return $timestamp > $currentTime - $window;
        });

        // Check limit
        if (count($attempts) >= $limit) {
            $resetTime = min($attempts) + $window;
            $retryAfter = $resetTime - $currentTime;

            return [
                'allowed' => false,
                'limit' => $limit,
                'remaining' => 0,
                'reset' => $resetTime,
                'retry_after' => $retryAfter,
            ];
        }

        // Record attempt
        $attempts[] = $currentTime;
        $cache->save($cacheKey, $attempts, $window);

        return [
            'allowed' => true,
            'limit' => $limit,
            'remaining' => $limit - count($attempts),
            'reset' => $currentTime + $window,
            'retry_after' => 0,
        ];
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    protected function validationErrors(): array
    {
        $validation = Services::validation();
        return $validation->getErrors();
    }

    /**
     * Shortcut to get POST/JSON input
     *
     * @param string|null $key Input key
     * @return mixed
     */
    protected function input(?string $key = null)
    {
        $data = $this->getRequestData();

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? null;
    }

    /**
     * Shortcut to get GET parameters
     *
     * @param string|null $key Query key
     * @return mixed
     */
    protected function query(?string $key = null)
    {
        if ($key === null) {
            return $this->request->getGet();
        }

        return $this->request->getGet($key);
    }

    /**
     * Require authentication (API version)
     *
     * Override dari BaseController untuk API (tidak redirect, tapi throw exception)
     *
     * @param string|null $permission Permission yang diperlukan (opsional)
     * @return int Admin ID
     * @throws \App\Exceptions\DomainException Jika tidak terautentikasi
     */
    protected function requireAuth(?string $permission = null): int
    {
        $adminId = $this->adminId();

        if (!$adminId) {
            throw new \App\Exceptions\DomainException('Unauthorized', 'UNAUTHORIZED', [], 401);
        }

        // Check permission if provided
        if ($permission && !$this->can($permission)) {
            throw new \App\Exceptions\DomainException('Forbidden', 'FORBIDDEN', [], 403);
        }

        return $adminId;
    }

    /**
     * Require permission (API version)
     *
     * @param string $permission Required permission
     * @return void
     * @throws \App\Exceptions\DomainException Jika tidak memiliki permission
     */
    protected function requirePermission(string $permission): void
    {
        $adminId = $this->adminId();

        if (!$adminId) {
            throw new \App\Exceptions\DomainException('Unauthorized', 'UNAUTHORIZED', [], 401);
        }

        if (!$this->can($permission)) {
            throw new \App\Exceptions\DomainException('Forbidden', 'FORBIDDEN', [], 403);
        }
    }
}
