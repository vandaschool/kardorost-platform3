<?php

namespace App\Console\Commands;

use App\Services\InvitationSmsService;
use Illuminate\Console\Command;

class ReconcileInvitationSms extends Command
{
    protected $signature = 'platform:dispatch-invitation-sms';

    protected $description = 'Queue pending invitation messages and mark interrupted sends for review';

    public function handle(InvitationSmsService $service): int
    {
        $reconciled = $service->reconcile();
        $dispatched = $service->dispatchPending();
        $this->info('Queued: '.$dispatched.'; interrupted sends marked unknown: '.$reconciled);

        return self::SUCCESS;
    }
}
