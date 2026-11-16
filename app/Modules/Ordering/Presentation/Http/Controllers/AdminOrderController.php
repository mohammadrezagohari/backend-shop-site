<?php

namespace App\Modules\Ordering\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ordering\Domain\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

final class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        return response()->json(Order::query()
            ->with([
                'user:id,name,email',
                'items.product:id,name,slug,sku,stock,is_active,price,image',
                'items.variant',
            ])
            ->latest('id')
            ->paginate($perPage));
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $order->load([
            'user:id,name,email',
            'items.product:id,name,slug,sku,stock,is_active,price,image',
            'items.variant',
        ])]);
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['pending', 'paid', 'processing', 'shipped', 'delivered', 'cancelled'])]]);
        $order->update($data);
        Cache::forget('reporting:dashboard');

        return response()->json(['data' => $order->refresh()->load([
            'user:id,name,email',
            'items.product:id,name,slug,sku,stock,is_active,price,image',
            'items.variant',
        ])]);
    }
}
