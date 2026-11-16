<?php

namespace App\Modules\Ordering\Application;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ZarinpalGateway
{
    public function request(int $amount, string $callback, string $orderId): string
    {
        $data = $this->call('request', [
            'amount' => $amount,
            'callback_url' => $callback,
            'description' => 'Golden AID order '.$orderId,
        ]);
        abort_unless((int) ($data['code'] ?? 0) === 100 && is_string($data['authority'] ?? null)
            && preg_match('/^[A-Za-z0-9]{10,100}$/', $data['authority']), 502, 'ایجاد پرداخت در زرین‌پال انجام نشد.');

        return $data['authority'];
    }

    public function verify(int $amount, string $authority): string
    {
        $data = $this->call('verify', ['amount' => $amount, 'authority' => $authority]);
        abort_unless(in_array((int) ($data['code'] ?? 0), [100, 101], true)
            && ! empty($data['ref_id']), 422, 'پرداخت توسط زرین‌پال تأیید نشد.');

        return (string) $data['ref_id'];
    }

    private function call(string $method, array $payload): array
    {
        $merchant = config('integrations.zarinpal.merchant_id');
        abort_unless(is_string($merchant) && $merchant !== '', 503, 'تنظیمات درگاه پرداخت کامل نیست.');
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->post(
                'https://api.zarinpal.com/pg/v4/payment/'.$method.'.json',
                ['merchant_id' => $merchant] + $payload,
            );
        } catch (ConnectionException) {
            abort(503, 'ارتباط با درگاه پرداخت برقرار نشد؛ دوباره تلاش کنید.');
        }
        abort_unless($response->successful(), 502, 'پاسخ معتبر از درگاه پرداخت دریافت نشد.');

        return is_array($response->json('data')) ? $response->json('data') : [];
    }
}
