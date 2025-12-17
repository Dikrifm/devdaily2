<?php

namespace App\Services;

use App\DTOs\Queries\PaginationQuery;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

class ResponseFormatter
{
    use ResponseTrait;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var PaginationService|null
     */
    protected $paginationService;

    /**
     * @var string
     */
    protected $requestId;

    /**
     * Constructor.
     * 
     * @param array $config
     * @param PaginationService|null $paginationService
     */
    public function __construct(?array $config = null, ?PaginationService $paginationService = null)
    {
        $this->config = $config ?? config('App');
        $this->paginationService = $paginationService ?? service('pagination');
        $this->requestId = $this->generateRequestId();
    }

    /**
     * Format successful response.
     * 
     * @param mixed $data
     * @param string $message
     * @param int $code
     * @param array $meta
     * @return array
     */
    public function success($data = null, string $message = 'Success', int $code = 200, array $meta = []): array
    {
        $response = [
            'status' => 'success',
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'meta' => $this->buildMeta($meta),
            'errors' => null,
        ];

        return $this->cleanNullValues($response);
    }

    /**
     * Format error response.
     * 
     * @param string $message
     * @param int $code
     * @param array $errors
     * @param array $meta
     * @return array
     */
    public function error(string $message = 'An error occurred', int $code = 500, array $errors = [], array $meta = []): array
    {
        $response = [
            'status' => 'error',
            'code' => $code,
            'message' => $message,
            'data' => null,
            'meta' => $this->buildMeta($meta),
            'errors' => $errors ?: null,
        ];

        return $this->cleanNullValues($response);
    }

    /**
     * Format validation error response.
     * 
     * @param array $validationErrors
     * @param string $message
     * @return array
     */
    public function validationError(array $validationErrors, string $message = 'Validation failed'): array
    {
        $errors = [];
        
        foreach ($validationErrors as $field => $error) {
            $errors[] = [
                'field' => $field,
                'message' => is_array($error) ? implode(', ', $error) : $error,
                'code' => 'VALIDATION_ERROR',
            ];
        }

        return $this->error($message, 422, $errors);
    }

    /**
     * Format not found response.
     * 
     * @param string $message
     * @param string $resource
     * @param mixed $identifier
     * @return array
     */
    public function notFound(string $message = 'Resource not found', string $resource = null, $identifier = null): array
    {
        $errors = [];
        
        if ($resource && $identifier) {
            $errors[] = [
                'resource' => $resource,
                'identifier' => $identifier,
                'code' => 'NOT_FOUND',
            ];
        }

        return $this->error($message, 404, $errors);
    }

    /**
     * Format unauthorized response.
     * 
     * @param string $message
     * @param string $reason
     * @return array
     */
    public function unauthorized(string $message = 'Unauthorized', string $reason = null): array
    {
        $errors = $reason ? [['reason' => $reason, 'code' => 'UNAUTHORIZED']] : [];

        return $this->error($message, 401, $errors);
    }

    /**
     * Format forbidden response.
     * 
     * @param string $message
     * @param string $permission
     * @return array
     */
    public function forbidden(string $message = 'Forbidden', string $permission = null): array
    {
        $errors = $permission ? [['required_permission' => $permission, 'code' => 'FORBIDDEN']] : [];

        return $this->error($message, 403, $errors);
    }

    /**
     * Format paginated response.
     * 
     * @param array $items
     * @param PaginationQuery $paginationQuery
     * @param int $totalItems
     * @param string $message
     * @param array $additionalMeta
     * @return array
     */
    public function paginated(
        array $items,
        PaginationQuery $paginationQuery,
        int $totalItems,
        string $message = 'Data retrieved successfully',
        array $additionalMeta = []
    ): array {
        $paginationData = $this->paginationService->createPagination($paginationQuery, $totalItems);
        
        $meta = array_merge($additionalMeta, [
            'pagination' => $paginationData,
        ]);

        return $this->success($items, $message, 200, $meta);
    }

    /**
     * Format created response.
     * 
     * @param mixed $data
     * @param string $message
     * @param string $location
     * @return array
     */
    public function created($data = null, string $message = 'Resource created successfully', string $location = null): array
    {
        $meta = [];
        
        if ($location) {
            $meta['location'] = $location;
        }

        return $this->success($data, $message, 201, $meta);
    }

    /**
     * Format updated response.
     * 
     * @param mixed $data
     * @param string $message
     * @return array
     */
    public function updated($data = null, string $message = 'Resource updated successfully'): array
    {
        return $this->success($data, $message, 200);
    }

