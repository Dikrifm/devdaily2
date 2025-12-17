<?php

namespace App\Entities;

use App\Enums\ProductStatus;
use App\Enums\ImageSourceType;
use DateTimeImmutable;

/**
 * Product Entity
 * 
 * Represents the core product in the system. Limited to 300 premium products.
 * Each product undergoes manual curation with strict quality control.
 * 
 * @package App\Entities
 */
class Product extends BaseEntity
{
    /**
     * Category ID (foreign key)
     * 
     * @var int|null
     */
    private ?int $category_id = null;

    /**
     * URL-friendly slug (unique)
     * 
     * @var string
     */
    private string $slug;

    /**
     * Main product image URL
     * 
     * @var string|null
     */
    private ?string $image = null;

    /**
     * Product name
     * 
     * @var string
     */
    private string $name;

    /**
     * Product description
     * 
     * @var string|null
     */
    private ?string $description = null;

    /**
     * Market reference price (decimal)
     * 
     * @var string
     */
    private float $market_price = '0.00';

    /**
     * View count for popularity tracking
     * 
     * @var int
     */
    private int $view_count = 0;

    /**
     * Local image path if uploaded
     * 
     * @var string|null
     */
    private ?string $image_path = null;

    /**
     * Image source type
     * 
     * @var ImageSourceType
     */
    private ImageSourceType $image_source_type;

    /**
     * Product workflow status
     * 
     * @var ProductStatus
     */
    private ProductStatus $status;

    /**
     * Publication timestamp
     * 
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $published_at = null;

    /**
     * Verification timestamp
     * 
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $verified_at = null;

    /**
     * Admin ID who verified the product
     * 
     * @var int|null
     */
    private ?int $verified_by = null;

    /**
     * Last price check timestamp
     * 
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $last_price_check = null;

    /**
     * Last link validation timestamp
     * 
     * @var DateTimeImmutable|null
     */
    private ?DateTimeImmutable $last_link_check = null;

