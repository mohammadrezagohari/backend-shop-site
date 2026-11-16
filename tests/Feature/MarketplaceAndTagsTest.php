<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Domain\Models\Category;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Content\Domain\Models\Post;
use App\Modules\Marketplaces\Application\MarketplaceCatalog;
use App\Modules\Marketplaces\Domain\Models\MarketplaceListing;
use App\Modules\Marketplaces\Jobs\SyncProductListing;
use Database\Seeders\CatalogEnrichmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MarketplaceAndTagsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $user->apiTokens()->create(['name' => 'test', 'token_hash' => hash('sha256', 'marketplace-token'), 'expires_at' => now()->addDay()]);
        $this->withToken('marketplace-token');
    }

    private function product(): Product
    {
        $category = Category::firstOrCreate(['slug' => 'clothes'], ['name' => 'پوشاک']);
        return Product::create(['category_id' => $category->id, 'name' => 'Shirt', 'slug' => 'shirt', 'sku' => 'SHIRT', 'price' => 300000, 'stock' => 4, 'is_active' => true, 'tags' => ['پوشاک', 'سبز']]);
    }

    public function test_enrichment_is_repeatable_and_preserves_existing_tags(): void
    {
        $product = $this->product();
        $post = Post::create(['title' => 'راهنما', 'slug' => 'guide', 'excerpt' => 'خلاصه', 'body' => 'متن', 'published_at' => now()]);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(1, 'data');
        $this->seed(CatalogEnrichmentSeeder::class);
        $this->seed(CatalogEnrichmentSeeder::class);
        $this->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('categories', 2);
        $this->assertSame(['پوشاک', 'سبز'], $product->fresh()->tags);
        $this->assertSame(['راهنما'], $post->fresh()->tags);
    }

    public function test_product_and_post_tag_filters_and_admin_tag_validation(): void
    {
        $product = $this->product();
        Post::create(['title' => 'راهنما', 'slug' => 'guide', 'excerpt' => 'خلاصه', 'body' => 'متن', 'published_at' => now(), 'tags' => ['آموزش']]);
        $this->getJson('/api/v1/products?tag='.urlencode('سبز'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/products?tag=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/posts?tag='.urlencode('آموزش'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/posts?tag=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->admin();
        $this->patchJson('/api/v1/admin/products/'.$product->id, ['tags' => ['x', 'x']])->assertUnprocessable();
        $this->patchJson('/api/v1/admin/products/'.$product->id, ['tags' => ['جدید']])->assertOk()->assertJsonPath('data.tags.0', 'جدید');
    }

    public function test_marketplace_settings_are_admin_only_and_do_not_expose_credentials(): void
    {
        $product = $this->product();
        $this->getJson('/api/v1/admin/marketplaces')->assertUnauthorized();
        $this->admin();
        config(['marketplaces.basalam.access_token' => 'secret-token', 'marketplaces.auto_sync' => false]);
        $this->patchJson('/api/v1/admin/products/'.$product->id, ['marketplaces' => [['channel' => 'basalam', 'enabled' => true, 'settings' => ['category_id' => 123, 'photo_id' => 456]]]])
            ->assertOk()->assertJsonPath('data.marketplace_listings.0.settings.category_id', 123);
        $public = $this->getJson('/api/v1/products/shirt')->assertOk();
        $public->assertJsonMissingPath('data.marketplace_listings');
        $this->assertStringNotContainsString('secret-token', $this->getJson('/api/v1/admin/marketplaces')->assertOk()->getContent());
        $this->patchJson('/api/v1/admin/products/'.$product->id, ['marketplaces' => [['channel' => 'bad', 'enabled' => true]]])->assertUnprocessable();
    }

    public function test_auto_sync_queues_enabled_channels_and_development_never_publishes(): void
    {
        Queue::fake(); Http::preventStrayRequests();
        $product = $this->product();
        config(['marketplaces.auto_sync' => true, 'marketplaces.basalam.enabled' => true, 'app.env' => 'develop']);
        app(MarketplaceCatalog::class)->queue($product);
        Queue::assertPushed(SyncProductListing::class);
        $listing = $product->marketplaceListings()->firstOrFail();
        (new SyncProductListing($listing->id))->handle();
        $this->assertSame('simulated', $listing->fresh()->status);
        Http::assertNothingSent();
    }

    private function listing(): MarketplaceListing
    {
        config(['app.env' => 'production', 'marketplaces.basalam.enabled' => true,
            'marketplaces.basalam.access_token' => 'secret-token', 'marketplaces.basalam.vendor_id' => 22]);
        return $this->product()->marketplaceListings()->create(['channel' => 'basalam', 'settings' => ['category_id' => 123, 'photo_id' => 456]]);
    }

    public function test_basalam_create_is_recorded_as_submitted_and_next_call_updates_same_external_id(): void
    {
        $listing = $this->listing();
        Http::fake(['openapi.basalam.com/*' => Http::response(['id' => 789])]);
        (new SyncProductListing($listing->id))->handle();
        $this->assertSame('submitted', $listing->fresh()->status);
        $this->assertSame('789', $listing->fresh()->external_id);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://openapi.basalam.com/v1/vendors/22/products' && $r['primary_price'] === 3000000);
        (new SyncProductListing($listing->id))->handle();
        Http::assertSentCount(1);
        Product::findOrFail($listing->product_id)->update(['stock' => 2]);
        (new SyncProductListing($listing->id))->handle();
        Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r->url() === 'https://openapi.basalam.com/v1/products/789' && $r['stock'] === 2);
    }

    public function test_ambiguous_create_does_not_retry_or_lose_uncertainty_on_product_save(): void
    {
        $listing = $this->listing();
        Http::fake(['openapi.basalam.com/*' => Http::response([], 503)]);
        (new SyncProductListing($listing->id))->handle();
        $this->assertSame('uncertain', $listing->fresh()->status);
        app(MarketplaceCatalog::class)->save(Product::findOrFail($listing->product_id), [['channel' => 'basalam', 'enabled' => true, 'settings' => ['category_id' => 123, 'photo_id' => 456]]]);
        (new SyncProductListing($listing->id))->handle();
        Http::assertSentCount(1);
        $this->assertSame('uncertain', $listing->fresh()->status);
    }

    public function test_digikala_requires_a_verified_publishing_contract(): void
    {
        Http::preventStrayRequests();
        config(['app.env' => 'production', 'marketplaces.digikala.enabled' => true]);
        $listing = $this->product()->marketplaceListings()->create(['channel' => 'digikala']);
        (new SyncProductListing($listing->id))->handle();
        $this->assertSame('needs_configuration', $listing->fresh()->status);
        Http::assertNothingSent();
    }

    private function torobHeaders(array $claims = []): array
    {
        $pair = sodium_crypto_sign_keypair();
        config(['marketplaces.torob.enabled' => true, 'marketplaces.torob.public_key' => base64_encode(sodium_crypto_sign_publickey($pair)), 'app.url' => 'https://api.example.com']);
        $encode = fn ($value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $body = $encode(json_encode(['alg' => 'EdDSA', 'typ' => 'JWT'])).'.'.$encode(json_encode($claims + ['aud' => 'api.example.com', 'exp' => time() + 60, 'nbf' => time() - 5]));
        $jwt = $body.'.'.$encode(sodium_crypto_sign_detached($body, sodium_crypto_sign_secretkey($pair)));
        return ['X-Torob-Token' => $jwt, 'X-Torob-Token-Version' => '1'];
    }

    public function test_torob_feed_requires_valid_signature_claims_and_request_shape(): void
    {
        $this->product();
        $this->postJson('/api/v1/marketplaces/torob/products', ['page' => 1, 'sort' => 'date_added_desc'])->assertNotFound();
        $headers = $this->torobHeaders();
        $this->postJson('/api/v1/marketplaces/torob/products', ['page' => 1, 'sort' => 'date_added_desc'])->assertUnauthorized();
        $this->postJson('/api/v1/marketplaces/torob/products', ['page' => 1, 'sort' => 'date_added_desc'], $headers)
            ->assertOk()->assertJsonPath('api_version', 'torob_api_v3')->assertJsonPath('products.0.current_price', 300000);
        $this->postJson('/api/v1/marketplaces/torob/products', [], $headers)->assertStatus(400);
        $this->postJson('/api/v1/marketplaces/torob/products', ['sort' => 'product_id_desc'], $headers)->assertOk()->assertJsonPath('current_page', null)->assertJsonPath('next_cursor', null);
        $this->postJson('/api/v1/marketplaces/torob/products', ['sort' => 'product_id_desc', 'cursor' => 'invalid'], $headers)->assertStatus(400);
        $this->postJson('/api/v1/marketplaces/torob/products', ['page' => 1, 'sort' => 'date_added_desc'], $this->torobHeaders(['aud' => 'other.example.com']))->assertUnauthorized();
        $this->postJson('/api/v1/marketplaces/torob/products', ['page' => 1, 'sort' => 'date_added_desc'], $this->torobHeaders(['exp' => time() - 1]))->assertUnauthorized();
    }
}
