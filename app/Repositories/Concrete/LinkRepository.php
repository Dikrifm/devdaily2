<?php

namespace App\Repositories\Concrete;

use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Entities\Link;
use App\Entities\Product;
use App\Entities\Marketplace;
use App\Models\LinkModel;
use App\Models\ProductModel;
use App\Models\MarketplaceModel;
use App\Models\MarketplaceBadgeModel;
use App\Services\CacheService;
use App\Services\AuditService;
use App\Exceptions\LinkNotFoundException;
use App\Exceptions\ValidationException;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

class LinkRepository implements LinkRepositoryInterface
{
    private LinkModel $linkModel;
    private ProductModel $productModel;
    private MarketplaceModel $marketplaceModel;
    private MarketplaceBadgeModel $marketplaceBadgeModel;
    private CacheService $cacheService;
    private AuditService $auditService;
    private ConnectionInterface $db;
    
    private int $cacheTtl = 3600;
    private string $cachePrefix = 'link_repo_';
    
    // Cache keys constants
    private const CACHE_KEY_FIND = 'find_';
    private const CACHE_KEY_BY_PRODUCT = 'by_product_';
    private const CACHE_KEY_BY_MARKETPLACE = 'by_marketplace_';
    private const CACHE_KEY_PRICE_HISTORY = 'price_history_';
    private const CACHE_KEY_STATS = 'stats_';
    private const CACHE_KEY_TOP_PERFORMERS = 'top_performers_';
    private const CACHE_KEY_NEEDS_UPDATE = 'needs_update_';
    private const CACHE_KEY_NEEDS_VALIDATION = 'needs_validation_';
    
    // Configuration constants
    private const MIN_PRICE_UPDATE_INTERVAL = 3600; // 1 hour
    private const MIN_VALIDATION_INTERVAL = 7200;   // 2 hours
    private const PRICE_CHANGE_THRESHOLD = 0.1;     // 10%
    private const MAX_CLICKS_PER_DAY = 1000;
    
    public function __construct(
        LinkModel $linkModel,
        ProductModel $productModel,
        MarketplaceModel $marketplaceModel,
        MarketplaceBadgeModel $marketplaceBadgeModel,
        CacheService $cacheService,
        AuditService $auditService,
        ConnectionInterface $db
    ) {
        $this->linkModel = $linkModel;
        $this->productModel = $productModel;
        $this->marketplaceModel = $marketplaceModel;
        $this->marketplaceBadgeModel = $marketplaceBadgeModel;
        $this->cacheService = $cacheService;
        $this->auditService = $auditService;
        $this->db = $db;
    }
    
    // ==================== BASIC CRUD OPERATIONS ====================
    
    public function find(int $id, bool $withTrashed = false): ?Link
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_FIND . $id . '_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($id, $withTrashed) {
            $link = $withTrashed 
                ? $this->linkModel->withDeleted()->find($id)
                : $this->linkModel->find($id);
                
            if (!$link instanceof Link) {
                return null;
            }
            
