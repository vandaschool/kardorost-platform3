<?php

namespace App\Console\Commands;

use App\Services\OperationalHeartbeats;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('platform:dispatch-heartbeats')]
#[Description('Record the scheduler signal and queue harmless default/sms worker probes')]
class DispatchOperationalHeartbeat extends Command
{
    public function handle(OperationalHeartbeats $heartbeats): int
    {
        try {
            $heartbeats->dispatch();
        } catch (Throwable) {
            $this->error('Heartbeat dispatch failed. Check database/cache connectivity on the server.');

            return self::FAILURE;
        }
        $this->info('Scheduler signal recorded; default and sms worker probes queued. No external request sent.');

        return self::SUCCESS;
    }
}
