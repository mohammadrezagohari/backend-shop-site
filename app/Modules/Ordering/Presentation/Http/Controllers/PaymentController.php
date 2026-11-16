<?php

namespace App\Modules\Ordering\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ordering\Application\ZarinpalGateway;
use App\Modules\Ordering\Domain\Models\Order;
use App\Support\RuntimeEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PaymentController extends Controller
{
    public function start(Order $order, ZarinpalGateway $gateway): JsonResponse
    {
        if (RuntimeEnvironment::isDevelopment()) {
            $data = app(TestPaymentController::class)->start($order)->getData(true)['data'];
            $data['payment_url'] = rtrim(config('integrations.site_url'), '/').'/payment/test?'.http_build_query([
                'order' => $data['order_id'], 'token' => $data['payment_token'],
                'reference' => $data['reference'], 'amount' => $data['amount'],
            ]);

            return response()->json(['data' => $data]);
        }
        abort_unless(RuntimeEnvironment::isProduction(), 503, 'محیط برنامه معتبر نیست.');
        abort_unless(str_starts_with(config('app.url'), 'https://')
            && str_starts_with(config('integrations.site_url'), 'https://'), 503, 'آدرس production باید HTTPS باشد.');

        return DB::transaction(function () use ($order, $gateway): JsonResponse {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->payment_method !== 'online' || $order->payment_status === 'paid'
                || ! in_array($order->status, ['pending'], true)) {
                throw ValidationException::withMessages(['payment' => 'این سفارش قابل پرداخت نیست.']);
            }
            // Reuse an outstanding attempt instead of creating concurrent payable sessions.
            $attempt = DB::table('payment_attempts')->where('order_id', $order->id)->where('status', 'pending')->first();
            if (! $attempt) {
                $amount = (int) $order->total * config('integrations.zarinpal.amount_multiplier');
                $callback = rtrim(config('app.url'), '/').route('payments.zarinpal.callback', [], false);
                $authority = $gateway->request($amount, $callback, $order->public_id);
                DB::table('payment_attempts')->insert([
                    'order_id' => $order->id, 'authority' => $authority, 'amount_rial' => $amount,
                    'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $authority = $attempt->authority;
            }
            $order->forceFill(['payment_status' => 'pending', 'payment_token_hash' => null])->save();

            return response()->json(['data' => [
                'order_id' => $order->public_id, 'amount' => $order->total, 'mode' => 'production',
                'payment_url' => 'https://www.zarinpal.com/pg/StartPay/'.$authority,
            ]]);
        });
    }

    public function callback(Request $request, ZarinpalGateway $gateway): RedirectResponse
    {
        abort_unless(RuntimeEnvironment::isProduction(), 404);
        $data = $request->validate([
            'Authority' => ['required', 'string', 'max:100'],
            'Status' => ['required', 'in:OK,NOK'],
        ]);
        $attempt = DB::table('payment_attempts')->where('authority', $data['Authority'])->first();
        abort_unless($attempt, 404);

        $order = DB::transaction(function () use ($attempt, $data, $gateway): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($attempt->order_id);
            $current = DB::table('payment_attempts')->where('id', $attempt->id)->first();
            if ($current->status === 'paid' || $data['Status'] !== 'OK') {
                return $order;
            }
            // The callback is untrusted. Only provider verification may mark an order paid.
            $reference = $gateway->verify((int) $current->amount_rial, $current->authority);
            DB::table('payment_attempts')->where('id', $attempt->id)->update([
                'status' => 'paid', 'reference' => $reference, 'updated_at' => now(),
            ]);
            $order->forceFill([
                'payment_status' => 'paid', 'status' => $order->status === 'pending' ? 'paid' : $order->status,
                'payment_reference' => $reference, 'paid_at' => $order->paid_at ?? now(), 'payment_token_hash' => null,
            ])->save();
            Cache::forget('reporting:dashboard');

            return $order;
        });

        return redirect()->away(rtrim(config('integrations.site_url'), '/').'/payment/result?'.http_build_query([
            'order' => $order->public_id,
        ]));
    }
}
