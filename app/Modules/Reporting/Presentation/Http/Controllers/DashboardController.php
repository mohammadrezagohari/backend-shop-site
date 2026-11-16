<?php

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Ordering\Domain\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

final class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $metrics = Cache::remember('reporting:dashboard', now()->addMinute(), fn () => [
            'products' => Product::query()->count(),
            'low_stock' => Product::query()->where('stock', '<', 10)->count(),
            'orders' => Order::query()->count(),
            'revenue' => (int) Order::query()->whereIn('status', ['paid', 'processing', 'shipped', 'delivered'])->sum('total'),
            'latest_orders' => Order::query()
                ->with(['user:id,name,email', 'items.product:id,name,slug,sku,stock,is_active,price,image', 'items.variant'])
                ->latest()->limit(6)->get()->toArray(),
        ]);

        return response()->json(['data' => $metrics]);
    }
}
