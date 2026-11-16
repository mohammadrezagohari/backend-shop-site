<?php

namespace Tests\Feature;

use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Content\Domain\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorefrontFeaturesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sorting_views_comments_blog_and_guest_cart_work(): void
    {
        $category = Category::query()->create(['name' => 'Health', 'slug' => 'health']);
        $cheap = Product::query()->create(['category_id' => $category->id, 'name' => 'Cheap', 'slug' => 'cheap', 'sku' => 'C-1', 'price' => 100, 'stock' => 10, 'is_active' => true]);
        Product::query()->create(['category_id' => $category->id, 'name' => 'Premium', 'slug' => 'premium', 'sku' => 'P-1', 'price' => 900, 'stock' => 10, 'views_count' => 20, 'is_active' => true]);

        $this->getJson('/api/v1/products?sort=price_desc')->assertJsonPath('data.0.slug', 'premium');
        $this->getJson('/api/v1/products?sort=popular')->assertJsonPath('data.0.slug', 'premium');
        $this->getJson('/api/v1/products/cheap')->assertOk();
        $this->assertDatabaseHas('products', ['id' => $cheap->id, 'views_count' => 1]);

        $this->postJson('/api/v1/products/cheap/comments', ['name' => 'Ali', 'rating' => 5, 'body' => 'Useful product'])->assertCreated();
        $this->getJson('/api/v1/products/cheap/comments')->assertJsonCount(0, 'data');

        Post::query()->create(['title' => 'Health guide', 'slug' => 'health-guide', 'excerpt' => 'Guide', 'body' => 'Article', 'published_at' => now()]);
        $this->getJson('/api/v1/posts')->assertJsonPath('data.0.slug', 'health-guide');
        $this->getJson('/api/v1/posts/health-guide')->assertOk();

        $cartToken = (string) Str::uuid();
        $headers = ['X-Cart-Token' => $cartToken];
        $itemId = $this->withHeaders($headers)->postJson('/api/v1/cart/items', ['product_id' => $cheap->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.items.0.quantity', 2)->json('data.items.0.id');
        $this->withHeaders($headers)->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 3])->assertJsonPath('data.items.0.quantity', 3);
        $this->withHeaders($headers)->deleteJson("/api/v1/cart/items/{$itemId}")->assertNoContent();
    }
}
