<?php

namespace Tests\Feature;

use App\Jobs\DeliverAnnouncement;
use App\Models\Announcement;
use App\Models\AnnouncementSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\Announcements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class ScheduledAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        Http::preventStrayRequests();
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function draft(): Announcement
    {
        return app(Announcements::class)->save($this->admin, ['title' => 'PRIVATE-SCHEDULE-TITLE', 'message' => 'متن خصوصی صرفاً آزمایشی برای برنامه اعلان', 'roles' => ['student']]);
    }

    private function scheduled(?Announcement $announcement = null): AnnouncementSchedule
    {
        $announcement ??= $this->draft();

        return app(Announcements::class)->schedule($this->admin, $announcement, $announcement->fresh()->revision, CarbonImmutable::now()->addMinutes(2), true);
    }

    private function due(AnnouncementSchedule $schedule): void
    {
        $this->travelTo($schedule->scheduled_for->copy()->addMinute());
    }

    public function test_schedule_requires_admin_explicit_review_future_date_and_current_draft(): void
    {
        $announcement = $this->draft();
        $url = '/admin/announcements/'.$announcement->id.'/schedule';
        $valid = ['revision' => 1, 'date' => now()->addDay()->format('Y-m-d'), 'time' => '12:30', 'reviewed' => true];
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->postJson($url, $valid)->assertForbidden();
        }
        foreach ([['reviewed' => false], ['time' => '25:00'], ['date' => 'not-date'], ['date' => now()->subDay()->format('Y-m-d')], ['date' => now()->addYears(2)->format('Y-m-d')], ['revision' => 99]] as $change) {
            $this->actingAs($this->admin)->postJson($url, [...$valid, ...$change])->assertUnprocessable();
        }
        $this->post($url, $valid)->assertSessionHasNoErrors();
        $this->assertSame('12:30', AnnouncementSchedule::sole()->scheduled_for->setTimezone('Asia/Tehran')->format('H:i'));
    }

    public function test_encrypted_immutable_schedule_creates_no_recipients_jobs_or_notifications_before_due(): void
    {
        Queue::fake();
        $this->user('student');
        $schedule = $this->scheduled();
        $this->assertSame(1, $schedule->source_revision);
        $this->assertSame(2, $schedule->announcement_revision);
        $this->assertSame('PRIVATE-SCHEDULE-TITLE', $schedule->definition['title']);
        $this->assertStringNotContainsString('PRIVATE-SCHEDULE-TITLE', DB::table('announcement_schedules')->value('definition'));
        $this->assertNotSame(hash('sha256', json_encode($schedule->definition)), $schedule->definition_hash);
        $this->assertSame('pending', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame(0, DB::table('announcement_recipients')->count());
        $this->assertSame(0, DB::table('notifications')->count());
        Queue::assertNothingPushed();
    }

    public function test_due_publication_uses_current_audience_once_and_actual_execution_time(): void
    {
        $old = $this->user('student');
        $schedule = $this->scheduled();
        $old->roles()->detach();
        $current = $this->user('student');
        $this->due($schedule);
        $service = app(Announcements::class);
        $this->assertSame('published', $service->processSchedule($schedule));
        $announcement = $schedule->announcement->fresh();
        $this->assertTrue($announcement->published_at->equalTo(now()));
        $this->assertFalse($announcement->published_at->equalTo($schedule->scheduled_for));
        $this->assertSame([$current->id], $announcement->recipients()->pluck('user_id')->all());
        $this->user('student');
        $this->assertSame('already_handled', $service->processSchedule($schedule));
        $this->assertSame(1, $service->deliver($announcement->id));
        $this->assertSame(0, $service->deliver($announcement->id));
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_saving_draft_cancels_old_schedule_and_preserves_approved_text(): void
    {
        $schedule = $this->scheduled();
        $announcement = $schedule->announcement;
        app(Announcements::class)->save($this->admin, ['title' => 'NEW-TITLE', 'message' => 'متن جدید صرفاً آزمایشی', 'roles' => ['coach']], $announcement, 2);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertSame('draft_changed', $schedule->fresh()->reason);
        $this->assertSame('PRIVATE-SCHEDULE-TITLE', $schedule->fresh()->definition['title']);
        $this->due($schedule);
        $this->assertSame('already_handled', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame(0, DB::table('announcement_recipients')->count());
    }

    public function test_replacement_and_explicit_cancellation_are_versioned_with_history(): void
    {
        $first = $this->scheduled();
        $second = $this->scheduled($first->announcement);
        $this->assertSame('replaced', $first->fresh()->reason);
        $this->assertSame(3, $second->announcement_revision);
        $url = '/admin/announcements/'.$second->announcement_id.'/schedules/'.$second->id.'/cancel';
        $this->actingAs($this->admin)->postJson($url, ['revision' => 2])->assertUnprocessable();
        $this->post($url, ['revision' => 3])->assertSessionHasNoErrors();
        $this->assertSame('admin_cancelled', $second->fresh()->reason);
        $this->assertSame(4, $second->announcement->fresh()->revision);
        $this->postJson($url, ['revision' => 4])->assertUnprocessable();
        $this->assertSame(2, AnnouncementSchedule::count());
    }

    public function test_cancel_schedule_cannot_target_a_different_announcement(): void
    {
        $schedule = $this->scheduled();
        $other = $this->draft();
        $this->actingAs($this->admin)->post('/admin/announcements/'.$other->id.'/schedules/'.$schedule->id.'/cancel', ['revision' => 1])->assertNotFound();
        $this->assertSame('pending', $schedule->fresh()->status);
    }

    public function test_immediate_publication_cancels_schedule_without_duplicate_delivery(): void
    {
        $this->user('student');
        $schedule = $this->scheduled();
        app(Announcements::class)->publish($this->admin, $schedule->announcement, 2);
        $this->assertSame('published_now', $schedule->fresh()->reason);
        $this->actingAs($this->admin)->postJson('/admin/announcements/'.$schedule->announcement_id.'/schedule', ['revision' => $schedule->announcement->fresh()->revision, 'date' => now()->addDay()->format('Y-m-d'), 'time' => '12:00', 'reviewed' => true])->assertUnprocessable();
        $this->due($schedule);
        $this->assertSame('already_handled', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame(1, app(Announcements::class)->deliver($schedule->announcement_id));
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_revoked_approver_blocks_permanently_until_fresh_approval(): void
    {
        $this->user('student');
        $schedule = $this->scheduled();
        $this->admin->roles()->detach();
        $this->due($schedule);
        $this->assertSame('blocked', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame('author_ineligible', $schedule->fresh()->reason);
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->assertSame('already_handled', app(Announcements::class)->processSchedule($schedule));
        $fresh = $this->scheduled($schedule->announcement);
        $this->due($fresh);
        $this->assertSame('published', app(Announcements::class)->processSchedule($fresh));
    }

    public function test_deactivated_approver_cannot_trigger_scheduled_publication(): void
    {
        $this->user('student');
        $schedule = $this->scheduled();
        User::whereKey($this->admin->id)->update(['is_active' => false]);
        $this->due($schedule);
        $this->assertSame('blocked', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame(0, DB::table('announcement_recipients')->count());
    }

    public function test_tampered_snapshot_source_and_revision_never_publish(): void
    {
        foreach (['snapshot', 'source', 'revision', 'time', 'approver', 'source_revision'] as $change) {
            $schedule = $this->scheduled();
            if ($change === 'snapshot') {
                $schedule->update(['definition' => [...$schedule->definition, 'message' => 'UNAPPROVED-PRIVATE-TEXT']]);
            } elseif ($change === 'source') {
                $schedule->announcement->update(['message' => 'UNAPPROVED-PRIVATE-TEXT']);
            } elseif ($change === 'revision') {
                $schedule->announcement->update(['revision' => 99]);
            } elseif ($change === 'time') {
                $schedule->update(['scheduled_for' => $schedule->scheduled_for->copy()->subMinute()]);
            } elseif ($change === 'approver') {
                $schedule->update(['created_by' => $this->user('admin')->id]);
            } else {
                $schedule->update(['source_revision' => 99]);
            }
            $this->due($schedule);
            $this->assertSame('blocked', app(Announcements::class)->processSchedule($schedule));
        }
        $this->assertSame(0, DB::table('announcement_recipients')->count());
    }

    public function test_zero_due_audience_blocks_and_later_registration_does_not_auto_resume(): void
    {
        $schedule = $this->scheduled();
        $this->due($schedule);
        $this->assertSame('blocked', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame('empty_audience', $schedule->fresh()->reason);
        $this->user('student');
        $this->assertSame('already_handled', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame('draft', $schedule->announcement->fresh()->status);
    }

    public function test_dispatch_command_prepares_due_snapshot_and_only_queues_delivery(): void
    {
        Queue::fake();
        $this->user('student');
        $schedule = $this->scheduled();
        $this->due($schedule);
        $this->artisan('platform:dispatch-announcements')->assertSuccessful();
        $this->assertSame('published', $schedule->fresh()->status);
        $this->assertSame('sending', $schedule->announcement->fresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
        Queue::assertPushed(DeliverAnnouncement::class, 1);
        $this->artisan('platform:dispatch-announcements')->assertSuccessful();
        Queue::assertPushed(DeliverAnnouncement::class, 1);
    }

    public function test_transient_failure_does_not_stop_other_schedules_or_log_secrets(): void
    {
        $this->user('student');
        $first = $this->scheduled();
        $second = $this->scheduled();
        $this->due($second);
        Log::spy();
        $service = Mockery::mock(Announcements::class)->makePartial();
        $service->shouldReceive('processSchedule')->withArgs(fn ($schedule) => $schedule->id === $first->id)->andThrow(new \RuntimeException('SECRET-ERROR-CONTENT'));
        $service->shouldReceive('processSchedule')->withArgs(fn ($schedule) => $schedule->id === $second->id)->passthru();
        $this->assertSame(['published' => 1, 'blocked' => 0, 'failed' => 1], $service->processDueSchedules());
        Log::shouldHaveReceived('warning')->once()->with('Scheduled announcement could not be processed.', ['schedule_id' => $first->id]);
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('published', $second->fresh()->status);
    }

    public function test_due_processing_handles_only_one_hundred_schedules_per_command_pass(): void
    {
        AnnouncementSchedule::factory()->count(103)->create(['scheduled_for' => now()->subMinute()]);
        $this->assertSame(['published' => 0, 'blocked' => 100, 'failed' => 0], app(Announcements::class)->processDueSchedules());
        $this->assertSame(3, AnnouncementSchedule::where('status', 'pending')->count());
        $this->assertSame('empty_audience', AnnouncementSchedule::orderBy('id')->firstOrFail()->reason);
        $this->assertSame(['published' => 0, 'blocked' => 3, 'failed' => 0], app(Announcements::class)->processDueSchedules());
    }

    public function test_admin_page_has_private_history_and_other_roles_cannot_access_it(): void
    {
        $schedule = $this->scheduled();
        $url = '/admin/announcements/'.$schedule->announcement_id;
        $this->actingAs($this->admin)->get($url)->assertInertia(fn (Assert $page) => $page->where('pending_schedule.id', $schedule->id)->where('announcements.data.0.next_scheduled_for', fn ($value) => $value !== null)->where('schedules.data.0.definition.title', 'PRIVATE-SCHEDULE-TITLE'));
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get($url)->assertForbidden();
        }
    }

    public function test_original_draft_author_is_separate_from_current_schedule_approver(): void
    {
        $announcement = $this->draft();
        $original = $this->admin;
        $this->admin = $this->user('admin');
        $schedule = $this->scheduled($announcement);
        $original->roles()->detach();
        $this->user('student');
        $this->due($schedule);
        $this->assertSame('published', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame($this->admin->id, $schedule->created_by);
    }

    public function test_invalid_utf8_is_rejected_before_saving_an_announcement(): void
    {
        $this->expectException(ValidationException::class);
        app(Announcements::class)->save($this->admin, ['title' => "bad-\xC3\x28", 'message' => 'Valid synthetic message', 'roles' => ['student']]);
    }
}
