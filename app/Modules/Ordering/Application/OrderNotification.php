<?php

namespace App\Modules\Ordering\Application;

use App\Modules\Ordering\Domain\Models\Order;
use App\Support\RuntimeEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OrderNotification
{
    public function send(Order $order): void
    {
        if (RuntimeEnvironment::isDevelopment()) {
            Log::info('Development: order SMS simulated.', ['order' => $order->public_id]);

            return;
        }
        if (! RuntimeEnvironment::isProduction()) {
            throw new RuntimeException('Invalid application environment.');
        }
        $key = config('integrations.kavenegar.api_key');
        $sender = config('integrations.kavenegar.sender');
        if (! $key || ! $sender) {
            throw new RuntimeException('Kavenegar credentials are not configured.');
        }
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->post(
                'https://api.kavenegar.com/v1/'.rawurlencode($key).'/sms/send.json',
                ['sender' => $sender, 'receptor' => $order->phone,
                    'message' => 'گلدن اید: سفارش شما با کد '.$order->public_id.' ثبت شد.'],
            );
        } catch (ConnectionException) {
            // Do not include the request URL: it contains the API key.
            throw new RuntimeException('Kavenegar connection failed.');
        }
        if (! $response->successful() || (int) $response->json('return.status') !== 200
            || (int) $response->json('entries.0.messageid') <= 0) {
            throw new RuntimeException('Kavenegar rejected the notification.');
        }
    }
}
