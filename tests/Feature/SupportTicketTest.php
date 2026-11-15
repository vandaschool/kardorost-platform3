<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\SupportDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Storage::fake('support');
        $this->student = $this->user('student');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function data(array $changes = []): array
    {
        return [...['subject' => 'پیام خصوصی', 'category' => 'technical', 'priority' => 'normal', 'body' => 'متن خصوصی درخواست', 'request_key' => (string) Str::uuid()], ...$changes];
    }

    private function ticket(array $changes = [], ?User $owner = null): SupportTicket
    {
        return app(SupportDesk::class)->create($owner ?? $this->student, $this->data($changes), null)->fresh();
    }

    private function academic(): array
    {
        $coach = $this->user('coach');
        $cohort = Cohort::factory()->create();
        $cohort->course->update(['coach_id' => $coach->id]);
        $enrollment = Enrollment::factory()->create(['user_id' => $this->student->id, 'cohort_id' => $cohort->id]);

        return [$this->ticket(['category' => 'academic', 'cohort_id' => $cohort->id]), $coach, $enrollment];
    }

    private function reply(SupportTicket $ticket, string $body = 'پاسخ خصوصی', bool $internal = false): array
    {
        return ['body' => $body, 'request_key' => (string) Str::uuid(), 'internal_note' => $internal];
    }

    public function test_all_four_roles_can_open_their_own_support_request(): void
    {
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->post('/support', $this->data())->assertRedirect();
            $this->assertDatabaseHas('support_tickets', ['user_id' => $user->id, 'status' => 'waiting_staff']);
            $this->get('/support')->assertOk();
        }
        $this->assertSame(4, SupportTicket::count());
    }

    public function test_validation_rejects_empty_oversize_invalid_uuid_and_wrong_course_context(): void
    {
        $this->actingAs($this->student);
        foreach ([['body' => '', 'subject' => ''], ['request_key' => 'invalid'], ['subject' => str_repeat('x', 161)], ['category' => 'invented'], ['priority' => 'extreme'], ['cohort_id' => Cohort::factory()->create()->id]] as $bad) {
            $this->post('/support', $this->data($bad))->assertSessionHasErrors();
        }
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_creation_and_uuid_case_replay_are_idempotent_and_mismatch_is_rejected(): void
    {
        $data = $this->data();
        $this->actingAs($this->student)->post('/support', $data)->assertRedirect();
        $this->post('/support', [...$data, 'request_key' => strtoupper($data['request_key'])])->assertRedirect();
        $this->post('/support', [...$data, 'subject' => 'متفاوت'])->assertSessionHasErrors('request_key');
        $this->post('/support', [...$data, 'body' => 'متفاوت'])->assertSessionHasErrors('request_key');
        $this->assertSame(1, SupportTicket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_body_is_encrypted_and_private_file_is_authorized_at_download(): void
    {
        $file = UploadedFile::fake()->create('private.pdf', 20, 'application/pdf');
        $this->actingAs($this->student)->post('/support', $this->data(['file' => $file]))->assertRedirect();
        $message = TicketMessage::firstOrFail();
        $this->assertStringNotContainsString('متن خصوصی', DB::table('ticket_messages')->value('body'));
        $this->assertSame('متن خصوصی درخواست', $message->body);
        Storage::disk('support')->assertExists($message->file_path);
        $this->get('/support-files/'.$message->id)->assertDownload('private.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach ([$this->user('student'), $this->user('coach'), $this->user('org_admin')] as $outsider) {
            $this->actingAs($outsider)->get('/support-files/'.$message->id)->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/support-files/'.$message->id)->assertDownload();
        $this->assertArrayNotHasKey('file_path', $message->toArray());
    }

    public function test_unsafe_and_oversized_uploads_are_rejected_and_file_only_message_is_allowed(): void
    {
        $this->actingAs($this->student);
        foreach ([UploadedFile::fake()->create('unsafe.html', 2, 'text/html'), UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')] as $file) {
            $this->post('/support', $this->data(['file' => $file]))->assertSessionHasErrors('file');
        }
        $this->post('/support', $this->data(['body' => '', 'file' => UploadedFile::fake()->create('valid.pdf', 2, 'application/pdf')]))->assertRedirect();
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_attachment_replay_has_one_file_and_changed_file_is_rejected(): void
    {
        $data = $this->data(['file' => UploadedFile::fake()->createWithContent('a.txt', 'private unique file')]);
        $this->actingAs($this->student)->post('/support', $data)->assertRedirect();
        $this->post('/support', [...$data, 'file' => UploadedFile::fake()->createWithContent('a.txt', 'private unique file')])->assertRedirect();
        $this->post('/support', [...$data, 'file' => UploadedFile::fake()->createWithContent('a.txt', 'changed file')])->assertSessionHasErrors('request_key');
        $this->assertCount(1, Storage::disk('support')->allFiles());
    }

    public function test_private_thread_and_queues_deny_peers_and_organization_managers(): void
    {
        $ticket = $this->ticket();
        foreach ([$this->user('student'), $this->user('org_admin'), $this->user('coach')] as $outsider) {
            $this->actingAs($outsider)->get('/support/'.$ticket->id)->assertForbidden();
            $this->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertForbidden();
            $this->get('/support?view=admin')->assertForbidden();
            $this->get('/support')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        }
        $this->actingAs($this->student)->get('/support?view=coach')->assertForbidden();
        $this->actingAs($this->admin)->get('/support?view=admin')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1));
    }

    public function test_academic_message_is_visible_only_to_current_assigned_coach(): void
    {
        [$ticket, $coach, $enrollment] = $this->academic();
        $this->actingAs($coach)->get('/support/'.$ticket->id)->assertOk();
        $this->get('/support?view=coach')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1));
        $this->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertRedirect();
        $this->assertSame('waiting_requester', $ticket->fresh()->status);
        $replacement = $this->user('coach');
        $ticket->cohort->course->update(['coach_id' => $replacement->id]);
        $this->get('/support/'.$ticket->id)->assertForbidden();
        $this->get('/support?view=coach')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        $this->actingAs($replacement)->get('/support/'.$ticket->id)->assertOk();
    }

    public function test_removed_enrollment_preserves_owner_archive_but_removes_coach_access_and_reply(): void
    {
        [$ticket, $coach, $enrollment] = $this->academic();
        $enrollment->update(['status' => 'cancelled']);
        $this->actingAs($coach)->get('/support/'.$ticket->id)->assertForbidden();
        $this->get('/support?view=coach')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        $this->actingAs($this->student)->get('/support/'.$ticket->id)->assertInertia(fn (Assert $page) => $page->where('can_reply', false));
        $this->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertSessionHasErrors('body');
        $this->actingAs($this->admin)->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertRedirect();
    }

    public function test_unenrolled_or_unverified_users_cannot_start_academic_context(): void
    {
        $cohort = Cohort::factory()->create();
        $this->actingAs($this->student)->post('/support', $this->data(['category' => 'academic', 'cohort_id' => $cohort->id]))->assertForbidden();
        $this->student->update(['phone_verified_at' => null]);
        $this->post('/support', $this->data())->assertForbidden();
        $this->assertSame(0, SupportTicket::count());
    }

    public function test_internal_notes_hide_body_file_count_history_and_notifications_from_owner_and_coach(): void
    {
        [$ticket, $coach] = $this->academic();
        $this->student->notifications()->delete();
        $coach->notifications()->delete();
        $this->actingAs($this->admin)->post('/support/'.$ticket->id.'/messages', [...$this->reply($ticket, 'محرمانه داخلی', true), 'file' => UploadedFile::fake()->create('internal.pdf', 2, 'application/pdf')])->assertRedirect();
        $note = TicketMessage::latest('id')->firstOrFail();
        $this->assertSame('waiting_staff', $ticket->fresh()->status);
        $this->assertSame(1, $ticket->fresh()->revision);
        foreach ([$this->student, $coach] as $viewer) {
            $this->actingAs($viewer)->get('/support/'.$ticket->id)->assertInertia(fn (Assert $page) => $page->has('messages.data', 1)->where('messages.total', 1)->where('messages.data.0.body', 'متن خصوصی درخواست')->missing('ticket.assigned_to'));
            $this->get('/support-files/'.$note->id)->assertForbidden();
            $this->assertSame(0, $viewer->notifications()->count());
        }
        $this->actingAs($this->admin)->get('/support/'.$ticket->id)->assertInertia(fn (Assert $page) => $page->has('messages.data', 2)->where('messages.data.1.body', 'محرمانه داخلی'));
        $this->get('/support-files/'.$note->id)->assertDownload();
    }

    public function test_owner_and_coach_cannot_forge_internal_notes_or_assignment(): void
    {
        [$ticket, $coach] = $this->academic();
        foreach ([$this->student, $coach] as $actor) {
            $this->actingAs($actor)->post('/support/'.$ticket->id.'/messages', $this->reply($ticket, 'مخفی', true))->assertForbidden();
            $this->post('/support/'.$ticket->id.'/change', ['revision' => 1, 'action' => 'assign', 'assigned_to' => $this->admin->id, 'reason' => 'ارجاع'])->assertForbidden();
        }
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_public_reply_status_and_idempotent_replay_do_not_duplicate_messages_or_notifications(): void
    {
        $ticket = $this->ticket();
        $data = $this->reply($ticket);
        $this->actingAs($this->admin)->post('/support/'.$ticket->id.'/messages', $data)->assertRedirect();
        $this->post('/support/'.$ticket->id.'/messages', $data)->assertRedirect();
        $this->post('/support/'.$ticket->id.'/messages', [...$data, 'body' => 'تغییر'])->assertSessionHasErrors('request_key');
        $this->assertSame(2, TicketMessage::count());
        $this->assertSame(2, $ticket->fresh()->revision);
        $this->assertSame(1, $this->student->notifications()->count());
        $this->actingAs($this->student)->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertRedirect();
        $this->assertSame('waiting_staff', $ticket->fresh()->status);
    }

    public function test_reply_key_cannot_be_reused_for_another_ticket_or_new_creation(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        $data = $this->reply($ticket);
        $this->actingAs($this->student)->post('/support/'.$ticket->id.'/messages', $data)->assertRedirect();
        $this->post('/support/'.$other->id.'/messages', $data)->assertSessionHasErrors('request_key');
        $this->post('/support', $this->data(['request_key' => $data['request_key']]))->assertSessionHasErrors('request_key');
        $this->assertSame(2, SupportTicket::count());
    }

    public function test_resolve_requires_explicit_reopen_and_closed_request_is_admin_controlled(): void
    {
        $ticket = $this->ticket();
        $url = '/support/'.$ticket->id;
        $this->actingAs($this->student)->post($url.'/change', ['revision' => 1, 'action' => 'resolve', 'reason' => 'حل شد'])->assertRedirect();
        $this->post($url.'/messages', $this->reply($ticket))->assertSessionHasErrors('body');
        $this->post($url.'/change', ['revision' => 2, 'action' => 'reopen', 'reason' => 'پیگیری'])->assertRedirect();
        $this->post($url.'/messages', $this->reply($ticket))->assertRedirect();
        $this->post($url.'/change', ['revision' => 4, 'action' => 'close', 'reason' => 'بستن'])->assertForbidden();
        $this->actingAs($this->admin)->post($url.'/change', ['revision' => 4, 'action' => 'close', 'reason' => 'پایان رسیدگی'])->assertRedirect();
        $this->actingAs($this->student)->post($url.'/change', ['revision' => 5, 'action' => 'reopen', 'reason' => 'پیگیری'])->assertSessionHasErrors('action');
        $this->actingAs($this->admin)->post($url.'/messages', $this->reply($ticket))->assertSessionHasErrors('body');
        $this->post($url.'/change', ['revision' => 5, 'action' => 'reopen', 'reason' => 'بررسی مجدد'])->assertRedirect();
        $this->assertSame('waiting_staff', $ticket->fresh()->status);
        $this->assertSame(4, $ticket->events()->count());
    }

    public function test_stale_forms_cannot_overwrite_reply_or_assignment(): void
    {
        $ticket = $this->ticket();
        $this->actingAs($this->admin)->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertRedirect();
        $this->post('/support/'.$ticket->id.'/change', ['revision' => 1, 'action' => 'close', 'reason' => 'قدیمی'])->assertSessionHasErrors('revision');
        $this->assertSame('waiting_requester', $ticket->fresh()->status);
        $this->assertSame(0, $ticket->events()->count());
    }

    public function test_assignment_is_private_active_admin_only_and_does_not_reduce_other_admin_access(): void
    {
        $ticket = $this->ticket();
        $target = $this->user('admin');
        $this->actingAs($this->admin)->post('/support/'.$ticket->id.'/change', ['revision' => 1, 'action' => 'assign', 'assigned_to' => $this->student->id, 'reason' => 'نامعتبر'])->assertSessionHasErrors('assigned_to');
        $this->post('/support/'.$ticket->id.'/change', ['revision' => 1, 'action' => 'assign', 'assigned_to' => $target->id, 'reason' => 'علت داخلی ارجاع'])->assertRedirect();
        $this->get('/support?view=admin&assigned=unassigned')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        $this->actingAs($this->student)->get('/support/'.$ticket->id)->assertInertia(fn (Assert $page) => $page->has('events.data', 0)->missing('ticket.assigned_to'));
        $this->actingAs($this->admin)->get('/support/'.$ticket->id)->assertOk();
        $this->actingAs($target)->get('/support?view=admin&assigned=mine')->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1));
        $target->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->post('/support/'.$ticket->id.'/change', ['revision' => 2, 'action' => 'assign', 'assigned_to' => $target->id, 'reason' => 'غیرفعال'])->assertSessionHasErrors('assigned_to');
    }

    public function test_notifications_contain_generic_text_without_private_subject_or_body(): void
    {
        [$ticket, $coach] = $this->academic();
        $this->assertSame(1, $coach->notifications()->count());
        $json = json_encode($coach->notifications()->firstOrFail()->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString($ticket->subject, $json);
        $this->assertStringNotContainsString('متن خصوصی درخواست', $json);
        $this->assertStringContainsString('/support/'.$ticket->id, $json);
    }

    public function test_paginated_threads_exclude_internal_notes_from_every_owner_page(): void
    {
        $ticket = $this->ticket();
        TicketMessage::factory()->count(25)->create(['support_ticket_id' => $ticket->id, 'author_id' => $this->admin->id, 'author_role' => 'admin', 'internal_note' => true]);
        TicketMessage::factory()->count(21)->create(['support_ticket_id' => $ticket->id, 'author_id' => $this->student->id]);
        $this->actingAs($this->student)->get('/support/'.$ticket->id.'?page=2')->assertInertia(fn (Assert $page) => $page->where('messages.total', 22)->has('messages.data', 2)->where('messages.data.0.internal_note', false));
        $this->actingAs($this->admin)->get('/support/'.$ticket->id)->assertInertia(fn (Assert $page) => $page->where('messages.total', 47));
    }

    public function test_inactive_accounts_cannot_read_or_mutate_private_support(): void
    {
        $ticket = $this->ticket();
        $this->student->forceFill(['is_active' => false])->save();
        $this->actingAs($this->student)->get('/support/'.$ticket->id)->assertRedirect('/login');
        $this->actingAs($this->student)->post('/support/'.$ticket->id.'/messages', $this->reply($ticket))->assertRedirect('/login');
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_database_failure_cleans_up_uploaded_private_file(): void
    {
        TicketMessage::creating(fn () => throw new \RuntimeException('Simulated database write failure'));
        try {
            app(SupportDesk::class)->create($this->student, $this->data(), UploadedFile::fake()->create('private.pdf', 2, 'application/pdf'));
            $this->fail('The database failure should escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database write failure', $exception->getMessage());
        }
        $this->assertSame(0, SupportTicket::count());
        $this->assertCount(0, Storage::disk('support')->allFiles());
    }
}
