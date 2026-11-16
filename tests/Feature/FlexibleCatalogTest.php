<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlexibleCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $token = 'catalog-test-token';
        $user->apiTokens()->create(['name' => 'test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withToken($token);
    }

    private function product(): array
    {
        $this->admin();
        $category = Category::create(['name' => 'Clothes', 'slug' => 'clothes']);

        return $this->postJson('/api/v1/admin/products', [
            'category_id' => $category->id, 'name' => 'T shirt', 'slug' => 't-shirt', 'sku' => 'TS', 'price' => 0, 'stock' => 0,
            'variants' => [
                ['sku' => 'TS-G-XL', 'attributes' => ['رنگ' => 'سبز', 'سایز' => 'XL'], 'price' => 300000, 'stock' => 3],
                ['sku' => 'TS-B-L', 'attributes' => ['رنگ' => 'آبی', 'سایز' => 'L'], 'price' => 400000, 'stock' => 5],
            ],
        ])->assertCreated()->assertJsonPath('data.has_variants', true)->assertJsonPath('data.stock', 8)->assertJsonPath('data.price', 300000)->json('data');
    }

    public function test_categories_support_children_filter_descendants_and_reject_cycles(): void
    {
        $this->admin();
        $root = $this->postJson('/api/v1/admin/categories', ['name' => 'Root', 'slug' => 'root'])->assertCreated()->json('data');
        $child = $this->postJson('/api/v1/admin/categories', ['name' => 'Child', 'slug' => 'child', 'parent_id' => $root['id']])->assertCreated()->json('data');
        $this->patchJson('/api/v1/admin/categories/'.$root['id'], ['parent_id' => $child['id']])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson('/api/v1/admin/categories/'.$root['id'], ['parent_id' => (string) $root['id']])->assertUnprocessable();
        Product::create(['category_id' => $child['id'], 'name' => 'Child item', 'slug' => 'child-item', 'sku' => 'C-1', 'price' => 100000, 'stock' => 2, 'is_active' => true]);
        $this->getJson('/api/v1/products?category=root')->assertOk()->assertJsonPath('data.0.slug', 'child-item');
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonFragment(['parent_id' => $root['id']]);
        $this->deleteJson('/api/v1/admin/categories/'.$root['id'])->assertStatus(409);
    }

    public function test_variant_selection_prices_cart_lines_stock_and_order_snapshot(): void
    {
        Queue::fake();
        $product = $this->product();
        [$green, $blue] = $product['variants'];
        $this->postJson('/api/v1/cart/items', ['product_id' => $product['id'], 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $this->withHeader('X-Cart-Token', 'variant-cart');
        $this->postJson('/api/v1/cart/items', ['product_id' => $product['id'], 'variant_id' => $green['id'], 'quantity' => 2])->assertCreated()->assertJsonPath('data.items.0.unit_price', 300000);
        $cart = $this->postJson('/api/v1/cart/items', ['product_id' => $product['id'], 'variant_id' => $blue['id'], 'quantity' => 1])->assertCreated()->assertJsonCount(2, 'data.items')->json('data');
        $this->postJson('/api/v1/cart/items', ['product_id' => $product['id'], 'variant_id' => $green['id'], 'quantity' => 2])->assertStatus(422);
        $this->patchJson('/api/v1/cart/items/'.$cart['items'][0]['id'], ['quantity' => 4])->assertStatus(422);
        $payload = ['customer_name' => 'Test', 'phone' => '09120000000', 'address' => 'Tehran', 'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product['id'], 'variant_id' => $green['id'], 'quantity' => 2], ['product_id' => $product['id'], 'variant_id' => $blue['id'], 'quantity' => 1]]];
        $order = $this->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('data.subtotal', 1000000)->assertJsonPath('data.items.0.unit_price', 300000)->assertJsonPath('data.items.0.selected_attributes.رنگ', 'سبز')->json('data');
        $this->assertDatabaseHas('product_variants', ['id' => $green['id'], 'stock' => 1]);
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'stock' => 5]);
        $green['price'] = 900000;
        $this->patchJson('/api/v1/admin/products/'.$product['id'], ['variants' => [$green, $blue]])->assertUnprocessable(); // response-only fields are not writable
        $this->patchJson('/api/v1/admin/products/'.$product['id'], ['variants' => [
            ['id' => $green['id'], 'sku' => $green['sku'], 'attributes' => $green['attributes'], 'price' => 900000, 'stock' => 1],
        ]])->assertOk();
        $this->getJson('/api/v1/orders/'.$order['public_id'])->assertOk()->assertJsonPath('data.items.0.unit_price', 300000)->assertJsonPath('data.items.1.selected_attributes.سایز', 'L');
        $this->postJson('/api/v1/cart/items', ['product_id' => $product['id'], 'variant_id' => $blue['id'], 'quantity' => 1])->assertUnprocessable();
    }

    public function test_duplicate_order_lines_cannot_oversell_a_variant(): void
    {
        Queue::fake();
        $product = $this->product();
        $item = ['product_id' => $product['id'], 'variant_id' => $product['variants'][0]['id'], 'quantity' => 2];
        $this->postJson('/api/v1/orders', ['customer_name' => 'Test', 'phone' => '09120000000', 'address' => 'Test', 'payment_method' => 'online', 'items' => [$item, $item]])->assertUnprocessable();
        $this->assertDatabaseHas('product_variants', ['id' => $item['variant_id'], 'stock' => 3]);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_foreign_variant_and_duplicate_combinations_are_rejected_atomically(): void
    {
        $product = $this->product();
        $other = Product::create(['category_id' => $product['category_id'], 'name' => 'Other', 'slug' => 'other', 'sku' => 'OTHER', 'price' => 500, 'stock' => 2, 'is_active' => true]);
        $variant = $product['variants'][0];
        $this->postJson('/api/v1/cart/items', ['product_id' => $other->id, 'variant_id' => $variant['id'], 'quantity' => 1])->assertUnprocessable();
        $data = ['sku' => 'NEW', 'attributes' => ['رنگ' => 'سبز', 'سایز' => 'XL'], 'price' => 5, 'stock' => 2];
        $this->patchJson('/api/v1/admin/products/'.$other->id, ['name' => 'Changed', 'variants' => [$data + ['id' => $variant['id']]]])->assertUnprocessable();
        $this->assertDatabaseHas('products', ['id' => $other->id, 'name' => 'Other']);
        $this->patchJson('/api/v1/admin/products/'.$other->id, ['variants' => [$data, array_merge($data, ['sku' => 'NEW2'])]])->assertUnprocessable();
        $this->assertDatabaseMissing('product_variants', ['sku' => 'NEW']);
    }

    public function test_site_badges_are_admin_managed_and_only_active_badges_are_public(): void
    {
        $this->postJson('/api/v1/admin/site-badges', [])->assertUnauthorized();
        $this->admin();
        $badge = $this->postJson('/api/v1/admin/site-badges', ['title' => 'ISO 9001', 'image_url' => 'https://example.com/iso.png', 'link_url' => 'https://example.com/verify', 'is_active' => true])->assertCreated()->json('data');
        $this->getJson('/api/v1/site-badges')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson('/api/v1/admin/site-badges/'.$badge['id'], ['title' => 'ISO 9001', 'image_url' => 'https://example.com/iso.png', 'is_active' => false])->assertOk();
        $this->getJson('/api/v1/site-badges')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/admin/site-badges', ['title' => 'Bad', 'image_url' => 'javascript:alert(1)'])->assertUnprocessable();
        $this->deleteJson('/api/v1/admin/site-badges/'.$badge['id'])->assertNoContent();
    }
}
