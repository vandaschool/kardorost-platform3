<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function event(array $attributes = []): AuditEvent
    {
        return AuditEvent::create(['actor_id' => $this->admin->id, 'action' => 'course.created', 'subject_type' => 'course', 'subject_id' => 7, ...$attributes]);
    }

    public function test_only_active_current_admin_can_read_audit_history(): void
    {
        $this->get('/admin/activity')->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/activity')->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/admin/activity')->assertOk();
        $this->admin->roles()->detach();
        $this->get('/admin/activity')->assertForbidden();
        $inactive = $this->user('admin');
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($inactive)->get('/admin/activity')->assertRedirect('/login');
    }

    public function test_history_beyond_previous_hundred_record_limit_is_available_in_stable_id_order(): void
    {
        $ids = [];
        for ($index = 0; $index < 126; $index++) {
            $ids[] = $this->event(['created_at' => '2026-01-01 12:00:00'])->id;
        }
        $this->actingAs($this->admin)->get('/admin/activity')->assertInertia(fn (Assert $page) => $page->component('AuditEvents')->has('events.data', 25)->where('events.total', 126)->where('events.last_page', 6)->where('events.data.0.id', $ids[125])->where('events.data.24.id', $ids[101]));
        $this->get('/admin/activity?page=6')->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.id', $ids[0]));
    }

    public function test_action_actor_subject_and_event_filters_are_exact_and_combined(): void
    {
        $other = $this->user('student');
        $wanted = $this->event();
        $this->event(['action' => 'course.created_extra']);
        $this->event(['actor_id' => $other->id]);
        $this->event(['subject_type' => 'cohort']);
        $this->event(['subject_id' => 8]);
        $filters = ['action' => 'course.created', 'actor_id' => $this->admin->id, 'subject_type' => 'course', 'subject_id' => 7];
        $this->actingAs($this->admin)->get('/admin/activity?'.http_build_query($filters))->assertInertia(fn (Assert $page) => $page->where('events.total', 1)->where('events.data.0.id', $wanted->id)->where('filters.actor_id', $this->admin->id));
        $this->get('/admin/activity?event_id='.$wanted->id)->assertInertia(fn (Assert $page) => $page->where('events.total', 1)->where('events.data.0.id', $wanted->id));
        $this->get('/admin/activity?event_id='.$wanted->id.'&subject_id=8')->assertInertia(fn (Assert $page) => $page->where('events.total', 0));
    }

    public function test_tehran_date_window_includes_full_end_day_and_excludes_neighboring_days(): void
    {
        $ids = [];
        foreach (['2026-03-01 00:00:00', '2026-03-02 23:59:59'] as $time) {
            $ids[] = $this->event(['created_at' => CarbonImmutable::parse($time, 'Asia/Tehran')->timezone(config('app.timezone'))])->id;
        }
        foreach (['2026-02-28 23:59:59', '2026-03-03 00:00:00'] as $time) {
            $this->event(['created_at' => CarbonImmutable::parse($time, 'Asia/Tehran')->timezone(config('app.timezone'))]);
        }
        $this->actingAs($this->admin)->get('/admin/activity?from=2026-03-01&to=2026-03-02')->assertInertia(fn (Assert $page) => $page->where('events.total', 2)->where('events.data.0.id', $ids[1])->where('events.data.1.id', $ids[0]));
    }

    public function test_dates_are_converted_to_application_timezone_before_querying(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->event(['created_at' => '2026-02-28 20:29:59']);
        $wanted = $this->event(['created_at' => '2026-02-28 20:30:00']);
        $this->event(['created_at' => '2026-03-01 20:30:00']);
        $this->actingAs($this->admin)->get('/admin/activity?from=2026-03-01&to=2026-03-01')->assertInertia(fn (Assert $page) => $page->where('events.total', 1)->where('events.data.0.id', $wanted->id));
    }

    public function test_one_sided_dates_and_empty_filters_are_supported(): void
    {
        $this->event(['created_at' => '2020-01-01 12:00:00']);
        $this->event(['created_at' => '2026-01-01 12:00:00']);
        $this->actingAs($this->admin)->get('/admin/activity?from=2025-01-01')->assertInertia(fn (Assert $page) => $page->where('events.total', 1));
        $this->get('/admin/activity?to=2025-01-01')->assertInertia(fn (Assert $page) => $page->where('events.total', 1));
        $this->get('/admin/activity?from=&to=&action=')->assertInertia(fn (Assert $page) => $page->where('events.total', 2));
    }

    public function test_filters_are_preserved_in_pagination_and_unknown_query_fields_are_discarded(): void
    {
        for ($index = 0; $index < 26; $index++) {
            $this->event();
        }
        $response = $this->actingAs($this->admin)->get('/admin/activity?action=course.created&subject_id=7&arbitrary=SHOULD-NOT-PERSIST')->assertInertia(fn (Assert $page) => $page->where('events.total', 26)->missing('filters.arbitrary'));
        $url = $response->viewData('page')['props']['events']['next_page_url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEquals(['action' => 'course.created', 'subject_id' => '7', 'page' => '2'], $query);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('events.current_page', 2)->where('events.total', 26)->has('events.data', 1));
    }

    public function test_metadata_actor_phone_and_private_account_fields_are_not_sent_to_browser(): void
    {
        $event = $this->event(['metadata' => ['private_note' => 'PRIVATE-AUDIT-METADATA', 'password' => 'SHOULD-NOT-BE-SENT']]);
        $this->actingAs($this->admin)->get('/admin/activity')->assertInertia(fn (Assert $page) => $page->where('events.data.0.actor_name', $this->admin->name)->where('events.data.0.actor_id', $this->admin->id)->missing('events.data.0.metadata')->missing('events.data.0.actor')->missing('events.data.0.actor_phone'))->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('PRIVATE-AUDIT-METADATA')->assertDontSee('SHOULD-NOT-BE-SENT');
        $this->assertSame(['private_note' => 'PRIVATE-AUDIT-METADATA', 'password' => 'SHOULD-NOT-BE-SENT'], $event->fresh()->metadata);
    }

    public function test_no_actor_unknown_action_empty_results_and_out_of_range_page_remain_readable(): void
    {
        $this->event(['actor_id' => null, 'action' => 'future.operation', 'subject_type' => null, 'subject_id' => null]);
        $this->actingAs($this->admin)->get('/admin/activity')->assertInertia(fn (Assert $page) => $page->where('events.data.0.actor_name', null)->where('events.data.0.actor_id', null)->where('events.data.0.action', 'future.operation'));
        $this->get('/admin/activity?action=does.not.exist')->assertInertia(fn (Assert $page) => $page->where('events.total', 0)->has('events.data', 0));
        $this->get('/admin/activity?page=20')->assertInertia(fn (Assert $page) => $page->where('events.total', 1)->has('events.data', 0));
    }

    public function test_invalid_filter_shapes_dates_and_ids_are_rejected_without_sql_side_effects(): void
    {
        $this->event();
        foreach (['event_id=0', 'actor_id=-1', 'subject_id=abc', 'action[]=bad', 'subject_type[]=bad', 'page=0', 'page=1000001', 'from=2026-02-30', 'to=not-a-date', 'from=2026-03-02&to=2026-03-01', 'action='.str_repeat('x', 256)] as $query) {
            $this->actingAs($this->admin)->getJson('/admin/activity?'.$query)->assertUnprocessable();
        }
        $this->get('/admin/activity?action='.urlencode("course.created' OR 1=1 --"))->assertInertia(fn (Assert $page) => $page->where('events.total', 0));
        $this->assertSame(1, AuditEvent::count());
    }

    public function test_audit_reader_does_not_create_modify_or_delete_history(): void
    {
        $event = $this->event();
        $before = $event->fresh()->getAttributes();
        $this->actingAs($this->admin)->get('/admin/activity')->assertOk();
        $this->get('/admin/activity?event_id='.$event->id)->assertOk();
        $this->post('/admin/activity')->assertStatus(405);
        $this->patch('/admin/activity')->assertStatus(405);
        $this->delete('/admin/activity')->assertStatus(405);
        $this->assertSame(1, AuditEvent::count());
        $this->assertSame($before, $event->fresh()->getAttributes());
    }
}
