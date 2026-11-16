<?php

namespace App\Modules\Ordering\Application;

use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Application\SelectProductVariant;
use App\Modules\Ordering\Domain\Models\Order;
use App\Modules\Ordering\Jobs\ProcessPlacedOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateOrder
{
    public function handle(array $payload): Order
    {
        return DB::transaction(function () use ($payload): Order {
            $ids = collect($payload['items'])->pluck('product_id');
            $products = Product::query()->whereIn('id', $ids)->where('is_active', true)->lockForUpdate()->get()->keyBy('id');
            $subtotal = 0;

            // $currentProduct = Product::query()->where('id', $payload['items'][0]['product_id'])
            // ->where('version', $payload['items'][0]['version'])
            // ->where('stock','>',0)
            // ->update([
            //     'stock' => $payload['items'][0]['quantity'] - 1,
            //     'version' => $payload['items'][0]['version'] + 1,
            // ])


            $lines = collect($payload['items'])->groupBy(fn ($item) => $item['product_id'].':'.($item['variant_id'] ?? 0))
                ->map(fn ($items) => array_merge($items->first(), ['quantity' => $items->sum('quantity')]))->values();
            $selections = [];
            foreach ($lines as $index => $item) {
                $product = $products->get($item['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'محصول قابل خرید نیست.']);
                }
                $variant = app(SelectProductVariant::class)->handle($product, $item['variant_id'] ?? null);
                if ($item['quantity'] > 20 || ($variant?->stock ?? $product->stock) < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => 'موجودی یکی از محصولات کافی نیست.']);
                }
                $selections[$index] = $variant;
                $subtotal += ($variant?->price ?? $product->price) * $item['quantity'];
            }

            $shipping = $subtotal >= 1_500_000 ? 0 : 75_000;
            $order = Order::query()->create([
                'user_id' => $payload['user_id'] ?? null,
                'public_id' => (string) Str::ulid(),
                'customer_name' => $payload['customer_name'],
                'phone' => $payload['phone'],
                'address' => $payload['address'],
                'status' => 'pending',
                'payment_method' => $payload['payment_method'],
                'payment_status' => 'unpaid',
                'subtotal' => $subtotal,
                'shipping' => $shipping,
                'total' => $subtotal + $shipping,
            ]);

            foreach ($lines as $index => $item) {
                $product = $products->get($item['product_id']);
                $variant = $selections[$index];
                $price = $variant?->price ?? $product->price;
                $quantity = (int) $item['quantity'];
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'variant_id' => $variant?->id,
                    'variant_sku' => $variant?->sku,
                    'selected_attributes' => $variant?->attributes,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'total' => $price * $quantity,
                ]);
                $variant?->decrement('stock', $quantity);
                $product->decrement('stock', $quantity);
            }

            ProcessPlacedOrder::dispatch($order->id)->afterCommit();
            DB::afterCommit(function () use ($products): void {
                \App\Modules\Catalog\Application\InvalidateCatalog::handle();
                foreach ($products as $product) {
                    app(\App\Modules\Marketplaces\Application\MarketplaceCatalog::class)->queue($product);
                }
            });

            return $order->load('items');
        });
    }
}
