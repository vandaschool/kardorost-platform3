<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\ContentSchedule;
use App\Models\Role;
use App\Models\User;
use App\Services\ContentPublishing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContentPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00', 'Asia/Tehran'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->page = ContentPage::factory()->create(['created_by' => $this->admin->id]);
    }

    private function schedule(): ContentSchedule
    {
        return app(ContentPublishing::class)->schedule($this->admin, $this->page, $this->page->fresh()->revision, CarbonImmutable::now()->addHour(), true);
    }

    public function test_scheduling_freezes_exact_definition_and_stays_private_until_due(): void
    {
        $schedule = $this->schedule();
        $this->assertSame($this->page->draft, $schedule->definition);
        $this->assertSame(1, $schedule->source_revision);
        $this->assertSame(2, $schedule->page_revision);
        $this->assertSame(2, $this->page->fresh()->revision);
        $this->assertFalse($this->page->fresh()->available);
        $this->assertSame(0, ContentPublication::count());
        $this->get($this->page->publicPath())->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee($this->page->publicPath());
        $this->assertSame('pending', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame('pending', $schedule->fresh()->status);
    }

    public function test_late_due_processing_publishes_once_at_actual_time_with_approving_actor_and_snapshot(): void
    {
        $schedule = $this->schedule();
        $this->travel(2)->hours();
        $this->assertSame('published', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame('already_handled', app(ContentPublishing::class)->processSchedule($schedule));
        $publication = ContentPublication::sole();
        $this->assertSame($schedule->definition, $publication->definition);
        $this->assertSame($this->admin->id, $publication->published_by);
        $this->assertSame(now()->toDateTimeString(), $publication->published_at->toDateTimeString());
        $this->assertTrue($publication->published_at->gt($schedule->scheduled_for));
        $this->assertSame($publication->id, $schedule->fresh()->content_publication_id);
        $this->assertSame('published', $schedule->fresh()->status);
        $this->assertSame(1, $this->page->fresh()->current_version);
        $this->assertSame(3, $this->page->fresh()->revision);
        $this->get($this->page->publicPath())->assertOk()->assertSee($schedule->definition['title']);
        $this->assertSame(1, AuditEvent::where('action', 'content.published')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_existing_public_copy_remains_until_scheduled_replacement_is_actually_published(): void
    {
        $service = app(ContentPublishing::class);
        $old = $service->publish($this->admin, $this->page, 1);
        $service->draft($this->admin, $this->page, 2, [...$this->page->draft, 'title' => 'نسخه آینده خصوصی']);
        $schedule = $this->schedule();
        $this->get($this->page->publicPath())->assertOk()->assertSee($old->definition['title'])->assertDontSee('نسخه آینده خصوصی');
        $this->travel(2)->hours();
        $service->processSchedule($schedule);
        $this->get($this->page->publicPath())->assertOk()->assertSee('نسخه آینده خصوصی');
        $this->assertSame(2, ContentPublication::count());
        $this->assertSame($old->definition, $old->fresh()->definition);
    }

    public function test_review_valid_future_minute_and_one_year_limit_are_required(): void
    {
        foreach ([[CarbonImmutable::now()->addHour(), false], [CarbonImmutable::now()->subMinute(), true], [CarbonImmutable::now()->addSeconds(30), true], [CarbonImmutable::now()->addYears(2), true]] as [$at, $reviewed]) {
            try {
                app(ContentPublishing::class)->schedule($this->admin, $this->page, 1, $at, $reviewed);
                $this->fail('Invalid schedule accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, ContentSchedule::count());
                $this->assertSame(1, $this->page->fresh()->revision);
            }
        }
    }

    public function test_tehran_date_time_is_stored_and_rendered_as_the_same_local_instant(): void
    {
        $this->actingAs($this->admin)->post('/admin/content/'.$this->page->id.'/schedule', ['revision' => 1, 'date' => '2026-11-16', 'time' => '14:30', 'reviewed' => true, 'definition' => ['title' => 'forged']])->assertSessionHasNoErrors();
        $schedule = ContentSchedule::sole();
        $this->assertSame('14:30', $schedule->scheduled_for->format('H:i'));
        $this->assertSame('11:00', $schedule->scheduled_for->utc()->format('H:i'));
        $this->assertSame($this->page->draft, $schedule->definition);
        $this->get('/admin/content/'.$this->page->id)->assertInertia(fn (Assert $p) => $p->where('pending_schedule.id', $schedule->id)->has('schedules.data', 1)->where('schedules.data.0.author_name', $this->admin->name));
        $this->get('/admin/content')->assertInertia(fn (Assert $p) => $p->where('pages.data.0.scheduled_for', fn ($time) => str_contains($time, '2026-11-16')));
    }

    public function test_only_current_active_admin_can_schedule_or_cancel(): void
    {
        $schedule = $this->schedule();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('key', $role)->value('id'));
            $this->actingAs($user)->post('/admin/content/'.$this->page->id.'/schedule', [])->assertForbidden();
            $this->post('/admin/content/'.$this->page->id.'/schedules/'.$schedule->id.'/cancel', ['revision' => 2])->assertForbidden();
        }
        $this->admin->roles()->detach();
        $this->actingAs($this->admin)->post('/admin/content/'.$this->page->id.'/schedule')->assertForbidden();
        $this->assertSame('pending', $schedule->fresh()->status);
    }

    public function test_editing_draft_cancels_pending_schedule_without_altering_frozen_body(): void
    {
        $schedule = $this->schedule();
        $original = $schedule->definition;
        app(ContentPublishing::class)->draft($this->admin, $this->page, 2, [...$this->page->draft, 'title' => 'ویرایش منتشرنشده']);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertSame('draft_changed', $schedule->fresh()->reason);
        $this->assertSame($original, $schedule->fresh()->definition);
        $this->travel(2)->hours();
        $this->assertSame('already_handled', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame(0, ContentPublication::count());
    }

    public function test_manual_visibility_change_or_immediate_publication_cancels_pending_program(): void
    {
        $schedule = $this->schedule();
        app(ContentPublishing::class)->availability($this->admin, $this->page, 2, false);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertSame('visibility_changed', $schedule->fresh()->reason);
        $next = $this->schedule();
        app(ContentPublishing::class)->publish($this->admin, $this->page, $this->page->fresh()->revision);
        $this->assertSame('published_now', $next->fresh()->reason);
        $this->assertSame('cancelled', $next->fresh()->status);
        $this->assertSame(1, ContentPublication::count());
    }

    public function test_rescheduling_preserves_cancelled_decision_and_rejects_stale_browser_version(): void
    {
        $first = $this->schedule();
        $second = $this->schedule();
        $this->assertSame('replaced', $first->fresh()->reason);
        $this->assertSame('pending', $second->status);
        $this->assertSame(2, ContentSchedule::count());
        try {
            app(ContentPublishing::class)->schedule($this->admin, $this->page, 2, CarbonImmutable::now()->addHours(3), true);
            $this->fail('Stale request accepted.');
        } catch (ValidationException) {
            $this->assertSame('pending', $second->fresh()->status);
            $this->assertSame(3, $this->page->fresh()->revision);
        }
    }

    public function test_explicit_cancellation_is_revision_checked_and_page_scoped(): void
    {
        $schedule = $this->schedule();
        $other = ContentSchedule::factory()->create();
        $this->actingAs($this->admin)->post('/admin/content/'.$this->page->id.'/schedules/'.$other->id.'/cancel', ['revision' => 2])->assertNotFound();
        $this->post('/admin/content/'.$this->page->id.'/schedules/'.$schedule->id.'/cancel', ['revision' => 1])->assertSessionHasErrors('content');
        $this->post('/admin/content/'.$this->page->id.'/schedules/'.$schedule->id.'/cancel', ['revision' => 2])->assertSessionHasNoErrors();
        $this->assertSame('admin_cancelled', $schedule->fresh()->reason);
        $this->assertNotNull($schedule->fresh()->closed_at);
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_author_role_loss_blocks_due_publication_and_role_restoration_does_not_retry_approval(): void
    {
        $schedule = $this->schedule();
        $this->admin->roles()->detach();
        $this->travel(2)->hours();
        $this->assertSame('blocked', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame('author_ineligible', $schedule->fresh()->reason);
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->assertSame('already_handled', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame(0, ContentPublication::count());
        $fresh = $this->schedule();
        $this->travel(2)->hours();
        $this->assertSame('published', app(ContentPublishing::class)->processSchedule($fresh));
    }

    public function test_inactive_author_blocks_due_work_without_changing_existing_public_copy(): void
    {
        app(ContentPublishing::class)->publish($this->admin, $this->page, 1);
        app(ContentPublishing::class)->draft($this->admin, $this->page, 2, [...$this->page->draft, 'title' => 'نسخه آینده']);
        $schedule = $this->schedule();
        $this->admin->forceFill(['is_active' => false])->save();
        $this->travel(2)->hours();
        $this->assertSame('blocked', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame(1, ContentPublication::count());
        $this->get($this->page->publicPath())->assertOk()->assertDontSee('نسخه آینده');
    }

    public function test_changed_page_or_tampered_snapshot_never_publishes_unapproved_text(): void
    {
        $schedule = $this->schedule();
        $schedule->update(['definition' => [...$schedule->definition, 'title' => 'تغییر تأییدنشده']]);
        $this->travel(2)->hours();
        $this->assertSame('blocked', app(ContentPublishing::class)->processSchedule($schedule));
        $this->assertSame('snapshot_changed', $schedule->fresh()->reason);
        $fresh = $this->schedule();
        $this->page->update(['draft' => [...$this->page->draft, 'title' => 'تغییر مستقیم پایگاه داده']]);
        $this->travel(2)->hours();
        $this->assertSame('blocked', app(ContentPublishing::class)->processSchedule($fresh));
        $this->assertSame('page_changed', $fresh->fresh()->reason);
        $this->assertSame(0, ContentPublication::count());
    }

    public function test_previously_published_definition_cannot_be_scheduled_again_as_a_new_publication(): void
    {
        app(ContentPublishing::class)->publish($this->admin, $this->page, 1);
        $this->expectException(ValidationException::class);
        $this->schedule();
    }

    public function test_scheduled_plain_text_is_escaped_and_private_schedule_history_never_leaks_publicly(): void
    {
        $definition = [...$this->page->draft, 'title' => '<script>unsafe()</script> برنامه آینده'];
        app(ContentPublishing::class)->draft($this->admin, $this->page, 1, $definition);
        $schedule = $this->schedule();
        $this->travel(2)->hours();
        app(ContentPublishing::class)->processSchedule($schedule);
        $this->get($this->page->publicPath())->assertOk()->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false)->assertDontSee($this->admin->phone)->assertDontSee('source_revision');
    }

    public function test_malformed_utf8_cannot_poison_draft_or_scheduled_content(): void
    {
        foreach (['title', 'excerpt', 'body', 'meta_description'] as $key) {
            try {
                app(ContentPublishing::class)->draft($this->admin, $this->page, 1, [...$this->page->draft, $key => "invalid\xFFtext"]);
                $this->fail('Invalid UTF-8 accepted.');
            } catch (ValidationException) {
                $this->assertSame(1, $this->page->fresh()->revision);
                $this->assertSame(0, ContentSchedule::count());
            }
        }
    }

    public function test_due_command_is_idempotent_and_failures_do_not_print_private_exception_text(): void
    {
        $schedule = $this->schedule();
        $this->travel(2)->hours();
        $this->artisan('platform:publish-scheduled-content')->expectsOutput('Published: 1; blocked: 0; failed: 0')->assertSuccessful();
        $this->artisan('platform:publish-scheduled-content')->expectsOutput('Published: 0; blocked: 0; failed: 0')->assertSuccessful();
        $this->assertSame(1, ContentPublication::count());
        $this->assertSame('published', $schedule->fresh()->status);
        ContentSchedule::factory()->create(['scheduled_for' => now()->subMinute()]);
        $this->mock(ContentPublishing::class, fn ($mock) => $mock->shouldReceive('processSchedule')->once()->andThrow(new \RuntimeException('never-print-private-content-error')));
        $this->assertSame(1, Artisan::call('platform:publish-scheduled-content'));
        $output = Artisan::output();
        $this->assertStringNotContainsString('never-print-private-content-error', $output);
        $this->assertStringContainsString('failed: 1', $output);
    }

    public function test_rollback_refuses_to_delete_schedule_decision_history(): void
    {
        $this->schedule();
        $migration = require database_path('migrations/2026_11_15_230344_create_content_schedules_table.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
