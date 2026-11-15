<?php

namespace App\Jobs;

use App\Services\MelipayamakClient;
use App\Services\SmsDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PollSmsDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(SmsDeliveryService $service, MelipayamakClient $client): void
    {
        $service->poll($this->deliveryId, $client);
    }
}