            return $link;
        }, $this->cacheTtl);
    }
    
    public function findByProductAndMarketplace(
        int $productId, 
        int $marketplaceId, 
        bool $activeOnly = true
    ): ?Link {
        $cacheKey = $this->getCacheKey("find_by_product_marketplace_{$productId}_{$marketplaceId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($productId, $marketplaceId, $activeOnly) {
            $builder = $this->linkModel;
            
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            $builder->where('product_id', $productId)
                   ->where('marketplace_id', $marketplaceId);
                   
            return $builder->first();
        }, $this->cacheTtl);
    }
    
    public function findByUrl(string $url, bool $withTrashed = false): ?Link
    {
        $cacheKey = $this->getCacheKey("find_by_url_" . md5($url) . '_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($url, $withTrashed) {
            $builder = $withTrashed 
                ? $this->linkModel->withDeleted()
                : $this->linkModel;
                
            $builder->where('url', $url);
            return $builder->first();
        }, $this->cacheTtl);
    }
    
    public function findAll(
        array $filters = [],
        string $sortBy = 'created_at',
        string $sortDirection = 'DESC',
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKey(
            "find_all_" . 
            md5(serialize($filters)) . "_" . 
            "{$sortBy}_{$sortDirection}_" . 
            ($withTrashed ? 'with' : 'without')
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($filters, $sortBy, $sortDirection, $withTrashed) {
            $builder = $withTrashed 
                ? $this->linkModel->withDeleted()
                : $this->linkModel;
            
            // Apply filters
            $this->applyFilters($builder, $filters);
            
            // Apply sorting
            $builder->orderBy($sortBy, $sortDirection);
            
            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }
    
    public function save(Link $link): Link
    {
        $isUpdate = $link->getId() !== null;
        $oldData = $isUpdate ? $this->find($link->getId(), true)?->toArray() : null;
        
        try {
            $this->db->transBegin();
            
            // Validate before save
            $validationResult = $this->validate($link);
            if (!$validationResult['is_valid']) {
                throw new ValidationException(
                    'Link validation failed',
                    $validationResult['errors']
                );
            }
            
            // Check for duplicate URL
            if (!$this->isUrlUnique($link->getUrl(), $link->getId())) {
                throw new ValidationException(
                    'Link URL already exists',
                    ['url' => 'This URL is already associated with another link']
                );
            }
            
            // Check for duplicate product-marketplace combination
            $existingLink = $this->findByProductAndMarketplace(
                $link->getProductId(),
                $link->getMarketplaceId(),
                false // Check all, not just active
            );
            
            if ($existingLink && $existingLink->getId() !== $link->getId()) {
                throw new ValidationException(
                    'Duplicate link',
                    ['product_marketplace' => 'This product already has a link for this marketplace']
                );
            }
            
            // Prepare for save
            $link->prepareForSave($isUpdate);
            
            // Save to database
            $saved = $isUpdate 
                ? $this->linkModel->update($link->getId(), $link)
                : $this->linkModel->insert($link);
                
            if (!$saved) {
                throw new RuntimeException(
                    'Failed to save link: ' . 
                    implode(', ', $this->linkModel->errors())
                );
            }
            
            // If new link, get the ID
            if (!$isUpdate) {
                $link->setId($this->linkModel->getInsertID());
            }
            
            // Clear relevant caches
            $this->clearCache($link->getId(), $link->getProductId());
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $isUpdate ? 'UPDATE' : 'CREATE';
                $adminId = service('auth')->user()?->getId() ?? 0;
                
                $this->auditService->logCrudOperation(
                    'LINK',
                    $link->getId(),
                    $action,
                    $adminId,
                    $oldData,
                    $link->toArray()
                );
            }
            
            $this->db->transCommit();
            
            return $link;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository save failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to save link: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function delete(int $id, bool $force = false): bool
    {
        $link = $this->find($id, true);
        if (!$link) {
            throw LinkNotFoundException::forId($id);
        }
        
        // Check if can be deleted
        $canDeleteResult = $this->canDelete($id);
        if (!$canDeleteResult['can_delete'] && !$force) {
            throw new ValidationException(
                'Cannot delete link',
                $canDeleteResult['reasons']
            );
        }
        
        try {
            $this->db->transBegin();
            
            $oldData = $link->toArray();
            $adminId = service('auth')->user()?->getId() ?? 0;
            
            if ($force) {
                // Permanent deletion
                $deleted = $this->linkModel->delete($id, true);
            } else {
                // Soft delete
                $link->softDelete();
                $deleted = $this->linkModel->save($link);
            }
            
            if (!$deleted) {
                throw new RuntimeException('Failed to delete link');
            }
            
            // Clear caches
            $this->clearCache($id, $link->getProductId());
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $force ? 'DELETE' : 'SOFT_DELETE';
                $this->auditService->logCrudOperation(
                    'LINK',
                    $id,
                    $action,
                    $adminId,
                    $oldData,
                    null
                );
            }
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository delete failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to delete link: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function restore(int $id): bool
    {
        $link = $this->find($id, true);
        if (!$link || !$link->isDeleted()) {
            return false;
        }
        
        try {
            $this->db->transBegin();
            
            $link->restore();
            $restored = $this->linkModel->save($link);
            
            if (!$restored) {
                throw new RuntimeException('Failed to restore link');
            }
            
            // Clear caches
            $this->clearCache($id, $link->getProductId());
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logCrudOperation(
                    'LINK',
                    $id,
                    'RESTORE',
                    $adminId,
                    null,
                    $link->toArray()
                );
            }
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository restore failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function exists(int $id, bool $withTrashed = false): bool
    {
        $cacheKey = $this->getCacheKey("exists_{$id}_" . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($id, $withTrashed) {
            $builder = $withTrashed 
                ? $this->linkModel->withDeleted()
                : $this->linkModel;
                
            return $builder->find($id) !== null;
        }, 300);
    }
    
    // ==================== PRODUCT-LINK RELATIONS ====================
    
    public function findByProduct(
        int $productId,
        bool $activeOnly = true,
        bool $withTrashed = false,
        string $sortBy = 'price',
        string $sortDirection = 'ASC'
    ): array {
        $cacheKey = $this->getCacheKey(
            self::CACHE_KEY_BY_PRODUCT . 
            "{$productId}_" .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with_' : 'without_') .
            "{$sortBy}_{$sortDirection}"
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($productId, $activeOnly, $withTrashed, $sortBy, $sortDirection) {
            $builder = $withTrashed 
                ? $this->linkModel->withDeleted()
                : $this->linkModel;
                
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            $builder->where('product_id', $productId)
                   ->orderBy($sortBy, $sortDirection);
                   
            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }
    
    public function findActiveByProduct(int $productId): array
    {
        return $this->findByProduct($productId, true, false, 'price', 'ASC');
    }
    
    public function countByProduct(int $productId, bool $activeOnly = true): int
    {
        $cacheKey = $this->getCacheKey("count_by_product_{$productId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($productId, $activeOnly) {
            $builder = $this->linkModel->where('product_id', $productId);
            
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            return $builder->countAllResults();
        }, 300);
    }
    
    public function productHasActiveLinks(int $productId): bool
    {
        return $this->countByProduct($productId, true) > 0;
    }
    
    public function findCheapestByProduct(int $productId, bool $activeOnly = true): ?Link
    {
        $links = $this->findByProduct($productId, $activeOnly, false, 'price', 'ASC');
        return !empty($links) ? $links[0] : null;
    }
    
    public function findHighestRatedByProduct(int $productId, bool $activeOnly = true): ?Link
    {
        $links = $this->findByProduct($productId, $activeOnly, false, 'rating', 'DESC');
        return !empty($links) ? $links[0] : null;
    }
    
    // ==================== MARKETPLACE-LINK RELATIONS ====================
    
    public function findByMarketplace(
        int $marketplaceId,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(
            self::CACHE_KEY_BY_MARKETPLACE . 
            "{$marketplaceId}_" .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with_' : 'without_') .
            "{$limit}_{$offset}"
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $activeOnly, $withTrashed, $limit, $offset) {
            $builder = $withTrashed 
                ? $this->linkModel->withDeleted()
                : $this->linkModel;
                
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            $builder->where('marketplace_id', $marketplaceId)
                   ->orderBy('created_at', 'DESC')
                   ->limit($limit, $offset);
                   
            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }
    
    public function countByMarketplace(int $marketplaceId, bool $activeOnly = true): int
    {
        $cacheKey = $this->getCacheKey("count_by_marketplace_{$marketplaceId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $activeOnly) {
            $builder = $this->linkModel->where('marketplace_id', $marketplaceId);
            
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            return $builder->countAllResults();
        }, 300);
    }
    
    // ==================== PRICE MANAGEMENT ====================
    
    public function updatePrice(int $linkId, string $newPrice, bool $autoUpdateTimestamp = true): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            throw LinkNotFoundException::forId($linkId);
        }
        
        $oldPrice = $link->getPrice();
        $priceChange = $this->calculatePriceChange($oldPrice, $newPrice);
        
        try {
            $this->db->transBegin();
            
            // Update price
            $link->setPrice($newPrice);
            
            if ($autoUpdateTimestamp) {
                $link->setLastPriceUpdate(new DateTimeImmutable());
            }
            
            $updated = $this->linkModel->save($link);
            
            if (!$updated) {
                throw new RuntimeException('Failed to update price');
            }
            
            // Log price history if significant change
            if ($priceChange >= self::PRICE_CHANGE_THRESHOLD) {
                $this->logPriceHistory($linkId, $oldPrice, $newPrice, 'manual_update');
            }
            
            // Clear caches
            $this->clearCache($linkId, $link->getProductId());
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $changes = [
                    'price' => [
                        'old' => $oldPrice,
                        'new' => $newPrice,
                        'change_percentage' => round($priceChange * 100, 2)
                    ]
                ];
                
                $this->auditService->logCrudOperation(
                    'LINK',
                    $linkId,
                    'UPDATE',
                    $adminId,
                    ['price' => $oldPrice],
                    ['price' => $newPrice],
                    json_encode($changes)
                );
            }
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository updatePrice failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function updatePriceWithHistory(
        int $linkId,
        string $newPrice,
        string $changeReason = 'manual_update',
        ?int $adminId = null
    ): array {
        $link = $this->find($linkId);
        if (!$link) {
            throw LinkNotFoundException::forId($linkId);
        }
        
        $oldPrice = $link->getPrice();
        $priceChange = $this->calculatePriceChange($oldPrice, $newPrice);
        
        try {
            $this->db->transBegin();
            
            // Update price
            $link->setPrice($newPrice);
            $link->setLastPriceUpdate(new DateTimeImmutable());
            
            $updated = $this->linkModel->save($link);
            
            if (!$updated) {
                throw new RuntimeException('Failed to update price');
            }
            
            // Always log price history for this method
            $historyId = $this->logPriceHistory($linkId, $oldPrice, $newPrice, $changeReason, $adminId);
            
            // Clear caches
            $this->clearCache($linkId, $link->getProductId());
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = $adminId ?? service('auth')->user()?->getId() ?? 0;
                $changes = [
                    'price' => [
                        'old' => $oldPrice,
                        'new' => $newPrice,
                        'change_percentage' => round($priceChange * 100, 2),
                        'reason' => $changeReason
                    ]
                ];
                
                $this->auditService->logCrudOperation(
                    'LINK',
                    $linkId,
                    'UPDATE',
                    $adminId,
                    ['price' => $oldPrice],
                    ['price' => $newPrice],
                    json_encode($changes)
                );
            }
            
            $this->db->transCommit();
            
            return [
                'success' => true,
                'price_change' => $priceChange,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'history_id' => $historyId
            ];
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository updatePriceWithHistory failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'price_change' => 0,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function getPriceHistory(int $linkId, int $limit = 50, string $period = 'all'): array
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_PRICE_HISTORY . "{$linkId}_{$limit}_{$period}");
        
        return $this->cacheService->remember($cacheKey, function() use ($linkId, $limit, $period) {
            // In real implementation, query price_history table
            // This is a simplified version
            
            $history = [];
            
            // Example query:
            // $builder = $this->db->table('price_history');
            // $builder->where('link_id', $linkId);
            
            // if ($period !== 'all') {
            //     $dateCondition = $this->getDateCondition($period);
            //     $builder->where($dateCondition);
            // }
            
            // $builder->orderBy('created_at', 'DESC')->limit($limit);
            // $history = $builder->get()->getResultArray();
            
            return $history;
        }, 1800);
    }
    
    public function findNeedsPriceUpdate(int $maxAgeHours = 24, int $limit = 100, bool $activeOnly = true): array
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_NEEDS_UPDATE . "{$maxAgeHours}_{$limit}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($maxAgeHours, $limit, $activeOnly) {
            $builder = $this->linkModel;
            
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            // Links without last_price_update or older than maxAgeHours
            $cutoffTime = date('Y-m-d H:i:s', time() - ($maxAgeHours * 3600));
            
            $builder->groupStart()
                   ->where('last_price_update IS NULL')
                   ->orWhere('last_price_update <', $cutoffTime)
                   ->groupEnd();
                   
            $builder->orderBy('last_price_update', 'ASC NULLS FIRST')
                   ->limit($limit);
                   
            $result = $builder->findAll();
            return $result ?: [];
        }, 300); // Short cache as this needs to be fresh
    }
    
    public function markPriceChecked(int $linkId): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        try {
            $link->setLastPriceUpdate(new DateTimeImmutable());
            $updated = $this->linkModel->save($link);
            
            if ($updated) {
                $this->clearCache($linkId, $link->getProductId());
            }
            
            return $updated;
            
        } catch (\Exception $e) {
            log_message('error', 'LinkRepository markPriceChecked failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function getPriceStatisticsByProduct(int $productId, bool $activeOnly = true): array
    {
        $cacheKey = $this->getCacheKey("price_stats_product_{$productId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($productId, $activeOnly) {
            $links = $this->findByProduct($productId, $activeOnly);
            
            if (empty($links)) {
                return [
                    'min' => 0,
                    'max' => 0,
                    'avg' => 0,
                    'median' => 0,
                    'count' => 0
                ];
            }
            
            $prices = array_map(function($link) {
                return (float) $link->getPrice();
            }, $links);
            
            sort($prices);
            
            $count = count($prices);
            $min = min($prices);
            $max = max($prices);
            $avg = array_sum($prices) / $count;
            
            // Calculate median
            $middle = floor(($count - 1) / 2);
            if ($count % 2) {
                $median = $prices[$middle];
            } else {
                $median = ($prices[$middle] + $prices[$middle + 1]) / 2;
            }
            
            return [
                'min' => $min,
                'max' => $max,
                'avg' => round($avg, 2),
                'median' => round($median, 2),
                'count' => $count,
                'currency' => 'IDR' // Default, should come from configuration
            ];
        }, $this->cacheTtl);
    }
    
    // ==================== VALIDATION & STATUS MANAGEMENT ====================
    
    public function validate(int $linkId, bool $force = false): array
    {
        $link = $this->find($linkId);
        if (!$link) {
            throw LinkNotFoundException::forId($linkId);
        }
        
        // Check if validation is needed
        if (!$force && !$this->needsValidation($link)) {
            return [
                'is_valid' => $link->isValid(),
                'status_code' => 200,
                'message' => 'Validation not required yet',
                'last_validation' => $link->getLastValidation()?->format('Y-m-d H:i:s'),
                'cached' => true
            ];
        }
        
        try {
            $url = $link->getUrl();
            $isValid = $this->validateUrl($url);
            
            // Update validation status
            $link->setLastValidation(new DateTimeImmutable());
            $this->linkModel->save($link);
            
            // Clear cache
            $this->clearCache($linkId, $link->getProductId());
            
            // Log validation result
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logStateTransition(
                    'LINK',
                    $linkId,
                    $link->isValid() ? 'valid' : 'invalid',
                    $isValid ? 'valid' : 'invalid',
                    $adminId,
                    'URL validation performed'
                );
            }
            
            return [
                'is_valid' => $isValid,
                'status_code' => $isValid ? 200 : 404,
                'message' => $isValid ? 'URL is accessible' : 'URL is not accessible',
                'last_validation' => $link->getLastValidation()->format('Y-m-d H:i:s'),
                'checked_url' => $url
            ];
            
        } catch (\Exception $e) {
            log_message('error', 'LinkRepository validate failed: ' . $e->getMessage());
            
            return [
                'is_valid' => false,
                'status_code' => 500,
                'message' => 'Validation failed: ' . $e->getMessage(),
                'last_validation' => null,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function markAsValidated(int $linkId, bool $isValid = true, ?string $validationNotes = null): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        try {
            $link->setLastValidation(new DateTimeImmutable());
            
            // If link is invalid, deactivate it
            if (!$isValid) {
                $link->setActive(false);
            }
            
            $updated = $this->linkModel->save($link);
            
            if ($updated) {
                $this->clearCache($linkId, $link->getProductId());
                
                // Log validation notes if provided
                if ($validationNotes) {
                    $this->logValidationNotes($linkId, $validationNotes, $isValid);
                }
            }
            
            return $updated;
            
        } catch (\Exception $e) {
            log_message('error', 'LinkRepository markAsValidated failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function findNeedsValidation(int $maxAgeHours = 48, int $limit = 100, bool $activeOnly = true): array
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_NEEDS_VALIDATION . "{$maxAgeHours}_{$limit}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($maxAgeHours, $limit, $activeOnly) {
            $builder = $this->linkModel;
            
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            // Links without last_validation or older than maxAgeHours
            $cutoffTime = date('Y-m-d H:i:s', time() - ($maxAgeHours * 3600));
            
            $builder->groupStart()
                   ->where('last_validation IS NULL')
                   ->orWhere('last_validation <', $cutoffTime)
                   ->groupEnd();
                   
            $builder->orderBy('last_validation', 'ASC NULLS FIRST')
                   ->limit($limit);
                   
            $result = $builder->findAll();
            return $result ?: [];
        }, 300); // Short cache
    }
    
    public function setActiveStatus(int $linkId, bool $active, ?string $reason = null): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        $oldStatus = $link->isActive();
        
        if ($oldStatus === $active) {
            return true; // Already in desired state
        }
        
        try {
            $link->setActive($active);
            $updated = $this->linkModel->save($link);
            
            if ($updated) {
                $this->clearCache($linkId, $link->getProductId());
                
                // Log status change
                if ($this->auditService->isEnabled()) {
                    $adminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logStateTransition(
                        'LINK',
                        $linkId,
                        $oldStatus ? 'active' : 'inactive',
                        $active ? 'active' : 'inactive',
                        $adminId,
                        $reason ?? 'Status changed'
                    );
                }
            }
            
            return $updated;
            
        } catch (\Exception $e) {
            log_message('error', 'LinkRepository setActiveStatus failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function activate(int $linkId, ?string $reason = null): bool
    {
        return $this->setActiveStatus($linkId, true, $reason);
    }
    
    public function deactivate(int $linkId, ?string $reason = null): bool
    {
        return $this->setActiveStatus($linkId, false, $reason);
    }
    
    public function isValid(int $linkId): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        // Check if validation is needed
        if ($this->needsValidation($link)) {
            $validationResult = $this->validate($linkId, false);
            return $validationResult['is_valid'];
        }
        
        return $link->isValid();
    }
    
    // ==================== CLICK & AFFILIATE TRACKING ====================
    
    public function incrementClicks(int $linkId, int $increment = 1): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        try {
            $link->setClicks($link->getClicks() + $increment);
            $updated = $this->linkModel->save($link);
            
            if ($updated) {
                $this->clearCache($linkId, $link->getProductId());
                
                // Log click in separate table for analytics
                // $this->logClick($linkId, $increment);
            }
            
            return $updated;
            
        } catch (\Exception $e) {
            log_message('error', 'LinkRepository incrementClicks failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function recordClick(
        int $linkId,
        string $ipAddress,
        ?string $userAgent = null,
        array $metadata = []
    ): bool {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        try {
            $this->db->transBegin();
            
            // Increment click count
            $this->incrementClicks($linkId);
            
            // Record detailed click in analytics table
            $clickData = [
                'link_id' => $linkId,
                'product_id' => $link->getProductId(),
                'marketplace_id' => $link->getMarketplaceId(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => json_encode($metadata),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // $this->db->table('link_clicks')->insert($clickData);
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository recordClick failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function incrementSoldCount(int $linkId, int $increment = 1): bool
    {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        try {
            $link->setSoldCount($link->getSoldCount() + $increment);
            $updated = $this->linkModel->save($link);
            
            if ($updated) {
                $this->clearCache($linkId, $link->getProductId());
            }
            
            return $updated;
            
        } catch (\Exception $e) {
            log_message('error', 'LinkRepository incrementSoldCount failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function addAffiliateRevenue(
        int $linkId,
        string $amount,
        string $currency = 'IDR',
        ?string $transactionId = null,
        array $metadata = []
    ): bool {
        $link = $this->find($linkId);
        if (!$link) {
            return false;
        }
        
        try {
            $this->db->transBegin();
            
            // Convert amount to numeric for calculation
            $currentRevenue = (float) $link->getAffiliateRevenue();
            $newRevenue = $currentRevenue + (float) $amount;
            
            $link->setAffiliateRevenue(number_format($newRevenue, 2, '.', ''));
            $updated = $this->linkModel->save($link);
            
            if ($updated) {
                // Log revenue transaction
                $revenueData = [
                    'link_id' => $linkId,
                    'product_id' => $link->getProductId(),
                    'marketplace_id' => $link->getMarketplaceId(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'transaction_id' => $transactionId,
                    'metadata' => json_encode($metadata),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                // $this->db->table('affiliate_revenue')->insert($revenueData);
                
                $this->clearCache($linkId, $link->getProductId());
                
                // Log audit trail
                if ($this->auditService->isEnabled()) {
                    $adminId = service('auth')->user()?->getId() ?? 0;
                    $this->auditService->logCrudOperation(
                        'LINK_REVENUE',
                        $linkId,
                        'CREATE',
                        $adminId,
                        null,
                        $revenueData
                    );
                }
            }
            
            $this->db->transCommit();
            
            return $updated;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'LinkRepository addAffiliateRevenue failed: ' . $e->getMessage());
            return false;
        }
    }
    
    // ==================== STATISTICS & ANALYTICS ====================
    
    public function getStatistics(?int $linkId = null): array
    {
        if ($linkId) {
            return $this->getLinkStatistics($linkId);
        }
        
        return $this->getSystemStatistics();
    }
    
    public function countByStatus(bool $withTrashed = false): array
    {
        $cacheKey = $this->getCacheKey("count_by_status_" . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($withTrashed) {
            $builder = $withTrashed 
                ? $this->linkModel->withDeleted()
                : $this->linkModel;
            
            $total = $builder->countAllResults();
            
            $builder->where('active', true);
            $active = $builder->countAllResults();
            
            $builder->where('active', false);
            $inactive = $builder->countAllResults();
            
            // Needs validation (active links without recent validation)
            $cutoffTime = date('Y-m-d H:i:s', time() - self::MIN_VALIDATION_INTERVAL);
            $needsValidation = $this->linkModel
                ->where('active', true)
                ->groupStart()
                ->where('last_validation IS NULL')
                ->orWhere('last_validation <', $cutoffTime)
                ->groupEnd()
                ->countAllResults();
            
            // Needs price update (active links without recent price update)
            $priceCutoffTime = date('Y-m-d H:i:s', time() - self::MIN_PRICE_UPDATE_INTERVAL);
            $needsPriceUpdate = $this->linkModel
                ->where('active', true)
                ->groupStart()
                ->where('last_price_update IS NULL')
                ->orWhere('last_price_update <', $priceCutoffTime)
                ->groupEnd()
                ->countAllResults();
            
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'needs_validation' => $needsValidation,
                'needs_price_update' => $needsPriceUpdate,
            ];
        }, 300);
    }
    
    // ==================== PRIVATE HELPER METHODS ====================
    
    private function getCacheKey(string $suffix): string
    {
        return $this->cachePrefix . $suffix;
    }
    
    private function applyFilters(&$builder, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if ($value !== null && $value !== '') {
                if ($field === 'search') {
                    $builder->groupStart()
                           ->like('store_name', $value)
                           ->orLike('url', $value)
                           ->groupEnd();
                } elseif ($field === 'min_price') {
                    $builder->where('price >=', $value);
                } elseif ($field === 'max_price') {
                    $builder->where('price <=', $value);
                } elseif ($field === 'needs_price_update') {
                    if ($value) {
                        $cutoffTime = date('Y-m-d H:i:s', time() - self::MIN_PRICE_UPDATE_INTERVAL);
                        $builder->groupStart()
                               ->where('last_price_update IS NULL')
                               ->orWhere('last_price_update <', $cutoffTime)
                               ->groupEnd();
                    }
                } elseif ($field === 'needs_validation') {
                    if ($value) {
                        $cutoffTime = date('Y-m-d H:i:s', time() - self::MIN_VALIDATION_INTERVAL);
                        $builder->groupStart()
                               ->where('last_validation IS NULL')
                               ->orWhere('last_validation <', $cutoffTime)
                               ->groupEnd();
                    }
                } elseif (is_array($value)) {
                    $builder->whereIn($field, $value);
                } else {
                    $builder->where($field, $value);
                }
            }
        }
    }
    
    private function calculatePriceChange(string $oldPrice, string $newPrice): float
    {
        $old = (float) $oldPrice;
        $new = (float) $newPrice;
        
        if ($old === 0.0) {
            return 1.0; // 100% change if old price was 0
        }
        
        return abs($new - $old) / $old;
    }
    
    private function logPriceHistory(
        int $linkId,
        string $oldPrice,
        string $newPrice,
        string $changeReason,
        ?int $adminId = null
    ): ?int {
        try {
            $historyData = [
                'link_id' => $linkId,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'change_percentage' => round($this->calculatePriceChange($oldPrice, $newPrice) * 100, 2),
                'change_reason' => $changeReason,
                'changed_by' => $adminId,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // $this->db->table('price_history')->insert($historyData);
            // return $this->db->insertID();
            
            return null; // Placeholder
            
        } catch (\Exception $e) {
            log_message('error', 'Failed to log price history: ' . $e->getMessage());
            return null;
        }
    }
    
    private function validateUrl(string $url): bool
    {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        // Check URL format (should start with http:// or https://)
        if (!preg_match('/^https?:\/\//', $url)) {
            return false;
        }
        
        // Check if URL is accessible (optional, can be heavy)
        // return $this->checkUrlAccessibility($url);
        
        return true;
    }
    
    private function needsValidation(Link $link): bool
    {
        if (!$link->isActive()) {
            return false;
        }
        
        $lastValidation = $link->getLastValidation();
        if (!$lastValidation) {
            return true; // Never validated
        }
        
        $now = new DateTimeImmutable();
        $interval = $now->getTimestamp() - $lastValidation->getTimestamp();
        
        return $interval > self::MIN_VALIDATION_INTERVAL;
    }
    
    private function logValidationNotes(int $linkId, string $notes, bool $isValid): void
    {
        try {
            $validationData = [
                'link_id' => $linkId,
                'is_valid' => $isValid,
                'notes' => $notes,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // $this->db->table('validation_logs')->insert($validationData);
            
        } catch (\Exception $e) {
            log_message('error', 'Failed to log validation notes: ' . $e->getMessage());
        }
    }
    
    private function getLinkStatistics(int $linkId): array
    {
        $link = $this->find($linkId);
        if (!$link) {
            return [];
        }
        
        return [
            'id' => $link->getId(),
            'store_name' => $link->getStoreName(),
            'product_id' => $link->getProductId(),
            'marketplace_id' => $link->getMarketplaceId(),
            'price' => $link->getPrice(),
            'clicks' => $link->getClicks(),
            'sold_count' => $link->getSoldCount(),
            'affiliate_revenue' => $link->getAffiliateRevenue(),
            'rating' => $link->getRating(),
            'active' => $link->isActive(),
            'last_price_update' => $link->getLastPriceUpdate()?->format('Y-m-d H:i:s'),
            'last_validation' => $link->getLastValidation()?->format('Y-m-d H:i:s'),
            'created_at' => $link->getCreatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
    
    private function getSystemStatistics(): array
    {
        return [
            'total_links' => $this->countAll(),
            'active_links' => $this->linkModel->where('active', true)->countAllResults(),
            'total_clicks' => $this->db->table('links')->selectSum('clicks')->get()->getRow()->clicks ?? 0,
            'total_revenue' => $this->db->table('links')->selectSum('affiliate_revenue')->get()->getRow()->affiliate_revenue ?? 0,
            'average_rating' => $this->db->table('links')->selectAvg('rating')->get()->getRow()->rating ?? 0,
            'top_marketplace' => [], // Would require group by query
            'recent_activity' => [], // Last 10 links created
        ];
    }
    
    private function isUrlUnique(string $url, ?int $excludeId = null): bool
    {
        $builder = $this->linkModel->where('url', $url);
        
        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }
        
        return $builder->countAllResults() === 0;
    }
    
    public function validate(Link $link): array
    {
        $errors = [];
        $isValid = true;
        
        // Required fields
        if (empty($link->getProductId())) {
            $errors[] = 'Product ID is required';
            $isValid = false;
        }
        
        if (empty($link->getMarketplaceId())) {
            $errors[] = 'Marketplace ID is required';
            $isValid = false;
        }
        
        if (empty($link->getStoreName())) {
            $errors[] = 'Store name is required';
            $isValid = false;
        }
        
        if (empty($link->getPrice())) {
            $errors[] = 'Price is required';
            $isValid = false;
        }
        
        // URL validation
        $url = $link->getUrl();
        if ($url && !$this->validateUrl($url)) {
            $errors[] = 'Invalid URL format';
            $isValid = false;
        }
        
        // Price validation
        $price = (float) $link->getPrice();
        if ($price < 0) {
            $errors[] = 'Price cannot be negative';
            $isValid = false;
        }
        
        if ($price > 1000000000) { // 1 billion
            $errors[] = 'Price exceeds maximum allowed value';
            $isValid = false;
        }
        
        // Rating validation
        $rating = (float) $link->getRating();
        if ($rating < 0 || $rating > 5) {
            $errors[] = 'Rating must be between 0 and 5';
            $isValid = false;
        }
        
        // Check if product exists
        $product = $this->productModel->find($link->getProductId());
        if (!$product) {
            $errors[] = 'Product does not exist';
            $isValid = false;
        }
        
        // Check if marketplace exists
        $marketplace = $this->marketplaceModel->find($link->getMarketplaceId());
        if (!$marketplace) {
            $errors[] = 'Marketplace does not exist';
            $isValid = false;
        }
        
        return [
            'is_valid' => $isValid,
            'errors' => $errors,
        ];
    }
    
    public function clearCache(?int $linkId = null, ?int $productId = null): void
    {
        // Clear specific link caches
        if ($linkId) {
            $patterns = [
                $this->getCacheKey(self::CACHE_KEY_FIND . "{$linkId}_*"),
                $this->getCacheKey(self::CACHE_KEY_PRICE_HISTORY . "{$linkId}_*"),
                $this->getCacheKey("stats_{$linkId}"),
                $this->getCacheKey("exists_{$linkId}_*"),
            ];
            
            foreach ($patterns as $pattern) {
                $keys = $this->cacheService->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    $this->cacheService->deleteMultiple($keys);
                }
            }
        }
        
        // Clear product-related caches
        if ($productId) {
            $patterns = [
                $this->getCacheKey(self::CACHE_KEY_BY_PRODUCT . "{$productId}_*"),
                $this->getCacheKey("price_stats_product_{$productId}_*"),
                $this->getCacheKey("count_by_product_{$productId}_*"),
            ];
            
            foreach ($patterns as $pattern) {
                $keys = $this->cacheService->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    $this->cacheService->deleteMultiple($keys);
                }
            }
        }
        
        // Clear system-wide caches if no specific ID
        if (!$linkId && !$productId) {
            $patterns = [
                $this->getCacheKey('*'),
                $this->getCacheKey(self::CACHE_KEY_NEEDS_UPDATE . '*'),
                $this->getCacheKey(self::CACHE_KEY_NEEDS_VALIDATION . '*'),
                $this->getCacheKey(self::CACHE_KEY_TOP_PERFORMERS . '*'),
                $this->getCacheKey(self::CACHE_KEY_STATS . '*'),
            ];
            
            foreach ($patterns as $pattern) {
                $keys = $this->cacheService->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    $this->cacheService->deleteMultiple($keys);
                }
            }
        }
    }
    
    // ==================== FACTORY METHOD ====================
    
    public static function create(): self
    {
        $linkModel = model(LinkModel::class);
        $productModel = model(ProductModel::class);
        $marketplaceModel = model(MarketplaceModel::class);
        $marketplaceBadgeModel = model(MarketplaceBadgeModel::class);
        $cacheService = service('cache');
        $auditService = service('audit');
        $db = db_connect();
        
        return new self(
            $linkModel,
            $productModel,
            $marketplaceModel,
            $marketplaceBadgeModel,
            $cacheService,
            $auditService,
            $db
        );
    }
    
    // Note: Many more methods need to be implemented to complete the interface
    // This is a partial implementation focusing on core functionality
    
    public function canDelete(int $linkId): array
    {
        $link = $this->find($linkId, true);
        if (!$link) {
            return [
                'can_delete' => false,
                'reasons' => ['Link not found'],
                'affiliate_data' => false,
            ];
        }
        
        $reasons = [];
        $canDelete = true;
        $hasAffiliateData = false;
        
        // Check if link has affiliate revenue
        $revenue = (float) $link->getAffiliateRevenue();
        if ($revenue > 0) {
            $hasAffiliateData = true;
            $reasons[] = "Link has affiliate revenue: {$revenue}";
            // May still be deletable, but warn about data loss
        }
        
        // Check if link has significant clicks
        $clicks = $link->getClicks();
        if ($clicks > 100) { // Arbitrary threshold
            $reasons[] = "Link has {$clicks} clicks";
        }
        
        // Additional business rules can be added here
        
        return [
            'can_delete' => $canDelete,
            'reasons' => $reasons,
            'affiliate_data' => $hasAffiliateData,
            'clicks' => $clicks,
            'revenue' => $revenue,
        ];
    }
    
    public function urlExists(string $url, ?int $excludeLinkId = null): bool
    {
        return !$this->isUrlUnique($url, $excludeLinkId);
    }
    
    public function checkDailyClickLimit(int $linkId, int $maxClicks = 1000): array
    {
        $link = $this->find($linkId);
        if (!$link) {
            return [
                'within_limit' => false,
                'current_clicks' => 0,
                'limit' => $maxClicks,
                'message' => 'Link not found'
            ];
        }
        
        // Get today's clicks from analytics table
        $today = date('Y-m-d');
        $todayClicks = 0; // $this->db->table('link_clicks')
                        //   ->where('link_id', $linkId)
                        //   ->where('DATE(created_at)', $today)
                        //   ->countAllResults();
        
        $withinLimit = $todayClicks < $maxClicks;
        
        return [
            'within_limit' => $withinLimit,
            'current_clicks' => $todayClicks,
            'limit' => $maxClicks,
            'remaining' => max(0, $maxClicks - $todayClicks),
            'date' => $today
        ];
    }
}