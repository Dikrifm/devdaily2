<?php

namespace App\DTOs\Requests\System;

use App\DTOs\BaseDTO;
use App\Exceptions\ValidationException;
use DateTimeImmutable;

final class SystemHealthRequest extends BaseDTO
{
    private ?array $components = null;
    private bool $deepCheck = false;
    private bool $includeMetrics = true;
    private bool $forceRefresh = false;
    private ?int $adminId = null;
    private DateTimeImmutable $requestedAt;
    private string $ipAddress;
    private string $userAgent;
    
    private const ALLOWED_COMPONENTS = [
        'database',
        'cache',
        'storage',
        'email',
        'queue',
        'api_connections',
        'external_services',
        'cron_jobs'
    ];
    
    private const MAX_COMPONENTS = 10;
    
    private function __construct() {}
    
    public static function fromRequest(array $requestData, ?int $adminId = null, ?string $ipAddress = null, ?string $userAgent = null): self
    {
        $instance = new self();
        $instance->validateAndHydrate($requestData, $adminId, $ipAddress, $userAgent);
        return $instance;
    }
    
    public static function quickCheck(?int $adminId = null): self
    {
        $instance = new self();
        
        $data = [
            'components' => ['database', 'cache'],
            'deep_check' => false,
            'include_metrics' => true,
            'force_refresh' => false,
        ];
        
        $instance->validateAndHydrate($data, $adminId, '127.0.0.1', 'System Health Check');
        return $instance;
    }
    
    public static function fullCheck(?int $adminId = null): self
    {
        $instance = new self();
        
        $data = [
            'components' => self::ALLOWED_COMPONENTS,
            'deep_check' => true,
            'include_metrics' => true,
            'force_refresh' => true,
        ];
        
        $instance->validateAndHydrate($data, $adminId, '127.0.0.1', 'System Health Check');
        return $instance;
    }
    