    /**
     * Product constructor
     * 
     * @param string $name Product name
     * @param string $slug URL slug
     */
    public function __construct(string $name, string $slug)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->status = ProductStatus::DRAFT;
        $this->image_source_type = ImageSourceType::URL;
        $this->initialize();
    }

    // ==================== GETTER METHODS ====================

    public function getCategoryId(): ?int
    {
        return $this->category_id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getMarketPrice(): string
    {
        return $this->market_price;
    }

    public function getViewCount(): int
    {
        return $this->view_count;
    }

    public function getImagePath(): ?string
    {
        return $this->image_path;
    }

    public function getImageSourceType(): ImageSourceType
    {
        return $this->image_source_type;
    }

    public function getStatus(): ProductStatus
    {
        return $this->status;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->published_at;
    }

    public function getVerifiedAt(): ?DateTimeImmutable
    {
        return $this->verified_at;
    }

    public function getVerifiedBy(): ?int
    {
        return $this->verified_by;
    }

    public function getLastPriceCheck(): ?DateTimeImmutable
    {
        return $this->last_price_check;
    }

    public function getLastLinkCheck(): ?DateTimeImmutable
    {
        return $this->last_link_check;
    }

    // ==================== SETTER METHODS ====================

    public function setCategoryId(?int $category_id): self
    {
        if ($this->category_id === $category_id) {
            return $this;
        }
        $this->category_id = $category_id;
        $this->markAsUpdated();
        return $this;
    }

    public function setSlug(string $slug): self
    {
        if ($this->slug === $slug) {
            return $this;
        }
        $this->slug = $slug;
        $this->markAsUpdated();
        return $this;
    }

    public function setImage(?string $image): self
    {
        if ($this->image === $image) {
            return $this;
        }
        $this->image = $image;
        $this->markAsUpdated();
        return $this;
    }

    public function setName(string $name): self
    {
        if ($this->name === $name) {
            return $this;
        }
        $this->name = $name;
        $this->markAsUpdated();
        return $this;
    }

    public function setDescription(?string $description): self
    {
        if ($this->description === $description) {
            return $this;
        }
        $this->description = $description;
        $this->markAsUpdated();
        return $this;
    }

    public function setMarketPrice(float $market_price): self
    {
        if (!preg_match('/^\d+\.\d{2}$/', $market_price)) {
            throw new \InvalidArgumentException('Market price must be in decimal format with 2 decimal places (e.g., 1234.56)');
        }
        
        if ($this->market_price === $market_price) {
            return $this;
        }
        $this->market_price = $market_price;
        $this->markAsUpdated();
        return $this;
    }

    public function setViewCount(int $view_count): self
    {
        if ($this->view_count === $view_count) {
            return $this;
        }
        $this->view_count = $view_count;
        $this->markAsUpdated();
        return $this;
    }

    public function setImagePath(?string $image_path): self
    {
        if ($this->image_path === $image_path) {
            return $this;
        }
        $this->image_path = $image_path;
        $this->markAsUpdated();
        return $this;
    }

    public function setImageSourceType(ImageSourceType $image_source_type): self
    {
        if ($this->image_source_type === $image_source_type) {
            return $this;
        }
        $this->image_source_type = $image_source_type;
        $this->markAsUpdated();
        return $this;
    }

    public function setStatus(ProductStatus $status): self
    {
        if ($this->status === $status) {
            return $this;
        }
        
        if (!$this->status->canTransitionTo($status)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot transition product status from %s to %s', 
                    $this->status->label(), 
                    $status->label()
                )
            );
        }
        
        $this->status = $status;
        $this->markAsUpdated();
        
        // Auto-set timestamps based on status transitions
        if ($status === ProductStatus::PUBLISHED && $this->published_at === null) {
            $this->published_at = new DateTimeImmutable();
        }
        
        return $this;
    }

    public function setPublishedAt(?DateTimeImmutable $published_at): self
    {
        if ($this->published_at === $published_at) {
            return $this;
        }
        $this->published_at = $published_at;
        $this->markAsUpdated();
        return $this;
    }

    public function setVerifiedAt(?DateTimeImmutable $verified_at): self
    {
        if ($this->verified_at === $verified_at) {
            return $this;
        }
        $this->verified_at = $verified_at;
        $this->markAsUpdated();
        return $this;
    }

    public function setVerifiedBy(?int $verified_by): self
    {
        if ($this->verified_by === $verified_by) {
            return $this;
        }
        $this->verified_by = $verified_by;
        $this->markAsUpdated();
        return $this;
    }

    public function setLastPriceCheck(?DateTimeImmutable $last_price_check): self
    {
        if ($this->last_price_check === $last_price_check) {
            return $this;
        }
        $this->last_price_check = $last_price_check;
        // Note: Don't mark as updated for maintenance fields
        return $this;
    }

    public function setLastLinkCheck(?DateTimeImmutable $last_link_check): self
    {
        if ($this->last_link_check === $last_link_check) {
            return $this;
        }
        $this->last_link_check = $last_link_check;
        // Note: Don't mark as updated for maintenance fields
        return $this;
    }

    // ==================== BUSINESS LOGIC METHODS ====================

    public function incrementViewCount(): self
    {
        $this->view_count++;
        // Don't update timestamp for view count increments
        return $this;
    }

    public function requestVerification(): self
    {
        return $this->setStatus(ProductStatus::PENDING_VERIFICATION);
    }

    public function verify(int $adminId): self
    {
        $this->verified_at = new DateTimeImmutable();
        $this->verified_by = $adminId;
        return $this->setStatus(ProductStatus::VERIFIED);
    }

    public function publish(): self
    {
        if ($this->status !== ProductStatus::VERIFIED) {
            throw new \LogicException('Only verified products can be published');
        }
        
        $this->published_at = new DateTimeImmutable();
        return $this->setStatus(ProductStatus::PUBLISHED);
    }

    public function archive(): self
    {
        return $this->setStatus(ProductStatus::ARCHIVED);
    }

    public function restore(): self
    {
        if ($this->status !== ProductStatus::ARCHIVED) {
            throw new \LogicException('Only archived products can be restored');
        }
        
        // Restore to previous logical status or default to DRAFT
        return $this->setStatus(ProductStatus::DRAFT);
    }

    public function isPublished(): bool
    {
        return $this->status === ProductStatus::PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === ProductStatus::DRAFT;
    }

    public function isPendingVerification(): bool
    {
        return $this->status === ProductStatus::PENDING_VERIFICATION;
    }

    public function isVerified(): bool
    {
        return $this->status === ProductStatus::VERIFIED;
    }

    public function isArchived(): bool
    {
        return $this->status === ProductStatus::ARCHIVED;
    }

    public function markPriceChecked(): self
    {
        $this->last_price_check = new DateTimeImmutable();
        return $this;
    }

    public function markLinksChecked(): self
    {
        $this->last_link_check = new DateTimeImmutable();
        return $this;
    }

    public function needsPriceUpdate(): bool
    {
        if ($this->last_price_check === null) {
            return true;
        }
        
        $now = new DateTimeImmutable();
        $interval = $now->diff($this->last_price_check);
        return $interval->days >= 7; // Business rule: 7 days
    }

    public function needsLinkValidation(): bool
    {
        if ($this->last_link_check === null) {
            return true;
        }
        
        $now = new DateTimeImmutable();
        $interval = $now->diff($this->last_link_check);
        return $interval->days >= 14; // Business rule: 14 days
    }

    public function getDisplayImageUrl(): ?string
    {
        if ($this->image_source_type === ImageSourceType::UPLOAD && $this->image_path !== null) {
            return '/uploads/products/' . $this->image_path;
        }
        
        return $this->image;
    }

    public function getFormattedMarketPrice(): string
    {
        return 'Rp ' . number_format((float) $this->market_price, 0, ',', '.');
    }

    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    public function getStatusColorClass(): string
    {
        return $this->status->colorClass();
    }

    public function getStatusIcon(): string
    {
        return $this->status->icon();
    }

    // ==================== SERIALIZATION METHODS ====================

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'category_id' => $this->getCategoryId(),
            'slug' => $this->getSlug(),
            'image' => $this->getImage(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'market_price' => $this->getMarketPrice(),
            'formatted_market_price' => $this->getFormattedMarketPrice(),
            'view_count' => $this->getViewCount(),
            'image_path' => $this->getImagePath(),
            'image_source_type' => $this->getImageSourceType()->value,
            'status' => $this->getStatus()->value,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColorClass(),
            'status_icon' => $this->getStatusIcon(),
            'published_at' => $this->getPublishedAt(),
            'verified_at' => $this->getVerifiedAt(),
            'verified_by' => $this->getVerifiedBy(),
            'last_price_check' => $this->getLastPriceCheck(),
            'last_link_check' => $this->getLastLinkCheck(),
            'is_published' => $this->isPublished(),
            'is_draft' => $this->isDraft(),
            'is_pending_verification' => $this->isPendingVerification(),
            'is_verified' => $this->isVerified(),
            'is_archived' => $this->isArchived(),
            'display_image_url' => $this->getDisplayImageUrl(),
            'needs_price_update' => $this->needsPriceUpdate(),
            'needs_link_validation' => $this->needsLinkValidation(),
            'created_at' => $this->getCreatedAt(),
            'updated_at' => $this->getUpdatedAt(),
            'deleted_at' => $this->getDeletedAt(),
        ];
    }

    public static function fromArray(array $data): static
    {
        $product = new self(
            $data['name'] ?? '',
            $data['slug'] ?? ''
        );

        if (isset($data['id'])) {
            $product->setId($data['id']);
        }

        if (isset($data['category_id'])) {
            $product->setCategoryId($data['category_id']);
        }

        if (isset($data['image'])) {
            $product->setImage($data['image']);
        }

        if (isset($data['description'])) {
            $product->setDescription($data['description']);
        }

        if (isset($data['market_price'])) {
            $product->setMarketPrice($data['market_price']);
        }

        if (isset($data['view_count'])) {
            $product->setViewCount((int) $data['view_count']);
        }

        if (isset($data['image_path'])) {
            $product->setImagePath($data['image_path']);
        }

        if (isset($data['image_source_type'])) {
            $product->setImageSourceType(ImageSourceType::from($data['image_source_type']));
        }

        if (isset($data['status'])) {
            $product->setStatus(ProductStatus::from($data['status']));
        }

        if (isset($data['published_at']) && $data['published_at'] instanceof DateTimeImmutable) {
            $product->setPublishedAt($data['published_at']);
        }

        if (isset($data['verified_at']) && $data['verified_at'] instanceof DateTimeImmutable) {
            $product->setVerifiedAt($data['verified_at']);
        }

        if (isset($data['verified_by'])) {
            $product->setVerifiedBy($data['verified_by']);
        }

        if (isset($data['last_price_check']) && $data['last_price_check'] instanceof DateTimeImmutable) {
            $product->setLastPriceCheck($data['last_price_check']);
        }

        if (isset($data['last_link_check']) && $data['last_link_check'] instanceof DateTimeImmutable) {
            $product->setLastLinkCheck($data['last_link_check']);
        }

        if (isset($data['created_at']) && $data['created_at'] instanceof DateTimeImmutable) {
            $product->setCreatedAt($data['created_at']);
        }

        if (isset($data['updated_at']) && $data['updated_at'] instanceof DateTimeImmutable) {
            $product->setUpdatedAt($data['updated_at']);
        }

        if (isset($data['deleted_at']) && $data['deleted_at'] instanceof DateTimeImmutable) {
            $product->setDeletedAt($data['deleted_at']);
        }

        return $product;
    }

    public static function createSample(): static
    {
        $product = new self(
            'Sample Premium Product',
            'sample-premium-product'
        );
        
        $product->setDescription('This is a sample product description for testing purposes.');
        $product->setMarketPrice('1250000.00');
        $product->setImage('https://via.placeholder.com/400x300');
        $product->verify(1); // Admin ID 1
        $product->publish();
        
        return $product;
    }
}