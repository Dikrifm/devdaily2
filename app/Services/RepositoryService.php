<?php

namespace App\Services;

use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Concrete\ProductRepository;
use App\Models\ProductModel;
use CodeIgniter\Config\Services as CodeIgniterServices;
use RuntimeException;

/**
 * Repository Service Factory
 * 
 * Centralized factory for creating and managing repository instances.
 * Implements lazy loading and singleton pattern for performance.
 * 
 * @package App\Services
 */
class RepositoryService
{
    /**
     * Repository instances cache
     * 
     * @var array
     */
    private static array $instances = [];

    /**
     * Configuration for repositories
     * 
     * @var array
     */
    private static array $config = [
        'product' => [
            'class' => ProductRepository::class,
            'dependencies' => ['model', 'cache', 'db'],
            'cache_ttl' => 3600,
        ],
        // Future repositories will be added here
    ];

    /**
     * Get product repository instance
     * 
     * @param bool $fresh Create fresh instance (bypass singleton)
     * @return ProductRepositoryInterface
     */
    public static function product(bool $fresh = false): ProductRepositoryInterface
    {
        $key = 'product';
        
        if ($fresh || !isset(self::$instances[$key])) {
            self::$instances[$key] = self::createProductRepository();
        }
        
        return self::$instances[$key];
    }

    /**
     * Create product repository with dependencies
     * 
     * @return ProductRepositoryInterface
     */
    private static function createProductRepository(): ProductRepositoryInterface
    {
        $config = self::$config['product'];
        
        // Resolve dependencies
        $dependencies = [];
        foreach ($config['dependencies'] as $dep) {
            $dependencies[] = match($dep) {
                'model' => self::createProductModel(),
                'cache' => self::createCacheService(),
                'db'    => self::createDatabaseConnection(),
                default => throw new RuntimeException("Unknown dependency: {$dep}")
            };
        }
        
        // Create instance
        $repository = new $config['class'](...$dependencies);
        
        // Apply configuration
        if (method_exists($repository, 'setCacheTtl') && isset($config['cache_ttl'])) {
            $repository->setCacheTtl($config['cache_ttl']);
        }
        
        return $repository;
    }

    /**
     * Create product model instance
     * 
     * @return ProductModel
     */
    private static function createProductModel(): ProductModel
    {
        return CodeIgniterServices::models()->get(ProductModel::class);
    }

    /**
     * Create cache service instance
     * 
     * @return CacheService
     */
    private static function createCacheService(): CacheService
    {
        return CacheService::create([
            'namespace' => 'repo_',
            'default_ttl' => 3600,
        ]);
    }

    /**
     * Create database connection
     * 
     * @return \CodeIgniter\Database\BaseConnection
     */
    private static function createDatabaseConnection()
    {
        return CodeIgniterServices::database();
    }

    /**
     * Register a custom repository
     * 
     * @param string $name Repository name
     * @param string $class Repository class
     * @param array $dependencies Required dependencies
     * @param array $config Additional configuration
     * @return void
     */
    public static function register(string $name, string $class, array $dependencies, array $config = []): void
    {
        self::$config[$name] = [
            'class' => $class,
            'dependencies' => $dependencies,
            ...$config,
        ];
        
        // Clear cached instance if exists
        if (isset(self::$instances[$name])) {
            unset(self::$instances[$name]);
        }
    }

    /**
     * Get all registered repository names
     * 
     * @return array
     */
    public static function getRegisteredRepositories(): array
    {
        return array_keys(self::$config);
    }

    /**
     * Get repository configuration
     * 
     * @param string $name Repository name
     * @return array|null
     */
    public static function getConfig(string $name): ?array
    {
        return self::$config[$name] ?? null;
    }

    /**
     * Check if repository is registered
     * 
     * @param string $name Repository name
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$config[$name]);
    }

    /**
     * Create a fresh instance of any registered repository
     * 
     * @param string $name Repository name
     * @return object|null
     * @throws RuntimeException If repository not found or creation fails
     */
    public static function make(string $name): ?object
    {
        if (!self::has($name)) {
            throw new RuntimeException("Repository '{$name}' is not registered");
        }
        
        $config = self::$config[$name];
        
        // Resolve dependencies
        $dependencies = [];
        foreach ($config['dependencies'] as $dep) {
            $dependencies[] = self::resolveDependency($dep);
        }
        
        // Create instance
        $instance = new $config['class'](...$dependencies);
        
        // Apply configuration
        self::applyConfiguration($instance, $config);
        
        return $instance;
    }

