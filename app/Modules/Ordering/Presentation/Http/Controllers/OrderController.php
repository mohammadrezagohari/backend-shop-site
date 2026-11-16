<?php

namespace App\Modules\Ordering\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ordering\Application\CreateOrder;
use App\Modules\Ordering\Domain\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OrderController extends Controller
{
    public function store(Request $request, CreateOrder $action): JsonResponse
    {
        $payload = $request->validate([
            'customer_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in(['online', 'cash_on_delivery'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'between:1,20'],
        ]);
        $payload['user_id'] = $request->user()?->id;

        return response()->json(['data' => $action->handle($payload)], 201);
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json(['data' => $order->load('items')]);
    }

    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'public_id' => ['required', 'string', 'size:26'],
            'phone' => ['required', 'string', 'max:20'],
        ]);
        $order = Order::query()
            ->where('public_id', $data['public_id'])
            ->where('phone', $data['phone'])
            ->with('items')
            ->firstOrFail();

        return response()->json(['data' => $order]);
    }

    public function mine(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->whereBelongsTo($request->user())
            ->with('items.product:id,name,slug,sku,stock,is_active,image')
            ->latest('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($orders);
    }
}
