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

    /** @return array{mode:string,fingerprint:string}|null */
    public function account(): ?array
    {
        $mode = config('platform.sms.melipayamak.mode');
        $key = config('platform.sms.melipayamak.api_key');
        $username = config('platform.sms.melipayamak.username');
        if (config('platform.sms.driver') !== 'melipayamak' || ! in_array($mode, ['rest', 'console'], true) || ! is_string($key) || trim($key) === '' || strlen($key) > 200 || ($mode === 'rest' && (! is_string($username) || trim($username) === '')) || ($mode === 'console' && ! preg_match('/^[A-Za-z0-9_-]{8,200}$/D', $key))) {
            return null;
        }

        return ['mode' => $mode, 'fingerprint' => hash_hmac('sha256', $mode."\0".($mode === 'rest' ? $username : '')."\0".$key, config('app.key'))];
    }

    /** @return array{state:string,code:?int,error:?string} */
    public function delivery(string $mode, string $fingerprint, string $reference): array
    {
        $account = $this->account();
        if (! $account) {
            return ['state' => 'unavailable', 'code' => null, 'error' => 'unconfigured'];
        }
        if ($account['mode'] !== $mode || ! hash_equals($fingerprint, $account['fingerprint'])) {
            return ['state' => 'unavailable', 'code' => null, 'error' => 'sender_account_changed'];
        }
        if ($mode !== 'rest') {
            return ['state' => 'unavailable', 'code' => null, 'error' => 'unsupported_delivery_mode'];
        }
        if (! preg_match('/^[1-9][0-9]{3,18}$/D', $reference) || (strlen($reference) === 19 && strcmp($reference, '9223372036854775807') > 0)) {
            return ['state' => 'unavailable', 'code' => null, 'error' => 'unsupported_reference'];
        }
        try {
            $response = Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->acceptJson()->asForm()->post('https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2', ['username' => config('platform.sms.melipayamak.username'), 'password' => config('platform.sms.melipayamak.api_key'), 'recId' => $reference]);
            if (! $response->successful()) {
                return ['state' => 'pending', 'code' => null, 'error' => 'query_transport_error'];
            }
            $data = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['state' => 'pending', 'code' => null, 'error' => 'query_transport_error'];
        }
        if (! is_array($data) || ! isset($data['RetStatus']) || ! in_array($data['RetStatus'], [1, '1'], true) || ! array_key_exists('Value', $data)) {
            return ['state' => 'pending', 'code' => null, 'error' => 'invalid_query_response'];
        }
        if ($data['Value'] === null) {
            return ['state' => 'unavailable', 'code' => null, 'error' => 'delivery_access_unavailable'];
        }
        $value = $data['Value'];
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^-?[0-9]{1,6}$/D', (string) $value)) {
            return ['state' => 'pending', 'code' => null, 'error' => 'invalid_query_response'];
        }
        $code = (int) $value;
        $state = match ($code) {
            1 => 'delivered',
            2, 3, 16, 35, 300, 500 => 'undelivered',
            -110, -109, -108, -3 => 'unavailable',
            default => 'pending',
        };

        return ['state' => $state, 'code' => $code, 'error' => $code < 0 ? 'provider_query_error' : null];
    }
}
