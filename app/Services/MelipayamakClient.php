<?php

namespace App\Services;

use App\Exceptions\SmsFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MelipayamakClient
{
    public function configured(mixed $bodyId): bool
    {
        $mode = config('platform.sms.melipayamak.mode');
        $key = config('platform.sms.melipayamak.api_key');
        $username = config('platform.sms.melipayamak.username');

        return config('platform.sms.driver') === 'melipayamak' && in_array($mode, ['rest', 'console'], true)
            && is_string($key) && trim($key) !== '' && strlen($key) <= 200
            && ($mode === 'rest' ? is_string($username) && trim($username) !== '' : (bool) preg_match('/^[A-Za-z0-9_-]{8,200}$/D', $key))
            && (is_int($bodyId) || is_string($bodyId)) && (bool) preg_match('/^[1-9][0-9]{0,9}$/D', (string) $bodyId) && (int) $bodyId <= 2147483647;
    }

    public function send(string $phone, mixed $bodyId, array $arguments): string
    {
        if (! $this->configured($bodyId)) {
            throw new SmsFailure('unconfigured');
        }
        $phone = '0'.substr(Phone::normalize($phone), 2);
        foreach ($arguments as $argument) {
            if (! is_string($argument) || trim($argument) === '' || mb_strlen($argument) > 150 || preg_match('/[;\x00-\x1F\x7F]/u', $argument)) {
                throw new SmsFailure('invalid_arguments');
            }
        }
        if (! $arguments || ! array_is_list($arguments)) {
            throw new SmsFailure('invalid_arguments');
        }
        $mode = config('platform.sms.melipayamak.mode');
        try {
            $request = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->acceptJson();
            if ($mode === 'rest') {
                $response = $request->asForm()->post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', ['username' => config('platform.sms.melipayamak.username'), 'password' => config('platform.sms.melipayamak.api_key'), 'to' => $phone, 'text' => implode(';', $arguments), 'bodyId' => (int) $bodyId]);
            } else {
                $response = $request->post('https://console.melipayamak.com/api/send/shared/'.rawurlencode(config('platform.sms.melipayamak.api_key')), ['bodyId' => (int) $bodyId, 'to' => $phone, 'args' => $arguments]);
            }
        } catch (ConnectionException) {
            throw new SmsFailure('transport_unknown', true);
        } catch (\Throwable) {
            throw new SmsFailure('transport_unknown', true);
        }
        if (! $response->successful()) {
            $rejected = $response->clientError() && $response->status() !== 408;
            throw new SmsFailure($rejected ? 'provider_rejected' : 'response_unknown', ! $rejected);
        }
        try {
            $data = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new SmsFailure('response_unknown', true);
        }
        if (! is_array($data)) {
            throw new SmsFailure('response_unknown', true);
        }
        if ($mode === 'rest') {
            if (! isset($data['RetStatus']) || ! array_key_exists('Value', $data)) {
                throw new SmsFailure('response_unknown', true);
            }
            if (! in_array($data['RetStatus'], [1, '1'], true)) {
                throw new SmsFailure('provider_rejected');
            }
        }
        $reference = $mode === 'rest' ? $data['Value'] : ($data['recId'] ?? null);
        if ((is_string($reference) || is_int($reference)) && preg_match('/^[1-9][0-9]{3,31}$/D', (string) $reference)) {
            return (string) $reference;
        }
        if ((is_string($reference) || is_int($reference)) && preg_match('/^-?[0-9]{1,3}$/D', (string) $reference)) {
            throw new SmsFailure('provider_rejected');
        }

        throw new SmsFailure('response_unknown', true);
    }
}