    /**
     * Format deleted response.
     * 
     * @param string $message
     * @return array
     */
    public function deleted(string $message = 'Resource deleted successfully'): array
    {
        return $this->success(null, $message, 200);
    }

    /**
     * Format no content response.
     * 
     * @return array
     */
    public function noContent(): array
    {
        return [
            'status' => 'success',
            'code' => 204,
            'message' => 'No content',
            'data' => null,
            'meta' => $this->buildMeta(),
            'errors' => null,
        ];
    }

    /**
     * Send JSON response to client.
     * 
     * @param ResponseInterface $response
     * @param array $formattedResponse
     * @param array $headers
     * @return ResponseInterface
     */
    public function sendJson(ResponseInterface $response, array $formattedResponse, array $headers = []): ResponseInterface
    {
        foreach ($headers as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response->setStatusCode($formattedResponse['code'])
            ->setJSON($formattedResponse);
    }

    /**
     * Build metadata array.
     * 
     * @param array $additionalMeta
     * @return array
     */
    protected function buildMeta(array $additionalMeta = []): array
    {
        $meta = [
            'timestamp' => $this->getTimestamp(),
            'request_id' => $this->requestId,
        ];

        // Add debug info in development
        if (ENVIRONMENT !== 'production') {
            $meta['debug'] = $this->getDebugInfo();
        }

        // Merge additional metadata
        if ($additionalMeta) {
            $meta = array_merge($meta, $additionalMeta);
        }

        return $meta;
    }

    /**
     * Get current timestamp in ISO 8601 format.
     * 
     * @return string
     */
    protected function getTimestamp(): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))->format('c');
    }

    /**
     * Generate unique request ID.
     * 
     * @return string
     */
    protected function generateRequestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }

    /**
     * Get debug information.
     * 
     * @return array
     */
    protected function getDebugInfo(): array
    {
        $debug = [
            'environment' => ENVIRONMENT,
            'execution_time' => $this->getExecutionTime(),
        ];

        // Add query count if available
        if (function_exists('db_connect')) {
            $db = db_connect();
            if ($db && method_exists($db, 'getConnectCount')) {
                $debug['query_count'] = $db->getConnectCount();
            }
        }

        return $debug;
    }

    /**
     * Get script execution time.
     * 
     * @return float
     */
    protected function getExecutionTime(): float
    {
        $startTime = defined('APP_START_TIME') ? APP_START_TIME : $_SERVER['REQUEST_TIME_FLOAT'];
        return microtime(true) - $startTime;
    }

    /**
     * Clean null values from response array.
     * 
     * @param array $response
     * @return array
     */
    protected function cleanNullValues(array $response): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->cleanNullValues($value);
            }
            return $value;
        }, $response);
    }

    /**
     * Format exception response.
     * 
     * @param \Throwable $exception
     * @param bool $includeStackTrace
     * @return array
     */
    public function exception(\Throwable $exception, bool $includeStackTrace = false): array
    {
        $code = $exception->getCode() >= 400 && $exception->getCode() < 600 
            ? $exception->getCode() 
            : 500;

        $errors = [
            [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode() ?: 'INTERNAL_ERROR',
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]
        ];

        if ($includeStackTrace && ENVIRONMENT !== 'production') {
            $errors[0]['trace'] = explode("\n", $exception->getTraceAsString());
        }

        $meta = [];
        if ($exception instanceof \App\Exceptions\DomainException) {
            $meta['error_code'] = $exception->getErrorCode();
            $meta['details'] = $exception->getDetails();
        }

        return $this->error('An exception occurred', $code, $errors, $meta);
    }

    /**
     * Format DTO collection response.
     * 
     * @param array $dtos
     * @param string $message
     * @return array
     */
    public function collection(array $dtos, string $message = 'Collection retrieved successfully'): array
    {
        $data = array_map(function ($dto) {
            if (method_exists($dto, 'toArray')) {
                return $dto->toArray();
            }
            return $dto;
        }, $dtos);

        return $this->success($data, $message);
    }

    /**
     * Format single DTO response.
     * 
     * @param mixed $dto
     * @param string $message
     * @return array
     */
    public function item($dto, string $message = 'Item retrieved successfully'): array
    {
        $data = method_exists($dto, 'toArray') ? $dto->toArray() : $dto;
        
        return $this->success($data, $message);
    }

    /**
     * Get current request ID.
     * 
     * @return string
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * Set custom request ID.
     * 
     * @param string $requestId
     * @return self
     */
    public function setRequestId(string $requestId): self
    {
        $this->requestId = $requestId;
        return $this;
    }
}