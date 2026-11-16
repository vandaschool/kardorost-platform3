<?php

$entries = array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', ''))), fn ($value) => $value !== ''));
$valid = array_values(array_filter($entries, function (string $value): bool {
    $parts = explode('/', $value);
    if (count($parts) > 2 || ! filter_var($parts[0], FILTER_VALIDATE_IP)) {
        return false;
    }
    if (count($parts) === 1) {
        return true;
    }
    $max = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;

    return ctype_digit($parts[1]) && (int) $parts[1] >= 1 && (int) $parts[1] <= $max;
}));

return ['proxies' => $valid, 'invalid_count' => count($entries) - count($valid)];
