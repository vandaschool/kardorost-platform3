<?php

namespace App\Console\Commands;

use App\Services\Announcements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:dispatch-announcements')]
#[Description('Dispatch pending internal announcement batches from the durable outbox')]
class DispatchAnnouncements extends Command
{
    public function handle(Announcements $service): int
    {
        $this->info('Announcement batches queued: '.$service->dispatchPending());

        return self::SUCCESS;
    }
}
