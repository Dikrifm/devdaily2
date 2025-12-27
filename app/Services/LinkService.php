<?php

namespace App\Services;

use App\Contracts\LinkInterface;
use App\DTOs\Requests\Link\CreateLinkRequest;
use App\DTOs\Requests\Link\UpdateLinkRequest;
use App\DTOs\Requests\Link\BulkLinkUpdateRequest;
use App\DTOs\Responses\LinkResponse;
use App\DTOs\Responses\LinkAnalyticsResponse;
use App\Entities\Link;
use App\Entities\Product;
use App\Entities\Marketplace;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\Interfaces\LinkRepositoryInterface;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceRepositoryInterface;
use App\Repositories\Interfaces\MarketplaceBadgeRepositoryInterface;
use App\Validators\LinkValidator;
use CodeIgniter\Database\ConnectionInterface;
use CodeIgniter\I18n\Time;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Link Service
 * 
 * Business Orchestrator Layer (Layer 5): Concrete implementation for link business operations.
 * Manages affiliate links, price monitoring, click tracking, and marketplace integration.
 *
 * @package App\Services
 */
class LinkService extends BaseService implements LinkInterface
{
    /**
     * Link repository for data persistence
     *
     * @var LinkRepositoryInterface
     */
    private LinkRepositoryInterface $linkRepository;

    /**
     * Product repository for product validation
     *
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * Marketplace repository for marketplace validation
     *
     * @var MarketplaceRepositoryInterface
     */
    private MarketplaceRepositoryInterface $marketplaceRepository;

    /**
     * Marketplace badge repository for badge operations
     *
     * @var MarketplaceBadgeRepositoryInterface
     */
    private MarketplaceBadgeRepositoryInterface $badgeRepository;

    /**
     * Link validator for business rule validation
     *
     * @var LinkValidator
     */
    private LinkValidator $linkValidator;

    /**
     * Default commission rate for affiliate revenue
     */
    private const DEFAULT_COMMISSION_RATE = 0.02; // 2%

    /**
     * Constructor with dependency injection
     *
     * @param ConnectionInterface $db
     * @param CacheInterface $cache
     * @param AuditService $auditService
     * @param LinkRepositoryInterface $linkRepository
     * @param ProductRepositoryInterface $productRepository
     * @param MarketplaceRepositoryInterface $marketplaceRepository
     * @param MarketplaceBadgeRepositoryInterface $badgeRepository
     * @param LinkValidator $linkValidator
     */
    public function __construct(
        ConnectionInterface $db,
        CacheInterface $cache,
        AuditService $auditService,
        LinkRepositoryInterface $linkRepository,
        ProductRepositoryInterface $productRepository,
        MarketplaceRepositoryInterface $marketplaceRepository,
        MarketplaceBadgeRepositoryInterface $badgeRepository,
        LinkValidator $linkValidator
    ) {
        parent::__construct($db, $cache, $auditService);
        
        $this->linkRepository = $linkRepository;
        $this->productRepository = $productRepository;
        $this->marketplaceRepository = $marketplaceRepository;
        $this->badgeRepository = $badgeRepository;
        $this->linkValidator = $linkValidator;
        
        $this->validateLinkDependencies();
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function createLink(CreateLinkRequest $request): LinkResponse
    {
        // Authorization check
        $this->authorize('link.create');
        
        // Validate DTO
        $this->validateDTOOrFail($request, ['context' => 'create']);
        
        return $this->transaction(function () use ($request) {
            // Validate product exists and is active
            $product = $this->productRepository->findById($request->productId);
            if ($product === null || !$product->isActive()) {
                throw new DomainException(
                    'Produk tidak ditemukan atau tidak aktif',
                    'PRODUCT_NOT_AVAILABLE',
                    ['product_id' => $request->productId]
                );
            }
            
            // Validate marketplace exists and is active
            $marketplace = $this->marketplaceRepository->find($request->marketplaceId);
            if ($marketplace === null || !$marketplace->isActive()) {
                throw new DomainException(
                    'Marketplace tidak ditemukan atau tidak aktif',
                    'MARKETPLACE_NOT_AVAILABLE',
                    ['marketplace_id' => $request->marketplaceId]
                );
            }
            
            // Validate URL if provided
            if ($request->url !== null) {
                $urlValidation = $this->validateUrl($request->url, $request->marketplaceId);
                if (!$urlValidation['valid']) {
                    throw ValidationException::forBusinessRule(
                        $this->getServiceName(),
                        'URL tidak valid',
                        ['field' => 'url', 'errors' => $urlValidation['errors']]
                    );
                }
            }
            
            // Validate store name uniqueness
            if (!$this->isStoreNameUnique($request->storeName, $request->productId, $request->marketplaceId)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Nama toko sudah digunakan untuk produk dan marketplace ini',
                    ['field' => 'store_name']
                );
            }
            
            // Validate price format
            $priceValidation = $this->validatePrice($request->price, $request->productId);
            if (!$priceValidation['valid']) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Format harga tidak valid',
                    ['field' => 'price', 'errors' => $priceValidation['errors']]
                );
            }
            
            // Create entity
            $link = new Link($request->productId, $request->marketplaceId, $request->storeName);
            $link->setPrice($priceValidation['normalized']);
            
            if ($request->url !== null) {
                $link->setUrl($request->url);
            }
            
            if ($request->rating !== null) {
                $link->setRating($request->rating);
            }
            
            if ($request->active !== null) {
                $link->setActive($request->active);
            }
            
            if ($request->marketplaceBadgeId !== null) {
                $link->setMarketplaceBadgeId($request->marketplaceBadgeId);
            }
            
            // Business rule validation
            $businessErrors = $this->validateLinkBusinessRules($link, 'create');
            if (!empty($businessErrors)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Validasi bisnis link gagal',
                    ['errors' => $businessErrors]
                );
            }
            
            // Save to repository
            $savedLink = $this->linkRepository->save($link);
            
            if ($savedLink === null) {
                throw new DomainException(
                    'Gagal menyimpan link',
                    'LINK_SAVE_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation('link:*');
            $this->queueCacheOperation($this->getLinkCacheKey($savedLink->getId()));
            $this->queueCacheOperation($this->getProductLinksCacheKey($request->productId));
            $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($request->marketplaceId));
            
            // Audit log
            $this->audit(
                'link.create',
                'link',
                $savedLink->getId(),
                null,
                $savedLink->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'product_id' => $request->productId,
                    'marketplace_id' => $request->marketplaceId,
                    'store_name' => $request->storeName
                ]
            );
            
