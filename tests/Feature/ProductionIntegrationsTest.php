<?php

namespace Tests\Feature;

use App\Modules\Ordering\Application\OrderNotification;
use App\Modules\Ordering\Domain\Models\Order;
use App\Modules\Ordering\Jobs\ProcessPlacedOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductionIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        return Order::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'customer_name' => 'Test', 'phone' => '09120000000', 'address' => 'Tehran',
            'status' => 'pending', 'payment_method' => 'online', 'payment_status' => 'unpaid',
            'subtotal' => 100000, 'shipping' => 75000, 'total' => 175000,
        ]);
    }

    private function production(): void
    {
        config([
            'app.env' => 'production', 'app.url' => 'https://api.example.com',
            'integrations.site_url' => 'https://example.com',
            'integrations.zarinpal.merchant_id' => 'test-merchant',
            'integrations.kavenegar.api_key' => 'test-key',
            'integrations.kavenegar.sender' => '100000',
        ]);
    }

    public function test_develop_simulates_payment_and_sms_without_external_requests(): void
    {
        Http::preventStrayRequests();
        config(['app.env' => 'develop']);
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->public_id}/payments")
            ->assertOk()->assertJsonPath('data.mode', 'test');
        app(OrderNotification::class)->send($order);
        Http::assertNothingSent();
    }

    public function test_production_disables_both_test_payment_endpoints(): void
    {
        $this->production();
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->public_id}/payments/test")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->public_id}/payments/test/verify", [
            'payment_token' => 'forged', 'outcome' => 'success',
        ])->assertNotFound();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_live_payment_converts_toman_to_rial_and_reuses_pending_attempt(): void
    {
        $this->production();
        Http::fake(['api.zarinpal.com/*' => Http::response(['data' => ['code' => 100, 'authority' => 'A000000000000000000000000000000001']])]);
        $order = $this->order();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/api/v1/orders/{$order->public_id}/payments")
                ->assertOk()->assertJsonPath('data.mode', 'production')
                ->assertJsonPath('data.payment_url', 'https://www.zarinpal.com/pg/StartPay/A000000000000000000000000000000001');
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === 1750000
            && $request['merchant_id'] === 'test-merchant'
            && $request['callback_url'] === 'https://api.example.com/api/v1/payments/zarinpal/callback');
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_missing_credentials_do_not_fall_back_to_fake_payment(): void
    {
        $this->production();
        config(['integrations.zarinpal.merchant_id' => null]);
        Http::preventStrayRequests();
        $order = $this->order();
        $this->postJson("/api/v1/orders/{$order->public_id}/payments")->assertStatus(503);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    private function attempt(Order $order): void
    {
        \Illuminate\Support\Facades\DB::table('payment_attempts')->insert([
            'order_id' => $order->id, 'authority' => 'A000000000000000000000000000000001',
            'amount_rial' => 1750000, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_callback_verifies_stored_amount_and_is_idempotent(): void
    {
        $this->production();
        Http::fake(['api.zarinpal.com/*' => Http::response(['data' => ['code' => 100, 'ref_id' => 123456]])]);
        $order = $this->order();
        $this->attempt($order);
        for ($i = 0; $i < 2; $i++) {
            $this->get('/api/v1/payments/zarinpal/callback?Authority=A000000000000000000000000000000001&Status=OK&amount=1')
                ->assertRedirect('https://example.com/payment/result?order='.$order->public_id);
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === 1750000);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('123456', $order->fresh()->payment_reference);
    }

    public function test_forged_cancelled_and_rejected_callbacks_cannot_mark_order_paid(): void
    {
        $this->production();
        Http::fake(['api.zarinpal.com/*' => Http::response(['data' => ['code' => -21]])]);
        $order = $this->order();
        $this->attempt($order);
        $this->getJson('/api/v1/payments/zarinpal/callback?Authority=forged&Status=OK')->assertNotFound();
        $this->get('/api/v1/payments/zarinpal/callback?Authority=A000000000000000000000000000000001&Status=NOK')->assertRedirect();
        Http::assertNothingSent();
        $this->getJson('/api/v1/payments/zarinpal/callback?Authority=A000000000000000000000000000000001&Status=OK')->assertStatus(422);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_production_sms_uses_kavenegar_and_successful_job_is_not_sent_twice(): void
    {
        $this->production();
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 123]]])]);
        $order = $this->order();
        $job = new ProcessPlacedOrder($order->id);
        $job->handle(app(OrderNotification::class));
        $job->handle(app(OrderNotification::class));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.kavenegar.com/v1/test-key/sms/send.json'
            && $request['receptor'] === $order->phone && $request['sender'] === '100000');
        $this->assertNotNull($order->fresh()->notification_sent_at);
    }

    public function test_sms_failure_is_retryable_and_does_not_record_delivery(): void
    {
        $this->production();
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 403]], 403)]);
        $order = $this->order();
        try {
            (new ProcessPlacedOrder($order->id))->handle(app(OrderNotification::class));
            $this->fail('Expected notification failure');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('test-key', $exception->getMessage());
        }
        $this->assertNull($order->fresh()->notification_sent_at);
    }
}
