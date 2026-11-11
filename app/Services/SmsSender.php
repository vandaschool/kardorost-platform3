<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SmsSender
{
    public function sendCode(string $phone, string $code): void
    {
        if (config('platform.sms.driver') === 'log' && app()->environment('local', 'testing')) {
            Log::channel('single')->info('LOCAL ONLY verification code', ['phone' => $phone, 'code' => $code]);

            return;
        }
        if (config('platform.sms.driver') !== 'kavenegar' || ! config('platform.sms.api_key') || ! config('platform.sms.template')) {
            throw ValidationException::withMessages(['phone' => 'ارسال پیامک هنوز فعال نشده است. لطفاً با پشتیبانی تماس بگیرید.']);
        }
        try {
            $response = Http::timeout(12)->asForm()->post('https://api.kavenegar.com/v1/'.config('platform.sms.api_key').'/verify/lookup.json', [
                'receptor' => '0'.substr($phone, 2), 'token' => $code, 'template' => config('platform.sms.template'),
            ]);
            if (! $response->successful() || $response->json('return.status') !== 200) {
                throw new \RuntimeException('sms failed');
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['phone' => 'ارسال پیامک انجام نشد. کمی بعد دوباره تلاش کنید.']);
        }
    }
}
