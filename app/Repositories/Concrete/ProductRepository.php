<?php

namespace App\Repositories\Concrete;

use App\Entities\Product;
use App\Enums\ProductStatus;
use App\Models\ProductModel;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\I18n\Time;
use InvalidArgumentException;

/**
 * ProductRepository - Concrete implementation for Product repository
 * 
 * @extends BaseRepository<Product>
 * @implements ProductRepositoryInterface
 */
class ProductRepository extends BaseRepository implements ProductRepositoryInterface
{
    /**
     * Constructor
     * 
     * @param ProductModel $model
     * @param CacheInterface|null $cache
     */
    public function __construct(ProductModel $model, ?CacheInterface $cache = null)
    {
        parent::__construct($model, $cache);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'product';
    }

    /**
     * {@inheritDoc}
     */
    public function findBySlug(string $slug, bool $activeOnly = true, bool $useCache = true): ?Product
    {
        $cacheKey = "slug:{$slug}:active:" . ($activeOnly ? '1' : '0');

        return $this->remember($cacheKey, function () use ($slug, $activeOnly) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $builder = $model->builder();
            $builder->where('slug', $slug);
            
            if ($activeOnly) {
                $builder->where('deleted_at IS NULL');
            }
            
            $result = $model->findAll(1);
            return $result[0] ?? null;
        }, $useCache ? $this->defaultCacheTtl : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function findPublished(
        ?int $limit = null,
        int $offset = 0,
        array $orderBy = ['published_at' => 'DESC'],
        bool $useCache = true
    ): array {
        $cacheKey = sprintf(
            'published:limit:%s:offset:%d:order:%s',
            $limit ?? 'all',
            $offset,
            md5(serialize($orderBy))
        );

        return $this->remember($cacheKey, function () use ($limit, $offset, $orderBy) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $builder = $model->builder();
            $model->scopePublished($builder);

            
            foreach ($orderBy as $field => $direction) {
                $builder->orderBy($field, $direction);
            }
            
            return $builder->findAll($limit, $offset);
        }, $useCache ? $this->defaultCacheTtl : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function findPopular(
        int $limit = 10,
        int $offset = 0,
        bool $publishedOnly = true,
        bool $useCache = true
    ): array {
        $cacheKey = sprintf(
            'popular:limit:%d:offset:%d:published:%d',
            $limit,
            $offset,
            $publishedOnly ? 1 : 0
        );

        return $this->remember($cacheKey, function () use ($limit, $offset, $publishedOnly) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $builder = $model->builder();
                if ($publishedOnly) {
                $model->scopePublished($builder);
             }
            $model->scopePopular($builder);

            
            return $builder->findAll($limit, $offset);
        }, $useCache ? $this->defaultCacheTtl : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function search(
        string $keyword,
        array $filters = [],
        ?int $limit = null,
        int $offset = 0,
        array $orderBy = ['name' => 'ASC'],
        bool $useCache = true
    ): array {
        $cacheKey = sprintf(
            'search:%s:filters:%s:limit:%s:offset:%d:order:%s',
            md5($keyword),
            md5(serialize($filters)),
            $limit ?? 'all',
            $offset,
            md5(serialize($orderBy))
        );

        return $this->remember($cacheKey, function () use ($keyword, $filters, $limit, $offset, $orderBy) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $builder = $model->builder();
            $model->scopePublished($builder); // Search biasanya perlu published only
            $model->scopeSearch($builder, $keyword);

            
            // Apply additional filters
            foreach ($filters as $field => $value) {
                if ($value !== null && $value !== '') {
                    if (is_array($value)) {
                        $builder->whereIn($field, $value);
                    } else {
                        $builder->where($field, $value);
                    }
                }
            }
            
            foreach ($orderBy as $field => $direction) {
                $builder->orderBy($field, $direction);
            }
            return $builder->findAll($limit, $offset);
        }, $useCache ? $this->defaultCacheTtl : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function publish(int $productId, ?int $publishedBy = null): bool
    {         
         $product = $this->find($productId, false);
         if ($product === null) {
            return false;
        }
        
        $product->publish();
        if ($publishedBy !== null) {
            $product->setVerifiedBy($publishedBy);
        }
        
        $success = $this->save($product);
        
        // Invalidate relevant caches
        if ($success) {
            $this->deleteMatching('published:*');
            $this->deleteMatching('search:*');
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function verify(int $productId, int $verifiedBy): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        $product->verify($verifiedBy);
        $success = $this->save($product);
        
        // Invalidate caches
        if ($success) {
            $this->deleteMatching('*');
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function archive(int $productId, ?int $archivedBy = null): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        $product->archive();
        $success = $this->save($product);
        // Invalidate all product caches
        if ($success) {
            $this->deleteMatching('*');
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function restore(int|string $id, ?int $restoredBy = null): bool
    {
        /** @var ProductModel $model */
        $model = $this->model;
        
        // Logika tambahan jika ingin mencatat siapa yang merestore (Opsional)
        // if ($restoredBy) { ... logic audit trail ... }

        $success = $model->restore($id);
        
        // Invalidate caches
        if ($success) {
            $this->deleteMatching('*');
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function updateStatus(int $productId, ProductStatus $status, ?int $changedBy = null): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        $product->setStatus($status);
        
        // Set timestamps based on status
        if ($status === ProductStatus::PUBLISHED && $product->getPublishedAt() === null) {
            $product->setPublishedAt(new \DateTimeImmutable());
        }
        
        if ($status === ProductStatus::VERIFIED && $product->getVerifiedAt() === null && $changedBy !== null) {
            $product->setVerifiedAt(new \DateTimeImmutable());
            $product->setVerifiedBy($changedBy);
        }
        
        $success = $this->save($product);
        
        // Invalidate caches
        if ($success) {
            $this->deleteMatching('*');
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function incrementViewCount(int $productId): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        $product->incrementViewCount();
        return $this->save($product);
    }

    /**
     * {@inheritDoc}
     */
    public function markPriceChecked(int $productId): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        $product->markPriceChecked();
        return $this->save($product);
    }

    /**
     * {@inheritDoc}
     */
    public function markLinksChecked(int $productId): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        $product->markLinksChecked();
        return $this->save($product);
    }

    /**
     * {@inheritDoc}
     */
    public function findNeedsPriceUpdate(
        int $daysThreshold = 7,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        $cacheKey = sprintf(
            'needsPriceUpdate:days:%d:limit:%d:published:%d',
            $daysThreshold,
            $limit,
            $publishedOnly ? 1 : 0
        );

        return $this->remember($cacheKey, function () use ($daysThreshold, $limit, $publishedOnly) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $builder = $publishedOnly ? $model->published() : $model->builder();
            
            // Products that haven't been checked for price updates in X days
            $cutoffDate = (new Time())->subDays($daysThreshold)->toDateTimeString();
            $builder->groupStart()
                    ->where('last_price_check IS NULL')
                    ->orWhere('last_price_check <', $cutoffDate)
                    ->groupEnd();
            
            return $builder->findAll($limit);
        }, 300); // Short cache TTL (5 minutes) for maintenance data
    }

    /**
     * {@inheritDoc}
     */
    public function findNeedsLinkValidation(
        int $daysThreshold = 14,
        int $limit = 50,
        bool $publishedOnly = true
    ): array {
        $cacheKey = sprintf(
            'needsLinkValidation:days:%d:limit:%d:published:%d',
            $daysThreshold,
            $limit,
            $publishedOnly ? 1 : 0
        );

        return $this->remember($cacheKey, function () use ($daysThreshold, $limit, $publishedOnly) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $builder = $publishedOnly ? $model->published() : $model->builder();
            
            // Products that haven't been checked for link validation in X days
            $cutoffDate = (new Time())->subDays($daysThreshold)->toDateTimeString();
            $builder->groupStart()
                    ->where('last_link_check IS NULL')
                    ->orWhere('last_link_check <', $cutoffDate)
                    ->groupEnd();
            
            return $builder->findAll($limit);
        }, 300); // Short cache TTL (5 minutes)
    }

    /**
     * {@inheritDoc}
     */
    public function countByStatus(?ProductStatus $status = null, bool $includeArchived = false): int
    {
        $cacheKey = sprintf(
            'countByStatus:status:%s:archived:%d',
            $status ? $status->value : 'all',
            $includeArchived ? 1 : 0
        );

        return (int) $this->remember($cacheKey, function () use ($status, $includeArchived) {
            $builder = $this->model->builder();
            
            if ($status !== null) {
                $builder->where('status', $status->value);
            }
            
            if (!$includeArchived) {
                $builder->where('deleted_at IS NULL');
            }
            
            return $builder->countAllResults();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function countByCategory(?int $categoryId = null, bool $publishedOnly = false)
    {
        if ($categoryId !== null) {
            $cacheKey = sprintf(
                'countByCategory:cat:%d:published:%d',
                $categoryId,
                $publishedOnly ? 1 : 0
            );
            
            return (int) $this->remember($cacheKey, function () use ($categoryId, $publishedOnly) {
                $builder = $this->model->builder();
                $builder->where('category_id', $categoryId);
                
                if ($publishedOnly) {
                    $builder->where('status', ProductStatus::PUBLISHED->value);
                    $builder->where('deleted_at IS NULL');
                }
                
                return $builder->countAllResults();
            });
        }
        
        // Return array of counts for all categories
        $cacheKey = 'countByCategory:all:published:' . ($publishedOnly ? 1 : 0);
        
        return $this->remember($cacheKey, function () use ($publishedOnly) {
            $builder = $this->model->builder();
            $builder->select('category_id, COUNT(*) as count');
            $builder->groupBy('category_id');
            
            if ($publishedOnly) {
                $builder->where('status', ProductStatus::PUBLISHED->value);
                $builder->where('deleted_at IS NULL');
            }
            
            $result = $builder->get()->getResultArray();
            
            $counts = [];
            foreach ($result as $row) {
                $counts[$row['category_id']] = (int) $row['count'];
            }
            
            return $counts;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(string $period = 'month'): array
    {
        $cacheKey = "statistics:{$period}";
        
        return $this->remember($cacheKey, function () use ($period) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            // Implement statistics logic based on period
            $now = new Time();
            
            switch ($period) {
                case 'day':
                    $startDate = $now->subDays(1);
                    break;
                case 'week':
                    $startDate = $now->subDays(7);
                    break;
                case 'month':
                    $startDate = $now->subMonths(1);
                    break;
                case 'year':
                    $startDate = $now->subYears(1);
                    break;
                default:
                    $startDate = $now->subMonths(1);
            }
            
            $builder = $model->builder();
            
            $stats = [
                'total' => $builder->where('deleted_at IS NULL')->countAllResults(),
                'published' => $builder->where('status', ProductStatus::PUBLISHED->value)
                                      ->where('deleted_at IS NULL')
                                      ->countAllResults(),
                'draft' => $builder->where('status', ProductStatus::DRAFT->value)
                                   ->where('deleted_at IS NULL')
                                   ->countAllResults(),
                'verified' => $builder->where('status', ProductStatus::VERIFIED->value)
                                      ->where('deleted_at IS NULL')
                                      ->countAllResults(),
                'new_this_period' => $builder->where('created_at >=', $startDate->toDateTimeString())
                                            ->where('deleted_at IS NULL')
                                            ->countAllResults(),
            ];
            
            return $stats;
        }, 1800); // 30 minutes cache for statistics
    }

    /**
     * {@inheritDoc}
     */
    public function updateProduct(int $productId, array $data): bool
    {
        $product = $this->find($productId, false);
        if ($product === null) {
            return false;
        }
        
        // Update entity properties
        foreach ($data as $key => $value) {
            if (property_exists($product, $key) || method_exists($product, 'set' . ucfirst($key))) {
                $setter = 'set' . ucfirst($key);
                if (method_exists($product, $setter)) {
                    $product->$setter($value);
                }
            }
        }
        
        $success = $this->save($product);
        
        // Invalidate caches
        if ($success) {
            $this->deleteMatching('*');
        }
        
        return $success;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $productIds, ProductStatus $status, ?int $changedBy = null): int
    {
        if (empty($productIds)) {
            return 0;
        }
        
        $successCount = 0;
        
        foreach ($productIds as $productId) {
            if ($this->updateStatus($productId, $status, $changedBy)) {
                $successCount++;
            }
        }
        
        // Invalidate all caches after bulk operation
        $this->deleteMatching('*');
        
        return $successCount;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchive(array $productIds, ?int $archivedBy = null): int
    {
        if (empty($productIds)) {
            return 0;
        }
        
        $successCount = 0;
        
        foreach ($productIds as $productId) {
            if ($this->archive($productId, $archivedBy)) {
                $successCount++;
            }
        }
        
        return $successCount;
    }

    /**
     * {@inheritDoc}
     */
    public function findByCategory(
        int $categoryId,
        bool $includeSubcategories = false,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true,
        bool $useCache = true
    ): array {
        $cacheKey = sprintf(
            'byCategory:%d:subcats:%d:limit:%s:offset:%d:published:%d',
            $categoryId,
            $includeSubcategories ? 1 : 0,
            $limit ?? 'all',
            $offset,
            $publishedOnly ? 1 : 0
        );

        return $this->remember($cacheKey, function () use ($categoryId, $includeSubcategories, $limit, $offset, $publishedOnly) {
            /** @var ProductModel $model */
            $model = $this->model;
            
         $builder = $model->builder();
         if ($publishedOnly) {
            $model->scopePublished($builder);
         }
            
            if ($includeSubcategories) {
                // This would require a subquery or join with categories table
                // For simplicity, we'll assume a flat structure for now
                $builder->where('category_id', $categoryId);
            } else {
                $builder->where('category_id', $categoryId);
            }
            
            $builder->orderBy('created_at', 'DESC');
            
            return $builder->findAll($limit, $offset);
        }, $useCache ? $this->defaultCacheTtl : 0);
    }

    /**
     * {@inheritDoc}
     */
    public function findByMarketplace(
        int $marketplaceId,
        bool $activeLinksOnly = true,
        ?int $limit = null,
        int $offset = 0,
        bool $publishedOnly = true
    ): array {
        $cacheKey = sprintf(
            'byMarketplace:%d:activeLinks:%d:limit:%s:offset:%d:published:%d',
            $marketplaceId,
            $activeLinksOnly ? 1 : 0,
            $limit ?? 'all',
            $offset,
            $publishedOnly ? 1 : 0
        );

        return $this->remember($cacheKey, function () use ($marketplaceId, $activeLinksOnly, $limit, $offset, $publishedOnly) {
            /** @var ProductModel $model */
            $model = $this->model;
            
        $builder = $model->builder();
            if ($publishedOnly) {
                $model->scopePublished($builder);
            }            
            // Join with links table
            $builder->distinct()
                    ->select('products.*')
                    ->join('links', 'links.product_id = products.id')
                    ->where('links.marketplace_id', $marketplaceId);
            
            if ($activeLinksOnly) {
                $builder->where('links.active', 1);
            }
            
            $builder->orderBy('products.created_at', 'DESC');
            
            return $builder->findAll($limit, $offset);
        });
    }

    /**
     * {@inheritDoc}
     */
    public function getRecommendations(
        int $currentProductId,
        int $limit = 4,
        array $criteria = ['category', 'popular']
    ): array {
        $cacheKey = sprintf(
            'recommendations:%d:limit:%d:criteria:%s',
            $currentProductId,
            $limit,
            md5(serialize($criteria))
        );

        return $this->remember($cacheKey, function () use ($currentProductId, $limit, $criteria) {
            /** @var ProductModel $model */
            $model = $this->model;
            
            $currentProduct = $this->find($currentProductId, true);
            if ($currentProduct === null) {
                return [];
            }
            
            $builder = $model->builder();
$model->scopePublished($builder)
      ->where('products.id !=', $currentProductId);

            $conditions = [];
            
            if (in_array('category', $criteria) && $currentProduct->getCategoryId() !== null) {
                $conditions[] = ['category_id' => $currentProduct->getCategoryId()];
            }
            
            if (in_array('popular', $criteria)) {
                $builder->orderBy('view_count', 'DESC');
            }
            
            if (in_array('recent', $criteria)) {
                $builder->orderBy('published_at', 'DESC');
            }
            
            // If we have category condition, apply it
            if (!empty($conditions)) {
                $builder->groupStart();
                foreach ($conditions as $condition) {
                    $builder->orWhere($condition);
                }
                $builder->groupEnd();
            }
            
            return $builder->findAll($limit);
        }, 1800); // 30 minutes cache for recommendations
    }

    /**
     * Helper method to get the model with proper type hint
     * 
     * @return ProductModel
     */
    protected function getProductModel(): ProductModel
    {
        /** @var ProductModel $model */
        $model = $this->model;
        return $model;
    }
}