<?php

namespace Config;

use App\Services\PaginationService;
use App\Services\ResponseFormatter;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * This file lets you define the dependency injection bindings for your app.
 * You can extend the BaseService class to add custom services.
 */
class Services extends BaseService
{
    /**
     * Returns a shared instance of the PaginationService.
     *
     * @param bool $getShared Whether to return a shared instance
     * @return PaginationService
     */
    public static function pagination(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('pagination');
        }

        $config = config('Pagination');
        return new PaginationService($config);
    }

    /**
     * Returns a shared instance of the ResponseFormatter.
     *
     * @param bool $getShared Whether to return a shared instance
     * @return ResponseFormatter
     */
    public static function responseFormatter(bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('responseFormatter');
        }

        $config = config('App');
        return new ResponseFormatter($config);
    }

    /**
     * Register any custom services you need here.
     * This method is automatically called by CodeIgniter's service registry.
     */
    public static function register()
    {
        // Bind custom services
        static::pagination();
        static::responseFormatter();

        // You can also override core services here if needed
        // parent::register();
    }
}
