<?php

namespace App\Services;

use App\Exceptions\SmsFailure;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SmsSender
{
    public function __construct(private MelipayamakClient $client) {}

    public function sendCode(string $phone, string $code): void
    {
        $phone = Phone::normalize($phone);
        if (! preg_match('/^[0-9]{5}$/D', $code)) {
            throw ValidationException::withMessages(['phone' => 'کد تأیید برای ارسال معتبر نیست.']);
        }
        if (config('platform.sms.driver') === 'log' && app()->environment('local', 'testing')) {
            Log::channel('single')->info('LOCAL ONLY verification code', ['phone' => $phone, 'code' => $code]);

            return;
        }
        try {
            $this->client->send($phone, config('platform.sms.melipayamak.otp_body_id'), [$code]);
        } catch (SmsFailure $failure) {
            throw ValidationException::withMessages(['phone' => $failure->failureCode === 'unconfigured' ? 'ارسال پیامک هنوز فعال نشده است. لطفاً با پشتیبانی تماس بگیرید.' : 'نتیجه ارسال پیامک تأیید نشد. کمی بعد دوباره تلاش کنید.']);
        }
    }

    public function sendInvitation(string $phone, string $organization): string
    {
        return $this->client->send($phone, config('platform.sms.melipayamak.invitation_body_id'), [$organization]);
    }
}
