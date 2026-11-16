<?php

namespace App\Modules\Ordering\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Ordering\Domain\Models\Order;
use App\Support\RuntimeEnvironment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TestPaymentController extends Controller
{
    public function start(Order $order): JsonResponse
    {
        abort_unless(RuntimeEnvironment::isDevelopment(), 404);
        if ($order->payment_method !== 'online') {
            throw ValidationException::withMessages(['payment' => 'این سفارش برای پرداخت آنلاین ثبت نشده است.']);
        }

        if ($order->payment_status === 'paid') {
            throw ValidationException::withMessages(['payment' => 'این سفارش قبلاً پرداخت شده است.']);
        }

        $plainToken = Str::random(64);
        $reference = 'TEST-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
        $order->forceFill([
            'payment_status' => 'pending',
            'payment_reference' => $reference,
            'payment_token_hash' => hash('sha256', $plainToken),
        ])->save();

        return response()->json(['data' => [
            'order_id' => $order->public_id,
            'amount' => $order->total,
            'reference' => $reference,
            'payment_token' => $plainToken,
            'mode' => 'test',
        ]]);
    }

    public function verify(Request $request, Order $order): JsonResponse
    {
        abort_unless(RuntimeEnvironment::isDevelopment(), 404);
        $data = $request->validate([
            'payment_token' => ['required', 'string'],
            'outcome' => ['required', Rule::in(['success', 'failed'])],
        ]);

        $validToken = $order->payment_token_hash
            && hash_equals($order->payment_token_hash, hash('sha256', $data['payment_token']));
        if (! $validToken) {
            throw ValidationException::withMessages(['payment_token' => 'نشست پرداخت معتبر نیست یا منقضی شده است.']);
        }

        $successful = $data['outcome'] === 'success';
        $order->forceFill([
            'payment_status' => $successful ? 'paid' : 'failed',
            'status' => $successful ? 'paid' : 'pending',
            'paid_at' => $successful ? now() : null,
            'payment_token_hash' => null,
        ])->save();
        Cache::forget('reporting:dashboard');

        return response()->json(['data' => $order->fresh()->load('items')]);
    }
}
