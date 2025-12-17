<?php

namespace App\Repositories\Concrete;

use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Entities\Marketplace;
use App\Entities\Link;
use App\Models\MarketplaceModel;
use App\Models\LinkModel;
use App\Services\CacheService;
use App\Services\AuditService;
use App\Exceptions\MarketplaceNotFoundException;
use App\Exceptions\ValidationException;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\I18n\Time;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

class MarketplaceRepository implements MarketplaceRepositoryInterface
{
    private MarketplaceModel $marketplaceModel;
    private LinkModel $linkModel;
    private CacheService $cacheService;
    private AuditService $auditService;
    private ConnectionInterface $db;
    
    private int $cacheTtl = 3600;
    private string $cachePrefix = 'marketplace_repo_';
    
    // Cache keys constants
    private const CACHE_KEY_FIND = 'find_';
    private const CACHE_KEY_BY_SLUG = 'by_slug_';
    private const CACHE_KEY_ALL_ACTIVE = 'all_active';
    private const CACHE_KEY_WITH_STATS = 'with_stats';
    private const CACHE_KEY_STATS = 'stats_';
    private const CACHE_KEY_TOP_PERFORMERS = 'top_performers_';
    private const CACHE_KEY_CONFIGURATION = 'configuration_';
    
    public function __construct(
        MarketplaceModel $marketplaceModel,
        LinkModel $linkModel,
        CacheService $cacheService,
        AuditService $auditService,
        ConnectionInterface $db
    ) {
        $this->marketplaceModel = $marketplaceModel;
        $this->linkModel = $linkModel;
        $this->cacheService = $cacheService;
        $this->auditService = $auditService;
        $this->db = $db;
    }
    
    // ==================== BASIC CRUD OPERATIONS ====================
    
    public function find(int $id, bool $withTrashed = false): ?Marketplace
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_FIND . $id . '_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($id, $withTrashed) {
            $marketplace = $withTrashed 
                ? $this->marketplaceModel->withDeleted()->find($id)
                : $this->marketplaceModel->find($id);
                
            if (!$marketplace instanceof Marketplace) {
                return null;
            }
            
