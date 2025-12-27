<?php

namespace Config;

use App\Contracts\AdminInterface;
use App\Contracts\AuditLogInterface;
use App\Contracts\AuthInterface;
use App\Contracts\AuthorizationInterface;
use App\Contracts\BadgeInterface;
use App\Contracts\CategoryInterface;
use App\Contracts\ImageServiceInterface;
use App\Contracts\LinkInterface;
use App\Contracts\MarketplaceBadgeInterface;
use App\Contracts\MarketplaceInterface;
use App\Contracts\ProductBulkInterface;
use App\Contracts\ProductCRUDInterface;
use App\Contracts\ProductMaintenanceInterface;
use App\Contracts\ProductQueryInterface;
use App\Contracts\ProductWorkflowInterface;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\AuditLogRepositoryInterface;
use App\Repositories\Interfaces\BadgeRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceBadgeRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Repositories\Interfaces\ProductBadgeRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Services\AdminService;
use App\Services\AuditLogService;
use App\Services\AuthService;
use App\Services\AuthorizationService;
use App\Services\BadgeService;
use App\Services\CacheService;
use App\Services\CategoryService;
use App\Services\ImageService;
use App\Services\LinkService;
use App\Services\MarketplaceBadgeService;
use App\Services\MarketplaceService;
use App\Services\PaginationService;
use App\Services\Product\Cache\ProductCacheInvalidator;
use App\Services\Product\Cache\ProductCacheKeyGenerator;
use App\Services\Product\Cache\ProductCacheManager;
use App\Services\Product\Concrete\ProductBulkService;
use App\Services\Product\Concrete\ProductCRUDService;
use App\Services\Product\Concrete\ProductMaintenanceService;
use App\Services\Product\Concrete\ProductQueryService;
use App\Services\Product\Concrete\ProductWorkflowService;
use App\Services\Product\Factories\ProductResponseFactory;
use App\Services\Product\ProductOrchestrator;
use App\Services\Product\Validators\ProductBusinessValidator;
use App\Services\RepositoryService;
use App\Services\ResponseFormatter;
use App\Services\TransactionService;
use App\Services\ValidationService;
use App\Repositories\Concrete\AdminRepository;
use App\Repositories\Concrete\AuditLogRepository;
use App\Repositories\Concrete\BadgeRepository;
use App\Repositories\Concrete\CategoryRepository;
use App\Repositories\Concrete\LinkRepository;
use App\Repositories\Concrete\MarketplaceRepository;
use App\Repositories\Concrete\MarketplaceBadgeRepository;
use App\Repositories\Concrete\ProductRepository;
use App\Repositories\Concrete\ProductBadgeRepository;
use App\Libraries\ImageProcessor;
use App\Validators\AdminValidator;
use App\Validators\AuditLogValidator;
use App\Validators\AuthValidator;
use App\Validators\AuthorizationValidator;
use App\Validators\BadgeBusinessValidator;
use App\Validators\CategoryValidator;
use App\Validators\LinkValidator;
use App\Validators\MarketplaceBadgeBusinessValidator;
use App\Validators\MarketplaceBusinessValidator;
use App\Validators\ProductValidator;
use CodeIgniter\Config\BaseService;
use CodeIgniter\Cache\CacheInterface as CodeIgniterCache;

class Services extends BaseService
{
    // ====================================================
    // REPOSITORY FACTORIES (LAYER 3 - Data Orchestrator)
    // ====================================================
    
