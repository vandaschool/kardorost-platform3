<?php

namespace App\Jobs;

use App\Services\InvitationSmsService;
use App\Services\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInvitationSms implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $smsId) {}

    public function handle(InvitationSmsService $service, SmsSender $sender): void
    {
        $service->send($this->smsId, $sender);
    }
}
