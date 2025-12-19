<?php

namespace App\Services;

use App\DTOs\Requests\Link\BulkLinkUpdateRequest;
use App\DTOs\Requests\Link\CreateLinkRequest;
use App\DTOs\Requests\Link\UpdateLinkRequest;
use App\DTOs\Responses\LinkAnalyticsResponse;
use App\DTOs\Responses\LinkResponse;
use App\Entities\Link;
use App\Entities\Marketplace;
use App\Entities\Product;
use App\Exceptions\DomainException;
use App\Exceptions\LinkNotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AdminModel;
use App\Models\LinkModel;
use App\Models\MarketplaceBadgeModel;
use App\Models\MarketplaceModel;
use App\Models\ProductModel;
use CodeIgniter\Database\ConnectionInterface;
use DateTimeImmutable;
use Exception;
use RuntimeException;

/*
 * Enterprise-grade Affiliate Link Service

 * Manages affiliate links with price monitoring, performance tracking,
 * automated validation, and marketplace integration.
 */
class LinkService
{
    private LinkModel $linkModel;
    private ProductModel $productModel;
    private MarketplaceModel $marketplaceModel;
    private MarketplaceBadgeModel $marketplaceBadgeModel;
    private ValidationService $validationService;
    private AuditService $auditService;
    private CacheService $cacheService;
    private ConnectionInterface $db;


    // Configuration
    private array $config;

    // Cache constants
    private const CACHE_TTL = 3600;
    private const CACHE_PREFIX = 'link_service_';
    private const ANALYTICS_CACHE_TTL = 1800;

    // Business rules
    private const MAX_LINKS_PER_PRODUCT = 10;
    private const MIN_PRICE_UPDATE_INTERVAL = 3600; // 1 hour
    private const MIN_VALIDATION_INTERVAL = 7200; // 2 hours
    private const MAX_URL_LENGTH = 500;
    private const MIN_CLICK_THRESHOLD = 10;

    // Validation constants
    public const PRICE_CHANGE_THRESHOLD = 0.1; // 10% price change triggers notification
    public const INACTIVITY_THRESHOLD = 30; // 30 days

    // Event types
    public const EVENT_LINK_CREATED = 'link.created';
    public const EVENT_LINK_UPDATED = 'link.updated';
    public const EVENT_LINK_PRICE_CHANGED = 'link.price_changed';
    public const EVENT_LINK_CLICKED = 'link.clicked';
    public const EVENT_LINK_VALIDATED = 'link.validated';
    public const EVENT_LINK_DEACTIVATED = 'link.deactivated';
    public const EVENT_AFFILIATE_REVENUE = 'link.revenue_generated';

    public function __construct(
        LinkModel $linkModel,
        ProductModel $productModel,
        MarketplaceModel $marketplaceModel,
        MarketplaceBadgeModel $marketplaceBadgeModel,
        ValidationService $validationService,
        AuditService $auditService,
        CacheService $cacheService,
        ConnectionInterface $db,
        array $config = []
    ) {
        $this->linkModel = $linkModel;
        $this->productModel = $productModel;
        $this->marketplaceModel = $marketplaceModel;
        $this->marketplaceBadgeModel = $marketplaceBadgeModel;
        $this->validationService = $validationService;
        $this->auditService = $auditService;
        $this->cacheService = $cacheService;
        $this->db = $db;
        $this->config = array_merge($this->getDefaultConfig(), $config);

        // Set cache TTL from config
        $this->cacheService->setDefaultTtl($this->config['cache_ttl'] ?? self::CACHE_TTL);
    }