            return LinkResponse::fromEntity($savedLink);
        }, 'create_link');
    }

    /**
     * {@inheritDoc}
     */
    public function updateLink(UpdateLinkRequest $request): LinkResponse
    {
        // Authorization check
        $this->authorize('link.update');
        
        // Validate DTO
        $this->validateDTOOrFail($request, ['context' => 'update']);
        
        return $this->transaction(function () use ($request) {
            // Get existing link
            $existingLink = $this->getEntity(
                $this->linkRepository,
                $request->linkId
            );
            
            // Store old values for audit
            $oldValues = $existingLink->toArray();
            
            // Update properties
            $link = clone $existingLink;
            
            if ($request->storeName !== null) {
                // Validate store name uniqueness
                if (!$this->isStoreNameUnique(
                    $request->storeName,
                    $existingLink->getProductId(),
                    $existingLink->getMarketplaceId(),
                    $request->linkId
                )) {
                    throw ValidationException::forBusinessRule(
                        $this->getServiceName(),
                        'Nama toko sudah digunakan untuk produk dan marketplace ini',
                        ['field' => 'store_name']
                    );
                }
                $link->setStoreName($request->storeName);
            }
            
            if ($request->url !== null) {
                $urlValidation = $this->validateUrl($request->url, $existingLink->getMarketplaceId());
                if (!$urlValidation['valid']) {
                    throw ValidationException::forBusinessRule(
                        $this->getServiceName(),
                        'URL tidak valid',
                        ['field' => 'url', 'errors' => $urlValidation['errors']]
                    );
                }
                $link->setUrl($request->url);
            }
            
            if ($request->price !== null) {
                $priceValidation = $this->validatePrice($request->price, $existingLink->getProductId());
                if (!$priceValidation['valid']) {
                    throw ValidationException::forBusinessRule(
                        $this->getServiceName(),
                        'Format harga tidak valid',
                        ['field' => 'price', 'errors' => $priceValidation['errors']]
                    );
                }
                $link->updatePrice($priceValidation['normalized'], $request->autoUpdateTimestamp ?? true);
            }
            
            if ($request->rating !== null) {
                $link->setRating($request->rating);
            }
            
            if ($request->active !== null) {
                $link->setActive($request->active);
            }
            
            if ($request->marketplaceBadgeId !== null) {
                $link->setMarketplaceBadgeId($request->marketplaceBadgeId);
            }
            
            // Business rule validation
            $businessErrors = $this->validateLinkBusinessRules($link, 'update');
            if (!empty($businessErrors)) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Validasi bisnis link gagal',
                    ['errors' => $businessErrors]
                );
            }
            
            // Save updates
            $updatedLink = $this->linkRepository->save($link);
            
            if ($updatedLink === null) {
                throw new DomainException(
                    'Gagal memperbarui link',
                    'LINK_UPDATE_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation('link:*');
            $this->queueCacheOperation($this->getLinkCacheKey($request->linkId));
            $this->queueCacheOperation($this->getProductLinksCacheKey($existingLink->getProductId()));
            $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($existingLink->getMarketplaceId()));
            
            // If price was updated, also invalidate product cache
            if ($request->price !== null) {
                $this->queueCacheOperation($this->getProductCacheKey($existingLink->getProductId()));
                $this->queueCacheOperation('product:*price*');
            }
            
            // Audit log
            $this->audit(
                'link.update',
                'link',
                $request->linkId,
                $oldValues,
                $updatedLink->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'changed_fields' => array_keys(array_diff_assoc($updatedLink->toArray(), $oldValues))
                ]
            );
            
            return LinkResponse::fromEntity($updatedLink);
        }, 'update_link');
    }

    /**
     * {@inheritDoc}
     */
    public function deleteLink(int $linkId, bool $force = false): bool
    {
        // Authorization check
        $this->authorize('link.delete');
        
        return $this->transaction(function () use ($linkId, $force) {
            // Get link
            $link = $this->getEntity(
                $this->linkRepository,
                $linkId
            );
            
            // Check preconditions if not forced
            if (!$force) {
                $preconditions = $this->validateLinkDeletion($linkId);
                if (!$preconditions['can_delete']) {
                    throw new DomainException(
                        'Link tidak dapat dihapus karena memiliki riwayat klik atau penjualan',
                        'LINK_DELETION_PRECONDITION_FAILED',
                        $preconditions
                    );
                }
            }
            
            // Store for audit
            $oldValues = $link->toArray();
            
            // Perform deletion
            $result = $this->linkRepository->delete($linkId);
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation('link:*');
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
                $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
                
                // Audit log
                $this->audit(
                    'link.delete',
                    'link',
                    $linkId,
                    $oldValues,
                    null,
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'force' => $force,
                        'product_id' => $link->getProductId(),
                        'marketplace_id' => $link->getMarketplaceId()
                    ]
                );
            }
            
            return $result;
        }, 'delete_link');
    }

    /**
     * {@inheritDoc}
     */
    public function archiveLink(int $linkId): bool
    {
        // Authorization check
        $this->authorize('link.archive');
        
        return $this->transaction(function () use ($linkId) {
            // Get link
            $link = $this->getEntity(
                $this->linkRepository,
                $linkId
            );
            
            // Check if can be archived
            if (!$this->canArchiveLink($linkId)) {
                throw new DomainException(
                    'Link tidak dapat diarsipkan karena masih aktif dan memiliki traffic',
                    'LINK_ARCHIVE_PRECONDITION_FAILED'
                );
            }
            
            // Store for audit
            $oldValues = $link->toArray();
            
            // Archive link
            $link->archive();
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation('link:*');
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
                $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
                
                // Audit log
                $this->audit(
                    'link.archive',
                    'link',
                    $linkId,
                    $oldValues,
                    $link->toArray(),
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'product_id' => $link->getProductId(),
                        'marketplace_id' => $link->getMarketplaceId()
                    ]
                );
            }
            
            return $result;
        }, 'archive_link');
    }

    /**
     * {@inheritDoc}
     */
    public function restoreLink(int $linkId): LinkResponse
    {
        // Authorization check
        $this->authorize('link.restore');
        
        return $this->transaction(function () use ($linkId) {
            // Get link (including archived)
            $link = $this->linkRepository->findById($linkId);
            if ($link === null) {
                throw NotFoundException::forEntity('Link', $linkId);
            }
            
            // Store for audit
            $oldValues = $link->toArray();
            
            // Restore link
            $link->restore();
            $restoredLink = $this->linkRepository->save($link);
            
            if ($restoredLink === null) {
                throw new DomainException(
                    'Gagal mengembalikan link',
                    'LINK_RESTORE_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation('link:*');
            $this->queueCacheOperation($this->getLinkCacheKey($linkId));
            $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
            $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
            
            // Audit log
            $this->audit(
                'link.restore',
                'link',
                $linkId,
                $oldValues,
                $restoredLink->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'product_id' => $link->getProductId(),
                    'marketplace_id' => $link->getMarketplaceId()
                ]
            );
            
            return LinkResponse::fromEntity($restoredLink);
        }, 'restore_link');
    }

    // ==================== QUERY OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function getLink(int $linkId): LinkResponse
    {
        $link = $this->getEntity(
            $this->linkRepository,
            $linkId
        );
        
        return LinkResponse::fromEntity($link);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductLinks(int $productId, bool $activeOnly = true): array
    {
        return $this->withCaching(
            $this->getProductLinksCacheKey($productId, $activeOnly),
            function () use ($productId, $activeOnly) {
                $links = $this->linkRepository->findActiveForProduct($productId, true);
                
                if ($activeOnly) {
                    $links = array_filter($links, fn($link) => $link->isActive());
                }
                
                return array_map(
                    fn($link) => LinkResponse::fromEntity($link),
                    $links
                );
            },
            600 // Cache for 10 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceLinks(int $marketplaceId, bool $activeOnly = true): array
    {
        return $this->withCaching(
            $this->getMarketplaceLinksCacheKey($marketplaceId, $activeOnly),
            function () use ($marketplaceId, $activeOnly) {
                $links = $this->linkRepository->findByMarketplaceSorted($marketplaceId, 'price', 'asc', true);
                
                if ($activeOnly) {
                    $links = array_filter($links, fn($link) => $link->isActive());
                }
                
                return array_map(
                    fn($link) => LinkResponse::fromEntity($link),
                    $links
                );
            },
            600 // Cache for 10 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getLinkByProductAndMarketplace(int $productId, int $marketplaceId): ?LinkResponse
    {
        $link = $this->linkRepository->findByProductAndMarketplace($productId, $marketplaceId, true);
        
        if ($link === null) {
            return null;
        }
        
        return LinkResponse::fromEntity($link);
    }

    /**
     * {@inheritDoc}
     */
    public function searchLinks(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('search', ['filters' => $filters, 'page' => $page, 'perPage' => $perPage]),
            function () use ($filters, $page, $perPage) {
                // Apply additional business logic to filters
                $processedFilters = $this->processLinkFilters($filters);
                
                $result = $this->linkRepository->paginateWithFilters($processedFilters, $perPage, $page);
                
                $links = array_map(
                    fn($link) => LinkResponse::fromEntity($link),
                    $result['data'] ?? []
                );
                
                return [
                    'links' => $links,
                    'pagination' => [
                        'total' => $result['total'] ?? 0,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => $result['last_page'] ?? 1,
                        'from' => $result['from'] ?? 0,
                        'to' => $result['to'] ?? 0
                    ]
                ];
            },
            300 // Cache for 5 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getLinksPaginated(
        string $sortBy = 'created_at',
        string $sortDirection = 'desc',
        int $page = 1,
        int $perPage = 25
    ): array {
        // Validate sort parameters
        $allowedSortFields = ['created_at', 'updated_at', 'price', 'rating', 'clicks', 'sold_count', 'store_name'];
        if (!in_array($sortBy, $allowedSortFields)) {
            $sortBy = 'created_at';
        }
        
        $sortDirection = strtolower($sortDirection) === 'asc' ? 'asc' : 'desc';
        
        return $this->withCaching(
            $this->getServiceCacheKey('paginated', [
                'sortBy' => $sortBy,
                'sortDirection' => $sortDirection,
                'page' => $page,
                'perPage' => $perPage
            ]),
            function () use ($sortBy, $sortDirection, $page, $perPage) {
                $filters = [
                    'sort_by' => $sortBy,
                    'sort_direction' => $sortDirection,
                    'active_only' => true
                ];
                
                $result = $this->linkRepository->paginateWithFilters($filters, $perPage, $page);
                
                $links = array_map(
                    fn($link) => LinkResponse::fromEntity($link),
                    $result['data'] ?? []
                );
                
                return [
                    'links' => $links,
                    'pagination' => $result['pagination'] ?? [
                        'total' => 0,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => 1
                    ]
                ];
            },
            300 // Cache for 5 minutes
        );
    }

    // ==================== BATCH OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function bulkUpdateStatus(array $linkIds, bool $active): int
    {
        // Authorization check
        $this->authorize('link.bulk_update');
        
        return $this->transaction(function () use ($linkIds, $active) {
            $count = $this->linkRepository->bulkUpdateStatus($linkIds, $active);
            
            if ($count > 0) {
                // Queue cache invalidation for all affected links
                $this->queueCacheOperation('link:*');
                foreach ($linkIds as $linkId) {
                    $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                }
                
                // Also invalidate product and marketplace caches
                $links = $this->linkRepository->findByIds($linkIds);
                $productIds = array_unique(array_map(fn($link) => $link->getProductId(), $links));
                $marketplaceIds = array_unique(array_map(fn($link) => $link->getMarketplaceId(), $links));
                
                foreach ($productIds as $productId) {
                    $this->queueCacheOperation($this->getProductLinksCacheKey($productId));
                }
                
                foreach ($marketplaceIds as $marketplaceId) {
                    $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($marketplaceId));
                }
                
                // Audit log
                $this->audit(
                    'link.bulk_update_status',
                    'link',
                    0,
                    null,
                    ['link_ids' => $linkIds, 'active' => $active],
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'count' => $count,
                        'action' => $active ? 'activate' : 'deactivate'
                    ]
                );
            }
            
            return $count;
        }, 'bulk_update_link_status');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkUpdatePrices(BulkLinkUpdateRequest $request): array
    {
        // Authorization check
        $this->authorize('link.bulk_update');
        
        // Validate DTO
        $this->validateDTOOrFail($request, ['context' => 'bulk_update']);
        
        $result = [
            'updated' => 0,
            'failed' => [],
            'details' => []
        ];
        
        return $this->batchOperation(
            $request->priceUpdates,
            function ($priceUpdate, $index) use (&$result) {
                try {
                    $linkId = $priceUpdate['link_id'];
                    $newPrice = $priceUpdate['price'];
                    
                    // Get link
                    $link = $this->getEntity($this->linkRepository, $linkId);
                    
                    // Validate price
                    $priceValidation = $this->validatePrice($newPrice, $link->getProductId());
                    if (!$priceValidation['valid']) {
                        $result['failed'][$linkId] = implode(', ', $priceValidation['errors']);
                        $result['details'][] = [
                            'link_id' => $linkId,
                            'status' => 'failed',
                            'error' => 'Invalid price format'
                        ];
                        return null;
                    }
                    
                    // Update price
                    $updatedLink = $this->updateLinkPrice(
                        $linkId,
                        $priceValidation['normalized'],
                        $priceUpdate['auto_update_timestamp'] ?? true
                    );
                    
                    $result['updated']++;
                    $result['details'][] = [
                        'link_id' => $linkId,
                        'status' => 'updated',
                        'old_price' => $link->getPrice(),
                        'new_price' => $priceValidation['normalized']
                    ];
                    
                    return $updatedLink;
                    
                } catch (\Exception $e) {
                    $result['failed'][$priceUpdate['link_id']] = $e->getMessage();
                    $result['details'][] = [
                        'link_id' => $priceUpdate['link_id'],
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                    
                    // Continue with other updates even if one fails
                    return null;
                }
            },
            50, // Process 50 at a time
            null
        );
        
        // Clear relevant caches
        $this->queueCacheOperation('link:*price*');
        $this->queueCacheOperation('product:*price*');
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function bulkCreateLinks(array $linksData): array
    {
        // Authorization check
        $this->authorize('link.bulk_create');
        
        $result = [
            'created' => 0,
            'failed' => [],
            'details' => []
        ];
        
        return $this->transaction(function () use ($linksData, &$result) {
            $createdLinks = [];
            
            foreach ($linksData as $index => $linkData) {
                try {
                    // Validate required fields
                    $requiredFields = ['product_id', 'marketplace_id', 'store_name'];
                    foreach ($requiredFields as $field) {
                        if (!isset($linkData[$field])) {
                            throw new ValidationException(
                                "Missing required field: {$field}",
                                'MISSING_REQUIRED_FIELD',
                                ['field' => $field, 'index' => $index]
                            );
                        }
                    }
                    
                    // Validate product exists
                    $product = $this->productRepository->findById($linkData['product_id']);
                    if ($product === null) {
                        throw new DomainException(
                            'Produk tidak ditemukan',
                            'PRODUCT_NOT_FOUND',
                            ['product_id' => $linkData['product_id'], 'index' => $index]
                        );
                    }
                    
                    // Validate marketplace exists
                    $marketplace = $this->marketplaceRepository->find($linkData['marketplace_id']);
                    if ($marketplace === null) {
                        throw new DomainException(
                            'Marketplace tidak ditemukan',
                            'MARKETPLACE_NOT_FOUND',
                            ['marketplace_id' => $linkData['marketplace_id'], 'index' => $index]
                        );
                    }
                    
                    // Validate store name uniqueness
                    if (!$this->isStoreNameUnique(
                        $linkData['store_name'],
                        $linkData['product_id'],
                        $linkData['marketplace_id']
                    )) {
                        throw new DomainException(
                            'Nama toko sudah digunakan',
                            'STORE_NAME_NOT_UNIQUE',
                            ['store_name' => $linkData['store_name'], 'index' => $index]
                        );
                    }
                    
                    // Create link entity
                    $link = new Link(
                        $linkData['product_id'],
                        $linkData['marketplace_id'],
                        $linkData['store_name']
                    );
                    
                    // Set optional fields
                    if (isset($linkData['url'])) {
                        $urlValidation = $this->validateUrl($linkData['url'], $linkData['marketplace_id']);
                        if ($urlValidation['valid']) {
                            $link->setUrl($linkData['url']);
                        }
                    }
                    
                    if (isset($linkData['price'])) {
                        $priceValidation = $this->validatePrice($linkData['price'], $linkData['product_id']);
                        if ($priceValidation['valid']) {
                            $link->setPrice($priceValidation['normalized']);
                        }
                    }
                    
                    if (isset($linkData['rating'])) {
                        $link->setRating($linkData['rating']);
                    }
                    
                    if (isset($linkData['active'])) {
                        $link->setActive((bool)$linkData['active']);
                    }
                    
                    // Save link
                    $savedLink = $this->linkRepository->save($link);
                    if ($savedLink !== null) {
                        $createdLinks[] = $savedLink;
                        $result['created']++;
                        $result['details'][] = [
                            'index' => $index,
                            'link_id' => $savedLink->getId(),
                            'status' => 'created'
                        ];
                    }
                    
                } catch (\Exception $e) {
                    $result['failed'][$index] = $e->getMessage();
                    $result['details'][] = [
                        'index' => $index,
                        'status' => 'failed',
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Queue cache invalidation if any links were created
            if ($result['created'] > 0) {
                $this->queueCacheOperation('link:*');
                
                // Collect unique product and marketplace IDs for cache invalidation
                $productIds = array_unique(array_map(fn($link) => $link->getProductId(), $createdLinks));
                $marketplaceIds = array_unique(array_map(fn($link) => $link->getMarketplaceId(), $createdLinks));
                
                foreach ($productIds as $productId) {
                    $this->queueCacheOperation($this->getProductLinksCacheKey($productId));
                }
                
                foreach ($marketplaceIds as $marketplaceId) {
                    $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($marketplaceId));
                }
                
                // Audit log
                $this->audit(
                    'link.bulk_create',
                    'link',
                    0,
                    null,
                    ['created_count' => $result['created'], 'failed_count' => count($result['failed'])],
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'total_attempted' => count($linksData)
                    ]
                );
            }
            
            return $result;
        }, 'bulk_create_links');
    }

    /**
     * {@inheritDoc}
     */
    public function bulkArchiveLinks(array $linkIds): int
    {
        // Authorization check
        $this->authorize('link.bulk_archive');
        
        $archivedCount = 0;
        
        return $this->batchOperation(
            $linkIds,
            function ($linkId) use (&$archivedCount) {
                try {
                    if ($this->archiveLink($linkId)) {
                        $archivedCount++;
                    }
                } catch (\Exception $e) {
                    log_message('error', sprintf(
                        'Failed to archive link %d: %s',
                        $linkId,
                        $e->getMessage()
                    ));
                }
                
                return null;
            },
            50,
            null
        );
    }

    /**
     * {@inheritDoc}
     */
    public function bulkRestoreLinks(array $linkIds): int
    {
        // Authorization check
        $this->authorize('link.bulk_restore');
        
        $restoredCount = 0;
        
        foreach ($linkIds as $linkId) {
            try {
                $this->restoreLink($linkId);
                $restoredCount++;
            } catch (\Exception $e) {
                log_message('error', sprintf(
                    'Failed to restore link %d: %s',
                    $linkId,
                    $e->getMessage()
                ));
            }
        }
        
        return $restoredCount;
    }

    // ==================== PRICE MONITORING OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function updateLinkPrice(int $linkId, string $newPrice, bool $autoUpdateTimestamp = true): LinkResponse
    {
        // Authorization check (price updates might be automated, so not always requiring admin)
        $this->authorize('link.update_price');
        
        return $this->transaction(function () use ($linkId, $newPrice, $autoUpdateTimestamp) {
            // Get existing link
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            // Validate price format
            $priceValidation = $this->validatePrice($newPrice, $link->getProductId());
            if (!$priceValidation['valid']) {
                throw ValidationException::forBusinessRule(
                    $this->getServiceName(),
                    'Format harga tidak valid',
                    ['field' => 'price', 'errors' => $priceValidation['errors']]
                );
            }
            
            // Update price
            $link->updatePrice($priceValidation['normalized'], $autoUpdateTimestamp);
            
            // Save updates
            $updatedLink = $this->linkRepository->save($link);
            
            if ($updatedLink === null) {
                throw new DomainException(
                    'Gagal memperbarui harga link',
                    'LINK_PRICE_UPDATE_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation('link:*');
            $this->queueCacheOperation($this->getLinkCacheKey($linkId));
            $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
            $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
            $this->queueCacheOperation($this->getProductCacheKey($link->getProductId()));
            $this->queueCacheOperation('product:*price*');
            
            // Audit log
            $this->audit(
                'link.update_price',
                'link',
                $linkId,
                $oldValues,
                $updatedLink->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'old_price' => $oldValues['price'],
                    'new_price' => $priceValidation['normalized'],
                    'auto_update_timestamp' => $autoUpdateTimestamp
                ]
            );
            
            return LinkResponse::fromEntity($updatedLink);
        }, 'update_link_price');
    }

    /**
     * {@inheritDoc}
     */
    public function getLinksNeedingPriceUpdate(int $limit = 100, int $maxAgeHours = 24): array
    {
        $links = $this->linkRepository->findNeedingPriceUpdate($maxAgeHours, $limit, true);
        
        return array_map(
            fn($link) => LinkResponse::fromEntity($link),
            $links
        );
    }

    /**
     * {@inheritDoc}
     */
    public function markPriceChecked(int $linkId): bool
    {
        return $this->transaction(function () use ($linkId) {
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            $link->markPriceChecked();
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                
                // Audit log
                $this->audit(
                    'link.mark_price_checked',
                    'link',
                    $linkId,
                    $oldValues,
                    $link->toArray(),
                    ['admin_id' => $this->getCurrentAdminId()]
                );
            }
            
            return $result;
        }, 'mark_price_checked');
    }

    /**
     * {@inheritDoc}
     */
    public function validatePrice(string $price, ?int $productId = null): array
    {
        $errors = [];
        $normalized = $price;
        
        // Remove currency symbols and thousand separators
        $normalized = preg_replace('/[^\d.,]/', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
        
        // Validate numeric format
        if (!is_numeric($normalized)) {
            $errors[] = 'Harga harus berupa angka';
        }
        
        // Validate positive value
        if (is_numeric($normalized) && floatval($normalized) <= 0) {
            $errors[] = 'Harga harus lebih dari 0';
        }
        
        // Validate maximum value
        if (is_numeric($normalized) && floatval($normalized) > 1000000000) {
            $errors[] = 'Harga tidak boleh melebihi 1.000.000.000';
        }
        
        // Format to 2 decimal places if valid
        if (empty($errors) && is_numeric($normalized)) {
            $normalized = number_format(floatval($normalized), 2, '.', '');
        }
        
        return [
            'valid' => empty($errors),
            'normalized' => $normalized,
            'errors' => $errors
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function comparePriceWithMarket(int $linkId): array
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        $product = $this->productRepository->findById($link->getProductId());
        
        if ($product === null) {
            throw new NotFoundException('Product not found for link');
        }
        
        $linkPrice = floatval($link->getPrice());
        $marketPrice = floatval($product->getMarketPrice());
        
        if ($marketPrice <= 0) {
            return [
                'link_price' => $link->getPrice(),
                'market_price' => $product->getMarketPrice(),
                'difference' => '0.00',
                'percentage' => 0.0,
                'is_cheaper' => false,
                'is_expensive' => false,
                'has_market_price' => false
            ];
        }
        
        $difference = $linkPrice - $marketPrice;
        $percentage = ($difference / $marketPrice) * 100;
        
        return [
            'link_price' => $link->getPrice(),
            'market_price' => $product->getMarketPrice(),
            'difference' => number_format($difference, 2, '.', ''),
            'percentage' => round($percentage, 2),
            'is_cheaper' => $difference < 0,
            'is_expensive' => $difference > 0,
            'has_market_price' => true,
            'price_difference_percentage' => abs($percentage)
        ];
    }

    // ==================== AFFILIATE & TRACKING OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function recordClick(int $linkId, array $trackingData = []): bool
    {
        return $this->transaction(function () use ($linkId, $trackingData) {
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            // Increment click count
            $link->incrementClicks();
            
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
                $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
                $this->queueCacheOperation('link:*analytics*');
                
                // Audit log for click tracking
                $this->audit(
                    'link.click',
                    'link',
                    $linkId,
                    ['old_clicks' => $oldValues['clicks']],
                    ['new_clicks' => $link->getClicks()],
                    array_merge(
                        $trackingData,
                        [
                            'admin_id' => $this->getCurrentAdminId(),
                            'product_id' => $link->getProductId(),
                            'marketplace_id' => $link->getMarketplaceId(),
                            'timestamp' => Time::now()->toDateTimeString()
                        ]
                    )
                );
            }
            
            return $result;
        }, 'record_click');
    }

    /**
     * {@inheritDoc}
     */
    public function recordConversion(int $linkId, string $orderId, string $revenue, array $metadata = []): bool
    {
        return $this->transaction(function () use ($linkId, $orderId, $revenue, $metadata) {
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            // Increment sold count
            $link->incrementSoldCount();
            
            // Add affiliate revenue
            $link->addAffiliateRevenue($revenue);
            
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
                $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
                $this->queueCacheOperation('link:*analytics*');
                $this->queueCacheOperation('link:*revenue*');
                
                // Audit log for conversion
                $this->audit(
                    'link.conversion',
                    'link',
                    $linkId,
                    [
                        'old_sold_count' => $oldValues['sold_count'],
                        'old_revenue' => $oldValues['affiliate_revenue']
                    ],
                    [
                        'new_sold_count' => $link->getSoldCount(),
                        'new_revenue' => $link->getAffiliateRevenue()
                    ],
                    array_merge(
                        $metadata,
                        [
                            'admin_id' => $this->getCurrentAdminId(),
                            'product_id' => $link->getProductId(),
                            'marketplace_id' => $link->getMarketplaceId(),
                            'order_id' => $orderId,
                            'revenue' => $revenue,
                            'timestamp' => Time::now()->toDateTimeString()
                        ]
                    )
                );
            }
            
            return $result;
        }, 'record_conversion');
    }

    /**
     * {@inheritDoc}
     */
    public function calculateAffiliateRevenue(int $linkId, ?float $customCommissionRate = null): string
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        $soldCount = $link->getSoldCount();
        $price = floatval($link->getPrice());
        $commissionRate = $customCommissionRate ?? self::DEFAULT_COMMISSION_RATE;
        
        // Calculate revenue: sold_count * price * commission_rate
        $revenue = $soldCount * $price * $commissionRate;
        
        return number_format($revenue, 2, '.', '');
    }

    /**
     * {@inheritDoc}
     */
    public function updateRevenueWithCommission(int $linkId, float $commissionRate): bool
    {
        return $this->transaction(function () use ($linkId, $commissionRate) {
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            // Calculate and update revenue
            $revenue = $this->calculateAffiliateRevenue($linkId, $commissionRate);
            $link->setAffiliateRevenue($revenue);
            
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                $this->queueCacheOperation('link:*revenue*');
                
                // Audit log
                $this->audit(
                    'link.update_revenue',
                    'link',
                    $linkId,
                    ['old_revenue' => $oldValues['affiliate_revenue']],
                    ['new_revenue' => $revenue],
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'commission_rate' => $commissionRate,
                        'calculated_revenue' => $revenue
                    ]
                );
            }
            
            return $result;
        }, 'update_revenue_with_commission');
    }

    /**
     * {@inheritDoc}
     */
    public function getClickThroughRate(int $linkId, float $totalProductViews = 0): float
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        $clicks = $link->getClicks();
        
        if ($totalProductViews > 0) {
            return $clicks > 0 ? ($clicks / $totalProductViews) * 100 : 0.0;
        }
        
        // If no product views provided, return clicks as absolute number
        return floatval($clicks);
    }

    /**
     * {@inheritDoc}
     */
    public function getRevenuePerClick(int $linkId): string
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        $clicks = $link->getClicks();
        $revenue = floatval($link->getAffiliateRevenue());
        
        if ($clicks === 0) {
            return '0.00';
        }
        
        $rpc = $revenue / $clicks;
        return number_format($rpc, 2, '.', '');
    }

    // ==================== VALIDATION OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateUrl(string $url, ?int $marketplaceId = null): array
    {
        $errors = [];
        $normalized = trim($url);
        
        // Basic URL validation
        if (empty($normalized)) {
            return [
                'valid' => true, // URL is optional
                'normalized' => '',
                'errors' => []
            ];
        }
        
        // Validate URL format
        if (!filter_var($normalized, FILTER_VALIDATE_URL)) {
            $errors[] = 'Format URL tidak valid';
        }
        
        // Validate URL length
        if (strlen($normalized) > 500) {
            $errors[] = 'URL tidak boleh melebihi 500 karakter';
        }
        
        // Validate marketplace-specific domains if marketplaceId provided
        if ($marketplaceId !== null && empty($errors)) {
            $marketplace = $this->marketplaceRepository->find($marketplaceId);
            if ($marketplace !== null) {
                $allowedDomains = $this->marketplaceRepository->getAllowedDomains($marketplaceId);
                if (!empty($allowedDomains)) {
                    $urlDomain = parse_url($normalized, PHP_URL_HOST);
                    $domainValid = false;
                    
                    foreach ($allowedDomains as $allowedDomain) {
                        if (strpos($urlDomain, $allowedDomain) !== false) {
                            $domainValid = true;
                            break;
                        }
                    }
                    
                    if (!$domainValid) {
                        $errors[] = sprintf(
                            'URL harus berasal dari domain marketplace %s',
                            $marketplace->getName()
                        );
                    }
                }
            }
        }
        
        return [
            'valid' => empty($errors),
            'normalized' => $normalized,
            'errors' => $errors
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function isStoreNameUnique(string $storeName, int $productId, int $marketplaceId, ?int $excludeLinkId = null): bool
    {
        // Implementation would check database for store name uniqueness
        // For now, return true as placeholder
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function markAsValidated(int $linkId): bool
    {
        return $this->transaction(function () use ($linkId) {
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            $link->markAsValidated();
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                
                // Audit log
                $this->audit(
                    'link.mark_validated',
                    'link',
                    $linkId,
                    $oldValues,
                    $link->toArray(),
                    ['admin_id' => $this->getCurrentAdminId()]
                );
            }
            
            return $result;
        }, 'mark_link_validated');
    }

    /**
     * {@inheritDoc}
     */
    public function markAsInvalid(int $linkId, string $reason): bool
    {
        return $this->transaction(function () use ($linkId, $reason) {
            $link = $this->getEntity($this->linkRepository, $linkId);
            $oldValues = $link->toArray();
            
            $link->markAsInvalid();
            $link->deactivate();
            
            $result = $this->linkRepository->save($link) !== null;
            
            if ($result) {
                // Queue cache invalidation
                $this->queueCacheOperation($this->getLinkCacheKey($linkId));
                $this->queueCacheOperation($this->getProductLinksCacheKey($link->getProductId()));
                $this->queueCacheOperation($this->getMarketplaceLinksCacheKey($link->getMarketplaceId()));
                
                // Audit log
                $this->audit(
                    'link.mark_invalid',
                    'link',
                    $linkId,
                    $oldValues,
                    $link->toArray(),
                    [
                        'admin_id' => $this->getCurrentAdminId(),
                        'reason' => $reason,
                        'action' => 'deactivated'
                    ]
                );
            }
            
            return $result;
        }, 'mark_link_invalid');
    }

    /**
     * {@inheritDoc}
     */
    public function getLinksNeedingValidation(int $limit = 100, int $maxAgeHours = 72): array
    {
        $links = $this->linkRepository->findNeedingValidation($limit, true);
        
        return array_map(
            fn($link) => LinkResponse::fromEntity($link),
            $links
        );
    }

    // ==================== ANALYTICS & REPORTING OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function getLinkAnalytics(int $linkId, string $period = '30d'): LinkAnalyticsResponse
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        // Parse period
        $days = 30;
        if (preg_match('/(\d+)d/', $period, $matches)) {
            $days = intval($matches[1]);
        }
        
        // Get analytics data (simplified for MVP)
        $startDate = Time::now()->subDays($days);
        
        // This would typically query detailed analytics from a separate table
        // For MVP, we'll use the link's aggregated data
        
        return new LinkAnalyticsResponse([
            'link_id' => $linkId,
            'period' => $period,
            'period_days' => $days,
            'total_clicks' => $link->getClicks(),
            'total_conversions' => $link->getSoldCount(),
            'total_revenue' => $link->getAffiliateRevenue(),
            'average_rating' => floatval($link->getRating()),
            'click_through_rate' => $this->getClickThroughRate($linkId),
            'revenue_per_click' => $this->getRevenuePerClick($linkId),
            'conversion_rate' => $link->getClicks() > 0 
                ? ($link->getSoldCount() / $link->getClicks()) * 100 
                : 0,
            'price_history' => [], // Would be populated from price history table
            'performance_trend' => 'stable', // Would be calculated from historical data
            'last_updated' => Time::now()->toDateTimeString()
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getProductLinkStatistics(int $productId): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('product_link_stats', ['product_id' => $productId]),
            function () use ($productId) {
                $links = $this->linkRepository->findActiveForProduct($productId, true);
                
                $totalLinks = count($links);
                $activeLinks = count(array_filter($links, fn($link) => $link->isActive()));
                
                $totalClicks = 0;
                $totalSales = 0;
                $totalRevenue = 0.0;
                $ratings = [];
                $prices = [];
                
                foreach ($links as $link) {
                    $totalClicks += $link->getClicks();
                    $totalSales += $link->getSoldCount();
                    $totalRevenue += floatval($link->getAffiliateRevenue());
                    
                    $rating = floatval($link->getRating());
                    if ($rating > 0) {
                        $ratings[] = $rating;
                    }
                    
                    $price = floatval($link->getPrice());
                    if ($price > 0) {
                        $prices[] = $price;
                    }
                }
                
                $averageRating = !empty($ratings) ? array_sum($ratings) / count($ratings) : 0;
                $cheapestPrice = !empty($prices) ? min($prices) : 0;
                $mostExpensivePrice = !empty($prices) ? max($prices) : 0;
                
                return [
                    'total_links' => $totalLinks,
                    'active_links' => $activeLinks,
                    'total_clicks' => $totalClicks,
                    'total_sales' => $totalSales,
                    'total_revenue' => number_format($totalRevenue, 2, '.', ''),
                    'average_rating' => round($averageRating, 2),
                    'cheapest_price' => number_format($cheapestPrice, 2, '.', ''),
                    'most_expensive_price' => number_format($mostExpensivePrice, 2, '.', ''),
                    'price_range' => $cheapestPrice > 0 ? number_format($mostExpensivePrice - $cheapestPrice, 2, '.', '') : '0.00',
                    'marketplaces_count' => count(array_unique(array_map(fn($link) => $link->getMarketplaceId(), $links)))
                ];
            },
            1800 // Cache for 30 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getMarketplaceLinkStatistics(int $marketplaceId): array
    {
        return $this->withCaching(
            $this->getServiceCacheKey('marketplace_link_stats', ['marketplace_id' => $marketplaceId]),
            function () use ($marketplaceId) {
                $links = $this->linkRepository->findByMarketplaceSorted($marketplaceId, 'clicks', 'desc', true);
                $activeLinks = array_filter($links, fn($link) => $link->isActive());
                
                $totalClicks = 0;
                $totalSales = 0;
                $totalRevenue = 0.0;
                $ratings = [];
                
                foreach ($activeLinks as $link) {
                    $totalClicks += $link->getClicks();
                    $totalSales += $link->getSoldCount();
                    $totalRevenue += floatval($link->getAffiliateRevenue());
                    
                    $rating = floatval($link->getRating());
                    if ($rating > 0) {
                        $ratings[] = $rating;
                    }
                }
                
                $averageRating = !empty($ratings) ? array_sum($ratings) / count($ratings) : 0;
                $clickThroughRate = count($activeLinks) > 0 ? ($totalClicks / count($activeLinks)) : 0;
                
                return [
                    'total_links' => count($links),
                    'active_links' => count($activeLinks),
                    'total_clicks' => $totalClicks,
                    'total_sales' => $totalSales,
                    'total_revenue' => number_format($totalRevenue, 2, '.', ''),
                    'average_rating' => round($averageRating, 2),
                    'click_through_rate' => round($clickThroughRate, 2),
                    'average_revenue_per_link' => count($activeLinks) > 0 
                        ? number_format($totalRevenue / count($activeLinks), 2, '.', '') 
                        : '0.00',
                    'products_count' => count(array_unique(array_map(fn($link) => $link->getProductId(), $links)))
                ];
            },
            1800 // Cache for 30 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getTopPerformingLinks(string $metric = 'revenue', string $period = 'month', int $limit = 10): array
    {
        $allowedMetrics = ['clicks', 'sales', 'revenue', 'rating'];
        if (!in_array($metric, $allowedMetrics)) {
            $metric = 'revenue';
        }
        
        return $this->withCaching(
            $this->getServiceCacheKey('top_performing', ['metric' => $metric, 'period' => $period, 'limit' => $limit]),
            function () use ($metric, $period, $limit) {
                $links = $this->linkRepository->findTopPerforming($metric, $period, $limit, true);
                
                return array_map(
                    fn($link) => LinkResponse::fromEntity($link),
                    $links
                );
            },
            900 // Cache for 15 minutes
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getPerformanceComparison(array $linkIds, string $period = 'month'): array
    {
        $comparison = [];
        
        foreach ($linkIds as $linkId) {
            try {
                $link = $this->getEntity($this->linkRepository, $linkId);
                $analytics = $this->getLinkAnalytics($linkId, $period);
                
                $comparison[$linkId] = [
                    'link_id' => $linkId,
                    'store_name' => $link->getStoreName(),
                    'marketplace' => $link->getMarketplaceId(),
                    'product' => $link->getProductId(),
                    'price' => $link->getPrice(),
                    'rating' => $link->getRating(),
                    'clicks' => $link->getClicks(),
                    'conversions' => $link->getSoldCount(),
                    'revenue' => $link->getAffiliateRevenue(),
                    'click_through_rate' => $analytics->click_through_rate,
                    'revenue_per_click' => $analytics->revenue_per_click,
                    'conversion_rate' => $analytics->conversion_rate,
                    'status' => $link->isActive() ? 'active' : 'inactive'
                ];
            } catch (\Exception $e) {
                $comparison[$linkId] = [
                    'link_id' => $linkId,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $comparison;
    }

    /**
     * {@inheritDoc}
     */
    public function generatePerformanceReport(array $filters = [], string $format = 'array')
    {
        // Authorization check
        $this->authorize('link.report');
        
        $reportData = [
            'generated_at' => Time::now()->toDateTimeString(),
            'filters' => $filters,
            'format' => $format,
            'data' => []
        ];
        
        // Get links based on filters
        $links = $this->searchLinks($filters, 1, 1000); // Max 1000 for report
        
        foreach ($links['links'] as $linkResponse) {
            $linkId = $linkResponse->id;
            $link = $this->linkRepository->findById($linkId);
            
            if ($link === null) {
                continue;
            }
            
            $analytics = $this->getLinkAnalytics($linkId, '30d');
            
            $reportData['data'][] = [
                'link_id' => $linkId,
                'store_name' => $link->getStoreName(),
                'product_id' => $link->getProductId(),
                'marketplace_id' => $link->getMarketplaceId(),
                'url' => $link->getUrl(),
                'price' => $link->getPrice(),
                'rating' => $link->getRating(),
                'status' => $link->isActive() ? 'active' : 'inactive',
                'clicks' => $link->getClicks(),
                'conversions' => $link->getSoldCount(),
                'revenue' => $link->getAffiliateRevenue(),
                'click_through_rate' => $analytics->click_through_rate,
                'conversion_rate' => $analytics->conversion_rate,
                'revenue_per_click' => $analytics->revenue_per_click,
                'last_price_update' => $link->getLastPriceUpdate()?->format('Y-m-d H:i:s'),
                'last_validation' => $link->getLastValidation()?->format('Y-m-d H:i:s')
            ];
        }
        
        // Audit log
        $this->audit(
            'link.generate_report',
            'link',
            0,
            null,
            ['report_summary' => ['count' => count($reportData['data']), 'format' => $format]],
            [
                'admin_id' => $this->getCurrentAdminId(),
                'filters' => $filters
            ]
        );
        
        // Format conversion
        switch ($format) {
            case 'csv':
                return $this->convertReportToCsv($reportData);
            case 'json':
                return json_encode($reportData, JSON_PRETTY_PRINT);
            case 'array':
            default:
                return $reportData;
        }
    }

    // ==================== BADGE OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function assignBadge(int $linkId, int $badgeId): LinkResponse
    {
        // Authorization check
        $this->authorize('link.update');
        
        return $this->transaction(function () use ($linkId, $badgeId) {
            // Get link
            $link = $this->getEntity($this->linkRepository, $linkId);
            
            // Validate badge exists
            $badge = $this->badgeRepository->findById($badgeId);
            if ($badge === null) {
                throw NotFoundException::forEntity('MarketplaceBadge', $badgeId);
            }
            
            // Store old values
            $oldValues = $link->toArray();
            
            // Assign badge
            $link->setMarketplaceBadgeId($badgeId);
            
            // Save updates
            $updatedLink = $this->linkRepository->save($link);
            
            if ($updatedLink === null) {
                throw new DomainException(
                    'Gagal menetapkan badge',
                    'BADGE_ASSIGN_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->getLinkCacheKey($linkId));
            
            // Audit log
            $this->audit(
                'link.assign_badge',
                'link',
                $linkId,
                $oldValues,
                $updatedLink->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'badge_id' => $badgeId,
                    'badge_label' => $badge->getLabel()
                ]
            );
            
            return LinkResponse::fromEntity($updatedLink);
        }, 'assign_badge');
    }

    /**
     * {@inheritDoc}
     */
    public function removeBadge(int $linkId): LinkResponse
    {
        // Authorization check
        $this->authorize('link.update');
        
        return $this->transaction(function () use ($linkId) {
            // Get link
            $link = $this->getEntity($this->linkRepository, $linkId);
            
            // Store old values
            $oldValues = $link->toArray();
            
            // Remove badge
            $link->setMarketplaceBadgeId(null);
            
            // Save updates
            $updatedLink = $this->linkRepository->save($link);
            
            if ($updatedLink === null) {
                throw new DomainException(
                    'Gagal menghapus badge',
                    'BADGE_REMOVE_FAILED'
                );
            }
            
            // Queue cache invalidation
            $this->queueCacheOperation($this->getLinkCacheKey($linkId));
            
            // Audit log
            $this->audit(
                'link.remove_badge',
                'link',
                $linkId,
                $oldValues,
                $updatedLink->toArray(),
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'old_badge_id' => $oldValues['marketplace_badge_id']
                ]
            );
            
            return LinkResponse::fromEntity($updatedLink);
        }, 'remove_badge');
    }

    /**
     * {@inheritDoc}
     */
    public function getLinksWithBadges(array $badgeIds, bool $activeOnly = true, int $limit = 50): array
    {
        $links = $this->linkRepository->findWithBadges($limit, true);
        
        // Filter by badge IDs if provided
        if (!empty($badgeIds)) {
            $links = array_filter($links, function ($link) use ($badgeIds) {
                $badgeId = $link->getMarketplaceBadgeId();
                return $badgeId !== null && in_array($badgeId, $badgeIds);
            });
        }
        
        // Filter by active status if requested
        if ($activeOnly) {
            $links = array_filter($links, fn($link) => $link->isActive());
        }
        
        return array_map(
            fn($link) => LinkResponse::fromEntity($link),
            $links
        );
    }

    // ==================== HEALTH & MAINTENANCE OPERATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function checkLinkHealth(int $linkId): array
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        $now = Time::now();
        $lastPriceUpdate = $link->getLastPriceUpdate();
        $lastValidation = $link->getLastValidation();
        
        $needsPriceUpdate = false;
        $needsValidation = false;
        
        // Check if price update is needed (older than 24 hours)
        if ($lastPriceUpdate !== null) {
            $priceUpdateAge = $now->difference($lastPriceUpdate)->getDays() * 24 + 
                             $now->difference($lastPriceUpdate)->getHours();
            $needsPriceUpdate = $priceUpdateAge > 24;
        } else {
            $needsPriceUpdate = true;
        }
        
        // Check if validation is needed (older than 72 hours)
        if ($lastValidation !== null) {
            $validationAge = $now->difference($lastValidation)->getDays() * 24 + 
                            $now->difference($lastValidation)->getHours();
            $needsValidation = $validationAge > 72;
        } else {
            $needsValidation = true;
        }
        
        // Check URL status (simplified)
        $urlStatus = 'unknown';
        if ($link->getUrl() !== null) {
            $urlStatus = $this->checkUrlStatus($link->getUrl());
        }
        
        // Overall status
        $status = 'healthy';
        if (!$link->isActive()) {
            $status = 'inactive';
        } elseif ($needsPriceUpdate && $needsValidation) {
            $status = 'needs_attention';
        } elseif ($needsPriceUpdate) {
            $status = 'needs_price_update';
        } elseif ($needsValidation) {
            $status = 'needs_validation';
        } elseif ($urlStatus === 'invalid') {
            $status = 'url_invalid';
        }
        
        return [
            'status' => $status,
            'last_price_update' => $lastPriceUpdate?->format('Y-m-d H:i:s'),
            'last_validation' => $lastValidation?->format('Y-m-d H:i:s'),
            'needs_price_update' => $needsPriceUpdate,
            'needs_validation' => $needsValidation,
            'is_active' => $link->isActive(),
            'url_status' => $urlStatus,
            'price' => $link->getPrice(),
            'rating' => $link->getRating(),
            'clicks' => $link->getClicks(),
            'sales' => $link->getSoldCount()
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function performHealthCheckBatch(int $batchSize = 100): array
    {
        $result = [
            'checked' => 0,
            'healthy' => 0,
            'unhealthy' => 0,
            'errors' => [],
            'details' => []
        ];
        
        // Get active links for health check
        $activeLinks = $this->linkRepository->findAll(['active' => true]);
        $batch = array_slice($activeLinks, 0, $batchSize);
        
        foreach ($batch as $link) {
            try {
                $health = $this->checkLinkHealth($link->getId());
                
                $result['checked']++;
                
                if ($health['status'] === 'healthy') {
                    $result['healthy']++;
                } else {
                    $result['unhealthy']++;
                }
                
                $result['details'][] = [
                    'link_id' => $link->getId(),
                    'status' => $health['status'],
                    'needs_price_update' => $health['needs_price_update'],
                    'needs_validation' => $health['needs_validation'],
                    'url_status' => $health['url_status']
                ];
                
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'link_id' => $link->getId(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function cleanupOldLinks(int $daysInactive = 90, bool $dryRun = true): array
    {
        // Authorization check
        $this->authorize('system.maintenance');
        
        $result = [
            'cleaned' => 0,
            'archived' => 0,
            'errors' => [],
            'dry_run' => $dryRun
        ];
        
        $cutoffDate = Time::now()->subDays($daysInactive);
        
        // Find inactive links older than cutoff
        $allLinks = $this->linkRepository->findAll();
        
        foreach ($allLinks as $link) {
            try {
                // Check if link is inactive and old enough
                $lastActivity = max(
                    $link->getUpdatedAt(),
                    $link->getLastPriceUpdate(),
                    $link->getLastValidation()
                );
                
                if (!$link->isActive() && $lastActivity < $cutoffDate) {
                    if (!$dryRun) {
                        // Archive the link
                        $this->archiveLink($link->getId());
                        $result['archived']++;
                    }
                    $result['cleaned']++;
                }
                
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'link_id' => $link->getId(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        if (!$dryRun && $result['archived'] > 0) {
            // Audit log
            $this->audit(
                'system.cleanup_links',
                'system',
                0,
                null,
                ['cleanup_summary' => $result],
                [
                    'admin_id' => $this->getCurrentAdminId(),
                    'days_inactive' => $daysInactive,
                    'dry_run' => false
                ]
            );
        }
        
        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function revalidateProductLinks(int $productId): array
    {
        // Authorization check
        $this->authorize('link.revalidate');
        
        $result = [
            'validated' => 0,
            'invalid' => 0,
            'errors' => [],
            'details' => []
        ];
        
        $links = $this->linkRepository->findActiveForProduct($productId, true);
        
        foreach ($links as $link) {
            try {
                $health = $this->checkLinkHealth($link->getId());
                
                if ($health['url_status'] === 'valid') {
                    $this->markAsValidated($link->getId());
                    $result['validated']++;
                    $result['details'][] = [
                        'link_id' => $link->getId(),
                        'status' => 'validated'
                    ];
                } else {
                    $this->markAsInvalid($link->getId(), 'Failed revalidation check');
                    $result['invalid']++;
                    $result['details'][] = [
                        'link_id' => $link->getId(),
                        'status' => 'invalid',
                        'reason' => 'URL validation failed'
                    ];
                }
                
            } catch (\Exception $e) {
                $result['errors'][] = [
                    'link_id' => $link->getId(),
                    'error' => $e->getMessage()
                ];
            }
        }
        
        // Audit log
        $this->audit(
            'link.revalidate_product',
            'link',
            0,
            null,
            ['revalidation_summary' => $result],
            [
                'admin_id' => $this->getCurrentAdminId(),
                'product_id' => $productId,
                'total_links' => count($links)
            ]
        );
        
        return $result;
    }

    // ==================== BUSINESS RULE VALIDATION ====================

    /**
     * {@inheritDoc}
     */
    public function validateLinkBusinessRules(Link $link, string $context): array
    {
        $errors = [];
        
        // Context-specific validations
        switch ($context) {
            case 'create':
                // Check daily limit for link creation
                if (!$this->checkDailyLinkLimit($this->getCurrentAdminId())) {
                    $errors['limit'] = ['Daily link creation limit reached'];
                }
                break;
                
            case 'update':
                // Cannot deactivate link with recent activity
                if (!$link->isActive() && $link->getClicks() > 0) {
                    $errors['active'] = ['Cannot deactivate link with click history'];
                }
                break;
                
            case 'delete':
                // Check if link can be deleted
                $preconditions = $this->validateLinkDeletion($link->getId());
                if (!$preconditions['can_delete']) {
                    $errors['deletion'] = ['Link cannot be deleted'];
                }
                break;
                
            case 'archive':
                // Check if link can be archived
                if (!$this->canArchiveLink($link->getId())) {
                    $errors['archive'] = ['Link cannot be archived'];
                }
                break;
        }
        
        // General business rules
        if (strlen($link->getStoreName()) < 2) {
            $errors['store_name'] = ['Store name must be at least 2 characters'];
        }
        
        if (strlen($link->getStoreName()) > 100) {
            $errors['store_name'] = ['Store name must not exceed 100 characters'];
        }
        
        $price = floatval($link->getPrice());
        if ($price < 100) {
            $errors['price'] = ['Price must be at least 100'];
        }
        
        if ($price > 1000000000) {
            $errors['price'] = ['Price must not exceed 1,000,000,000'];
        }
        
        $rating = floatval($link->getRating());
        if ($rating < 0 || $rating > 5) {
            $errors['rating'] = ['Rating must be between 0 and 5'];
        }
        
        return $errors;
    }

    /**
     * {@inheritDoc}
     */
    public function canArchiveLink(int $linkId): bool
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        // Cannot archive if link is active and has recent clicks
        if ($link->isActive() && $link->getClicks() > 0) {
            return false;
        }
        
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function validateLinkDeletion(int $linkId): array
    {
        $link = $this->getEntity($this->linkRepository, $linkId);
        
        $hasSales = $link->getSoldCount() > 0;
        $hasClicks = $link->getClicks() > 0;
        
        $canDelete = !$hasSales; // Can delete if no sales (clicks are okay)
        
        return [
            'can_delete' => $canDelete,
            'has_sales' => $hasSales,
            'has_clicks' => $hasClicks,
            'sale_count' => $link->getSoldCount(),
            'click_count' => $link->getClicks(),
            'store_name' => $link->getStoreName(),
            'product_id' => $link->getProductId(),
            'marketplace_id' => $link->getMarketplaceId()
        ];
    }

    // ==================== BASE SERVICE ABSTRACT METHOD IMPLEMENTATIONS ====================

    /**
     * {@inheritDoc}
     */
    public function validateBusinessRules(BaseDTO $dto, array $context = []): array
    {
        // This method is implemented via validateLinkBusinessRules for Link-specific DTOs
        // Generic validation is handled by the DTO itself
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getServiceName(): string
    {
        return 'LinkService';
    }

    // ==================== PRIVATE HELPER METHODS ====================

    /**
     * Validate link service dependencies
     *
     * @return void
     * @throws RuntimeException
     */
    private function validateLinkDependencies(): void
    {
        if (!$this->linkRepository instanceof LinkRepositoryInterface) {
            throw new RuntimeException('Invalid link repository dependency');
        }
        
        if (!$this->productRepository instanceof ProductRepositoryInterface) {
            throw new RuntimeException('Invalid product repository dependency');
        }
        
        if (!$this->marketplaceRepository instanceof MarketplaceRepositoryInterface) {
            throw new RuntimeException('Invalid marketplace repository dependency');
        }
        
        if (!$this->badgeRepository instanceof MarketplaceBadgeRepositoryInterface) {
            throw new RuntimeException('Invalid badge repository dependency');
        }
        
        if (!$this->linkValidator instanceof LinkValidator) {
            throw new RuntimeException('Invalid link validator dependency');
        }
        
        log_message('debug', sprintf(
            '[%s] Link service dependencies validated successfully',
            $this->getServiceName()
        ));
    }

    /**
     * Process link filters for repository query
     *
     * @param array $filters
     * @return array
     */
    private function processLinkFilters(array $filters): array
    {
        $processed = $filters;
        
        // Convert status filter to active flag
        if (isset($processed['status'])) {
            if ($processed['status'] === 'active') {
                $processed['active'] = true;
            } elseif ($processed['status'] === 'inactive') {
                $processed['active'] = false;
            }
            unset($processed['status']);
        }
        
        // Convert price range filter
        if (isset($processed['min_price']) || isset($processed['max_price'])) {
            $priceConditions = [];
            if (isset($processed['min_price'])) {
                $priceConditions['price >='] = $processed['min_price'];
                unset($processed['min_price']);
            }
            if (isset($processed['max_price'])) {
                $priceConditions['price <='] = $processed['max_price'];
                unset($processed['max_price']);
            }
            $processed = array_merge($processed, $priceConditions);
        }
        
        return $processed;
    }

    /**
     * Check daily link creation limit for admin
     *
     * @param int|null $adminId
     * @return bool
     */
    private function checkDailyLinkLimit(?int $adminId): bool
    {
        if ($adminId === null) {
            return true; // System operations have no limit
        }
        
        // Get today's link creation count for this admin
        $today = Time::now()->format('Y-m-d');
        $cacheKey = $this->getServiceCacheKey('daily_limit', ['admin_id' => $adminId, 'date' => $today]);
        
        $count = $this->withCaching($cacheKey, function () use ($adminId, $today) {
            // Query audit log for link.create actions today
            // Placeholder - returns random number for demonstration
            return rand(0, 20);
        }, 300); // Cache for 5 minutes
        
        // Limit: 50 links per day per admin
        return $count < 50;
    }

    /**
     * Check URL status (simplified)
     *
     * @param string $url
     * @return string
     */
    private function checkUrlStatus(string $url): string
    {
        // Simplified URL check
        // In production, this would make an HTTP request to check the URL
        
        if (empty($url)) {
            return 'empty';
        }
        
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return 'invalid_format';
        }
        
        // Check if URL contains marketplace domains
        $marketplaceDomains = [
            'tokopedia.com',
            'shopee.co.id',
            'bukalapak.com',
            'blibli.com',
            'lazada.co.id'
        ];
        
        $urlDomain = parse_url($url, PHP_URL_HOST);
        $isMarketplaceUrl = false;
        
        foreach ($marketplaceDomains as $domain) {
            if (strpos($urlDomain, $domain) !== false) {
                $isMarketplaceUrl = true;
                break;
            }
        }
        
        return $isMarketplaceUrl ? 'valid' : 'unknown_domain';
    }

    /**
     * Convert report data to CSV format
     *
     * @param array $reportData
     * @return string
     */
    private function convertReportToCsv(array $reportData): string
    {
        if (empty($reportData['data'])) {
            return '';
        }
        
        $headers = array_keys($reportData['data'][0]);
        $csv = implode(',', $headers) . "\n";
        
        foreach ($reportData['data'] as $row) {
            $csv .= implode(',', array_map(function ($value) {
                // Escape commas and quotes
                $value = str_replace('"', '""', $value);
                if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
                    $value = '"' . $value . '"';
                }
                return $value;
            }, $row)) . "\n";
        }
        
        return $csv;
    }

    /**
     * Generate cache key for link
     *
     * @param int $linkId
     * @return string
     */
    private function getLinkCacheKey(int $linkId): string
    {
        return sprintf('link:%d:v3', $linkId);
    }

    /**
     * Generate cache key for product links
     *
     * @param int $productId
     * @param bool $activeOnly
     * @return string
     */
    private function getProductLinksCacheKey(int $productId, bool $activeOnly = true): string
    {
        return sprintf('product:%d:links:%s:v3', $productId, $activeOnly ? 'active' : 'all');
    }

    /**
     * Generate cache key for marketplace links
     *
     * @param int $marketplaceId
     * @param bool $activeOnly
     * @return string
     */
    private function getMarketplaceLinksCacheKey(int $marketplaceId, bool $activeOnly = true): string
    {
        return sprintf('marketplace:%d:links:%s:v3', $marketplaceId, $activeOnly ? 'active' : 'all');
    }

    /**
     * Generate cache key for product
     *
     * @param int $productId
     * @return string
     */
    private function getProductCacheKey(int $productId): string
    {
        return sprintf('product:%d:v3', $productId);
    }
}