    /**
     * Admin Repository
     */
    public static function adminRepository(bool $getShared = true): AdminRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AdminRepository(
            model('AdminModel'),
            self::cacheService()
        );
    }
    
    /**
     * Audit Log Repository
     */
    public static function auditLogRepository(bool $getShared = true): AuditLogRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuditLogRepository(
            model('AuditLogModel'),
            self::cacheService()
        );
    }
    
    /**
     * Badge Repository
     */
    public static function badgeRepository(bool $getShared = true): BadgeRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new BadgeRepository(
            model('BadgeModel'),
            self::cacheService()
        );
    }
    
    /**
     * Category Repository
     */
    public static function categoryRepository(bool $getShared = true): CategoryRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new CategoryRepository(
            model('CategoryModel'),
            self::cacheService()
        );
    }
    
    /**
     * Link Repository
     */
    public static function linkRepository(bool $getShared = true): LinkRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new LinkRepository(
            model('LinkModel'),
            self::cacheService()
        );
    }
    
    /**
     * Marketplace Repository
     */
    public static function marketplaceRepository(bool $getShared = true): MarketplaceRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new MarketplaceRepository(
            model('MarketplaceModel'),
            self::cacheService()
        );
    }
    
    /**
     * Marketplace Badge Repository
     */
    public static function marketplaceBadgeRepository(bool $getShared = true): MarketplaceBadgeRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new MarketplaceBadgeRepository(
            model('MarketplaceBadgeModel'),
            self::cacheService()
        );
    }
    
    /**
     * Product Repository
     */
    public static function productRepository(bool $getShared = true): ProductRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductRepository(
            model('ProductModel'),
            self::cacheService()
        );
    }
    
    /**
     * Product Badge Repository
     */
    public static function productBadgeRepository(bool $getShared = true): ProductBadgeRepositoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductBadgeRepository(
            model('ProductBadgeModel'),
            self::cacheService()
        );
    }
    
    // ====================================================
    // SERVICE FACTORIES (LAYER 5 - Business Orchestrator)
    // ====================================================
    
    /**
     * Admin Service
     */
    public static function adminService(bool $getShared = true): AdminInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AdminService(
            self::adminRepository(),
            self::auditLogRepository(),
            self::transactionService(),
            self::validationService(),
            self::adminValidator()
        );
    }
    
    /**
     * Audit Log Service
     */
    public static function auditLogService(bool $getShared = true): AuditLogInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuditLogService(
            self::auditLogRepository(),
            self::adminRepository(),
            self::transactionService(),
            self::auditLogValidator()
        );
    }
    
    /**
     * Auth Service
     */
    public static function authService(bool $getShared = true): AuthInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuthService(
            self::adminRepository(),
            self::auditLogRepository(),
            self::transactionService(),
            self::authValidator()
        );
    }
    
    /**
     * Authorization Service
     */
    public static function authorizationService(bool $getShared = true): AuthorizationInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuthorizationService(
            self::adminRepository(),
            self::auditLogRepository(),
            self::transactionService(),
            self::authorizationValidator()
        );
    }
    
    /**
     * Badge Service
     */
    public static function badgeService(bool $getShared = true): BadgeInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new BadgeService(
            self::badgeRepository(),
            self::transactionService(),
            self::badgeBusinessValidator()
        );
    }
    
    /**
     * Category Service
     */
    public static function categoryService(bool $getShared = true): CategoryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new CategoryService(
            self::categoryRepository(),
            self::transactionService(),
            self::categoryValidator()
        );
    }
    
    /**
     * Image Service
     */
    public static function imageService(bool $getShared = true): ImageServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ImageService();
    }
    
    /**
     * Link Service
     */
    public static function linkService(bool $getShared = true): LinkInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new LinkService(
            self::linkRepository(),
            self::productRepository(),
            self::marketplaceRepository(),
            self::marketplaceBadgeRepository(),
            self::transactionService(),
            self::linkValidator()
        );
    }
    
    /**
     * Marketplace Badge Service
     */
    public static function marketplaceBadgeService(bool $getShared = true): MarketplaceBadgeInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new MarketplaceBadgeService(
            self::marketplaceBadgeRepository(),
            self::linkRepository(),
            self::transactionService(),
            self::marketplaceBadgeBusinessValidator()
        );
    }
    
    /**
     * Marketplace Service
     */
    public static function marketplaceService(bool $getShared = true): MarketplaceInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new MarketplaceService(
            self::marketplaceRepository(),
            self::linkRepository(),
            self::productRepository(),
            self::transactionService(),
            self::marketplaceBusinessValidator()
        );
    }
    
    // ====================================================
    // PRODUCT SPECIALIZED SERVICES
    // ====================================================
    
    /**
     * Product CRUD Service
     */
    public static function productCRUDService(bool $getShared = true): ProductCRUDInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductCRUDService(
            self::productRepository(),
            self::categoryRepository(),
            self::linkRepository(),
            self::auditLogRepository(),
            self::transactionService(),
            self::productValidator(),
            self::imageService()
        );
    }
    
    /**
     * Product Query Service
     */
    public static function productQueryService(bool $getShared = true): ProductQueryInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductQueryService(
            self::productRepository(),
            self::categoryRepository(),
            self::linkRepository(),
            self::marketplaceRepository(),
            self::paginationService(),
            self::productResponseFactory()
        );
    }
    
    /**
     * Product Workflow Service
     */
    public static function productWorkflowService(bool $getShared = true): ProductWorkflowInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductWorkflowService(
            self::productRepository(),
            self::auditLogRepository(),
            self::transactionService(),
            self::productValidator()
        );
    }
    
    /**
     * Product Bulk Service
     */
    public static function productBulkService(bool $getShared = true): ProductBulkInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductBulkService(
            self::productRepository(),
            self::auditLogRepository(),
            self::linkRepository(),
            self::transactionService(),
            self::productValidator(),
            self::cacheService()
        );
    }
    
    /**
     * Product Maintenance Service
     */
    public static function productMaintenanceService(bool $getShared = true): ProductMaintenanceInterface
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductMaintenanceService(
            self::productRepository(),
            self::productBadgeRepository(),
            self::transactionService()
        );
    }
    
    // ====================================================
    // PRODUCT CACHE SERVICES
    // ====================================================
    
    /**
     * Product Cache Manager
     */
    public static function productCacheManager(bool $getShared = true): ProductCacheManager
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductCacheManager(
            self::cacheService(),
            self::productCacheInvalidator(),
            self::productCacheKeyGenerator(),
            self::productRepository()
        );
    }
    
    /**
     * Product Cache Key Generator
     */
    public static function productCacheKeyGenerator(bool $getShared = true): ProductCacheKeyGenerator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductCacheKeyGenerator();
    }
    
    /**
     * Product Cache Invalidator
     */
    public static function productCacheInvalidator(bool $getShared = true): ProductCacheInvalidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductCacheInvalidator(
            self::cacheService(),
            self::productRepository(),
            self::productCacheKeyGenerator()
        );
    }
    
    /**
     * Product Business Validator
     */
    public static function productBusinessValidator(bool $getShared = true): ProductBusinessValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductBusinessValidator(
            self::productRepository(),
            self::categoryRepository(),
            self::linkRepository(),
            self::marketplaceRepository()
        );
    }
    
    /**
     * Product Response Factory
     */
    public static function productResponseFactory(bool $getShared = true): ProductResponseFactory
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductResponseFactory();
    }
    
    /**
     * Product Orchestrator
     */
    public static function productOrchestrator(bool $getShared = true): ProductOrchestrator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductOrchestrator(
            self::productCRUDService(),
            self::productQueryService(),
            self::productWorkflowService(),
            self::productBulkService(),
            self::productMaintenanceService()
        );
    }
    
    // ====================================================
    // INFRASTRUCTURE SERVICES
    // ====================================================
    
    /**
     * Cache Service
     */
    public static function cacheService(bool $getShared = true): CacheService
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new CacheService(
            service('cache')
        );
    }
    
    /**
     * Transaction Service
     */
    public static function transactionService(bool $getShared = true): TransactionService
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new TransactionService(
            service('db'),
            [
                'max_retries' => 3,
                'retry_delay' => 100, // milliseconds
                'isolation_level' => TransactionService::ISOLATION_REPEATABLE_READ,
                'timeout' => 30,
                'log_errors' => true,
                'auto_rollback' => true,
            ]
        );
    }
    
    /**
     * Validation Service
     */
    public static function validationService(bool $getShared = true): ValidationService
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ValidationService(
            self::productRepository(),
            model('CategoryModel'),
            self::linkRepository(),
            model('AdminModel')
        );
    }
    
    /**
     * Pagination Service
     */
    public static function paginationService(bool $getShared = true): PaginationService
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new PaginationService();
    }
    
    /**
     * Repository Service
     */
    public static function repositoryService(bool $getShared = true): RepositoryService
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new RepositoryService();
    }
    
    /**
     * Response Formatter
     */
    public static function responseFormatter(bool $getShared = true): ResponseFormatter
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ResponseFormatter();
    }
    
    // ====================================================
    // VALIDATOR FACTORIES
    // ====================================================
    
    /**
     * Admin Validator
     */
    public static function adminValidator(bool $getShared = true): AdminValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AdminValidator();
    }
    
    /**
     * Audit Log Validator
     */
    public static function auditLogValidator(bool $getShared = true): AuditLogValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuditLogValidator();
    }
    
    /**
     * Auth Validator
     */
    public static function authValidator(bool $getShared = true): AuthValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuthValidator();
    }
    
    /**
     * Authorization Validator
     */
    public static function authorizationValidator(bool $getShared = true): AuthorizationValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new AuthorizationValidator();
    }
    
    /**
     * Badge Business Validator
     */
    public static function badgeBusinessValidator(bool $getShared = true): BadgeBusinessValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new BadgeBusinessValidator();
    }
    
    /**
     * Category Validator
     */
    public static function categoryValidator(bool $getShared = true): CategoryValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new CategoryValidator();
    }
    
    /**
     * Link Validator
     */
    public static function linkValidator(bool $getShared = true): LinkValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new LinkValidator();
    }
    
    /**
     * Marketplace Badge Business Validator
     */
    public static function marketplaceBadgeBusinessValidator(bool $getShared = true): MarketplaceBadgeBusinessValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new MarketplaceBadgeBusinessValidator();
    }
    
    /**
     * Marketplace Business Validator
     */
    public static function marketplaceBusinessValidator(bool $getShared = true): MarketplaceBusinessValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new MarketplaceBusinessValidator();
    }
    
    /**
     * Product Validator
     */
    public static function productValidator(bool $getShared = true): ProductValidator
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ProductValidator();
    }
    
    // ====================================================
    // LIBRARY FACTORIES
    // ====================================================
    
    /**
     * Image Processor
     */
    public static function imageProcessor(bool $getShared = true): ImageProcessor
    {
        if ($getShared) {
            return static::getSharedInstance(__FUNCTION__);
        }
        
        return new ImageProcessor();
    }
}