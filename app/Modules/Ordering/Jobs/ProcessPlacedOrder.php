<?php

namespace App\Modules\Ordering\Jobs;

use App\Modules\Ordering\Domain\Models\Order;
use App\Modules\Ordering\Application\OrderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class ProcessPlacedOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $orderId)
    {
        $this->onConnection('redis')->onQueue('orders');
    }

    public function handle(OrderNotification $notification): void
    {
        DB::transaction(function () use ($notification): void {
            $order = Order::query()->lockForUpdate()->findOrFail($this->orderId);
            if ($order->notification_sent_at) {
                return;
            }
            $notification->send($order);
            $order->forceFill(['notification_sent_at' => now()])->save();
        });
    }
}