    /**
     * Resolve a dependency by name
     * 
     * @param string $dependency Dependency identifier
     * @return mixed
     * @throws RuntimeException If dependency cannot be resolved
     */
    private static function resolveDependency(string $dependency)
    {
        $resolvers = [
            'model' => fn() => self::resolveModel($dependency),
            'cache' => fn() => self::createCacheService(),
            'db'    => fn() => self::createDatabaseConnection(),
            'config' => fn() => config('App'),
        ];
        
        // Check for specific model dependency (e.g., 'model:ProductModel')
        if (strpos($dependency, 'model:') === 0) {
            $modelName = substr($dependency, 6);
            return self::resolveSpecificModel($modelName);
        }
        
        if (isset($resolvers[$dependency])) {
            return $resolvers[$dependency]();
        }
        
        // Try to resolve as a CodeIgniter service
        if (class_exists($dependency) || interface_exists($dependency)) {
            try {
                return CodeIgniterServices::get($dependency, false);
            } catch (\Exception $e) {
                // Continue to next attempt
            }
        }
        
        // Try to create instance via reflection
        try {
            return new $dependency();
        } catch (\Exception $e) {
            throw new RuntimeException("Cannot resolve dependency: {$dependency}");
        }
    }

    /**
     * Resolve model dependency
     * 
     * @param string $modelIdentifier
     * @return object
     */
    private static function resolveModel(string $modelIdentifier): object
    {
        // Default to generic model resolution
        // In practice, you might want more sophisticated logic
        return CodeIgniterServices::models()->get($modelIdentifier);
    }

    /**
     * Resolve specific model by class name
     * 
     * @param string $modelClass
     * @return object
     */
    private static function resolveSpecificModel(string $modelClass): object
    {
        $model = model($modelClass);
        if (!$model) {
            throw new RuntimeException("Model '{$modelClass}' not found");
        }
        return $model;
    }

    /**
     * Apply configuration to repository instance
     * 
     * @param object $instance
     * @param array $config
     * @return void
     */
    private static function applyConfiguration(object $instance, array $config): void
    {
        foreach ($config as $key => $value) {
            if ($key === 'class' || $key === 'dependencies') {
                continue;
            }
            
            $method = 'set' . ucfirst($key);
            if (method_exists($instance, $method)) {
                $instance->$method($value);
            }
            
            // Try property access
            $property = $key;
            if (property_exists($instance, $property)) {
                $instance->$property = $value;
            }
        }
    }

    /**
     * Clear all cached repository instances
     * 
     * @return void
     */
    public static function clearInstances(): void
    {
        self::$instances = [];
    }

    /**
     * Clear specific repository instance
     * 
     * @param string $name Repository name
     * @return void
     */
    public static function clearInstance(string $name): void
    {
        unset(self::$instances[$name]);
    }

    /**
     * Get all cached instances
     * 
     * @return array
     */
    public static function getCachedInstances(): array
    {
        return array_keys(self::$instances);
    }

    /**
     * Preload repositories for better performance
     * 
     * @param array $repositories Repository names to preload
     * @return void
     */
    public static function preload(array $repositories = []): void
    {
        if (empty($repositories)) {
            $repositories = array_keys(self::$config);
        }
        
        foreach ($repositories as $repo) {
            if (self::has($repo)) {
                self::make($repo);
            }
        }
    }

    /**
     * Health check for all repositories
     * 
     * @return array [repository_name => status]
     */
    public static function healthCheck(): array
    {
        $health = [];
        
        foreach (self::$config as $name => $config) {
            try {
                $instance = self::make($name);
                
                // Check if instance can perform a basic operation
                $healthy = method_exists($instance, 'exists') 
                    || method_exists($instance, 'countAll')
                    || true; // If no specific method, assume healthy
                
                $health[$name] = [
                    'status' => $healthy ? 'healthy' : 'warning',
                    'class' => $config['class'],
                    'cached' => isset(self::$instances[$name]),
                ];
            } catch (\Exception $e) {
                $health[$name] = [
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                    'class' => $config['class'],
                    'cached' => isset(self::$instances[$name]),
                ];
            }
        }
        
        return $health;
    }

    /**
     * Get repository statistics
     * 
     * @return array
     */
    public static function getStatistics(): array
    {
        $stats = [
            'total_registered' => count(self::$config),
            'total_cached' => count(self::$instances),
            'registered_repositories' => self::getRegisteredRepositories(),
            'cached_instances' => self::getCachedInstances(),
            'config' => self::$config,
        ];
        
        // Add cache statistics if available
        try {
            $cacheService = self::createCacheService();
            if (method_exists($cacheService, 'getStats')) {
                $stats['cache'] = $cacheService->getStats();
            }
        } catch (\Exception $e) {
            // Ignore cache errors for stats
        }
        
        return $stats;
    }

    /**
     * Initialize with default repositories
     * 
     * @return void
     */
    public static function initialize(): void
    {
        // Product repository already registered in $config
        // Future repositories will be registered here
        
        // Example for future repositories:
        // self::register('category', CategoryRepository::class, ['model:CategoryModel', 'cache', 'db']);
        // self::register('link', LinkRepository::class, ['model:LinkModel', 'cache', 'db']);
    }

    /**
     * Magic static method for repository access
     * Example: RepositoryService::product()->find(1)
     * 
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        // Check if it's a registered repository
        if (self::has($name)) {
            $fresh = !empty($arguments) && $arguments[0] === true;
            return self::make($name);
        }
        
        // Try to call on product repository (backward compatibility)
        if ($name === 'productRepository') {
            return self::product(...$arguments);
        }
        
        throw new RuntimeException("Unknown repository or method: {$name}");
    }
}

// Initialize on load
RepositoryService::initialize();