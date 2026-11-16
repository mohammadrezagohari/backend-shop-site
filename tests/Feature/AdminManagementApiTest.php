<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Ordering\Domain\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_catalog_orders_and_user_roles(): void
    {
        $admin = User::factory()->create(['password' => 'password', 'is_admin' => true]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'password'])->json('data.token');
        $this->withToken($token);

        $categoryId = $this->postJson('/api/v1/admin/categories', [
            'name' => 'Supplements', 'slug' => 'supplements', 'is_active' => true,
        ])->assertCreated()->json('data.id');

        $productId = $this->postJson('/api/v1/admin/products', [
            'category_id' => $categoryId, 'name' => 'Creatine', 'slug' => 'creatine',
            'sku' => 'CR-1', 'price' => 100000, 'stock' => 8, 'is_active' => true,
            'specifications' => [['label' => 'Brand', 'value' => 'Golden Aid']],
            'additional_description' => 'More information', 'usage' => 'Use once daily',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/admin/products/{$productId}", ['price' => 120000])
            ->assertOk()->assertJsonPath('data.price', 120000);
        $this->getJson('/api/v1/products/creatine')->assertOk()
            ->assertJsonPath('data.specifications.0.label', 'Brand')
            ->assertJsonPath('data.usage', 'Use once daily');

        $order = Order::query()->create([
            'public_id' => (string) str()->ulid(), 'customer_name' => 'Customer', 'phone' => '09120000000',
            'address' => 'Address', 'status' => 'pending', 'subtotal' => 120000, 'shipping' => 0, 'total' => 120000,
        ]);
        $this->patchJson("/api/v1/admin/orders/{$order->id}", ['status' => 'processing'])
            ->assertOk()->assertJsonPath('data.status', 'processing');

        $customer = User::factory()->create(['is_admin' => false]);
        $this->patchJson("/api/v1/admin/users/{$customer->id}", ['is_admin' => true])
            ->assertOk()->assertJsonPath('data.is_admin', true);
        $this->patchJson("/api/v1/admin/users/{$admin->id}", ['is_admin' => false])->assertUnprocessable();

        $postId = $this->postJson('/api/v1/admin/posts', [
            'title' => 'Health Guide', 'slug' => 'health-guide', 'excerpt' => 'Short guide',
            'body' => 'Complete article', 'published_at' => now()->toISOString(),
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/admin/posts/{$postId}", [
            'title' => 'Updated Guide', 'slug' => 'health-guide', 'excerpt' => 'Short guide',
            'body' => 'Complete article', 'published_at' => now()->toISOString(),
        ])->assertOk()->assertJsonPath('data.title', 'Updated Guide');

        $commentId = $this->postJson('/api/v1/products/creatine/comments', [
            'name' => 'Customer', 'rating' => 5, 'body' => 'Great product',
        ])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/admin/comments')->assertOk()->assertJsonPath('data.0.product.slug', 'creatine');
        $this->patchJson("/api/v1/admin/comments/{$commentId}", ['is_approved' => true])
            ->assertOk()->assertJsonPath('data.is_approved', true);
        $this->deleteJson("/api/v1/admin/comments/{$commentId}")->assertNoContent();
        $this->deleteJson("/api/v1/admin/posts/{$postId}")->assertNoContent();

        $this->deleteJson("/api/v1/admin/products/{$productId}")->assertNoContent();
        $this->deleteJson("/api/v1/admin/categories/{$categoryId}")->assertNoContent();
    }
}