    /**
     * Create a new affiliate link with comprehensive validation
     */
    public function create(CreateLinkRequest $request, int $adminId): Link
    {
        // 1. Validate business rules
        $validationErrors = $this->validationService->validateLinkOperation(
            $request->toArray(),
            ValidationService::CONTEXT_CREATE
        );

        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'LINK_CREATE_VALIDATION',
                'Link creation validation failed',
                ['errors' => $validationErrors]
            );
        }

        // 2. Validate admin permissions
        $adminValidation = $this->validationService->validateAdminPermission(
            $adminId,
            ValidationService::CONTEXT_CREATE,
            'link'
        );

        if (!empty($adminValidation)) {
            throw ValidationException::forBusinessRule(
                'ADMIN_PERMISSION_DENIED',
                'Admin does not have permission to create links',
                ['errors' => $adminValidation]
            );
        }

        // 3. Check product exists and is active
        $product = $this->productModel->find($request->productId);
        if (!$product || !$product->isPublished()) {
            throw new DomainException(
                'INVALID_PRODUCT',
                'Product not found or not published',
                ['product_id' => $request->productId]
            );
        }

        // 4. Check marketplace exists and is active
        $marketplace = $this->marketplaceModel->find($request->marketplaceId);
        if (!$marketplace || !$marketplace->isActive()) {
            throw new DomainException(
                'INVALID_MARKETPLACE',
                'Marketplace not found or inactive',
                ['marketplace_id' => $request->marketplaceId]
            );
        }

        // 5. Check for duplicate links (same product + marketplace)
        $existingLinks = $this->linkModel->findByProduct($request->productId);
        foreach ($existingLinks as $existingLink) {
            if ($existingLink->getMarketplaceId() === $request->marketplaceId) {
                throw new DomainException(
                    'DUPLICATE_LINK',
                    'Link already exists for this product and marketplace',
                    [
                        'product_id' => $request->productId,
                        'marketplace_id' => $request->marketplaceId
                    ]
                );
            }
        }

        // 6. Check max links per product
        if (count($existingLinks) >= self::MAX_LINKS_PER_PRODUCT) {
            throw new DomainException(
                'MAX_LINKS_EXCEEDED',
                sprintf('Maximum %s links per product reached', self::MAX_LINKS_PER_PRODUCT),
                [
                    'product_id' => $request->productId,
                    'current_links' => count($existingLinks),
                    'max_links' => self::MAX_LINKS_PER_PRODUCT
                ]
            );
        }

        // 7. Validate URL format and accessibility (if provided)
        if ($request->url && !$this->validateUrl($request->url)) {
            throw new DomainException(
                'INVALID_URL',
                'URL is invalid or inaccessible',
                ['url' => $request->url]
            );
        }


        // 8. Create link entity
        $linkData = $request->toArray();
        $link = Link::fromArray($linkData);

        // Hitung revenue dengan commission rate
        $rateDecimal = $request->commission_rate !== null
                       ? ($request->commission_rate / 100)
                       : Link::DEFAULT_COMMISSION_RATE;

        $revenueRupiah = $link->calculateRevenue($rateDecimal);
        $link->setAffiliateRevenue($revenueRupiah);

        // Set additional fields
        $link->setActive(true);
        $link->setLastValidation(new DateTimeImmutable());
        $link->initializeTimestamps();

        // Calculate commission if not provided
        if (empty($linkData['affiliate_revenue'])) {
            $commission = Link::DEFAULT_COMMISSION_RATE;
            $link->setAffiliateRevenue($commission);
        }

        // 9. Save with transaction
        $this->db->transStart();

        try {
            $savedLink = $this->linkModel->save($link);

            // 10. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);
            $this->auditService->logCreate(
                AuditService::ENTITY_LINK,
                $savedLink->getId(),
                $savedLink,
                $admin,
                sprintf(
                    'Link created for product %s on marketplace %s',
                    $product->getName(),
                    $marketplace->getName()
                )
            );

            // 11. Update product's last link check timestamp
            $product->markLinksChecked();
            $this->productModel->save($product);

            $this->db->transComplete();

            // 12. Clear relevant caches
            $this->clearLinkCaches($savedLink->getId(), $request->productId);
            $this->clearProductLinkCaches($request->productId);

            // 13. Publish event
            $this->publishEvent(self::EVENT_LINK_CREATED, [
                'link_id' => $savedLink->getId(),
                'product_id' => $request->productId,
                'marketplace_id' => $request->marketplaceId,
                'admin_id' => $adminId,
                'price' => $savedLink->getPrice(),
                'url' => $savedLink->getUrl(),
                'timestamp' => new DateTimeImmutable()
            ]);

            return $savedLink;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Link creation failed', [
                'admin_id' => $adminId,
                'request_data' => $request->toArray(),
                'error' => $e->getMessage()
            ]);

            throw new DomainException(
                'LINK_CREATION_FAILED',
                'Failed to create link: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }

    /**
     * Update existing link with change tracking
     */
    public function update(UpdateLinkRequest $request, int $adminId): Link
    {
        $linkId = $request->id;

        // 1. Get existing link
        $existingLink = $this->linkModel->find($linkId);

        if (!$existingLink) {
            throw LinkNotFoundException::forId($linkId);
        }

        // 2. Validate update
        $validationErrors = $this->validationService->validateLinkOperation(
            array_merge($request->toArray(), [
                'product_id' => $existingLink->getProductId(),
                'marketplace_id' => $existingLink->getMarketplaceId()
            ]),
            ValidationService::CONTEXT_UPDATE
        );

        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'LINK_UPDATE_VALIDATION',
                'Link update validation failed',
                ['errors' => $validationErrors]
            );
        }

        // 3. Check if price is being updated
        $priceChanged = false;
        $oldPrice = (float) $existingLink->getPrice();
        $newPrice = null;

        if ($request->price !== null) {
            $newPrice = (float) $request->price;
            $priceChanged = abs($oldPrice - $newPrice) > 0.01; // Account for floating point precision
        }

        // 4. Apply changes
        $updateData = $request->toArray();
        $updatedLink = clone $existingLink;

        foreach ($updateData as $field => $value) {
            if ($value !== null) {
                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($updatedLink, $setter)) {
                    $updatedLink->$setter($value);
                }
            }
        }

        // Update timestamps based on changes
        if ($priceChanged) {
            $updatedLink->setLastPriceUpdate(new DateTimeImmutable());

            // Update commission based on new price
            $marketplace = $this->marketplaceModel->find($updatedLink->getMarketplaceId());
            if ($marketplace) {
                $commission = $this->calculateCommission(
                    $updatedLink->getPrice(),
                    $existingLink->getImpliedCommissionRate()
                );
                $updatedLink->setAffiliateRevenue($commission);
            }
        }

        $updatedLink->markAsUpdated();

        // 5. Save with transaction
        $this->db->transStart();

        try {
            $savedLink = $this->linkModel->save($updatedLink);

            // 6. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);

            $changeSummary = [];
            if ($priceChanged) {
                $changeSummary[] = sprintf(
                    'Price: %s → %s',
                    number_format($oldPrice, 2),
                    number_format($newPrice, 2)
                );
            }
            if ($request->active !== null && $request->active !== $existingLink->isActive()) {
                $changeSummary[] = sprintf(
                    'Status: %s → %s',
                    $existingLink->isActive() ? 'Active' : 'Inactive',
                    $request->active ? 'Active' : 'Inactive'
                );
            }

            $this->auditService->logUpdate(
                AuditService::ENTITY_LINK,
                $savedLink->getId(),
                $existingLink,
                $savedLink,
                $admin,
                !empty($changeSummary) ? implode(', ', $changeSummary) : 'Link updated'
            );

            $this->db->transComplete();

            // 7. Clear caches
            $this->clearLinkCaches($savedLink->getId(), $savedLink->getProductId());
            $this->clearProductLinkCaches($savedLink->getProductId());

            // 8. Publish events
            $this->publishEvent(self::EVENT_LINK_UPDATED, [
                'link_id' => $savedLink->getId(),
                'product_id' => $savedLink->getProductId(),
                'admin_id' => $adminId,
                'changes' => $changeSummary,
                'timestamp' => new DateTimeImmutable()
            ]);

            // Jika ada commission_rate, hitung ulang revenue
            if ($request->commission_rate !== null) {
                $rateDecimal = $request->commission_rate / 100;
                $newRevenue = $updatedLink->calculateRevenue($rateDecimal);
                $updatedLink->setAffiliateRevenue($newRevenue);
            }
            // Jika harga berubah tapi commission_rate tidak diisi
            elseif ($request->price !== null) {
                // Pertahankan rate lama
                $currentRate = Link::DEFAULT_COMMISSION_RATE;

                $newRevenue = $updatedLink->calculateRevenue($currentRate);
                $updatedLink->setAffiliateRevenue($newRevenue);
            }
            if ($priceChanged) {
                $priceChangePercent = $oldPrice > 0 ?
                    (($newPrice - $oldPrice) / $oldPrice) * 100 : 0;

                $this->publishEvent(self::EVENT_LINK_PRICE_CHANGED, [
                    'link_id' => $savedLink->getId(),
                    'product_id' => $savedLink->getProductId(),
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                    'change_percent' => $priceChangePercent,
                    'change_amount' => $newPrice - $oldPrice,
                    'admin_id' => $adminId,
                    'timestamp' => new DateTimeImmutable()
                ]);
            }

            return $savedLink;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Link update failed', [
                'link_id' => $linkId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            throw new DomainException(
                'LINK_UPDATE_FAILED',
                'Failed to update link: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }

    /**
     * Delete link (soft or hard delete)
     */
    public function delete(int $linkId, int $adminId, bool $force = false): bool
    {
        // 1. Get existing link
        $existingLink = $this->linkModel->find($linkId, true); // Include trashed

        if (!$existingLink) {
            throw LinkNotFoundException::forId($linkId);
        }

        // 2. Check if already deleted
        if ($existingLink->isDeleted() && !$force) {
            throw new DomainException(
                'LINK_ALREADY_DELETED',
                'Link is already deleted',
                ['link_id' => $linkId]
            );
        }

        // 3. Check if link has revenue history (prevent accidental deletion)
        if (!$force && (float) $existingLink->getAffiliateRevenue() > 0) {
            throw new DomainException(
                'LINK_HAS_REVENUE',
                'Link has generated revenue. Consider deactivating instead.',
                [
                    'link_id' => $linkId,
                    'revenue' => $existingLink->getAffiliateRevenue()
                ]
            );
        }

        // 4. Perform deletion with transaction
        $this->db->transStart();

        try {
            $result = $force ?
                $this->linkModel->delete($linkId, true) : // Hard delete
                $this->linkModel->delete($linkId); // Soft delete

            if (!$result) {
                throw new RuntimeException('Link deletion failed');
            }

            // 5. Log audit trail
            $admin = $this->getAdminModel()->find($adminId);

            $this->auditService->logDelete(
                AuditService::ENTITY_LINK,
                $linkId,
                $existingLink,
                $admin,
                !$force,
                'Link ' . ($force ? 'permanently deleted' : 'soft deleted')
            );

            $this->db->transComplete();

            // 6. Clear caches
            $this->clearLinkCaches($linkId, $existingLink->getProductId());
            $this->clearProductLinkCaches($existingLink->getProductId());

            // 7. Publish event
            $this->publishEvent('link.deleted', [
                'link_id' => $linkId,
                'product_id' => $existingLink->getProductId(),
                'admin_id' => $adminId,
                'force_delete' => $force,
                'had_revenue' => (float) $existingLink->getAffiliateRevenue() > 0,
                'timestamp' => new DateTimeImmutable()
            ]);

            return true;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Link deletion failed', [
                'link_id' => $linkId,
                'admin_id' => $adminId,
                'force' => $force,
                'error' => $e->getMessage()
            ]);

            throw new DomainException(
                'LINK_DELETE_FAILED',
                'Failed to delete link: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }

    /**
     * Activate link
     */
    public function activate(int $linkId, int $adminId): Link
    {
        return $this->setActiveStatus($linkId, true, $adminId);
    }

    /**
     * Deactivate link
     */
    public function deactivate(int $linkId, int $adminId, ?string $reason = null): Link
    {
        return $this->setActiveStatus($linkId, false, $adminId, $reason);
    }

    /**
     * Record link click (for analytics)
     */
    public function recordClick(int $linkId, ?string $ipAddress = null, ?string $userAgent = null): bool
    {
        $link = $this->linkModel->find($linkId);

        if (!$link || !$link->isActive()) {
            return false;
        }

        $this->db->transStart();

        try {
            // Increment click count
            $link->incrementClicks();
            $this->linkModel->save($link);

            // Update product view count
            $product = $this->productModel->find($link->getProductId());
            if ($product) {
                $product->incrementViewCount();
                $this->productModel->save($product);
            }

            $this->db->transComplete();

            // Clear caches
            $this->clearLinkCaches($linkId, $link->getProductId());
            $this->clearProductLinkCaches($link->getProductId());

            // Publish click event
            $this->publishEvent(self::EVENT_LINK_CLICKED, [
                'link_id' => $linkId,
                'product_id' => $link->getProductId(),
                'marketplace_id' => $link->getMarketplaceId(),
                'price' => $link->getPrice(),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'timestamp' => new DateTimeImmutable()
            ]);

            return true;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Failed to record click', [
                'link_id' => $linkId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Record affiliate sale (conversion)
     */
    public function recordSale(int $linkId, float $saleAmount, int $quantity = 1, array $metadata = []): bool
    {
        $link = $this->linkModel->find($linkId);

        if (!$link || !$link->isActive()) {
            return false;
        }

        $this->db->transStart();

        try {
            // Increment sold count
            $link->incrementSoldCount($quantity);

            $commissionRate = Link::DEFAULT_COMMISSION_RATE;

            $commission = $saleAmount * $commissionRate * $quantity;

            $link->addAffiliateRevenue(number_format($commission, 2, '.', ''));

            $this->linkModel->save($link);

            $this->db->transComplete();

            // Clear caches
            $this->clearLinkCaches($linkId, $link->getProductId());

            // Publish revenue event
            $this->publishEvent(self::EVENT_AFFILIATE_REVENUE, [
                'link_id' => $linkId,
                'product_id' => $link->getProductId(),
                'marketplace_id' => $link->getMarketplaceId(),
                'sale_amount' => $saleAmount,
                'quantity' => $quantity,
                'commission' => $commission,
                'metadata' => $metadata,
                'timestamp' => new DateTimeImmutable()
            ]);

            return true;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Failed to record sale', [
                'link_id' => $linkId,
                'sale_amount' => $saleAmount,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Update link price with validation
     */
    public function updatePrice(int $linkId, string $newPrice, bool $autoUpdate = true, ?int $adminId = null): Link
    {
        $link = $this->linkModel->find($linkId);

        if (!$link) {
            throw LinkNotFoundException::forId($linkId);
        }

        $oldPrice = (float) $link->getPrice();
        $newPriceFloat = (float) $newPrice;

        // Validate price change
        if ($oldPrice > 0) {
            $priceChangePercent = abs(($newPriceFloat - $oldPrice) / $oldPrice) * 100;

            if ($priceChangePercent > ($this->config['max_price_change_percent'] ?? 100)) {
                throw new DomainException(
                    'PRICE_CHANGE_TOO_LARGE',
                    sprintf('Price change exceeds maximum allowed percentage'),
                    [
                        'old_price' => $oldPrice,
                        'new_price' => $newPriceFloat,
                        'change_percent' => $priceChangePercent,
                        'max_allowed_percent' => $this->config['max_price_change_percent'] ?? 100
                    ]
                );
            }
        }

        $this->db->transStart();

        try {
            $updatedLink = clone $link;
            $updatedLink->updatePrice($newPrice, $autoUpdate);

            // Update commission based on new price
            $marketplace = $this->marketplaceModel->find($updatedLink->getMarketplaceId());
            if ($marketplace) {
                $commission = $this->calculateCommission(
                    $updatedLink->getPrice(),
                    $marketplace->getCommissionRate()
                );
                $updatedLink->setAffiliateRevenue($commission);
            }

            $savedLink = $this->linkModel->save($updatedLink);

            // Update product's last price check timestamp
            $product = $this->productModel->find($savedLink->getProductId());
            if ($product) {
                $product->markPriceChecked();
                $this->productModel->save($product);
            }

            // Log audit if admin initiated
            if ($adminId) {
                $admin = $this->getAdminModel()->find($adminId);
                $this->auditService->logUpdate(
                    AuditService::ENTITY_LINK,
                    $linkId,
                    $link,
                    $savedLink,
                    $admin,
                    sprintf('Price updated: %s → %s', $oldPrice, $newPriceFloat)
                );
            }

            $this->db->transComplete();

            // Clear caches
            $this->clearLinkCaches($linkId, $savedLink->getProductId());
            $this->clearProductLinkCaches($savedLink->getProductId());

            // Publish price change event
            $priceChangePercent = $oldPrice > 0 ?
                (($newPriceFloat - $oldPrice) / $oldPrice) * 100 : 0;

            $this->publishEvent(self::EVENT_LINK_PRICE_CHANGED, [
                'link_id' => $linkId,
                'product_id' => $savedLink->getProductId(),
                'old_price' => $oldPrice,
                'new_price' => $newPriceFloat,
                'change_percent' => $priceChangePercent,
                'change_amount' => $newPriceFloat - $oldPrice,
                'auto_update' => $autoUpdate,
                'admin_id' => $adminId,
                'timestamp' => new DateTimeImmutable()
            ]);

            return $savedLink;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Price update failed', [
                'link_id' => $linkId,
                'new_price' => $newPrice,
                'error' => $e->getMessage()
            ]);

            throw new DomainException(
                'PRICE_UPDATE_FAILED',
                'Failed to update price: ' . $e->getMessage(),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }

    /**
     * Validate link (check if still active and accessible)
     */
    public function validate(int $linkId, bool $force = false): array
    {
        $link = $this->linkModel->find($linkId);

        if (!$link) {
            throw LinkNotFoundException::forId($linkId);
        }

        // Check if validation is needed
        if (!$force && !$link->needsValidation()) {
            return [
                'link_id' => $linkId,
                'validated' => false,
                'reason' => 'Validation not needed yet',
                'last_validation' => $link->getLastValidation()?->format('c'),
                'next_validation_due' => $link->getLastValidation() ?
                    $link->getLastValidation()->modify('+48 hours')->format('c') : null
            ];
        }

        $validationResult = $this->performLinkValidation($link);

        $this->db->transStart();

        try {
            $updatedLink = clone $link;

            if ($validationResult['is_valid']) {
                $updatedLink->markAsValidated();
            } else {
                $updatedLink->markAsInvalid();

                // Auto-deactivate if configured
                if ($this->config['auto_deactivate_invalid_links'] && $updatedLink->isActive()) {
                    $updatedLink->setActive(false);
                    $validationResult['auto_deactivated'] = true;
                }
            }

            $updatedLink->setLastValidation(new DateTimeImmutable());
            $this->linkModel->save($updatedLink);

            // Update product's last link check timestamp
            $product = $this->productModel->find($updatedLink->getProductId());
            if ($product) {
                $product->markLinksChecked();
                $this->productModel->save($product);
            }

            $this->db->transComplete();

            // Clear caches
            $this->clearLinkCaches($linkId, $link->getProductId());

            // Publish validation event
            $this->publishEvent(self::EVENT_LINK_VALIDATED, [
                'link_id' => $linkId,
                'product_id' => $link->getProductId(),
                'is_valid' => $validationResult['is_valid'],
                'validation_details' => $validationResult,
                'timestamp' => new DateTimeImmutable()
            ]);

            return array_merge($validationResult, [
                'link_id' => $linkId,
                'validated' => true,
                'validation_timestamp' => (new DateTimeImmutable())->format('c')
            ]);

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Link validation failed', [
                'link_id' => $linkId,
                'error' => $e->getMessage()
            ]);

            return [
                'link_id' => $linkId,
                'validated' => false,
                'error' => $e->getMessage(),
                'is_valid' => false,
                'status_code' => 0
            ];
        }
    }

    /**
     * Bulk update links
     */
    public function bulkUpdate(BulkLinkUpdateRequest $request, int $adminId): array
    {
        $linkIds = $request->linkIds;

        // Validate bulk operation
        $validationErrors = $this->validationService->validateBulkOperation(
            $linkIds,
            'link',
            ValidationService::CONTEXT_UPDATE,
            $adminId
        );

        if (!empty($validationErrors)) {
            throw ValidationException::forBusinessRule(
                'BULK_LINK_UPDATE_VALIDATION',
                'Bulk link update validation failed',
                ['errors' => $validationErrors]
            );
        }

        $results = [
            'successful' => [],
            'failed' => [],
            'total' => count($linkIds)
        ];

        $this->db->transStart();

        try {
            $admin = $this->getAdminModel()->find($adminId);

            foreach ($linkIds as $linkId) {
                try {
                    $existingLink = $this->linkModel->find($linkId);

                    if (!$existingLink) {
                        $results['failed'][] = [
                            'link_id' => $linkId,
                            'error' => 'Link not found'
                        ];
                        continue;
                    }

                    // Validate individual update
                    $linkValidation = $this->validationService->validateLinkOperation(
                        array_merge($request->updateData, [
                            'product_id' => $existingLink->getProductId(),
                            'marketplace_id' => $existingLink->getMarketplaceId()
                        ]),
                        ValidationService::CONTEXT_UPDATE
                    );

                    if (!empty($linkValidation)) {
                        $results['failed'][] = [
                            'link_id' => $linkId,
                            'error' => 'Validation failed',
                            'details' => $linkValidation
                        ];
                        continue;
                    }

                    // Apply update
                    $updatedLink = clone $existingLink;

                    foreach ($request->updateData as $field => $value) {
                        if ($value !== null) {
                            $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                            if (method_exists($updatedLink, $setter)) {
                                $updatedLink->$setter($value);
                            }
                        }
                    }

                    $updatedLink->markAsUpdated();

                    // Save
                    $savedLink = $this->linkModel->save($updatedLink);

                    // Log audit
                    $this->auditService->logUpdate(
                        AuditService::ENTITY_LINK,
                        $linkId,
                        $existingLink,
                        $savedLink,
                        $admin,
                        'Bulk update: ' . implode(', ', array_keys($request->updateData))
                    );

                    $results['successful'][] = [
                        'link_id' => $linkId,
                        'changes' => array_keys($request->updateData)
                    ];

                } catch (Exception $e) {
                    $results['failed'][] = [
                        'link_id' => $linkId,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $this->db->transComplete();

            // Clear caches for all affected links
            foreach ($linkIds as $linkId) {
                try {
                    $link = $this->linkModel->find($linkId);
                    if ($link) {
                        $this->clearLinkCaches($linkId, $link->getProductId());
                        $this->clearProductLinkCaches($link->getProductId());
                    }
                } catch (Exception $e) {
                    // Continue clearing other caches
                }
            }

            // Publish bulk event
            $this->publishEvent('link.bulk_updated', [
                'link_ids' => $linkIds,
                'admin_id' => $adminId,
                'update_data' => $request->updateData,
                'results' => $results,
                'timestamp' => new DateTimeImmutable()
            ]);

            return $results;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError('Bulk link update failed', [
                'link_ids' => $linkIds,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            throw new DomainException(
                'BULK_LINK_UPDATE_FAILED',
                'Bulk link update failed: ' . $e->getMessage(),
                ['results' => $results],
                500,
                $e
            );
        }
    }

    /**
     * Get link analytics
     */
    public function getAnalytics(int $linkId, string $period = '30d'): LinkAnalyticsResponse
    {
        $cacheKey = $this->getCacheKey(sprintf('analytics_%s_%s', $linkId, $period));

        return $this->cacheService->remember($cacheKey, function () use ($linkId, $period) {
            $link = $this->linkModel->find($linkId);

            if (!$link) {
                throw LinkNotFoundException::forId($linkId);
            }

            // Get click stats for period
            $clickStats = $this->linkModel->getClickStats($period, null, $linkId);

            // Calculate metrics
            $clicks = $link->getClicks();
            $sold = $link->getSoldCount();
            $revenue = (float) $link->getAffiliateRevenue();

            $conversionRate = $clicks > 0 ? ($sold / $clicks) * 100 : 0;
            $revenuePerClick = $clicks > 0 ? $revenue / $clicks : 0;
            $averageOrderValue = $sold > 0 ? $revenue / $sold : 0;

            // Get price history (simplified - would query price_history table in real implementation)
            $priceHistory = $this->getPriceHistory($linkId, $period);

            // Get comparison to marketplace average
            $marketplaceComparison = $this->getMarketplaceComparison(
                $link->getMarketplaceId(),
                $link->getPrice(),
                $link->getRating()
            );

            return LinkAnalyticsResponse::create([
                'link_id' => $linkId,
                'period' => $period,
                'clicks' => $clicks,
                'conversions' => $sold,
                'revenue' => $revenue,
                'conversion_rate' => round($conversionRate, 2),
                'revenue_per_click' => round($revenuePerClick, 2),
                'average_order_value' => round($averageOrderValue, 2),
                'click_through_rate' => $this->calculateClickThroughRate($linkId),
                'price_history' => $priceHistory,
                'marketplace_comparison' => $marketplaceComparison,
                'performance_trend' => $this->calculatePerformanceTrend($linkId, $period),
                'generated_at' => (new DateTimeImmutable())->format('c')
            ]);

        }, $this->config['cache_ttl_analytics'] ?? self::ANALYTICS_CACHE_TTL);
    }

    /**
     * Get links needing price update
     */
    public function getLinksNeedingPriceUpdate(int $limit = 50): array
    {
        $cacheKey = $this->getCacheKey(sprintf('needs_price_update_%s', $limit));

        return $this->cacheService->remember($cacheKey, function () use ($limit) {
            $links = $this->linkModel->findExpired('price', $limit);

            $results = [];
            foreach ($links as $link) {
                $results[] = [
                    'link' => LinkResponse::fromEntity($link)->toArray(),
                    'last_price_update' => $link->getLastPriceUpdate()?->format('c'),
                    'hours_since_update' => $link->getLastPriceUpdate() ?
                        round((time() - $link->getLastPriceUpdate()->getTimestamp()) / 3600, 1) : null,
                    'product_name' => $this->getProductName($link->getProductId()),
                    'marketplace_name' => $this->getMarketplaceName($link->getMarketplaceId())
                ];
            }

            return [
                'data' => $results,
                'count' => count($results),
                'limit' => $limit,
                'threshold_hours' => self::MIN_PRICE_UPDATE_INTERVAL / 3600
            ];

        }, 300); // 5 minute cache
    }

    /**
     * Get links needing validation
     */
    public function getLinksNeedingValidation(int $limit = 50): array
    {
        $cacheKey = $this->getCacheKey(sprintf('needs_validation_%s', $limit));

        return $this->cacheService->remember($cacheKey, function () use ($limit) {
            $links = $this->linkModel->findExpired('validation', $limit);

            $results = [];
            foreach ($links as $link) {
                $results[] = [
                    'link' => LinkResponse::fromEntity($link)->toArray(),
                    'last_validation' => $link->getLastValidation()?->format('c'),
                    'days_since_validation' => $link->getLastValidation() ?
                        round((time() - $link->getLastValidation()->getTimestamp()) / 86400, 1) : null,
                    'product_name' => $this->getProductName($link->getProductId()),
                    'marketplace_name' => $this->getMarketplaceName($link->getMarketplaceId())
                ];
            }

            return [
                'data' => $results,
                'count' => count($results),
                'limit' => $limit,
                'threshold_days' => self::MIN_VALIDATION_INTERVAL / 86400
            ];

        }, 300); // 5 minute cache
    }

    /**
     * Get top performing links
     */
    public function getTopPerformers(string $by = 'revenue', int $limit = 10, string $period = '30d'): array
    {
        $cacheKey = $this->getCacheKey(sprintf('top_performers_%s_%s_%s', $by, $limit, $period));

        return $this->cacheService->remember($cacheKey, function () use ($by, $limit, $period) {
            $links = $this->linkModel->getTopPerformers($by, $limit);

            $results = [];
            foreach ($links as $link) {
                $linkData = LinkResponse::fromEntity($link)->toArray();

                // Add performance metrics
                $metrics = [];
                switch ($by) {
                    case 'revenue':
                        $metrics['value'] = (float) $link->getAffiliateRevenue();
                        $metrics['unit'] = 'currency';
                        break;
                    case 'clicks':
                        $metrics['value'] = $link->getClicks();
                        $metrics['unit'] = 'clicks';
                        break;
                    case 'conversions':
                        $metrics['value'] = $link->getSoldCount();
                        $metrics['unit'] = 'sales';
                        break;
                    case 'ctr':
                        $ctr = $this->calculateClickThroughRate($link->getId());
                        $metrics['value'] = $ctr;
                        $metrics['unit'] = 'percentage';
                        break;
                }

                $results[] = array_merge($linkData, [
                    'performance_metric' => $metrics,
                    'product_name' => $this->getProductName($link->getProductId()),
                    'marketplace_name' => $this->getMarketplaceName($link->getMarketplaceId()),
                    'ranking_by' => $by
                ]);
            }

            return [
                'data' => $results,
                'ranking_by' => $by,
                'period' => $period,
                'limit' => $limit,
                'generated_at' => (new DateTimeImmutable())->format('c')
            ];

        }, 600); // 10 minute cache
    }

    /**
     * Get link statistics for a product
     */
    public function getProductLinkStats(int $productId): array
    {
        $cacheKey = $this->getCacheKey(sprintf('product_stats_%s', $productId));

        return $this->cacheService->remember($cacheKey, function () use ($productId) {
            $links = $this->linkModel->findByProduct($productId, null, 100);

            $activeLinks = array_filter($links, fn ($link) => $link->isActive());
            $inactiveLinks = array_filter($links, fn ($link) => !$link->isActive());

            $totalClicks = array_sum(array_map(fn ($link) => $link->getClicks(), $links));
            $totalSales = array_sum(array_map(fn ($link) => $link->getSoldCount(), $links));
            $totalRevenue = array_sum(array_map(
                fn ($link) => (float) $link->getAffiliateRevenue(),
                $links
            ));

            $priceRange = $this->calculatePriceRange($activeLinks);
            $averageRating = $this->calculateAverageRating($activeLinks);

            $marketplaceDistribution = [];
            foreach ($activeLinks as $link) {
                $marketplaceId = $link->getMarketplaceId();
                if (!isset($marketplaceDistribution[$marketplaceId])) {
                    $marketplaceDistribution[$marketplaceId] = [
                        'count' => 0,
                        'clicks' => 0,
                        'revenue' => 0
                    ];
                }
                $marketplaceDistribution[$marketplaceId]['count']++;
                $marketplaceDistribution[$marketplaceId]['clicks'] += $link->getClicks();
                $marketplaceDistribution[$marketplaceId]['revenue'] += (float) $link->getAffiliateRevenue();
            }

            return [
                'product_id' => $productId,
                'total_links' => count($links),
                'active_links' => count($activeLinks),
                'inactive_links' => count($inactiveLinks),
                'total_clicks' => $totalClicks,
                'total_sales' => $totalSales,
                'total_revenue' => $totalRevenue,
                'average_conversion_rate' => $totalClicks > 0 ? ($totalSales / $totalClicks) * 100 : 0,
                'average_revenue_per_click' => $totalClicks > 0 ? $totalRevenue / $totalClicks : 0,
                'price_range' => $priceRange,
                'average_rating' => $averageRating,
                'marketplace_distribution' => $marketplaceDistribution,
                'needs_attention' => [
                    'price_update' => count(array_filter($activeLinks, fn ($link) => $link->needsPriceUpdate())),
                    'validation' => count(array_filter($activeLinks, fn ($link) => $link->needsValidation()))
                ],
                'calculated_at' => (new DateTimeImmutable())->format('c')
            ];

        }, 300); // 5 minute cache
    }

    /**
     * Get marketplace comparison data
     */
    public function getMarketplaceStats(int $marketplaceId, string $period = '30d'): array
    {
        $cacheKey = $this->getCacheKey(sprintf('marketplace_stats_%s_%s', $marketplaceId, $period));

        return $this->cacheService->remember($cacheKey, function () use ($marketplaceId, $period) {
            $marketplace = $this->marketplaceModel->find($marketplaceId);

            if (!$marketplace) {
                throw new DomainException(
                    'MARKETPLACE_NOT_FOUND',
                    'Marketplace not found',
                    ['marketplace_id' => $marketplaceId]
                );
            }

            $links = $this->linkModel->findByMarketplace($marketplaceId, true, 1000);

            $activeLinks = array_filter($links, fn ($link) => $link->isActive());
            $inactiveLinks = array_filter($links, fn ($link) => !$link->isActive());

            $clickStats = $this->linkModel->getClickStats($period, null, null, $marketplaceId);

            $totalClicks = array_sum(array_map(fn ($link) => $link->getClicks(), $links));
            $totalSales = array_sum(array_map(fn ($link) => $link->getSoldCount(), $links));
            $totalRevenue = array_sum(array_map(
                fn ($link) => (float) $link->getAffiliateRevenue(),
                $links
            ));

            $averageCommissionRate = count($activeLinks) > 0 ?
                array_sum(array_map(
                    fn ($link) => $this->calculateEffectiveCommissionRate($link),
                    $activeLinks
                )) / count($activeLinks) : 0;

            $topProducts = [];
            $productRevenue = [];

            foreach ($activeLinks as $link) {
                $productId = $link->getProductId();
                if (!isset($productRevenue[$productId])) {
                    $productRevenue[$productId] = 0;
                }
                $productRevenue[$productId] += (float) $link->getAffiliateRevenue();
            }

            arsort($productRevenue);
            $topProductIds = array_slice(array_keys($productRevenue), 0, 5);

            foreach ($topProductIds as $productId) {
                $product = $this->productModel->find($productId);
                if ($product) {
                    $topProducts[] = [
                        'product_id' => $productId,
                        'product_name' => $product->getName(),
                        'revenue' => $productRevenue[$productId]
                    ];
                }
            }

            return [
                'marketplace_id' => $marketplaceId,
                'marketplace_name' => $marketplace->getName(),
                'total_links' => count($links),
                'active_links' => count($activeLinks),
                'inactive_links' => count($inactiveLinks),
                'total_clicks' => $totalClicks,
                'total_sales' => $totalSales,
                'total_revenue' => $totalRevenue,
                'click_stats' => $clickStats,
                'top_products' => $topProducts,
                'period' => $period,
                'calculated_at' => (new DateTimeImmutable())->format('c')
            ];

        }, 600); // 10 minute cache
    }

    /**
     * Set link active status
     */
    private function setActiveStatus(int $linkId, bool $active, int $adminId, ?string $reason = null): Link
    {
        $link = $this->linkModel->find($linkId);

        if (!$link) {
            throw LinkNotFoundException::forId($linkId);
        }

        // Check if already in desired state
        if ($link->isActive() === $active) {
            $state = $active ? 'active' : 'inactive';
            throw new DomainException(
                'LINK_ALREADY_' . strtoupper($state),
                sprintf('Link is already %s', $state),
                ['link_id' => $linkId, 'current_state' => $state]
            );
        }

        $this->db->transStart();

        try {
            $updatedLink = clone $link;
            $updatedLink->setActive($active);
            $updatedLink->markAsUpdated();

            $savedLink = $this->linkModel->save($updatedLink);

            // Log audit trail
            $admin = $this->getAdminModel()->find($adminId);

            $action = $active ? 'ACTIVATED' : 'DEACTIVATED';
            $this->auditService->logStateTransition(
                AuditService::ENTITY_LINK,
                $linkId,
                $active ? 'INACTIVE' : 'ACTIVE',
                $active ? 'ACTIVE' : 'INACTIVE',
                $admin,
                ['reason' => $reason],
                $reason ?? sprintf('Link %s', strtolower($action))
            );

            $this->db->transComplete();

            // Clear caches
            $this->clearLinkCaches($linkId, $link->getProductId());
            $this->clearProductLinkCaches($link->getProductId());

            // Publish event
            $eventType = $active ? 'link.activated' : self::EVENT_LINK_DEACTIVATED;

            $this->publishEvent($eventType, [
                'link_id' => $linkId,
                'product_id' => $link->getProductId(),
                'admin_id' => $adminId,
                'reason' => $reason,
                'timestamp' => new DateTimeImmutable()
            ]);

            return $savedLink;

        } catch (Exception $e) {
            $this->db->transRollback();

            $this->logError(sprintf('Link %s failed', $active ? 'activation' : 'deactivation'), [
                'link_id' => $linkId,
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            $action = $active ? 'activate' : 'deactivate';
            throw new DomainException(
                'LINK_' . strtoupper($action) . '_FAILED',
                sprintf('Failed to %s link: %s', $action, $e->getMessage()),
                ['previous' => $e->getMessage()],
                500,
                $e
            );
        }
    }

    /**
     * Perform actual link validation
     */
    private function performLinkValidation(Link $link): array
    {
        $url = $link->getUrl();

        if (!$url) {
            return [
                'is_valid' => false,
                'reason' => 'No URL provided',
                'status_code' => 0
            ];
        }

        try {
            // In production, this would use a proper HTTP client with timeout
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; LinkValidator/1.0)');

            curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $isValid = $statusCode >= 200 && $statusCode < 400;

            return [
                'is_valid' => $isValid,
                'status_code' => $statusCode,
                'reason' => $isValid ? 'URL accessible' : ($error ?: "HTTP $statusCode"),
                'checked_url' => $url,
                'validation_method' => 'HTTP_HEAD'
            ];

        } catch (Exception $e) {
            return [
                'is_valid' => false,
                'reason' => $e->getMessage(),
                'status_code' => 0,
                'checked_url' => $url,
                'validation_method' => 'EXCEPTION'
            ];
        }
    }

    /**
     * Validate URL format and accessibility
     */
    private function validateUrl(string $url): bool
    {
        // Basic URL format validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Check URL length
        if (strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }

        // Check if URL is from allowed marketplaces (optional)
        if ($this->config['validate_marketplace_domains']) {
            $parsed = parse_url($url);
            $host = $parsed['host'] ?? '';

            $allowedDomains = $this->getAllowedMarketplaceDomains();
            if (!empty($allowedDomains) && !$this->isDomainAllowed($host, $allowedDomains)) {
                return false;
            }
        }

        // If deep validation is enabled, check accessibility
        if ($this->config['validate_url_accessibility']) {
            return $this->checkUrlAccessibility($url);
        }

        return true;
    }

    /**
     * Check URL accessibility
     */
    private function checkUrlAccessibility(string $url): bool
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; URLValidator/1.0)');

            curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $statusCode >= 200 && $statusCode < 400;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Calculate commission
     */
    private function calculateCommission(string $price, string $commissionRate): string
    {
        $priceFloat = (float) $price;
        $rateFloat = (float) $commissionRate;

        $commission = $priceFloat * $rateFloat;

        return number_format($commission, 2, '.', '');
    }

    /**
     * Calculate effective commission rate
     */
    private function calculateEffectiveCommissionRate(Link $link): float
    {
        $price = (float) $link->getPrice();
        $revenue = (float) $link->getAffiliateRevenue();

        return $price > 0 ? $revenue / $price : 0;
    }

    /**
     * Calculate price range
     */
    private function calculatePriceRange(array $links): ?array
    {
        $prices = array_map(function ($link) {
            return (float) $link->getPrice();
        }, $links);

        if (empty($prices)) {
            return null;
        }

        return [
            'min' => min($prices),
            'max' => max($prices),
            'average' => array_sum($prices) / count($prices),
            'count' => count($prices)
        ];
    }

    /**
     * Calculate average rating
     */
    private function calculateAverageRating(array $links): float
    {
        $ratings = array_map(function ($link) {
            return (float) $link->getRating();
        }, $links);

        $validRatings = array_filter($ratings, function ($rating) {
            return $rating > 0;
        });

        if (empty($validRatings)) {
            return 0;
        }

        return array_sum($validRatings) / count($validRatings);
    }

    /**
     * Calculate click-through rate
     */
    private function calculateClickThroughRate(int $linkId): float
    {
        $link = $this->linkModel->find($linkId);
        if (!$link) {
            return 0;
        }

        $clicks = $link->getClicks();
        $product = $this->productModel->find($link->getProductId());

        if (!$product || $product->getViewCount() === 0) {
            return 0;
        }

        return ($clicks / $product->getViewCount()) * 100;
    }

    /**
     * Calculate performance trend
     */
    private function calculatePerformanceTrend(int $linkId, string $period): array
    {
        // Simplified - in production would compare current vs previous period
        $link = $this->linkModel->find($linkId);

        if (!$link) {
            return ['trend' => 'stable', 'change_percent' => 0];
        }

        // Mock trend calculation
        $trends = ['up', 'down', 'stable'];
        $trend = $trends[array_rand($trends)];
        $changePercent = $trend === 'stable' ? 0 : rand(5, 30);

        if ($trend === 'down') {
            $changePercent = -$changePercent;
        }

        return [
            'trend' => $trend,
            'change_percent' => $changePercent,
            'period' => $period
        ];
    }

    /**
     * Get price history
     */
    private function getPriceHistory(int $linkId, string $period): array
    {
        // Simplified - in production would query price_history table
        $link = $this->linkModel->find($linkId);

        if (!$link) {
            return [];
        }

        // Generate mock price history
        $history = [];
        $basePrice = (float) $link->getPrice();
        $days = (int) str_replace('d', '', $period);

        for ($i = $days; $i >= 0; $i--) {
            $date = (new DateTimeImmutable())->modify("-$i days");
            $variation = rand(-10, 10) / 100; // ±10% variation
            $price = $basePrice * (1 + $variation);

            $history[] = [
                'date' => $date->format('Y-m-d'),
                'price' => round($price, 2),
                'change_percent' => round($variation * 100, 2)
            ];
        }

        return $history;
    }

    /**
     * Get marketplace comparison
     */
    private function getMarketplaceComparison(int $marketplaceId, string $price, string $rating): array
    {
        $marketplace = $this->marketplaceModel->find($marketplaceId);

        if (!$marketplace) {
            return [];
        }

        // Get average price and rating for this marketplace
        $links = $this->linkModel->findByMarketplace($marketplaceId, true, 100);

        if (empty($links)) {
            return [];
        }

        $prices = array_map(fn ($link) => (float) $link->getPrice(), $links);
        $ratings = array_map(fn ($link) => (float) $link->getRating(), $links);
        $validRatings = array_filter($ratings, fn ($r) => $r > 0);

        $avgPrice = array_sum($prices) / count($prices);
        $avgRating = !empty($validRatings) ? array_sum($validRatings) / count($validRatings) : 0;

        $priceDiff = ((float) $price - $avgPrice) / $avgPrice * 100;
        $ratingDiff = ((float) $rating - $avgRating) / ($avgRating > 0 ? $avgRating : 1) * 100;

        return [
            'marketplace_name' => $marketplace->getName(),
            'average_price' => round($avgPrice, 2),
            'average_rating' => round($avgRating, 2),
            'price_comparison' => [
                'value' => (float) $price,
                'difference_percent' => round($priceDiff, 2),
                'status' => abs($priceDiff) < 10 ? 'competitive' : ($priceDiff < 0 ? 'below_average' : 'above_average')
            ],
            'rating_comparison' => [
                'value' => (float) $rating,
                'difference_percent' => round($ratingDiff, 2),
                'status' => abs($ratingDiff) < 10 ? 'average' : ($ratingDiff < 0 ? 'below_average' : 'above_average')
            ]
        ];
    }

    /**
     * Get allowed marketplace domains
     */
    private function getAllowedMarketplaceDomains(): array
    {
        $marketplaces = $this->marketplaceModel->findActive();

        $domains = [];
        foreach ($marketplaces as $marketplace) {
            // Extract domain from marketplace data or configuration
            // This is simplified - real implementation would have domain mapping
            $slug = $marketplace->getSlug();
            $domains[] = $slug . '.com';
            $domains[] = 'www.' . $slug . '.com';
        }

        return $domains;
    }

    /**
     * Check if domain is allowed
     */
    private function isDomainAllowed(string $host, array $allowedDomains): bool
    {
        foreach ($allowedDomains as $domain) {
            if (strpos($host, $domain) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get product name
     */
    private function getProductName(int $productId): ?string
    {
        $product = $this->productModel->find($productId);
        return $product ? $product->getName() : null;
    }

    /**
     * Get marketplace name
     */
    private function getMarketplaceName(int $marketplaceId): ?string
    {
        $marketplace = $this->marketplaceModel->find($marketplaceId);
        return $marketplace ? $marketplace->getName() : null;
    }

    /**
     * Clear link-specific caches
     */
    private function clearLinkCaches(int $linkId, int $productId): void
    {
        $this->cacheService->deleteMultiple([
            $this->getCacheKey("link_$linkId"),
            $this->getCacheKey("analytics_$linkId"),
            $this->getCacheKey("product_stats_$productId"),
            $this->getCacheKey("needs_price_update_*"),
            $this->getCacheKey("needs_validation_*"),
        ]);
    }

    /**
     * Clear product link caches
     */
    private function clearProductLinkCaches(int $productId): void
    {
        // Also clear product caches since link changes affect product data
        $cacheService = $this->cacheService;
        $prefix = self::CACHE_PREFIX;

        // Clear product detail caches that include links
        $pattern = "product_service_find_*_*_" . md5(json_encode(['relations' => ['links']]));
        $cacheService->deleteMultiple(
            $cacheService->getKeysByPattern($pattern)
        );
    }

    /**
     * Generate cache key
     */
    private function getCacheKey(string $suffix): string
    {
        return self::CACHE_PREFIX . $suffix;
    }

    /**
     * Publish event for loose coupling
     */
    private function publishEvent(string $eventType, array $payload): void
    {
        if (!$this->config['enable_events']) {
            return;
        }

        try {
            $event = [
                'event' => $eventType,
                'payload' => $payload,
                'timestamp' => (new DateTimeImmutable())->format('c'),
                'source' => 'LinkService'
            ];

            // Log event for debugging
            if ($this->config['log_events']) {
                $this->logEvent($event);
            }

        } catch (Exception $e) {
            $this->logError('Event publishing failed', [
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Lazy-load AdminModel
     */
    private function getAdminModel()
    {
        return model(\App\Models\AdminModel::class);
    }

    /**
     * Log error with context
     */
    private function logError(string $message, array $context = []): void
    {
        error_log(sprintf(
            '[LinkService Error] %s: %s',
            $message,
            json_encode($context, JSON_PRETTY_PRINT)
        ));
    }

    /**
     * Log event for debugging
     */
    private function logEvent(array $event): void
    {
        error_log(sprintf(
            '[LinkService Event] %s',
            json_encode($event, JSON_PRETTY_PRINT)
        ));
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'cache_ttl' => self::CACHE_TTL,
            'cache_ttl_analytics' => self::ANALYTICS_CACHE_TTL,
            'enable_events' => true,
            'log_events' => false,
            'max_links_per_product' => self::MAX_LINKS_PER_PRODUCT,
            'min_price_update_interval' => self::MIN_PRICE_UPDATE_INTERVAL,
            'min_validation_interval' => self::MIN_VALIDATION_INTERVAL,
            'max_price_change_percent' => 100, // Maximum allowed price change percentage
            'auto_deactivate_invalid_links' => true,
            'validate_marketplace_domains' => true,
            'validate_url_accessibility' => false, // Disabled by default for performance
            'enable_click_tracking' => true,
            'enable_revenue_tracking' => true,
        ];
    }
}
