<?php

namespace App\Modules\Marketplaces\Jobs;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Marketplaces\Domain\Models\MarketplaceListing;
use App\Support\RuntimeEnvironment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class SyncProductListing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public readonly int $listingId)
    {
        $this->onQueue('marketplaces');
    }

    public function handle(): void
    {
        $shouldSend = DB::transaction(function (): bool {
            $listing = MarketplaceListing::query()->lockForUpdate()->find($this->listingId);
            if (! $listing || ! $listing->enabled || ! config("marketplaces.{$listing->channel}.enabled")) { return false; }
            $product = Product::query()->with(['variants' => fn ($q) => $q->where('is_active', true)])->find($listing->product_id);
            if (! $product) { return false; }
            $payload = ['product' => $product->toArray(), 'settings' => $listing->settings ?? [], 'external_id' => $listing->external_id];
            $hash = hash('sha256', json_encode($payload));
            if ($listing->payload_hash === $hash && in_array($listing->status, ['submitted', 'feed_ready'], true)) { return false; }
            if ($listing->status === 'uncertain' && ! $listing->external_id) {
                $listing->update(['last_error' => 'نتیجه درخواست قبلی نامشخص است؛ ابتدا شناسه خارجی را با پلتفرم تطبیق دهید.']);
                return false;
            }
            if ($listing->channel === 'torob') {
                $listing->update(['status' => 'feed_ready', 'last_error' => 'آماده دریافت توسط ترب؛ به معنی نمایش یا تأیید در ترب نیست.', 'payload_hash' => $hash]);
                return false;
            }
            if (RuntimeEnvironment::isDevelopment()) {
                $listing->update(['status' => 'simulated', 'last_error' => 'شبیه‌سازی توسعه؛ هیچ محصولی در پلتفرم منتشر نشده است.']);
                return false;
            }
            if (! RuntimeEnvironment::isProduction()) { $this->blocked($listing, 'محیط برنامه معتبر نیست.'); return false; }
            if ($listing->channel === 'digikala') {
                $this->blocked($listing, 'اتصال دیجی‌کالا به قرارداد API حساب فروشندگی و آداپتور اختصاصی نیاز دارد.');
                return false;
            }
            // A process killed during the outbound request leaves a durable uncertain marker.
            // Commit this marker before any non-idempotent external creation.
            $listing->update(['status' => 'uncertain', 'last_error' => 'درخواست در حال ارسال است؛ در قطع فرایند نتیجه باید تطبیق داده شود.']);
            return true;
        });
        if (! $shouldSend) { return; }
        $listing = MarketplaceListing::find($this->listingId);
        if (! $listing || $listing->status !== 'uncertain') { return; }
        // The committed uncertain marker prevents automatic duplicate creates.
        $this->send($listing);
    }

    private function send(MarketplaceListing $listing): void
    {
        $product = Product::query()->with(['variants' => fn ($q) => $q->where('is_active', true)])->find($listing->product_id);
        if (! $product) { return; }
        $settings = $listing->settings ?? [];
        $channel = $listing->channel;
        if ($channel === 'basalam') {
            $token = config('marketplaces.basalam.access_token');
            $vendor = config('marketplaces.basalam.vendor_id');
            if (! $token || ! $vendor || ! ($settings['category_id'] ?? null) || ! ($settings['photo_id'] ?? null)) {
                $this->blocked($listing, 'توکن، شناسه غرفه، دسته‌بندی باسلام و شناسه تصویر آپلودشده لازم است.'); return;
            }
            if ($product->has_variants) {
                $this->blocked($listing, 'انتشار محصول متنوع باسلام نیازمند تکمیل نگاشت ویژگی‌ها و ارسال نسخه سازگار با دسته‌بندی غرفه است.'); return;
            }
            $multiplier = (int) config('marketplaces.basalam.amount_multiplier');
            if (! in_array($multiplier, [1, 10], true)) { $this->blocked($listing, 'ضریب واحد قیمت باسلام باید ۱ یا ۱۰ باشد.'); return; }
            $payload = [
                'name' => $product->name, 'sku' => $product->sku, 'category_id' => $settings['category_id'],
                'photo' => $settings['photo_id'], 'description' => $product->description ?? $product->summary ?? $product->name,
                'brief' => $product->summary, 'primary_price' => (int) $product->price * $multiplier,
                'stock' => $product->stock, 'status' => $product->is_active ? 2976 : 3790,
                'preparation_days' => $settings['preparation_days'] ?? 1, 'keywords' => $product->tags ?? [],
                'weight' => $settings['weight'] ?? null, 'package_weight' => $settings['package_weight'] ?? null,
            ];
            $url = $listing->external_id ? 'https://openapi.basalam.com/v1/products/'.rawurlencode($listing->external_id)
                : 'https://openapi.basalam.com/v1/vendors/'.rawurlencode((string) $vendor).'/products';
            $method = $listing->external_id ? 'patch' : 'post';
        } elseif ($channel === 'custom') {
            $url = config('marketplaces.custom.url'); $token = config('marketplaces.custom.token'); $method = 'post';
            if (! $token || ! is_string($url) || ! str_starts_with($url, 'https://')) {
                $this->blocked($listing, 'آدرس HTTPS و توکن واسط اتصال لازم است.'); return;
            }
            $payload = ['schema_version' => 1, 'external_id' => $listing->external_id, 'settings' => $settings,
                'product' => $product->toArray(), 'price_unit' => 'toman'];
        } else { $this->blocked($listing, 'آداپتور پلتفرم موجود نیست.'); return; }

        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false])
                ->withHeader('Idempotency-Key', 'listing-'.$listing->id.'-'.hash('sha256', json_encode($payload)))
                ->{$method}($url, $payload);
        } catch (ConnectionException) {
            $listing->update(['status' => 'uncertain', 'last_error' => 'ارتباط قطع شد؛ پیش از تلاش مجدد، نتیجه را در حساب فروشندگی تطبیق دهید.']); return;
        }
        if (! $response->successful()) {
            $listing->update(['status' => $response->serverError() ? 'uncertain' : 'failed', 'last_error' => 'خطای HTTP '.$response->status().' از پلتفرم؛ جزئیات در پنل فروشندگی بررسی شود.']); return;
        }
        $id = $channel === 'custom' ? $response->json('external_id') : $response->json('id');
        $id ??= $listing->external_id;
        if (! is_scalar($id) || (string) $id === '') {
            $listing->update(['status' => 'uncertain', 'last_error' => 'پاسخ بدون شناسه بود؛ پیش از تلاش مجدد شناسه خارجی را تطبیق دهید.']); return;
        }
        $listing->update(['external_id' => (string) $id, 'status' => 'submitted', 'last_error' => null, 'synced_at' => now()]);
        // Recompute using the now-persisted external ID for subsequent identical saves.
        $listing->update(['payload_hash' => hash('sha256', json_encode(['product' => $product->toArray(), 'settings' => $settings, 'external_id' => (string) $id]))]);
    }

    private function blocked(MarketplaceListing $listing, string $message): void
    {
        $listing->update(['status' => 'needs_configuration', 'last_error' => $message]);
    }
}
