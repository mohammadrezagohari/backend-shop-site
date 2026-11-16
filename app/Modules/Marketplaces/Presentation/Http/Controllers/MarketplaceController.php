<?php

namespace App\Modules\Marketplaces\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Marketplaces\Application\MarketplaceCatalog;
use Illuminate\Http\JsonResponse;

final class MarketplaceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['auto_sync' => config('marketplaces.auto_sync'), 'data' => collect(MarketplaceCatalog::CHANNELS)
            ->map(fn ($name, $channel) => [
                'channel' => $channel, 'name' => $channel === 'custom' ? config('marketplaces.custom.name') : $name,
                'enabled' => (bool) config("marketplaces.$channel.enabled"),
                'fee_model' => config("marketplaces.$channel.fee_model"),
                'commission_percent' => config("marketplaces.$channel.commission_percent"),
                'driver' => ['torob' => 'pull_feed', 'basalam' => 'api_simple_products', 'digikala' => 'awaiting_contract', 'custom' => 'integration_gateway'][$channel],
            ])->values()]);
    }

    public function sync(Product $product, MarketplaceCatalog $catalog): JsonResponse
    {
        $catalog->queue($product, true);

        return response()->json(['message' => 'درخواست برای اتصال‌های فعال ارسال شد؛ نتیجه در وضعیت هر پلتفرم قابل مشاهده است.', 'data' => $product->marketplaceListings()->get()], 202);
    }
}
