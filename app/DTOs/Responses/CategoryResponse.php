<?php

namespace App\DTOs\Responses;

use App\Entities\Category;

class CategoryResponse
{
    public ?array $statistics = null;
    public ?int $product_count = null;
    public ?int $children_count = null;
    public ?string $highlight_query = null;

    // UBAH METHOD INI:
    public static function fromEntity(Category $category, array $options = []): self
    {
        // 1. Instansiasi objek (sesuaikan dengan cara Anda membuat objek saat ini)
        $response = new self();

        // 2. Mapping standar (id, name, slug, dll)
        $response->id = $category->getId();
        $response->name = $category->getName();
        $response->slug = $category->getSlug();
        // ... mapping properti dasar lainnya ...

        // 3. Mapping Options (Ini bagian yang menyebabkan error sebelumnya)
        if (!empty($options['statistics'])) {
            $response->statistics = $options['statistics'];
        }

        if (isset($options['with_product_count']) && $options['with_product_count']) {
            // Jika entity punya method getProductCount(), atau ambil dari options jika dipass manual
            $response->product_count = $category->getProductCount() ?? 0;
        }

        if (isset($options['with_children_count']) && $options['with_children_count']) {
            // Asumsi logic pengambilan children count
            $response->children_count = $category->getChildrenCount() ?? 0;
        }

        if (isset($options['highlight_query'])) {
            $response->highlight_query = $options['highlight_query'];
        }

        return $response;
    }
}
