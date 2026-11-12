<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PaymentGateway
{
    private function ready(): void
    {
        if (! config('platform.zibal.enabled') || ! config('platform.zibal.merchant')) {
            throw ValidationException::withMessages(['payment' => 'درگاه پرداخت هنوز فعال نشده است.']);
        }
    }

    public function start(Payment $payment): string
    {
        $this->ready();
        $response = Http::timeout(15)->post('https://gateway.zibal.ir/v1/request', [
            'merchant' => config('platform.zibal.merchant'), 'amount' => $payment->amount,
            'callbackUrl' => route($payment->organization_invoice_id ? 'organization-payments.callback' : ($payment->consultation_id ? 'consultation-payments.callback' : 'payments.callback')), 'orderId' => (string) $payment->id, 'description' => $payment->organization_invoice_id ? 'صورتحساب سازمانی کاردرست' : ($payment->consultation_id ? 'مشاوره کاردرست' : 'ثبت‌نام کاردرست'),
        ]);
        $track = $response->json('trackId');
        if (! $response->successful() || $response->json('result') !== 100 || ! (is_string($track) || is_int($track)) || (string) $track === '' || strlen((string) $track) > 100) {
            throw ValidationException::withMessages(['payment' => 'اتصال به درگاه انجام نشد. دوباره تلاش کنید.']);
        }
        $payment->update(['track_id' => (string) $track]);

        return 'https://gateway.zibal.ir/start/'.rawurlencode((string) $track);
    }

    public function verify(Payment $payment): ?string
    {
        $this->ready();
        $response = Http::timeout(15)->post('https://gateway.zibal.ir/v1/verify', ['merchant' => config('platform.zibal.merchant'), 'trackId' => $payment->track_id]);
        if ($response->successful() && $response->json('result') === 201) {
            $response = Http::timeout(15)->post('https://gateway.zibal.ir/v1/inquiry', ['merchant' => config('platform.zibal.merchant'), 'trackId' => $payment->track_id]);
        }
        if (! $response->successful() || $response->json('result') !== 100 || ! in_array($response->json('status'), [1, 2], true) || filter_var($response->json('amount'), FILTER_VALIDATE_INT) !== (int) $payment->amount || ! is_scalar($response->json('orderId')) || (string) $response->json('orderId') !== (string) $payment->id || ! is_scalar($response->json('refNumber')) || (string) $response->json('refNumber') === '' || strlen((string) $response->json('refNumber')) > 100) {
            return null;
        }

        return (string) $response->json('refNumber');
    }
}
