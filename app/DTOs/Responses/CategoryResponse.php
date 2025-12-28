<?php

namespace App\DTOs\Responses;

use App\Entities\Category;

/**
 * Class CategoryResponse
 *
 * DTO (Layer 6) untuk merepresentasikan data Kategori ke publik (API/Web).
 * Mendukung struktur Hierarki (Tree) melalui properti $children.
 */
class CategoryResponse
{
    public int $id;
    public string $name;
    public string $slug;
    public ?string $description = null;
    public ?string $image_url = null;
    public ?int $parent_id = null;
    
    /** @var CategoryResponse[] List sub-kategori */
    public array $children = [];

    // Statistik (Opsional)
    public ?int $product_count = null;
    public ?int $children_count = null;

    /**
     * Factory Method dari Entity
     */
    public static function fromEntity(Category $category, array $options = []): self
    {
        $response = new self();
        
        // 1. Mapping Data Dasar
        $response->id          = $category->getId();
        $response->name        = $category->getName();
        $response->slug        = $category->getSlug();
        $response->description = $category->getDescription();
        $response->parent_id   = $category->getParentId();
        
        // 2. Mapping Gambar (Jika ada logic URL khusus, handle di sini)
        // Asumsi: getIcon() mengembalikan path/url
        // $response->image_url = $category->getIcon(); 

        // 3. Mapping Statistik (Opsional via Options)
        if (isset($options['product_count'])) {
            $response->product_count = (int) $options['product_count'];
        } elseif (method_exists($category, 'getProductCount')) {
             $response->product_count = $category->getProductCount();
        }

        if (isset($options['children_count'])) {
            $response->children_count = (int) $options['children_count'];
        }

        return $response;
    }

    /**
     * Set Anak Kategori (Untuk Membangun Tree)
     * @param CategoryResponse[] $children
     */
    public function setChildren(array $children): self
    {
        $this->children = $children;
        return $this;
    }

    /**
     * Konversi ke Array (Recursive untuk API JSON)
     * Penting: Method ini memastikan struktur tree terjaga dalam JSON
     */
    public function toArray(): array
    {
        $data = [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'image_url'   => $this->image_url,
            'parent_id'   => $this->parent_id,
        ];

        // Include stats jika tidak null
        if ($this->product_count !== null) {
            $data['product_count'] = $this->product_count;
        }

        // Include children secara rekursif
        // Jika children kosong, tetap kirim array kosong [] agar frontend konsisten loop-nya
        $data['children'] = array_map(fn($child) => $child->toArray(), $this->children);

        return $data;
    }
}
