<?php

namespace App\Repositories\Concrete;

use App\Entities\Link;
use App\Models\LinkModel;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use RuntimeException;
use InvalidArgumentException;

/**
 * Link Repository - Concrete Implementation
 * 
 * Data Orchestrator Layer with caching strategy for Link entities.
 * Implements "Transient Input, Persistent Revenue" commission logic.
 * 
 * @package App\Repositories\Concrete
 */
final class LinkRepository extends BaseRepository implements LinkRepositoryInterface
{
    /**
     * LinkModel instance with typed access
     * 
     * @var LinkModel
     */
    protected LinkModel $model;

    /**
     * Database connection for transactions
     * 
     * @var ConnectionInterface
     */
    private ConnectionInterface $db;

    /**
     * Constructor with dependency injection
     * 
     * @param LinkModel $model
     * @param CacheInterface|null $cache
     * @param ConnectionInterface $db
     */
    public function __construct(
        LinkModel $model, 
        ?CacheInterface $cache = null,
        ConnectionInterface $db
    ) {
        parent::__construct($model, $cache);
        $this->model = $model;
        $this->db = $db;
        
        // Set specific cache TTL for links (30 minutes)
        $this->setCacheTtl(1800);
    }

    // ============================================
    // CORE REPOSITORY METHODS (Type-hinted overrides)
    // ============================================

     /**
     * Alias for findActiveForProduct to satisfy generic service calls
     * @param int $productId
     * @return array
     */
    public function findByProduct(int $productId): array
    {
        return $this->findActiveForProduct($productId);
    }


    /**
     * {@inheritDoc}
     */
    public function find($id, bool $useCache = true): ?Link
    {
        return parent::find($id, $useCache);
    }

