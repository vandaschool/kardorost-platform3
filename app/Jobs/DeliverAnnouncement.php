<?php

namespace App\Jobs;

use App\Services\Announcements;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeliverAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $announcementId) {}

    public function handle(Announcements $service): void
    {
        $service->deliver($this->announcementId);
        $service->dispatchPending();
    }

    public function failed(?Throwable $exception): void
    {
        app(Announcements::class)->failed($this->announcementId);
    }
}
