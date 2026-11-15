<?php

namespace App\Console\Commands;

use App\Services\SmsDeliveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:poll-sms-deliveries')]
#[Description('Queue authenticated delivery queries without resending SMS')]
class PollSmsDeliveries extends Command
{
    public function handle(SmsDeliveryService $service): int
    {
        $reconciled = $service->reconcile();
        $queued = $service->dispatchPending();
        $this->info('Delivery queries queued: '.$queued.'; interrupted queries: '.$reconciled);

        return self::SUCCESS;
    }
}
