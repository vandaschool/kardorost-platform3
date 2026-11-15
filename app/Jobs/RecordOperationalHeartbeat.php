<?php

namespace App\Jobs;

use App\Services\OperationalHeartbeats;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use InvalidArgumentException;

class RecordOperationalHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public readonly string $signalQueue)
    {
        if (! in_array($signalQueue, ['default', 'sms'], true)) {
            throw new InvalidArgumentException('Unsupported heartbeat queue.');
        }
    }

    public function handle(OperationalHeartbeats $heartbeats): void
    {
        if (! in_array($this->signalQueue, ['default', 'sms'], true)) {
            throw new InvalidArgumentException('Unsupported heartbeat queue.');
        }
        $heartbeats->record('queue.'.$this->signalQueue);
    }
}