    /**
     * {@inheritDoc}
     */
    public function findOrFail($id, bool $useCache = true): Link
    {
        $link = parent::findOrFail($id, $useCache);
        if (!$link instanceof Link) {
            throw new RuntimeException('Invalid entity type returned');
        }
        return $link;
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(
        array $conditions = [], 
        ?int $limit = null, 
        int $offset = 0,
        bool $useCache = true
    ): array {
        $result = parent::findAll($conditions, $limit, $offset, $useCache);
        
        // Ensure array of Link entities
        return array_filter($result, static fn($item) => $item instanceof Link);
    }

    /**
     * {@inheritDoc}
     */
    public function save(Link $entity): bool
    {
        // Validate store name uniqueness before save
        if (!$this->validateStoreNameUniqueness($entity)) {
            throw new InvalidArgumentException(
                'Store name must be unique for this product and marketplace combination.'
            );
        }

        $isNew = $entity->isNew();
        $success = parent::save($entity);

        // Invalidate relevant caches after save
        if ($success) {
            $this->invalidateCachesForLink($entity, $isNew);
        }

        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function first(array $conditions = [], bool $useCache = true): ?Link
    {
        $result = parent::first($conditions, $useCache);
        return $result instanceof Link ? $result : null;
    }

    // ============================================
    // BUSINESS-SPECIFIC QUERY METHODS
    // ============================================

    /**
     * {@inheritDoc}
     */
    public function findActiveForProduct(int $productId, bool $useCache = true): array
    {
        $cacheKey = "active_for_product:{$productId}";
        
        return $this->remember($cacheKey, function() use ($productId) {
            return $this->model->findActiveForProduct($productId);
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    /**
     * {@inheritDoc}
     */
    public function findByProductAndMarketplace(
        int $productId, 
        int $marketplaceId, 
        bool $useCache = true
    ): ?Link {
        $cacheKey = "product_marketplace:{$productId}:{$marketplaceId}";
        
        return $this->remember($cacheKey, function() use ($productId, $marketplaceId) {
            return $this->model->findByProductAndMarketplace($productId, $marketplaceId);
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    /**
     * {@inheritDoc}
     */
    public function findNeedingPriceUpdate(
        int $marketplaceId = 0, 
        int $limit = 50, 
        bool $useCache = true
    ): array {
        $cacheKey = "needs_price_update:{$marketplaceId}:{$limit}";
        
        // Lower TTL for frequently changing data (5 minutes)
        $ttl = $useCache ? 300 : null;
        
        return $this->remember($cacheKey, function() use ($marketplaceId, $limit) {
            if ($marketplaceId > 0) {
                return $this->model->findNeedingPriceUpdate($marketplaceId, $limit);
            }
            
            // For all marketplaces
            return $this->model
                ->withScopes(['needsPriceUpdate' => null])
                ->withMarketplace()
                ->limit($limit)
                ->findAll();
        }, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function findNeedingValidation(int $limit = 100, bool $useCache = true): array
    {
        $cacheKey = "needs_validation:{$limit}";
        
        // Low TTL for validation data (2 minutes)
        $ttl = $useCache ? 120 : null;
        
        return $this->remember($cacheKey, function() use ($limit) {
            return $this->model
                ->withScopes(['needsValidation' => null])
                ->withMarketplace()
                ->limit($limit)
                ->findAll();
        }, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function findTopPerforming(
        int $limit = 10, 
        float $minRevenue = 10000.00, 
        bool $useCache = true
    ): array {
        $cacheKey = "top_performing:{$limit}:{$minRevenue}";
        
        return $this->remember($cacheKey, function() use ($limit, $minRevenue) {
            return $this->model->findTopPerforming($limit);
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    /**
     * {@inheritDoc}
     */
    public function findWithBadges(int $limit = 50, bool $useCache = true): array
    {
        $cacheKey = "with_badges:{$limit}";
        
        return $this->remember($cacheKey, function() use ($limit) {
            return $this->model
                ->withScopes(['withBadge' => null])
                ->withMarketplaceBadge()
                ->limit($limit)
                ->findAll();
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    /**
     * {@inheritDoc}
     */
    public function findByMarketplaceSorted(
        int $marketplaceId, 
        string $orderBy = 'revenue', 
        string $direction = 'DESC',
        bool $useCache = true
    ): array {
        $cacheKey = "marketplace_sorted:{$marketplaceId}:{$orderBy}:{$direction}";
        
        $allowedOrder = ['revenue', 'clicks', 'sold', 'rating', 'price'];
        $orderBy = in_array($orderBy, $allowedOrder, true) ? $orderBy : 'revenue';
        $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        
        return $this->remember($cacheKey, function() use ($marketplaceId, $orderBy, $direction) {
            return $this->model
                ->withScopes(['forMarketplace' => $marketplaceId])
                ->withScopes(['orderByPerformance' => [$orderBy, $direction]])
                ->withMarketplace()
                ->findAll();
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    // ============================================
    // STATISTICS & ANALYTICS METHODS
    // ============================================

    /**
     * {@inheritDoc}
     */
    public function getStatistics(bool $useCache = true): array
    {
        $cacheKey = 'statistics:global';
        
        // Short TTL for statistics (10 minutes)
        $ttl = $useCache ? 600 : null;
        
        return $this->remember($cacheKey, function() {
            return $this->model->getLinkStatistics();
        }, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductLinkStatistics(int $productId, bool $useCache = true): array
    {
        $cacheKey = "statistics:product:{$productId}";
        
        return $this->remember($cacheKey, function() use ($productId) {
            $builder = $this->model->builder();
            
            $stats = $builder->select([
                    'COUNT(*) as total_links',
                    'SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_links',
                    'SUM(clicks) as total_clicks',
                    'SUM(sold_count) as total_sold',
                    'SUM(CAST(affiliate_revenue AS DECIMAL(10,2))) as total_revenue',
                    'AVG(CAST(rating AS DECIMAL(3,2))) as avg_rating',
                    'MIN(CAST(price AS DECIMAL(10,2))) as min_price',
                    'MAX(CAST(price AS DECIMAL(10,2))) as max_price'
                ])
                ->where('product_id', $productId)
                ->where($this->model->deletedField . ' IS NULL')
                ->get()
                ->getRowArray();

            return $stats ?: [
                'total_links' => 0,
                'active_links' => 0,
                'total_clicks' => 0,
                'total_sold' => 0,
                'total_revenue' => '0.00',
                'avg_rating' => '0.00',
                'min_price' => '0.00',
                'max_price' => '0.00'
            ];
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceLinkStatistics(int $marketplaceId, bool $useCache = true): array
    {
        $cacheKey = "statistics:marketplace:{$marketplaceId}";
        
        return $this->remember($cacheKey, function() use ($marketplaceId) {
            $builder = $this->model->builder();
            
            $stats = $builder->select([
                    'COUNT(*) as total_links',
                    'SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_links',
                    'SUM(clicks) as total_clicks',
                    'SUM(sold_count) as total_sold',
                    'SUM(CAST(affiliate_revenue AS DECIMAL(10,2))) as total_revenue',
                    'AVG(CAST(rating AS DECIMAL(3,2))) as avg_rating',
                    'AVG(CAST(price AS DECIMAL(10,2))) as avg_price'
                ])
                ->where('marketplace_id', $marketplaceId)
                ->where($this->model->deletedField . ' IS NULL')
                ->get()
                ->getRowArray();

            return $stats ?: [
                'total_links' => 0,
                'active_links' => 0,
                'total_clicks' => 0,
                'total_sold' => 0,
                'total_revenue' => '0.00',
                'avg_rating' => '0.00',
                'avg_price' => '0.00'
            ];
        }, $useCache ? $this->defaultCacheTtl : null);
    }

    // ============================================
    // BUSINESS OPERATIONS METHODS
    // ============================================

    /**
     * {@inheritDoc}
     */
    public function incrementClicks(int $linkId): bool
    {
        try {
            $success = $this->model->incrementClicks($linkId);
            
            if ($success) {
                $this->deleteMatching("id:{$linkId}");
                $this->deleteMatching("statistics:*");
            }
            
            return $success;
        } catch (DatabaseException $e) {
            log_message('error', "Failed to increment clicks for link {$linkId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updatePrice(int $linkId, string $price): bool
    {
        // Validate price format
        if (!preg_match('/^\d+(\.\d{2})?$/', $price)) {
            throw new InvalidArgumentException('Price must be in decimal format with 2 decimals');
        }

        try {
            $success = $this->model->updatePriceWithTimestamp($linkId, $price);
            
            if ($success) {
                $this->invalidateLinkCache($linkId);
            }
            
            return $success;
        } catch (DatabaseException $e) {
            log_message('error', "Failed to update price for link {$linkId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function updateRevenueWithCommission(int $linkId, float $commissionRate): bool
    {
        // Validate commission rate
        if ($commissionRate < 0 || $commissionRate > 1) {
            throw new InvalidArgumentException('Commission rate must be between 0.00 and 1.00');
        }

        try {
            $success = $this->model->updateAffiliateRevenue($linkId, $commissionRate);
            
            if ($success) {
                $this->invalidateLinkCache($linkId);
                $this->deleteMatching("top_performing:*");
                $this->deleteMatching("statistics:*");
            }
            
            return $success;
        } catch (DatabaseException $e) {
            log_message('error', "Failed to update revenue for link {$linkId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $linkIds, bool $active): int
    {
        if (empty($linkIds)) {
            return 0;
        }

        // Validate all IDs are integers
        $linkIds = array_map('intval', $linkIds);
        $linkIds = array_filter($linkIds, static fn($id) => $id > 0);

        if (empty($linkIds)) {
            return 0;
        }

        try {
            $updated = $this->model->bulkUpdateStatus($linkIds, $active);
            
            if ($updated > 0) {
                foreach ($linkIds as $linkId) {
                    $this->invalidateLinkCache($linkId);
                }
                $this->deleteMatching("active_for_product:*");
                $this->deleteMatching("statistics:*");
            }
            
            return $updated;
        } catch (DatabaseException $e) {
            log_message('error', "Failed to bulk update status: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function markAsValidated(int $linkId): bool
    {
        $link = $this->find($linkId, false);
        if (!$link instanceof Link) {
            throw new RuntimeException("Link with ID {$linkId} not found");
        }

        $link->markAsValidated();
        return $this->save($link);
    }

    /**
     * {@inheritDoc}
     */
    public function archive(int $linkId): bool
    {
        $link = $this->find($linkId, false);
        if (!$link instanceof Link) {
            return false;
        }

        $link->archive();
        return $this->save($link);
    }

    /**
     * {@inheritDoc}
     */
    public function restore(int $linkId): bool
    {
        $success = $this->model->restore($linkId);
        
        if ($success) {
            $this->invalidateLinkCache($linkId);
            $this->deleteMatching("active_for_product:*");
            $this->deleteMatching("statistics:*");
        }
        
        return $success;
    }

    // ============================================
    // CACHE MANAGEMENT METHODS
    // ============================================

    /**
     * {@inheritDoc}
     */
    public function invalidateProductCache(int $productId): int
    {
        $patterns = [
            "active_for_product:{$productId}",
            "product_marketplace:{$productId}:*",
            "statistics:product:{$productId}"
        ];

        $deleted = 0;
        foreach ($patterns as $pattern) {
            $deleted += $this->deleteMatching($pattern);
        }

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function invalidateMarketplaceCache(int $marketplaceId): int
    {
        $patterns = [
            "product_marketplace:*:{$marketplaceId}",
            "needs_price_update:{$marketplaceId}:*",
            "marketplace_sorted:{$marketplaceId}:*",
            "statistics:marketplace:{$marketplaceId}"
        ];

        $deleted = 0;
        foreach ($patterns as $pattern) {
            $deleted += $this->deleteMatching($pattern);
        }

        return $deleted;
    }

    /**
     * Invalidate all cache for a specific link
     * 
     * @param int $linkId
     * @return int
     */
    private function invalidateLinkCache(int $linkId): int
    {
        $patterns = [
            "id:{$linkId}",
            "product_marketplace:*:{$linkId}"
        ];

        $deleted = 0;
        foreach ($patterns as $pattern) {
            $deleted += $this->deleteMatching($pattern);
        }

        return $deleted;
    }

    // ============================================
    // BULK OPERATIONS METHODS
    // ============================================

    /**
     * {@inheritDoc}
     */
    public function bulkCreate(array $linksData): array
    {
        if (empty($linksData)) {
            return [];
        }

        $createdIds = [];
        $this->db->transStart();

        try {
            foreach ($linksData as $linkData) {
                // Create entity from data
                $link = Link::fromArray($linkData);
                
                // Validate store name uniqueness
                if (!$this->validateStoreNameUniqueness($link)) {
                    throw new InvalidArgumentException(
                        "Store name '{$link->getStoreName()}' is not unique for product {$link->getProductId()} and marketplace {$link->getMarketplaceId()}"
                    );
                }

                // Save the link
                if ($this->save($link)) {
                    $createdIds[] = $link->getId();
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                return [];
            }

            return $createdIds;
        } catch (DatabaseException $e) {
            $this->db->transRollback();
            log_message('error', "Bulk create failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdatePrices(array $priceMap): int
    {
        if (empty($priceMap)) {
            return 0;
        }

        $updated = 0;
        $this->db->transStart();

        try {
            foreach ($priceMap as $linkId => $price) {
                if ($this->updatePrice($linkId, $price)) {
                    $updated++;
                }
            }

            $this->db->transComplete();
            return $this->db->transStatus() === false ? 0 : $updated;
        } catch (DatabaseException $e) {
            $this->db->transRollback();
            log_message('error', "Bulk update prices failed: " . $e->getMessage());
            return 0;
        }
    }

    // ============================================
    // VALIDATION & INTEGRITY METHODS
    // ============================================

    /**
     * {@inheritDoc}
     */
    public function isStoreNameUnique(
        string $storeName, 
        int $productId, 
        int $marketplaceId,
        ?int $excludeLinkId = null
    ): bool {
        $builder = $this->model->builder();
        
        $builder->where('store_name', $storeName)
                ->where('product_id', $productId)
                ->where('marketplace_id', $marketplaceId)
                ->where($this->model->deletedField . ' IS NULL');

        if ($excludeLinkId !== null) {
            $builder->where($this->model->primaryKey . ' !=', $excludeLinkId);
        }

        $count = $builder->countAllResults();
        return $count === 0;
    }

    /**
     * {@inheritDoc}
     */
    public function validateUrl(string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Check for allowed marketplaces patterns
        $allowedDomains = ['tokopedia.com', 'shopee.co.id', 'bukalapak.com', 'blibli.com'];
        $domain = parse_url($url, PHP_URL_HOST);
        
        foreach ($allowedDomains as $allowed) {
            if (stripos($domain, $allowed) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate store name uniqueness for a link entity
     * 
     * @param Link $link
     * @return bool
     */
    private function validateStoreNameUniqueness(Link $link): bool
    {
        return $this->isStoreNameUnique(
            $link->getStoreName(),
            $link->getProductId(),
            $link->getMarketplaceId(),
            $link->isNew() ? null : $link->getId()
        );
    }

    /**
     * Invalidate caches for link operations
     * 
     * @param Link $link
     * @param bool $isNew
     * @return void
     */
    private function invalidateCachesForLink(Link $link, bool $isNew): void
    {
        // Always invalidate link-specific cache
        if (!$isNew) {
            $this->invalidateLinkCache($link->getId());
        }

        // Invalidate product and marketplace caches
        $this->invalidateProductCache($link->getProductId());
        $this->invalidateMarketplaceCache($link->getMarketplaceId());

        // Invalidate statistics and list caches
        $this->deleteMatching("top_performing:*");
        $this->deleteMatching("needs_*:*");
        $this->deleteMatching("statistics:*");
        $this->deleteMatching("all:*");
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'link';
    }

    /**
     * Get cache key with repository-specific prefix
     * Override to add link-specific prefix
     * 
     * @param string $suffix
     * @return string
     */
    protected function getCacheKey(string $suffix): string
    {
        return parent::getCacheKey($suffix);
    }
}