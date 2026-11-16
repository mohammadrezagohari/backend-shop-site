<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Ordering\Jobs\ProcessPlacedOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_calculates_total_decrements_stock_and_queues_job(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'تست', 'slug' => 'test']);
        $product = Product::query()->create([
            'category_id' => $category->id, 'name' => 'محصول', 'slug' => 'product',
            'sku' => 'P-1', 'price' => 800000, 'stock' => 5, 'is_active' => true,
        ]);

        $this->postJson('/api/v1/orders', [
            'customer_name' => 'علی رضایی', 'phone' => '09120000000', 'address' => 'تهران',
            'payment_method' => 'online',
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.total', 1600000)
            ->assertJsonPath('data.payment_method', 'online')
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 3]);
        Queue::assertPushed(ProcessPlacedOrder::class);
    }

    public function test_guest_can_track_order_with_public_id_and_matching_phone(): void
    {
        Queue::fake();
        $category = Category::query()->create(['name' => 'تست', 'slug' => 'test']);
        $product = Product::query()->create([
            'category_id' => $category->id, 'name' => 'محصول', 'slug' => 'product',
            'sku' => 'P-TRACK', 'price' => 400000, 'stock' => 5, 'is_active' => true,
        ]);
        $publicId = $this->postJson('/api/v1/orders', [
            'customer_name' => 'مهمان', 'phone' => '09121111111', 'address' => 'تهران',
            'payment_method' => 'cash_on_delivery',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.public_id');

        $this->postJson('/api/v1/orders/track', ['public_id' => $publicId, 'phone' => '09121111111'])
            ->assertOk()->assertJsonPath('data.items.0.product_name', 'محصول');
        $this->postJson('/api/v1/orders/track', ['public_id' => $publicId, 'phone' => '09120000000'])
            ->assertNotFound();
    }

    public function test_order_rejects_an_unknown_payment_method(): void
    {
        $this->postJson('/api/v1/orders', [
            'customer_name' => 'Test', 'phone' => '09120000000', 'address' => 'Tehran',
            'payment_method' => 'crypto',
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }

    public function test_authenticated_order_is_visible_in_customer_account_and_test_payment_can_succeed(): void
    {
        Queue::fake();
        $user = User::factory()->create(['password' => 'password']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data.token');
        $category = Category::query()->create(['name' => 'تست', 'slug' => 'test']);
        $product = Product::query()->create([
            'category_id' => $category->id, 'name' => 'محصول', 'slug' => 'product',
            'sku' => 'P-2', 'price' => 500000, 'stock' => 5, 'is_active' => true,
        ]);

        $publicId = $this->withToken($token)->postJson('/api/v1/orders', [
            'customer_name' => $user->name, 'phone' => '09120000000', 'address' => 'تهران',
            'payment_method' => 'online',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.user_id', $user->id)->json('data.public_id');

        $this->withToken($token)->getJson('/api/v1/orders/mine')
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $publicId)
            ->assertJsonPath('data.0.items.0.product.sku', 'P-2');

        $payment = $this->postJson("/api/v1/orders/{$publicId}/payments/test")
            ->assertOk()
            ->assertJsonPath('data.mode', 'test')
            ->json('data');

        $this->postJson("/api/v1/orders/{$publicId}/payments/test/verify", [
            'payment_token' => $payment['payment_token'],
            'outcome' => 'success',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_status', 'paid');
    }
}
