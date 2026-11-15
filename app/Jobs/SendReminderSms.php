<?php

namespace App\Jobs;

use App\Services\EventReminders;
use App\Services\MelipayamakClient;
use App\Services\ReminderSmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendReminderSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $reminderId) {}

    public function handle(ReminderSmsService $service, MelipayamakClient $client, EventReminders $events): void
    {
        $service->send($this->reminderId, $client, $events);
    }
}
