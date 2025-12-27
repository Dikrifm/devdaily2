<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * BaseApi Controller - Layer 4 (Presentation Layer)
 * 
 * Parent untuk SEMUA API controllers dalam sistem DevDaily.
 * Mengikuti prinsip "Thin & Dumb" untuk API context.
 * 
 * @package App\Controllers\Api
 */
class BaseApi extends BaseController
{
    /**
     * API version (diambil dari route)
     * 
     * @var string
     */
    protected string $apiVersion = 'v1';

    /**
     * Current authenticated API key data (dari Filter)
     * 
     * @var array|null
     */
    protected ?array $apiKeyData = null;

    /**
     * Rate limit info (dari Filter)
     * 
     * @var array|null
     */
    protected ?array $rateLimitInfo = null;

    /**
     * Constructor.
     */
    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::initController($request, $response, $logger);
        
        // Set JSON content type untuk semua API responses
        $response->setContentType('application/json');
        
        // Get data dari Filter pipeline
        $this->apiKeyData = $request->getAttribute('api_key_data');
        $this->rateLimitInfo = $request->getAttribute('rate_limit_info');
        
        // Parse API version dari route
        $this->parseApiVersionFromRoute();
    }

    // =====================================================================
    // API CONTEXT DATA ACCESSORS
    // =====================================================================

    /**
     * Get current API key data (dari Filter).
     * 
     * @return array|null
     */
    protected function getApiKeyData(): ?array
    {
        return $this->apiKeyData;
    }

    /**
     * Get current API key ID.
     * 
     * @return int|null
     */
    protected function getApiKeyId(): ?int
    {
        return $this->apiKeyData['id'] ?? null;
    }

    /**
     * Get current API key scopes.
     * 
     * @return array
     */
    protected function getApiKeyScopes(): array
    {
        return $this->apiKeyData['scopes'] ?? [];
    }

    /**
     * Check if current API key has specific scope.
     * 
     * @param string $scope
     * @return bool
     */
    protected function hasScope(string $scope): bool
    {
        $scopes = $this->getApiKeyScopes();
        return in_array($scope, $scopes) || in_array('*', $scopes);
    }

    /**
     * Require specific scope (throw exception jika tidak ada).
     * 
     * @param string $scope
     * @throws \App\Exceptions\AuthorizationException
     */
    protected function requireScope(string $scope): void
    {
        if (!$this->hasScope($scope)) {
            throw new \App\Exceptions\AuthorizationException(
                "Insufficient scope. Required scope: {$scope}"
            );
        }
    }

    /**
     * Get rate limit info.
     * 
     * @return array|null
     */
    protected function getRateLimitInfo(): ?array
    {
        return $this->rateLimitInfo;
    }

    /**
     * Apply rate limit headers ke response.
     * 
     * @return void
     */
    protected function applyRateLimitHeaders(): void
    {
        if (!$this->rateLimitInfo) {
            return;
        }

        $this->response->setHeader('X-RateLimit-Limit', $this->rateLimitInfo['limit'] ?? 100);
        $this->response->setHeader('X-RateLimit-Remaining', $this->rateLimitInfo['remaining'] ?? 100);
        
        if (isset($this->rateLimitInfo['reset'])) {
            $this->response->setHeader('X-RateLimit-Reset', $this->rateLimitInfo['reset']);
        }
    }

    // =====================================================================
    // API-SPECIFIC REQUEST HANDLING
    // =====================================================================

    /**
     * Parse API version dari route.
     * 
     * @return void
     */
    protected function parseApiVersionFromRoute(): void
    {
        $uri = service('uri');
        $segments = $uri->getSegments();
        
        // Cari segment 'api' lalu ambil version setelahnya
        $apiIndex = array_search('api', $segments);
        
        if ($apiIndex !== false && isset($segments[$apiIndex + 1])) {
            $version = $segments[$apiIndex + 1];
            
            if (preg_match('/^v[1-9][0-9]*$/', $version)) {
                $this->apiVersion = $version;
            }
        }
    }

    /**
     * Get API-specific request data (support JSON and form data).
     * 
     * @return array
     */
    protected function getApiRequestData(): array
    {
        $data = [];
        
        // GET parameters
        $data = array_merge($data, $this->request->getGet() ?? []);
        
        // Handle request body berdasarkan content type
        if (in_array($this->request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $contentType = $this->request->getHeaderLine('Content-Type');
            
            if (strpos($contentType, 'application/json') !== false) {
                $jsonData = $this->request->getJSON(true);
                if ($jsonData) {
                    $data = array_merge($data, $jsonData);
                }
            } else {
                // Form data
                $data = array_merge($data, $this->request->getPost() ?? []);
            }
        }
        
        return $this->sanitizeInput($data);
    }

    /**
     * Create DTO dari API request data.
     * 
     * @template T
     * @param class-string<T> $dtoClass
     * @param array $additionalData
     * @return T
     */
    protected function createApiDto(string $dtoClass, array $additionalData = [])
    {
        $requestData = $this->getApiRequestData();
        $data = array_merge($requestData, $additionalData);
        
        // Gunakan factory method dari BaseController
        return $this->createDtoFromRequest($dtoClass, $data);
    }

    /**
     * Handle standard API GET request dengan pagination.
     * 
     * @param callable $serviceCall Function yang menerima (PaginationQuery, array filters)
     * @param array $filterConfig Konfigurasi filter yang diizinkan
     * @return ResponseInterface
     */
    protected function handleApiGetRequest(callable $serviceCall, array $filterConfig = []): ResponseInterface
    {
        try {
            // Create pagination query
            $paginationQuery = $this->paginationService()->createFromRequest(
                $this->request->getGet() ?? []
            );
            
            // Extract filters
            $filters = $this->extractApiFilters($filterConfig);
            
            // Call service
            $result = $serviceCall($paginationQuery, $filters);
            
            // Apply rate limit headers
            $this->applyRateLimitHeaders();
            
            // Return paginated response
            return $this->responseFormatter()->paginated(
                $result['data'] ?? [],
                $paginationQuery,
                $result['total'] ?? 0,
                $result['message'] ?? 'Data retrieved successfully',
                array_merge(
                    $result['meta'] ?? [],
                    ['api_version' => $this->apiVersion]
                )
            );
            
        } catch (\App\Exceptions\NotFoundException $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->notFound($e->getMessage());
        } catch (\Exception $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->exception($e);
        }
    }

    /**
     * Handle standard API write request (POST/PUT/PATCH).
     * 
     * @template T
     * @param class-string<T> $dtoClass
     * @param callable $serviceCall Function yang menerima DTO
     * @param array $additionalData Data tambahan untuk DTO
     * @return ResponseInterface
     */
    protected function handleApiWriteRequest(
        string $dtoClass, 
        callable $serviceCall,
        array $additionalData = []
    ): ResponseInterface {
        try {
            // Require scope jika diperlukan (opsional)
            // $this->requireScope('products:write');
            
            // Create DTO
            $dto = $this->createApiDto($dtoClass, $additionalData);
            
            // Call service
            $result = $serviceCall($dto);
            
            // Apply rate limit headers
            $this->applyRateLimitHeaders();
            
            // Return response berdasarkan HTTP method
            if ($this->request->getMethod() === 'POST') {
                return $this->responseFormatter()->created(
                    $result,
                    'Resource created successfully',
                    site_url("api/{$this->apiVersion}/{$this->getResourceType()}/{$result->getId()}")
                );
            }
            
            return $this->responseFormatter()->updated(
                $result,
                'Resource updated successfully'
            );
            
        } catch (\App\Exceptions\ValidationException $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->validationError($e->getErrors());
        } catch (\App\Exceptions\AuthorizationException $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->forbidden($e->getMessage());
        } catch (\Exception $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->exception($e);
        }
    }

    /**
     * Handle standard API DELETE request.
     * 
     * @param callable $serviceCall
     * @return ResponseInterface
     */
    protected function handleApiDeleteRequest(callable $serviceCall): ResponseInterface
    {
        try {
            // Require scope jika diperlukan
            // $this->requireScope('products:delete');
            
            // Call service
            $result = $serviceCall();
            
            $this->applyRateLimitHeaders();
            
            return $this->responseFormatter()->deleted(
                $result['message'] ?? 'Resource deleted successfully',
                $result['data'] ?? null
            );
            
        } catch (\App\Exceptions\NotFoundException $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->notFound($e->getMessage());
        } catch (\Exception $e) {
            $this->applyRateLimitHeaders();
            return $this->responseFormatter()->exception($e);
        }
    }

    // =====================================================================
    // API-SPECIFIC UTILITIES
    // =====================================================================

    /**
     * Extract filters dari API request.
     * 
     * @param array $filterConfig Konfigurasi filter yang diizinkan
     * @return array
     */
    protected function extractApiFilters(array $filterConfig = []): array
    {
        $filters = [];
        $queryParams = $this->request->getGet() ?? [];
        
        // Reserved parameters
        $reserved = ['page', 'per_page', 'limit', 'offset', 'sort', 'fields', 'include', 'api_version'];
        
        foreach ($queryParams as $key => $value) {
            if (in_array($key, $reserved)) {
                continue;
            }
            
            // Jika ada filterConfig, validasi
            if (!empty($filterConfig) && !in_array($key, $filterConfig)) {
                continue;
            }
            
            // Clean value
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            
            $filters[$key] = $value;
        }
        
        return $filters;
    }

    /**
     * Parse sparse fieldsets parameter.
     * 
     * @param string $paramName Nama parameter (default: 'fields')
     * @param array $availableFields Fields yang tersedia
     * @return array
     */
    protected function parseFieldsParam(string $paramName = 'fields', array $availableFields = []): array
    {
        $fieldsParam = $this->request->getGet($paramName);
        
        if (!$fieldsParam || !is_string($fieldsParam)) {
            return $availableFields;
        }
        
        $requestedFields = explode(',', $fieldsParam);
        $result = [];
        
        foreach ($requestedFields as $field) {
            $field = trim($field);
            
            if (empty($availableFields) || in_array($field, $availableFields)) {
                $result[] = $field;
            }
        }
        
        return empty($result) ? $availableFields : $result;
    }

    /**
     * Parse include relationships parameter.
     * 
     * @param string $paramName Nama parameter (default: 'include')
     * @param array $availableIncludes Relationships yang tersedia
     * @return array
     */
    protected function parseIncludeParam(string $paramName = 'include', array $availableIncludes = []): array
    {
        $includeParam = $this->request->getGet($paramName);
        
        if (!$includeParam || !is_string($includeParam)) {
            return [];
        }
        
        $requestedIncludes = explode(',', $includeParam);
        $result = [];
        
        foreach ($requestedIncludes as $include) {
            $include = trim($include);
            
            if (empty($availableIncludes) || in_array($include, $availableIncludes)) {
                $result[] = $include;
            }
        }
        
        return $result;
    }

    /**
     * Parse sorting parameter.
     * 
     * @param string $paramName Nama parameter (default: 'sort')
     * @param array $allowedFields Fields yang diizinkan
     * @return array [field => direction]
     */
    protected function parseSortParam(string $paramName = 'sort', array $allowedFields = []): array
    {
        $sortParam = $this->request->getGet($paramName);
        
        if (!$sortParam || !is_string($sortParam)) {
            return ['created_at' => 'desc'];
        }
        
        $result = [];
        $sortPairs = explode(',', $sortParam);
        
        foreach ($sortPairs as $pair) {
            $parts = explode(':', $pair);
            $field = trim($parts[0] ?? '');
            $direction = strtolower(trim($parts[1] ?? 'asc'));
            
            if (!in_array($direction, ['asc', 'desc'])) {
                $direction = 'asc';
            }
            
            // Jika ada allowedFields, validasi
            if (empty($allowedFields) || in_array($field, $allowedFields)) {
                $result[$field] = $direction;
            }
        }
        
        return empty($result) ? ['created_at' => 'desc'] : $result;
    }

    /**
     * Get resource type dari controller name.
     * 
     * @return string
     */
    protected function getResourceType(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();
        $type = str_replace('Controller', '', $className);
        return strtolower($type);
    }

    /**
     * Check if request is JSON.
     * 
     * @return bool
     */
    protected function isJsonRequest(): bool
    {
        $contentType = $this->request->getHeaderLine('Content-Type');
        return strpos($contentType, 'application/json') !== false;
    }

    /**
     * Get API version.
     * 
     * @return string
     */
    protected function getApiVersion(): string
    {
        return $this->apiVersion;
    }
}