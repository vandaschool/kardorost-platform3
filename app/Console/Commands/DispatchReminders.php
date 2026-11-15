<?php

namespace App\Console\Commands;

use App\Services\EventReminders;
use App\Services\ReminderSmsService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:dispatch-reminders')]
#[Description('Create eligible one-time event reminders and queue configured reminder SMS')]
class DispatchReminders extends Command
{
    public function handle(EventReminders $events, ReminderSmsService $sms): int
    {
        $created = $events->generate();
        $reconciled = $sms->reconcile();
        $queued = $sms->dispatchPending();
        $this->info('In-app reminders: '.$created.'; queued SMS: '.$queued.'; interrupted sends: '.$reconciled);

        return self::SUCCESS;
    }
}