    private function validateAndHydrate(array $data, ?int $adminId, ?string $ipAddress, ?string $userAgent): void
    {
        $errors = [];
        
        // Set admin ID
        $this->adminId = $adminId;
        
        // Validate components (optional)
        if (isset($data['components'])) {
            if (!is_array($data['components'])) {
                $errors['components'] = 'Components must be an array';
            } else {
                $components = array_unique($data['components']);
                
                if (count($components) > self::MAX_COMPONENTS) {
                    $errors['components'] = sprintf('Maximum %d components allowed', self::MAX_COMPONENTS);
                } else {
                    foreach ($components as $component) {
                        if (!in_array($component, self::ALLOWED_COMPONENTS, true)) {
                            $errors['components'] = sprintf(
                                'Invalid component: %s. Allowed: %s',
                                $component,
                                implode(', ', self::ALLOWED_COMPONENTS)
                            );
                            break;
                        }
                    }
                    
                    if (empty($errors['components'])) {
                        $this->components = $components;
                    }
                }
            }
        }
        
        // Validate deep_check (optional, default false)
        if (isset($data['deep_check'])) {
            $deepCheck = filter_var($data['deep_check'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($deepCheck === null) {
                $errors['deep_check'] = 'Deep check must be a boolean value';
            } else {
                $this->deepCheck = $deepCheck;
            }
        } else {
            $this->deepCheck = false;
        }
        
        // Validate include_metrics (optional, default true)
        if (isset($data['include_metrics'])) {
            $includeMetrics = filter_var($data['include_metrics'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($includeMetrics === null) {
                $errors['include_metrics'] = 'Include metrics must be a boolean value';
            } else {
                $this->includeMetrics = $includeMetrics;
            }
        } else {
            $this->includeMetrics = true;
        }
        
        // Validate force_refresh (optional, default false)
        if (isset($data['force_refresh'])) {
            $forceRefresh = filter_var($data['force_refresh'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($forceRefresh === null) {
                $errors['force_refresh'] = 'Force refresh must be a boolean value';
            } else {
                $this->forceRefresh = $forceRefresh;
            }
        } else {
            $this->forceRefresh = false;
        }
        
        // Set metadata
        $this->requestedAt = new DateTimeImmutable();
        $this->ipAddress = $ipAddress ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $this->userAgent = $userAgent ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Validate business rules
        $this->validateBusinessRules($errors);
        
        if (!empty($errors)) {
            throw ValidationException::forField('system_health', 'Validation failed', $errors);
        }
    }
    
    private function validateBusinessRules(array &$errors): void
    {
        // Business rule: Deep check requires admin authentication
        if ($this->deepCheck && $this->adminId === null) {
            $errors['deep_check'] = 'Admin authentication required for deep check';
        }
        
        // Business rule: Force refresh should not be used too frequently
        if ($this->forceRefresh && $this->adminId === null) {
            $errors['force_refresh'] = 'Admin authentication required for force refresh';
        }
        
        // Business rule: If no components specified, use default set
        if ($this->components === null) {
            $this->components = $this->deepCheck 
                ? self::ALLOWED_COMPONENTS 
                : ['database', 'cache', 'storage'];
        }
    }
    
    public static function rules(): array
    {
        return [
            'components' => 'nullable|array',
            'components.*' => 'string|in:' . implode(',', self::ALLOWED_COMPONENTS),
            'deep_check' => 'nullable|boolean',
            'include_metrics' => 'nullable|boolean',
            'force_refresh' => 'nullable|boolean',
        ];
    }
    
    public static function messages(): array
    {
        return [
            'components.array' => 'Components must be an array',
            'components.*.in' => 'Invalid component. Allowed: ' . implode(', ', self::ALLOWED_COMPONENTS),
            'deep_check.boolean' => 'Deep check must be true or false',
            'include_metrics.boolean' => 'Include metrics must be true or false',
            'force_refresh.boolean' => 'Force refresh must be true or false',
        ];
    }
    
    public function getComponents(): array
    {
        return $this->components ?? ['database', 'cache', 'storage'];
    }
    
    public function isDeepCheck(): bool
    {
        return $this->deepCheck;
    }
    
    public function shouldIncludeMetrics(): bool
    {
        return $this->includeMetrics;
    }
    
    public function isForceRefresh(): bool
    {
        return $this->forceRefresh;
    }
    
    public function getAdminId(): ?int
    {
        return $this->adminId;
    }
    
    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }
    
    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }
    
    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
    
    public function checkComponent(string $component): bool
    {
        return in_array($component, $this->getComponents(), true);
    }
    
    public function checkDatabase(): bool
    {
        return $this->checkComponent('database');
    }
    
    public function checkCache(): bool
    {
        return $this->checkComponent('cache');
    }
    
    public function checkStorage(): bool
    {
        return $this->checkComponent('storage');
    }
    
    public function checkEmail(): bool
    {
        return $this->checkComponent('email');
    }
    
    public function getCheckType(): string
    {
        if ($this->deepCheck) {
            return 'DEEP_CHECK';
        }
        
        if ($this->components === null || count($this->components) <= 3) {
            return 'QUICK_CHECK';
        }
        
        return 'CUSTOM_CHECK';
    }
    
    public function toArray(): array
    {
        return [
            'components' => $this->getComponents(),
            'deep_check' => $this->deepCheck,
            'include_metrics' => $this->includeMetrics,
            'force_refresh' => $this->forceRefresh,
            'admin_id' => $this->adminId,
            'requested_at' => $this->requestedAt->format('Y-m-d H:i:s'),
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'check_type' => $this->getCheckType(),
            'components_count' => count($this->getComponents()),
        ];
    }
    
    public function toDatabaseArray(): array
    {
        return [
            'admin_id' => $this->adminId,
            'components' => json_encode($this->getComponents()),
            'deep_check' => $this->deepCheck,
            'include_metrics' => $this->includeMetrics,
            'force_refresh' => $this->forceRefresh,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'requested_at' => $this->requestedAt,
        ];
    }
    
    public function getAuditMessage(): string
    {
        $components = implode(', ', $this->getComponents());
        $adminInfo = $this->adminId ? " by Admin #{$this->adminId}" : '';
        
        return sprintf(
            'System health check%s: %s components%s',
            $adminInfo,
            count($this->getComponents()),
            $this->deepCheck ? ' (deep check)' : ''
        );
    }
    
    public function validate(): array
    {
        $errors = [];
        
        // Validate components if set
        if ($this->components !== null) {
            foreach ($this->components as $component) {
                if (!in_array($component, self::ALLOWED_COMPONENTS, true)) {
                    $errors['components'] = "Invalid component: {$component}";
                    break;
                }
            }
        }
        
        // Validate deep check requires admin
        if ($this->deepCheck && $this->adminId === null) {
            $errors['deep_check'] = 'Deep check requires admin authentication';
        }
        
        return $errors;
    }
    
    public function getCacheKey(): string
    {
        $componentsHash = md5(implode(',', $this->getComponents()));
        return sprintf(
            'system_health:%s:%d:%d:%d',
            $componentsHash,
            $this->deepCheck ? 1 : 0,
            $this->includeMetrics ? 1 : 0,
            $this->forceRefresh ? 1 : 0
        );
    }
    
    public function getEstimatedDuration(): int
    {
        $baseTime = 2; // seconds
        $componentTime = count($this->getComponents()) * 0.5;
        $deepMultiplier = $this->deepCheck ? 3 : 1;
        
        return (int) ceil(($baseTime + $componentTime) * $deepMultiplier);
    }
}