<?php

namespace App\Console\Commands;

use App\Services\OperationalHeartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

#[Signature('platform:heartbeat-status {signal : scheduler, queue.default or queue.sms}')]
#[Description('Read-only worker/scheduler liveness check without contacting external providers')]
class OperationalHeartbeatStatus extends Command
{
    public function handle(OperationalHeartbeats $heartbeats): int
    {
        try {
            $fresh = $heartbeats->status($this->argument('signal'))['fresh'];
        } catch (InvalidArgumentException) {
            $this->error('Unsupported operational signal.');

            return self::INVALID;
        } catch (Throwable) {
            $this->error('Operational signal unavailable.');

            return self::FAILURE;
        }
        $this->line($fresh ? 'Operational signal fresh.' : 'Operational signal stale.');

        return $fresh ? self::SUCCESS : self::FAILURE;
    }
}
