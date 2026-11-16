<?php

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_pages_have_independent_cache_keys(): void
    {
        $category = Category::query()->create(['name' => 'Test', 'slug' => 'test']);
        foreach ([1, 2] as $id) {
            Product::query()->create([
                'category_id' => $category->id, 'name' => 'Product '.$id, 'slug' => 'product-'.$id,
                'sku' => 'PAGE-'.$id, 'price' => 100000, 'stock' => 10, 'is_active' => true,
            ]);
        }
        $this->getJson('/api/v1/products?per_page=1&page=1')->assertOk()
            ->assertJsonPath('current_page', 1)->assertJsonPath('data.0.slug', 'product-2');
        $this->getJson('/api/v1/products?per_page=1&page=2')->assertOk()
            ->assertJsonPath('current_page', 2)->assertJsonPath('data.0.slug', 'product-1');
    }

    public function test_products_can_be_filtered_by_category(): void
    {
        $category = Category::query()->create(['name' => 'ویتامین', 'slug' => 'vitamin']);
        Product::query()->create([
            'category_id' => $category->id, 'name' => 'ویتامین C', 'slug' => 'vitamin-c',
            'sku' => 'VIT-C', 'price' => 100000, 'stock' => 10, 'is_active' => true,
        ]);

        $this->getJson('/api/v1/products?category=vitamin')
            ->assertOk()->assertJsonPath('data.0.slug', 'vitamin-c');
    }
}
