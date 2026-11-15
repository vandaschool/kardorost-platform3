<?php

namespace App\Services;

use App\Jobs\RecordOperationalHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class OperationalHeartbeats
{
    public const MAX_AGE_SECONDS = 180;

    private function key(string $signal): string
    {
        if (! in_array($signal, ['scheduler', 'queue.default', 'queue.sms'], true)) {
            throw new InvalidArgumentException('Unsupported operational signal.');
        }

        return 'platform:operations:heartbeat:v1:'.$signal;
    }

    public function record(string $signal): void
    {
        Cache::put($this->key($signal), now()->timestamp, 600);
    }

    public function dispatch(): void
    {
        $this->record('scheduler');
        foreach (['default', 'sms'] as $queue) {
            RecordOperationalHeartbeat::dispatch($queue)->onConnection('database')->onQueue($queue);
        }
    }

    public function status(string $signal): array
    {
        $seen = Cache::get($this->key($signal));
        $age = is_int($seen) ? now()->timestamp - $seen : null;

        return ['fresh' => $age !== null && $age >= -30 && $age <= self::MAX_AGE_SECONDS, 'last_seen_at' => is_int($seen) && $seen > 0 && $seen <= now()->timestamp + 30 ? CarbonImmutable::createFromTimestamp($seen)->toIso8601String() : null];
    }
}
