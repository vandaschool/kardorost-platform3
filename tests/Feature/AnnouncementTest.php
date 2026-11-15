<?php

namespace Tests\Feature;

use App\Jobs\DeliverAnnouncement;
use App\Models\Announcement;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Announcements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function draft(array $roles = ['student']): Announcement
    {
        return app(Announcements::class)->save($this->admin, ['title' => 'اطلاعیه صرفاً آزمایشی', 'message' => "متن آزمایشی\nخط دوم", 'roles' => $roles]);
    }

    private function published(array $roles = ['student']): Announcement
    {
        $announcement = $this->draft($roles);
        app(Announcements::class)->publish($this->admin, $announcement, 1);

        return $announcement->fresh();
    }

    public function test_only_current_admin_can_manage_announcements_and_recipient_history(): void
    {
        $announcement = $this->draft();
        $this->get('/admin/announcements')->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/announcements/'.$announcement->id)->assertForbidden();
            $this->post('/admin/announcements', [])->assertForbidden();
            $this->patch('/admin/announcements/'.$announcement->id, [])->assertForbidden();
            foreach (['publish', 'stop', 'retry'] as $action) {
                $this->post('/admin/announcements/'.$announcement->id.'/'.$action, [])->assertForbidden();
            }
        }
        $this->actingAs($this->admin)->get('/admin/announcements')->assertOk();
        $this->admin->roles()->detach();
        $this->get('/admin/announcements')->assertForbidden();
    }

    public function test_draft_content_is_encrypted_and_has_no_recipients_jobs_or_notifications(): void
    {
        $announcement = $this->draft();
        $raw = DB::table('announcements')->find($announcement->id);
        $this->assertNotSame($announcement->title, $raw->title);
        $this->assertStringNotContainsString('متن آزمایشی', $raw->message);
        $this->assertSame("متن آزمایشی\nخط دوم", $announcement->fresh()->message);
        $this->assertDatabaseCount('announcement_recipients', 0);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    }

    public function test_controller_creates_a_draft_and_saves_successive_revisions(): void
    {
        $data = ['title' => 'خبر آزمایشی', 'message' => 'متن اطلاعیه آزمایشی', 'roles' => ['student']];
        $this->actingAs($this->admin)->post('/admin/announcements', ['revision' => 1, 'definition' => $data])->assertSessionHasNoErrors();
        $announcement = Announcement::sole();
        $this->patch('/admin/announcements/'.$announcement->id, ['revision' => 1, 'definition' => [...$data, 'title' => 'ویرایش خبر']])->assertSessionHasNoErrors();
        $this->assertSame(2, $announcement->fresh()->revision);
        $this->patch('/admin/announcements/'.$announcement->id, ['revision' => 1, 'definition' => $data])->assertSessionHasErrors('announcement');
        $this->assertSame('ویرایش خبر', $announcement->fresh()->title);
    }

    public function test_empty_duplicate_invalid_roles_and_overlong_content_are_rejected(): void
    {
        $base = ['title' => 'آزمایش', 'message' => 'اطلاعیه آزمایشی', 'roles' => ['student']];
        foreach ([['roles' => []], ['roles' => ['student', 'student']], ['roles' => ['superadmin']], ['roles' => ['named' => 'student']], ['title' => str_repeat('a', 161)], ['message' => str_repeat('a', 3001)]] as $bad) {
            $this->actingAs($this->admin)->post('/admin/announcements', ['revision' => 1, 'definition' => [...$base, ...$bad]])->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('announcements', 0);
    }

    public function test_publish_snapshots_active_verified_selected_roles_once_per_account(): void
    {
        $student = $this->user('student');
        $student->roles()->attach(Role::where('key', 'coach')->value('id'));
        $coach = $this->user('coach');
        $this->user('org_admin');
        $disabled = $this->user('student');
        $disabled->forceFill(['is_active' => false])->save();
        $unverified = $this->user('student');
        $unverified->update(['phone_verified_at' => null]);
        $announcement = $this->published(['student', 'coach']);
        $this->assertSame(2, $announcement->recipient_count);
        $this->assertSame([$student->id, $coach->id], $announcement->recipients()->orderBy('user_id')->pluck('user_id')->all());
        $this->assertDatabaseCount('notifications', 0);
        $this->expectException(ValidationException::class);
        app(Announcements::class)->publish($this->admin, $announcement, 2);
    }

    public function test_publish_requires_saved_review_and_nonempty_final_audience(): void
    {
        $announcement = $this->draft();
        $this->actingAs($this->admin)->post('/admin/announcements/'.$announcement->id.'/publish', ['revision' => 1])->assertSessionHasErrors('reviewed');
        $this->post('/admin/announcements/'.$announcement->id.'/publish', ['revision' => 1, 'reviewed' => true])->assertSessionHasErrors('announcement');
        $this->assertSame('draft', $announcement->fresh()->status);
        $this->assertDatabaseCount('announcement_recipients', 0);
    }

    public function test_published_text_and_audience_cannot_be_rewritten(): void
    {
        $this->user('student');
        $announcement = $this->published();
        $this->actingAs($this->admin)->patch('/admin/announcements/'.$announcement->id, ['revision' => 2, 'definition' => ['title' => 'بازنویسی', 'message' => 'متن جدید', 'roles' => ['coach']]])->assertSessionHasErrors('announcement');
        $this->assertSame(['student'], $announcement->fresh()->roles);
        $this->assertSame('اطلاعیه صرفاً آزمایشی', $announcement->fresh()->title);
    }

    public function test_later_users_or_role_additions_are_not_added_to_published_audience(): void
    {
        $student = $this->user('student');
        $coach = $this->user('coach');
        $announcement = $this->published();
        $late = $this->user('student');
        $coach->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(0, $coach->notifications()->count());
        $this->assertSame(0, $late->notifications()->count());
    }

    public function test_delivery_rechecks_role_activity_and_phone_verification(): void
    {
        $lostRole = $this->user('student');
        $inactive = $this->user('student');
        $unverified = $this->user('student');
        $announcement = $this->published();
        $lostRole->roles()->detach();
        $inactive->forceFill(['is_active' => false])->save();
        $unverified->update(['phone_verified_at' => null]);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame(3, $announcement->recipients()->where('status', 'skipped')->count());
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame('completed', $announcement->fresh()->status);
    }

    public function test_duplicate_workers_create_one_database_notification_per_recipient_without_sms(): void
    {
        $student = $this->user('student');
        $announcement = $this->published();
        $service = app(Announcements::class);
        $this->assertSame(1, $service->deliver($announcement->id));
        $this->assertSame(0, $service->deliver($announcement->id));
        $this->assertSame(1, $student->notifications()->count());
        $data = $student->notifications()->sole()->data;
        $this->assertSame($announcement->title, $data['title']);
        $this->assertSame($announcement->message, $data['text']);
        $this->assertArrayNotHasKey('url', $data);
        $this->assertSame($student->notifications()->sole()->id, $announcement->recipients()->sole()->notification_id);
        Http::assertNothingSent();
    }

    public function test_interrupted_batch_rolls_back_notifications_and_can_be_retried_safely(): void
    {
        $this->user('student');
        $this->user('student');
        $announcement = $this->published();
        $count = 0;
        Event::listen(NotificationSent::class, function () use (&$count) {
            if (++$count === 2) {
                throw new \RuntimeException('Synthetic storage interruption');
            }
        });
        try {
            app(Announcements::class)->deliver($announcement->id);
            $this->fail('Expected the synthetic interruption.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('notifications', 0);
            $this->assertSame(2, $announcement->recipients()->where('status', 'pending')->count());
        } finally {
            Event::forget(NotificationSent::class);
        }
        $this->assertSame(2, app(Announcements::class)->deliver($announcement->id));
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_stop_cancels_only_remaining_recipients_and_keeps_already_delivered_notifications(): void
    {
        User::factory()->count(101)->create()->each(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id')));
        $announcement = $this->published();
        $service = app(Announcements::class);
        $this->assertSame(100, $service->deliver($announcement->id));
        $this->assertSame('sending', $announcement->fresh()->status);
        $service->stop($this->admin, $announcement, $announcement->fresh()->revision, 'توقف آزمایشی');
        $this->assertSame(0, $service->deliver($announcement->id));
        $this->assertSame(100, $announcement->recipients()->where('status', 'delivered')->count());
        $this->assertSame(1, $announcement->recipients()->where('status', 'cancelled')->count());
        $this->assertDatabaseCount('notifications', 100);
        $this->assertSame('stopped', $announcement->fresh()->status);
        $this->assertStringNotContainsString('توقف آزمایشی', DB::table('announcements')->value('stop_reason'));
    }

    public function test_worker_queues_the_next_batch_and_completes_without_duplicate_delivery(): void
    {
        User::factory()->count(101)->create()->each(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id')));
        $announcement = $this->published();
        $service = app(Announcements::class);
        $service->dispatchPending();
        (new DeliverAnnouncement($announcement->id))->handle($service);
        Queue::assertPushed(DeliverAnnouncement::class, 2);
        $this->assertSame(100, $announcement->recipients()->where('status', 'delivered')->count());
        (new DeliverAnnouncement($announcement->id))->handle($service);
        (new DeliverAnnouncement($announcement->id))->handle($service);
        $this->assertSame('completed', $announcement->fresh()->status);
        $this->assertDatabaseCount('notifications', 101);
        Queue::assertPushed(DeliverAnnouncement::class, 2);
    }

    public function test_stale_stop_cannot_cancel_a_changed_batch(): void
    {
        $this->user('student');
        $announcement = $this->published();
        $this->actingAs($this->admin)->post('/admin/announcements/'.$announcement->id.'/stop', ['revision' => 1, 'reason' => 'فرم قدیمی'])->assertSessionHasErrors('announcement');
        $this->assertSame('sending', $announcement->fresh()->status);
        $this->assertSame('pending', $announcement->recipients()->sole()->status);
    }

    public function test_final_job_failure_pauses_delivery_without_saving_raw_exception_and_admin_can_resume(): void
    {
        $this->user('student');
        $announcement = $this->published();
        (new DeliverAnnouncement($announcement->id))->failed(new \RuntimeException('private provider secret'));
        $this->assertSame('failed', $announcement->fresh()->status);
        $this->assertSame(0, app(Announcements::class)->dispatchPending());
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $this->actingAs($this->admin)->post('/admin/announcements/'.$announcement->id.'/retry', ['revision' => $announcement->fresh()->revision])->assertSessionHasNoErrors();
        $this->assertSame('sending', $announcement->fresh()->status);
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
        (new DeliverAnnouncement($announcement->id))->failed(new \RuntimeException('late failure'));
        $this->assertSame('completed', $announcement->fresh()->status);
        $this->assertStringNotContainsString('private provider secret', json_encode(AuditEvent::all()));
    }

    public function test_dispatch_uses_database_default_queue_and_does_not_enqueue_again_before_timeout(): void
    {
        $this->user('student');
        $announcement = $this->published();
        $service = app(Announcements::class);
        $this->assertSame(1, $service->dispatchPending());
        $this->assertSame(0, $service->dispatchPending());
        Queue::assertPushed(DeliverAnnouncement::class, fn ($job) => $job->announcementId === $announcement->id && $job->connection === 'database' && $job->queue === 'default');
        $this->travel(11)->minutes();
        $this->artisan('platform:dispatch-announcements')->assertSuccessful();
        Queue::assertPushed(DeliverAnnouncement::class, 2);
    }

    public function test_recipient_history_is_paginated_and_does_not_expose_phone_or_private_notifications(): void
    {
        $student = $this->user('student');
        $announcement = $this->published();
        app(Announcements::class)->deliver($announcement->id);
        $this->actingAs($this->admin)->get('/admin/announcements/'.$announcement->id)->assertInertia(fn (Assert $page) => $page->where('counts.delivered', 1)->where('recipients.data.0.user_id', $student->id)->missing('recipients.data.0.phone')->missing('recipients.data.0.notification_id'));
        $peer = $this->user('student');
        $this->actingAs($peer)->get('/notifications')->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));
        $this->actingAs($student)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.title', $announcement->title));
    }

    public function test_migration_refuses_to_destroy_authored_announcement_history(): void
    {
        $this->draft();
        $migration = require database_path('migrations/2026_11_15_175328_create_announcement_outbox.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