            return $marketplace;
        }, $this->cacheTtl);
    }
    
    public function findBySlug(string $slug, bool $withTrashed = false): ?Marketplace
    {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_BY_SLUG . $slug . '_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($slug, $withTrashed) {
            $method = $withTrashed ? 'withDeleted' : 'where';
            $this->marketplaceModel->$method(['slug' => $slug]);
            
            return $this->marketplaceModel->first();
        }, $this->cacheTtl);
    }
    
    public function findByName(string $name, bool $withTrashed = false): ?Marketplace
    {
        $cacheKey = $this->getCacheKey('find_by_name_' . md5($name) . '_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($name, $withTrashed) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
                
            $builder->where('name', $name);
            return $builder->first();
        }, $this->cacheTtl);
    }
    
    public function findByIdOrSlug($identifier, bool $withTrashed = false): ?Marketplace
    {
        if (is_numeric($identifier)) {
            return $this->find((int) $identifier, $withTrashed);
        }
        
        return $this->findBySlug((string) $identifier, $withTrashed);
    }
    
    public function findAll(
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'ASC',
        bool $withTrashed = false
    ): array {
        $cacheKey = $this->getCacheKey(
            'find_all_' . 
            md5(serialize($filters)) . '_' . 
            "{$sortBy}_{$sortDirection}_" . 
            ($withTrashed ? 'with' : 'without')
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($filters, $sortBy, $sortDirection, $withTrashed) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
            
            // Apply filters
            $this->applyFilters($builder, $filters);
            
            // Apply sorting
            $builder->orderBy($sortBy, $sortDirection);
            
            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }
    
    public function save(Marketplace $marketplace): Marketplace
    {
        $isUpdate = $marketplace->getId() !== null;
        $oldData = $isUpdate ? $this->find($marketplace->getId(), true)?->toArray() : null;
        
        try {
            $this->db->transBegin();
            
            // Validate before save
            $validationResult = $this->validate($marketplace);
            if (!$validationResult['is_valid']) {
                throw new ValidationException(
                    'Marketplace validation failed',
                    $validationResult['errors']
                );
            }
            
            // Check for unique slug
            if (!$this->isSlugUnique($marketplace->getSlug(), $marketplace->getId())) {
                throw new ValidationException(
                    'Marketplace slug must be unique',
                    ['slug' => 'This slug is already used by another marketplace']
                );
            }
            
            // Check for unique name
            if (!$this->isNameUnique($marketplace->getName(), $marketplace->getId())) {
                throw new ValidationException(
                    'Marketplace name must be unique',
                    ['name' => 'This name is already used by another marketplace']
                );
            }
            
            // Prepare for save
            $marketplace->prepareForSave($isUpdate);
            
            // Save to database
            $saved = $isUpdate 
                ? $this->marketplaceModel->update($marketplace->getId(), $marketplace)
                : $this->marketplaceModel->insert($marketplace);
                
            if (!$saved) {
                throw new RuntimeException(
                    'Failed to save marketplace: ' . 
                    implode(', ', $this->marketplaceModel->errors())
                );
            }
            
            // If new marketplace, get the ID
            if (!$isUpdate) {
                $marketplace->setId($this->marketplaceModel->getInsertID());
            }
            
            // Clear relevant caches
            $this->clearCache($marketplace->getId());
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $isUpdate ? 'UPDATE' : 'CREATE';
                $adminId = service('auth')->user()?->getId() ?? 0;
                
                $this->auditService->logCrudOperation(
                    'MARKETPLACE',
                    $marketplace->getId(),
                    $action,
                    $adminId,
                    $oldData,
                    $marketplace->toArray()
                );
            }
            
            $this->db->transCommit();
            
            return $marketplace;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'MarketplaceRepository save failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to save marketplace: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function delete(int $id, bool $force = false): bool
    {
        $marketplace = $this->find($id, true);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($id);
        }
        
        // Check if can be deleted
        $canDeleteResult = $this->canDelete($id);
        if (!$canDeleteResult['can_delete'] && !$force) {
            throw new ValidationException(
                'Cannot delete marketplace',
                $canDeleteResult['reasons']
            );
        }
        
        try {
            $this->db->transBegin();
            
            $oldData = $marketplace->toArray();
            $adminId = service('auth')->user()?->getId() ?? 0;
            
            if ($force) {
                // Permanent deletion
                $deleted = $this->marketplaceModel->delete($id, true);
            } else {
                // Soft delete
                $marketplace->softDelete();
                $deleted = $this->marketplaceModel->save($marketplace);
            }
            
            if (!$deleted) {
                throw new RuntimeException('Failed to delete marketplace');
            }
            
            // Handle associated links if soft deleting
            if (!$force && $marketplace->isDeleted()) {
                // Deactivate all associated links
                $this->deactivateAllLinks($id);
            }
            
            // Clear caches
            $this->clearCache($id);
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $action = $force ? 'DELETE' : 'SOFT_DELETE';
                $this->auditService->logCrudOperation(
                    'MARKETPLACE',
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
            
            log_message('error', 'MarketplaceRepository delete failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to delete marketplace: ' . $e->getMessage(), 0, $e);
        }
    }
    
    public function restore(int $id): bool
    {
        $marketplace = $this->find($id, true);
        if (!$marketplace || !$marketplace->isDeleted()) {
            return false;
        }
        
        try {
            $this->db->transBegin();
            
            $marketplace->restore();
            $restored = $this->marketplaceModel->save($marketplace);
            
            if (!$restored) {
                throw new RuntimeException('Failed to restore marketplace');
            }
            
            // Clear caches
            $this->clearCache($id);
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logCrudOperation(
                    'MARKETPLACE',
                    $id,
                    'RESTORE',
                    $adminId,
                    null,
                    $marketplace->toArray()
                );
            }
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'MarketplaceRepository restore failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function exists(int $id, bool $withTrashed = false): bool
    {
        $cacheKey = $this->getCacheKey("exists_{$id}_" . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($id, $withTrashed) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
                
            return $builder->find($id) !== null;
        }, 300);
    }
    
    // ==================== STATUS & ACTIVATION MANAGEMENT ====================
    
    public function activate(int $marketplaceId): bool
    {
        $marketplace = $this->find($marketplaceId);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($marketplaceId);
        }
        
        if ($marketplace->isActive()) {
            return true; // Already active
        }
        
        try {
            $this->db->transBegin();
            
            $marketplace->activate();
            $saved = $this->marketplaceModel->save($marketplace);
            
            if (!$saved) {
                throw new RuntimeException('Failed to activate marketplace');
            }
            
            // Clear caches
            $this->clearCache($marketplaceId);
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logStateTransition(
                    'MARKETPLACE',
                    $marketplaceId,
                    'inactive',
                    'active',
                    $adminId,
                    'Marketplace activated'
                );
            }
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'MarketplaceRepository activate failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function deactivate(int $marketplaceId, ?string $reason = null): bool
    {
        $marketplace = $this->find($marketplaceId);
        if (!$marketplace) {
            throw MarketplaceNotFoundException::forId($marketplaceId);
        }
        
        if (!$marketplace->isActive()) {
            return true; // Already inactive
        }
        
        try {
            $this->db->transBegin();
            
            $marketplace->deactivate();
            $saved = $this->marketplaceModel->save($marketplace);
            
            if (!$saved) {
                throw new RuntimeException('Failed to deactivate marketplace');
            }
            
            // Also deactivate all associated links
            $this->deactivateAllLinks($marketplaceId, $reason ?? 'Marketplace deactivated');
            
            // Clear caches
            $this->clearCache($marketplaceId);
            
            // Log audit trail
            if ($this->auditService->isEnabled()) {
                $adminId = service('auth')->user()?->getId() ?? 0;
                $this->auditService->logStateTransition(
                    'MARKETPLACE',
                    $marketplaceId,
                    'active',
                    'inactive',
                    $adminId,
                    $reason ?? 'Marketplace deactivated'
                );
            }
            
            $this->db->transCommit();
            
            return true;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'MarketplaceRepository deactivate failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function archive(int $marketplaceId, ?string $notes = null): bool
    {
        // Archive is essentially a soft delete with notes
        return $this->delete($marketplaceId, false);
    }
    
    public function isActive(int $marketplaceId): bool
    {
        $marketplace = $this->find($marketplaceId);
        return $marketplace ? $marketplace->isActive() : false;
    }
    
    public function bulkUpdateStatus(array $marketplaceIds, string $status, ?string $reason = null): int
    {
        if (empty($marketplaceIds)) {
            return 0;
        }
        
        $validStatuses = ['active', 'inactive', 'archived'];
        if (!in_array($status, $validStatuses)) {
            throw new InvalidArgumentException('Invalid status: ' . $status);
        }
        
        try {
            $this->db->transBegin();
            
            $updated = 0;
            $adminId = service('auth')->user()?->getId() ?? 0;
            
            foreach ($marketplaceIds as $marketplaceId) {
                try {
                    switch ($status) {
                        case 'active':
                            if ($this->activate($marketplaceId)) {
                                $updated++;
                            }
                            break;
                        case 'inactive':
                            if ($this->deactivate($marketplaceId, $reason)) {
                                $updated++;
                            }
                            break;
                        case 'archived':
                            if ($this->archive($marketplaceId, $reason)) {
                                $updated++;
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to update marketplace {$marketplaceId}: " . $e->getMessage());
                    // Continue with other marketplaces
                }
            }
            
            // Clear all marketplace caches
            $this->clearCache();
            
            $this->db->transCommit();
            
            return $updated;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'MarketplaceRepository bulkUpdateStatus failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    // ==================== COMMISSION STATISTICS (Simplified to Revenue) ====================
    
    public function getCommissionStatistics(string $period = 'month', ?int $marketplaceId = null): array
    {
        $cacheKey = $this->getCacheKey(
            'revenue_stats_' . 
            "{$period}_" . 
            ($marketplaceId ?? 'all')
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($period, $marketplaceId) {
            $stats = [];
            
            // Calculate based on period and marketplace
            if ($marketplaceId) {
                // Single marketplace statistics
                $marketplace = $this->find($marketplaceId);
                if (!$marketplace) {
                    return [];
                }
                
                // Get revenue data for the period
                $revenueData = $this->getRevenueForPeriod($marketplaceId, $period);
                $totalRevenue = $revenueData['total_revenue'] ?? 0;
                
                $stats = [
                    'marketplace_id' => $marketplaceId,
                    'marketplace_name' => $marketplace->getName(),
                    'period' => $period,
                    'total_revenue' => $totalRevenue,
                    'transactions_count' => $revenueData['transactions_count'] ?? 0,
                    'average_transaction' => $revenueData['average_transaction'] ?? 0,
                ];
            } else {
                // System-wide statistics
                $activeMarketplaces = $this->findAll(['active' => true]);
                
                $totalSystemRevenue = 0;
                $marketplaceStats = [];
                
                foreach ($activeMarketplaces as $mp) {
                    $revenueData = $this->getRevenueForPeriod($mp->getId(), $period);
                    $revenue = $revenueData['total_revenue'] ?? 0;
                    
                    $totalSystemRevenue += $revenue;
                    
                    $marketplaceStats[] = [
                        'id' => $mp->getId(),
                        'name' => $mp->getName(),
                        'revenue' => $revenue,
                    ];
                }
                
                // Sort by revenue descending
                usort($marketplaceStats, function($a, $b) {
                    return $b['revenue'] <=> $a['revenue'];
                });
                
                $stats = [
                    'period' => $period,
                    'total_revenue' => $totalSystemRevenue,
                    'marketplace_count' => count($activeMarketplaces),
                    'marketplaces' => $marketplaceStats,
                ];
            }
            
            return $stats;
        }, 1800);
    }
    
    // ==================== LINK & PRODUCT RELATIONS ====================
    
    public function getLinks(
        int $marketplaceId,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 100,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(
            "links_{$marketplaceId}_" .
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
    
    public function countLinks(int $marketplaceId, bool $activeOnly = true): int
    {
        $cacheKey = $this->getCacheKey("count_links_{$marketplaceId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $activeOnly) {
            $builder = $this->linkModel->where('marketplace_id', $marketplaceId);
            
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            return $builder->countAllResults();
        }, 300);
    }
    
    public function countActiveLinks(int $marketplaceId): int
    {
        return $this->countLinks($marketplaceId, true);
    }
    
    public function getProducts(
        int $marketplaceId,
        bool $activeOnly = true,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(
            "products_{$marketplaceId}_" .
            ($activeOnly ? 'active_' : 'all_') .
            "{$limit}_{$offset}"
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $activeOnly, $limit, $offset) {
            // Get unique product IDs from links
            $builder = $this->linkModel->distinct()
                ->select('product_id')
                ->where('marketplace_id', $marketplaceId);
                
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            $productIds = $builder->findAll();
            
            if (empty($productIds)) {
                return [];
            }
            
            // Get products using ProductModel
            $productIds = array_column($productIds, 'product_id');
            // $products = $this->productModel->whereIn('id', $productIds)->findAll($limit, $offset);
            
            // Placeholder - return product IDs
            return array_map(function($id) {
                return ['id' => $id];
            }, array_slice($productIds, $offset, $limit));
        }, $this->cacheTtl);
    }
    
    public function getCategories(int $marketplaceId, bool $activeOnly = true): array
    {
        $cacheKey = $this->getCacheKey("categories_{$marketplaceId}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $activeOnly) {
            // Get unique categories from products linked to this marketplace
            $categories = [];
            
            // This would require a more complex query joining links, products, and categories
            // For now, return placeholder
            
            return $categories;
        }, $this->cacheTtl);
    }
    
    public function getTopSellingProducts(int $marketplaceId, int $limit = 10, string $period = 'month'): array
    {
        $cacheKey = $this->getCacheKey("top_selling_{$marketplaceId}_{$limit}_{$period}");
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $limit, $period) {
            // Query to get top selling products by sold_count or revenue
            $topProducts = [];
            
            // Example:
            // $builder = $this->db->table('links');
            // $builder->select('product_id, SUM(sold_count) as total_sold, SUM(affiliate_revenue) as total_revenue')
            //         ->where('marketplace_id', $marketplaceId)
            //         ->where('active', true);
            
            // if ($period !== 'all') {
            //     $dateCondition = $this->getDateCondition($period);
            //     $builder->where($dateCondition);
            // }
            
            // $builder->groupBy('product_id')
            //         ->orderBy('total_sold', 'DESC')
            //         ->limit($limit);
            
            // $result = $builder->get()->getResultArray();
            
            return $topProducts;
        }, 1800);
    }
    
    public function getLinkStatistics(int $marketplaceId, string $period = 'month'): array
    {
        $cacheKey = $this->getCacheKey("link_stats_{$marketplaceId}_{$period}");
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceId, $period) {
            $builder = $this->linkModel->where('marketplace_id', $marketplaceId);
            
            if ($period !== 'all') {
                $dateCondition = $this->getDateCondition($period);
                $builder->where($dateCondition);
            }
            
            $totalLinks = $builder->countAllResults();
            
            $builder->where('active', true);
            $activeLinks = $builder->countAllResults();
            
            // Get click and revenue totals
            $clickStats = $this->db->table('links')
                ->selectSum('clicks', 'total_clicks')
                ->selectSum('affiliate_revenue', 'total_revenue')
                ->where('marketplace_id', $marketplaceId)
                ->where('active', true);
                
            if ($period !== 'all') {
                $dateCondition = $this->getDateCondition($period, 'updated_at');
                $clickStats->where($dateCondition);
            }
            
            $stats = $clickStats->get()->getRow();
            
            // Calculate conversion rate (if we have sold_count)
            $conversionRate = 0;
            $totalClicks = $stats->total_clicks ?? 0;
            if ($totalClicks > 0) {
                $totalSold = $this->db->table('links')
                    ->selectSum('sold_count', 'total_sold')
                    ->where('marketplace_id', $marketplaceId)
                    ->where('active', true)
                    ->get()
                    ->getRow()
                    ->total_sold ?? 0;
                    
                $conversionRate = $totalSold / $totalClicks;
            }
            
            return [
                'total_links' => $totalLinks,
                'active_links' => $activeLinks,
                'total_clicks' => $totalClicks,
                'total_revenue' => $stats->total_revenue ?? 0,
                'conversion_rate' => round($conversionRate * 100, 2),
                'period' => $period,
            ];
        }, 1800);
    }
    
    // ==================== SEARCH & FILTER ====================
    
    public function search(
        string $keyword,
        bool $activeOnly = true,
        bool $withTrashed = false,
        int $limit = 50,
        int $offset = 0
    ): array {
        $cacheKey = $this->getCacheKey(
            'search_' . md5($keyword) . '_' .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with_' : 'without_') .
            "{$limit}_{$offset}"
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($keyword, $activeOnly, $withTrashed, $limit, $offset) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
                
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            $builder->groupStart();
            $builder->like('name', $keyword);
            $builder->orLike('slug', $keyword);
            $builder->groupEnd();
            
            $builder->orderBy('name', 'ASC')
                   ->limit($limit, $offset);
                   
            $result = $builder->findAll();
            return $result ?: [];
        }, 300);
    }
    
    public function findByIds(
        array $marketplaceIds,
        bool $activeOnly = true,
        bool $withTrashed = false
    ): array {
        if (empty($marketplaceIds)) {
            return [];
        }
        
        $cacheKey = $this->getCacheKey(
            'by_ids_' . md5(implode(',', $marketplaceIds)) . '_' .
            ($activeOnly ? 'active_' : 'all_') .
            ($withTrashed ? 'with' : 'without')
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($marketplaceIds, $activeOnly, $withTrashed) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
                
            if ($activeOnly) {
                $builder->where('active', true);
            }
            
            $builder->whereIn('id', $marketplaceIds)
                   ->orderBy('name', 'ASC');
                   
            $result = $builder->findAll();
            return $result ?: [];
        }, $this->cacheTtl);
    }
    
    public function findWithActiveLinks(int $minLinks = 1, bool $activeOnly = true, int $limit = 50): array
    {
        $cacheKey = $this->getCacheKey(
            'with_active_links_' .
            "{$minLinks}_" .
            ($activeOnly ? 'active' : 'all') .
            "_{$limit}"
        );
        
        return $this->cacheService->remember($cacheKey, function() use ($minLinks, $activeOnly, $limit) {
            // This requires a subquery or join with links table
            // For now, get all marketplaces and filter
            
            $marketplaces = $this->findAll([], 'name', 'ASC', !$activeOnly);
            
            $result = [];
            foreach ($marketplaces as $marketplace) {
                $activeLinkCount = $this->countActiveLinks($marketplace->getId());
                
                if ($activeLinkCount >= $minLinks) {
                    $marketplaceData = $marketplace->toArray();
                    $marketplaceData['active_links_count'] = $activeLinkCount;
                    $result[] = $marketplaceData;
                    
                    if (count($result) >= $limit) {
                        break;
                    }
                }
            }
            
            return $result;
        }, $this->cacheTtl);
    }
    
    public function getByPerformance(
        string $metric = 'revenue',
        string $period = 'month',
        bool $activeOnly = true,
        int $limit = 10
    ): array {
        $cacheKey = $this->getCacheKey(self::CACHE_KEY_TOP_PERFORMERS . "{$metric}_{$period}_{$limit}_" . ($activeOnly ? 'active' : 'all'));
        
        return $this->cacheService->remember($cacheKey, function() use ($metric, $period, $activeOnly, $limit) {
            $marketplaces = $activeOnly 
                ? $this->findAll(['active' => true]) 
                : $this->findAll();
            
            $performanceData = [];
            
            foreach ($marketplaces as $marketplace) {
                $stats = $this->getLinkStatistics($marketplace->getId(), $period);
                
                $value = 0;
                switch ($metric) {
                    case 'revenue':
                        $value = $stats['total_revenue'] ?? 0;
                        break;
                    case 'clicks':
                        $value = $stats['total_clicks'] ?? 0;
                        break;
                    case 'conversion':
                        $value = $stats['conversion_rate'] ?? 0;
                        break;
                    case 'links':
                        $value = $stats['active_links'] ?? 0;
                        break;
                }
                
                $performanceData[] = [
                    'marketplace' => $marketplace,
                    'value' => $value,
                    'stats' => $stats,
                ];
            }
            
            // Sort by value descending
            usort($performanceData, function($a, $b) use ($metric) {
                return $b['value'] <=> $a['value'];
            });
            
            // Return limited results
            return array_slice($performanceData, 0, $limit);
        }, 1800);
    }
    
    // ==================== STATISTICS & ANALYTICS ====================
    
    public function getStatistics(?int $marketplaceId = null): array
    {
        if ($marketplaceId) {
            return $this->getMarketplaceStatistics($marketplaceId);
        }
        
        return $this->getSystemStatistics();
    }
    
    public function countByStatus(bool $withTrashed = false): array
    {
        $cacheKey = $this->getCacheKey('count_by_status_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($withTrashed) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
            
            $total = $builder->countAllResults();
            
            $builder->where('active', true);
            $active = $builder->countAllResults();
            
            $builder->where('active', false);
            $inactive = $builder->countAllResults();
            
            $archived = $withTrashed 
                ? $this->marketplaceModel->onlyDeleted()->countAllResults()
                : 0;
            
            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'archived' => $archived,
            ];
        }, 300);
    }
    
    public function countAll(bool $withTrashed = false): int
    {
        $cacheKey = $this->getCacheKey('count_all_' . ($withTrashed ? 'with' : 'without'));
        
        return $this->cacheService->remember($cacheKey, function() use ($withTrashed) {
            $builder = $withTrashed 
                ? $this->marketplaceModel->withDeleted()
                : $this->marketplaceModel;
                
            return $builder->countAllResults();
        }, 300);
    }
    
    public function countActive(): int
    {
        $cacheKey = $this->getCacheKey('count_active');
        
        return $this->cacheService->remember($cacheKey, function() {
            return $this->marketplaceModel->where('active', true)->countAllResults();
        }, 300);
    }
    
    public function getGrowthStatistics(string $period = 'month'): array
    {
        $cacheKey = $this->getCacheKey('growth_stats_' . $period);
        
        return $this->cacheService->remember($cacheKey, function() use ($period) {
            // Calculate growth based on period
            $dateCondition = $this->getDateCondition($period);
            
            // New marketplaces
            $newCount = $this->marketplaceModel
                ->where($dateCondition)
                ->countAllResults();
            
            // Deactivated marketplaces
            $deactivatedCount = $this->marketplaceModel
                ->where('active', false)
                ->where($dateCondition)
                ->countAllResults();
            
            // Growth rate
            $previousPeriodCount = $this->getPreviousPeriodCount($period);
            $currentCount = $this->countActive();
            
            $growthRate = $previousPeriodCount > 0 
                ? (($currentCount - $previousPeriodCount) / $previousPeriodCount) * 100
                : ($currentCount > 0 ? 100 : 0);
            
            return [
                'new_marketplaces' => $newCount,
                'deactivated_marketplaces' => $deactivatedCount,
                'growth_rate' => round($growthRate, 2),
                'period' => $period,
                'current_count' => $currentCount,
                'previous_period_count' => $previousPeriodCount,
            ];
        }, 1800);
    }
    
    // ==================== BATCH & BULK OPERATIONS ====================
    
    public function bulkUpdate(array $marketplaceIds, array $updateData): int
    {
        if (empty($marketplaceIds) || empty($updateData)) {
            return 0;
        }
        
        try {
            $this->db->transBegin();
            
            $updated = 0;
            
            foreach ($marketplaceIds as $marketplaceId) {
                try {
                    $marketplace = $this->find($marketplaceId);
                    if (!$marketplace) {
                        continue;
                    }
                    
                    // Apply updates
                    foreach ($updateData as $field => $value) {
                        $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                        if (method_exists($marketplace, $setter)) {
                            $marketplace->$setter($value);
                        }
                    }
                    
                    // Save updated marketplace
                    if ($this->save($marketplace)) {
                        $updated++;
                    }
                } catch (\Exception $e) {
                    log_message('error', "Failed to update marketplace {$marketplaceId}: " . $e->getMessage());
                    // Continue with other marketplaces
                }
            }
            
            // Clear all caches
            $this->clearCache();
            
            $this->db->transCommit();
            
            return $updated;
            
        } catch (\Exception $e) {
            $this->db->transRollback();
            
            log_message('error', 'MarketplaceRepository bulkUpdate failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    public function bulkActivate(array $marketplaceIds, ?string $reason = null): int
    {
        return $this->bulkUpdateStatus($marketplaceIds, 'active', $reason);
    }
    
    public function bulkDeactivate(array $marketplaceIds, ?string $reason = null): int
    {
        return $this->bulkUpdateStatus($marketplaceIds, 'inactive', $reason);
    }
    
    // ==================== VALIDATION & BUSINESS RULES ====================
    
    public function canDelete(int $marketplaceId): array
    {
        $marketplace = $this->find($marketplaceId, true);
        if (!$marketplace) {
            return [
                'can_delete' => false,
                'reasons' => ['Marketplace not found'],
                'active_links' => 0,
            ];
        }
        
        $reasons = [];
        $canDelete = true;
        
        // Check if marketplace has active links
        $activeLinkCount = $this->countActiveLinks($marketplaceId);
        if ($activeLinkCount > 0) {
            $canDelete = false;
            $reasons[] = "Marketplace has {$activeLinkCount} active link(s)";
        }
        
        // Check if marketplace has any revenue (optional)
        $totalRevenue = $this->getTotalRevenue($marketplaceId);
        if ($totalRevenue > 0) {
            $reasons[] = "Marketplace has generated revenue: {$totalRevenue}";
            // May still be deletable, but warn about historical data
        }
        
        // Check if it's a default marketplace
        if ($this->isDefaultMarketplace($marketplaceId)) {
            $reasons[] = "This is a default marketplace";
            // May require special handling
        }
        
        return [
            'can_delete' => $canDelete,
            'reasons' => $reasons,
            'active_links' => $activeLinkCount,
            'total_revenue' => $totalRevenue,
            'is_default' => $this->isDefaultMarketplace($marketplaceId),
        ];
    }
    
    public function canDeactivate(int $marketplaceId): array
    {
        $marketplace = $this->find($marketplaceId);
        if (!$marketplace) {
            return [
                'can_deactivate' => false,
                'reasons' => ['Marketplace not found'],
                'active_links' => 0,
            ];
        }
        
        $reasons = [];
        $canDeactivate = true;
        
        // Check if already inactive
        if (!$marketplace->isActive()) {
            $canDeactivate = false;
            $reasons[] = 'Marketplace is already inactive';
        }
        
        // Check if it's the last active marketplace
        $activeMarketplaceCount = $this->countActive();
        if ($activeMarketplaceCount <= 1 && $marketplace->isActive()) {
            $canDeactivate = false;
            $reasons[] = 'Cannot deactivate the last active marketplace';
        }
        
        // Check active links (will be deactivated automatically)
        $activeLinkCount = $this->countActiveLinks($marketplaceId);
        if ($activeLinkCount > 0) {
            $reasons[] = "{$activeLinkCount} active link(s) will be deactivated";
        }
        
        return [
            'can_deactivate' => $canDeactivate,
            'reasons' => $reasons,
            'active_links' => $activeLinkCount,
            'active_marketplace_count' => $activeMarketplaceCount,
        ];
    }
    
    public function isSlugUnique(string $slug, ?int $excludeId = null): bool
    {
        $builder = $this->marketplaceModel->where('slug', $slug);
        
        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }
        
        return $builder->countAllResults() === 0;
    }
    
    public function isNameUnique(string $name, ?int $excludeId = null): bool
    {
        $builder = $this->marketplaceModel->where('name', $name);
        
        if ($excludeId) {
            $builder->where('id !=', $excludeId);
        }
        
        return $builder->countAllResults() === 0;
    }
    
    public function validate(Marketplace $marketplace): array
    {
        $errors = [];
        $isValid = true;
        
        // Required fields
        if (empty($marketplace->getName())) {
            $errors[] = 'Marketplace name is required';
            $isValid = false;
        }
        
        if (empty($marketplace->getSlug())) {
            $errors[] = 'Marketplace slug is required';
            $isValid = false;
        }
        
        // Name length
        if (strlen($marketplace->getName()) > 100) {
            $errors[] = 'Marketplace name cannot exceed 100 characters';
            $isValid = false;
        }
        
        // Slug format
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $marketplace->getSlug())) {
            $errors[] = 'Marketplace slug can only contain lowercase letters, numbers, and hyphens';
            $isValid = false;
        }
        
        // Color validation (if provided)
        $color = $marketplace->getColor();
        if ($color && !preg_match('/^#[0-9a-f]{6}$/i', $color)) {
            $errors[] = 'Invalid color format. Use hex format (#RRGGBB)';
            $isValid = false;
        }
        
        return [
            'is_valid' => $isValid,
            'errors' => $errors,
        ];
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
                           ->like('name', $value)
                           ->orLike('slug', $value)
                           ->groupEnd();
                } elseif (is_array($value)) {
                    $builder->whereIn($field, $value);
                } else {
                    $builder->where($field, $value);
                }
            }
        }
    }
    
    private function getRevenueForPeriod(int $marketplaceId, string $period): array
    {
        // This would query link revenue for the period
        // For now, return placeholder data
        
        return [
            'total_revenue' => 0,
            'transactions_count' => 0,
            'average_transaction' => 0,
        ];
    }
    
    private function deactivateAllLinks(int $marketplaceId, string $reason = 'Marketplace deactivated'): int
    {
        try {
            $links = $this->getLinks($marketplaceId, true, false, 1000, 0);
            
            $deactivated = 0;
            foreach ($links as $link) {
                if ($link->isActive()) {
                    $link->setActive(false);
                    if ($this->linkModel->save($link)) {
                        $deactivated++;
                    }
                }
            }
            
            return $deactivated;
            
        } catch (\Exception $e) {
            log_message('error', 'Failed to deactivate links for marketplace ' . $marketplaceId . ': ' . $e->getMessage());
            return 0;
        }
    }
    
    private function getDateCondition(string $period, string $field = 'created_at'): string
    {
        $now = date('Y-m-d H:i:s');
        
        switch ($period) {
            case 'day':
                $start = date('Y-m-d 00:00:00');
                break;
            case 'week':
                $start = date('Y-m-d 00:00:00', strtotime('-7 days'));
                break;
            case 'month':
                $start = date('Y-m-d 00:00:00', strtotime('-30 days'));
                break;
            case 'quarter':
                $start = date('Y-m-d 00:00:00', strtotime('-90 days'));
                break;
            case 'year':
                $start = date('Y-m-d 00:00:00', strtotime('-365 days'));
                break;
            default:
                return '1=1'; // All time
        }
        
        return "{$field} BETWEEN '{$start}' AND '{$now}'";
    }
    
    private function getPreviousPeriodCount(string $period): int
    {
        // Calculate count for previous period
        // For simplicity, returning 0
        return 0;
    }
    
    private function getTotalRevenue(int $marketplaceId): float
    {
        $result = $this->db->table('links')
            ->selectSum('affiliate_revenue', 'total_revenue')
            ->where('marketplace_id', $marketplaceId)
            ->get()
            ->getRow();
            
        return (float) ($result->total_revenue ?? 0);
    }
    
    private function isDefaultMarketplace(int $marketplaceId): bool
    {
        // Check if marketplace is in default list
        // Since we removed 'createDefaults' from this repo (it was only in Model/Entity for seeding)
        // We can just query by slug for known defaults or remove this check
        // For now, let's keep it simple and check against a static list of slugs
        
        $marketplace = $this->find($marketplaceId);
        if (!$marketplace) {
            return false;
        }
        
        $defaultSlugs = ['tokopedia', 'shopee', 'lazada', 'blibli', 'bukalapak'];
        return in_array($marketplace->getSlug(), $defaultSlugs);
    }
    
    private function getMarketplaceStatistics(int $marketplaceId): array
    {
        $marketplace = $this->find($marketplaceId);
        if (!$marketplace) {
            return [];
        }
        
        $linkStats = $this->getLinkStatistics($marketplaceId, 'all');
        
        return [
            'id' => $marketplace->getId(),
            'name' => $marketplace->getName(),
            'slug' => $marketplace->getSlug(),
            'active' => $marketplace->isActive(),
            'total_links' => $linkStats['total_links'] ?? 0,
            'active_links' => $linkStats['active_links'] ?? 0,
            'total_clicks' => $linkStats['total_clicks'] ?? 0,
            'total_revenue' => $linkStats['total_revenue'] ?? 0,
            'conversion_rate' => $linkStats['conversion_rate'] ?? 0,
            'created_at' => $marketplace->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updated_at' => $marketplace->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
    
    private function getSystemStatistics(): array
    {
        $activeCount = $this->countActive();
        $totalCount = $this->countAll();
        
        // Get top 5 marketplaces by revenue
        $topByRevenue = $this->getByPerformance('revenue', 'all', true, 5);
        
        // Calculate total system revenue
        $totalRevenue = $this->db->table('links')
            ->selectSum('affiliate_revenue', 'total_revenue')
            ->get()
            ->getRow()
            ->total_revenue ?? 0;
        
        return [
            'total_marketplaces' => $totalCount,
            'active_marketplaces' => $activeCount,
            'inactive_marketplaces' => $totalCount - $activeCount,
            'total_revenue' => $totalRevenue,
            'top_by_revenue' => array_map(function($item) {
                return [
                    'id' => $item['marketplace']->getId(),
                    'name' => $item['marketplace']->getName(),
                    'revenue' => $item['value'],
                ];
            }, $topByRevenue),
            'recently_added' => [], // Last 5 marketplaces
            'growth_rate' => $this->getGrowthStatistics('month')['growth_rate'] ?? 0,
        ];
    }
    
    public function clearCache(?int $marketplaceId = null): void
    {
        if ($marketplaceId) {
            // Clear specific marketplace caches
            $patterns = [
                $this->getCacheKey(self::CACHE_KEY_FIND . "{$marketplaceId}_*"),
                $this->getCacheKey(self::CACHE_KEY_BY_SLUG . "*"),
                $this->getCacheKey("links_{$marketplaceId}_*"),
                $this->getCacheKey("products_{$marketplaceId}_*"),
                $this->getCacheKey("categories_{$marketplaceId}_*"),
                $this->getCacheKey(self::CACHE_KEY_STATS . "{$marketplaceId}"),
                $this->getCacheKey("link_stats_{$marketplaceId}_*"),
                $this->getCacheKey("count_links_{$marketplaceId}_*"),
                $this->getCacheKey(self::CACHE_KEY_CONFIGURATION . "{$marketplaceId}"),
                $this->getCacheKey("exists_{$marketplaceId}_*"),
            ];
            
            foreach ($patterns as $pattern) {
                $keys = $this->cacheService->getKeysByPattern($pattern);
                if (!empty($keys)) {
                    $this->cacheService->deleteMultiple($keys);
                }
            }
        } else {
            // Clear all marketplace caches
            $patterns = [
                $this->getCacheKey('*'),
                $this->getCacheKey(self::CACHE_KEY_ALL_ACTIVE),
                $this->getCacheKey(self::CACHE_KEY_WITH_STATS),
                $this->getCacheKey(self::CACHE_KEY_TOP_PERFORMERS . '*'),
                $this->getCacheKey('count_*'),
                $this->getCacheKey('search_*'),
                $this->getCacheKey('by_ids_*'),
                $this->getCacheKey('growth_stats_*'),
                $this->getCacheKey('revenue_stats_*'),
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
        $marketplaceModel = model(MarketplaceModel::class);
        $linkModel = model(LinkModel::class);
        $cacheService = service('cache');
        $auditService = service('audit');
        $db = db_connect();
        
        return new self(
            $marketplaceModel,
            $linkModel,
            $cacheService,
            $auditService,
            $db
        );
    }
}
