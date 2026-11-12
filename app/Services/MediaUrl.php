<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class MediaUrl
{
    private function host(string $url, string $field): string
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw ValidationException::withMessages([$field => 'یک لینک معتبر HTTPS وارد کنید.']);
        }
        $host = strtolower($parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.') || preg_match('/(?:^|\.)(localhost|local|internal)$/', $host)) {
            throw ValidationException::withMessages([$field => 'دامنه عمومی و معتبر وارد کنید.']);
        }

        return $host;
    }

    public function media(string $kind, ?string $url, string $field = 'media_url'): ?string
    {
        if ($kind === 'none') {
            return null;
        }
        if (! $url) {
            throw ValidationException::withMessages([$field => 'لینک ویدیو را وارد کنید.']);
        }
        $host = $this->host($url, $field);
        if ($kind === 'embed' && ! in_array($host, config('platform.learning.embed_hosts'), true)) {
            throw ValidationException::withMessages([$field => 'لینک نمایشگر آروان را وارد کنید. دامنه سفارشی باید در تنظیمات سرور مجاز شده باشد.']);
        }

        return $url;
    }

    public function meeting(string $provider, string $url): string
    {
        $host = $this->host($url, 'meeting_url');
        $allowed = match ($provider) {
            'meet' => ['meet.google.com'],'zoom' => ['zoom.us', 'zoom.com'],'skyroom' => config('platform.learning.skyroom_hosts'),default => []
        };
        foreach ($allowed as $domain) {
            if ($host === $domain || ($provider !== 'meet' && str_ends_with($host, '.'.$domain))) {
                return $url;
            }
        }
        throw ValidationException::withMessages(['meeting_url' => 'دامنه لینک با سرویس انتخاب‌شده مطابقت ندارد.']);
    }
}
