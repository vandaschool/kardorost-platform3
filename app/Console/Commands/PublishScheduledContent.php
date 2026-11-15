<?php

namespace App\Console\Commands;

use App\Models\ContentSchedule;
use App\Services\ContentPublishing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('platform:publish-scheduled-content')]
#[Description('Publish up to 100 due exact-version public content schedules after current admin checks')]
class PublishScheduledContent extends Command
{
    public function handle(ContentPublishing $publishing): int
    {
        $counts = ['published' => 0, 'blocked' => 0, 'failed' => 0];
        foreach (ContentSchedule::where('status', 'pending')->where('scheduled_for', '<=', now())->orderBy('scheduled_for')->orderBy('id')->limit(100)->get() as $schedule) {
            try {
                $result = $publishing->processSchedule($schedule);
                if (isset($counts[$result])) {
                    $counts[$result]++;
                }
            } catch (Throwable) {
                $counts['failed']++;
                $this->error('Scheduled content could not be processed: '.$schedule->id.'. Retry after checking server/database health.');
            }
        }
        $this->info('Published: '.$counts['published'].'; blocked: '.$counts['blocked'].'; failed: '.$counts['failed']);

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
