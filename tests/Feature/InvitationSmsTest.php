<?php

namespace Tests\Feature;

use App\Jobs\SendInvitationSms;
use App\Models\AuditEvent;
use App\Models\InvitationSms;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\InvitationSmsService;
use App\Services\OrganizationMembership;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InvitationSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $student;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'fixture-account', 'platform.sms.melipayamak.api_key' => 'fixture-api-secret', 'platform.sms.melipayamak.otp_body_id' => '123', 'platform.sms.melipayamak.invitation_body_id' => '456', 'platform.sms.melipayamak.invitations_enabled' => true]);
        $this->manager = User::factory()->create();
        $this->manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $this->student = User::factory()->create();
        $this->student->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->organization = Organization::factory()->create(['name' => 'سازمان آزمایشی', 'type' => 'university', 'status' => 'active']);
        $this->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
    }

    private function invitation(): OrganizationInvitation
    {
        app(OrganizationMembership::class)->invite($this->manager, $this->organization, $this->student->phone);

        return OrganizationInvitation::firstOrFail();
    }

    private function send(InvitationSms $sms): void
    {
        (new SendInvitationSms($sms->id))->handle(app(InvitationSmsService::class), app(SmsSender::class));
    }

    public function test_invitation_sms_is_opt_in_and_does_not_change_default_internal_notice(): void
    {
        config(['platform.sms.melipayamak.invitations_enabled' => false]);
        $this->invitation();
        $this->assertDatabaseCount('invitation_sms', 0);
        $this->assertSame(1, $this->student->notifications()->count());
        Http::assertNothingSent();
    }

    public function test_new_invitation_is_queued_once_without_inline_network_or_membership_creation(): void
    {
        $this->invitation();
        $this->invitation();
        $this->assertDatabaseCount('invitation_sms', 1);
        $this->assertDatabaseHas('invitation_sms', ['status' => 'queued', 'invitation_revision' => 1]);
        $this->assertSame(1, $this->organization->members()->count());
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_outbox_rolls_back_with_invitation_creation(): void
    {
        try {
            DB::transaction(function () {
                $this->invitation();
                throw new \RuntimeException('Fixture rollback');
            });
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('invitation_sms', 0);
            $this->assertDatabaseCount('organization_invitations', 0);
            $this->assertSame(0, $this->student->notifications()->count());
        }
        Http::assertNothingSent();
    }

    public function test_dispatcher_enqueues_only_identifiers_on_durable_sms_queue_and_throttles_recovery(): void
    {
        Queue::fake();
        $this->invitation();
        $service = app(InvitationSmsService::class);
        $this->assertSame(1, $service->dispatchPending());
        $this->assertSame(0, $service->dispatchPending());
        Queue::assertPushed(SendInvitationSms::class, fn ($job) => $job->connection === 'database' && $job->queue === 'sms' && $job->smsId === InvitationSms::first()->id && $job->tries === 1);
        Queue::assertPushed(SendInvitationSms::class, 1);
        $this->travel(11)->minutes();
        $this->assertSame(1, $service->dispatchPending());
        Queue::assertPushed(SendInvitationSms::class, 2);
        Http::assertNothingSent();
    }

    public function test_worker_sends_current_invitation_once_and_preserves_provider_acceptance_without_claiming_delivery(): void
    {
        $this->invitation();
        $sms = InvitationSms::firstOrFail();
        $transactionLevel = DB::transactionLevel();
        Http::fake(function ($request, $options) use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(456, $request['bodyId']);

            return Http::response(['RetStatus' => 1, 'Value' => '123456789012345678901']);
        });
        $this->send($sms);
        $this->send($sms);
        $this->assertSame('accepted', $sms->fresh()->status);
        $this->assertSame('123456789012345678901', $sms->fresh()->provider_reference);
        $this->assertNotNull($sms->fresh()->attempted_at);
        $this->assertDatabaseCount('organization_user', 1);
        $this->assertSame(1, AuditEvent::where('action', 'invitation_sms.accepted')->count());
        Http::assertSentCount(1);
    }

    public function test_worker_skips_stale_revoked_accepted_expired_or_inactive_invites_before_provider_call(): void
    {
        foreach (['revoked', 'accepted', 'expired', 'revision', 'organization'] as $case) {
            $invitation = OrganizationInvitation::factory()->create(['organization_id' => $this->organization->id, 'created_by' => $this->manager->id]);
            app(InvitationSmsService::class)->enqueue($invitation, $this->organization);
            $sms = InvitationSms::latest('id')->firstOrFail();
            if (in_array($case, ['revoked', 'accepted'], true)) {
                $invitation->update(['status' => $case]);
            } elseif ($case === 'expired') {
                $invitation->update(['expires_at' => now()->subMinute()]);
            } elseif ($case === 'revision') {
                $invitation->update(['revision' => 2]);
            } else {
                $this->organization->update(['status' => 'inactive']);
            }
            $this->send($sms);
            $this->assertSame('skipped', $sms->fresh()->status);
        }
        Http::assertNothingSent();
    }

    public function test_disabling_sms_after_queueing_skips_queued_message(): void
    {
        $this->invitation();
        config(['platform.sms.melipayamak.invitations_enabled' => false]);
        $sms = InvitationSms::firstOrFail();
        $this->send($sms);
        $this->assertSame('skipped', $sms->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_transport_timeout_stays_unknown_without_retry_or_raw_error_storage(): void
    {
        $this->invitation();
        $sms = InvitationSms::firstOrFail();
        Http::fake(['rest.payamak-panel.com/*' => Http::failedConnection('fixture-api-secret transport failure')]);
        $this->send($sms);
        $this->send($sms);
        $this->assertSame('unknown', $sms->fresh()->status);
        $this->assertSame('transport_unknown', $sms->fresh()->failure_code);
        $this->assertNull($sms->fresh()->provider_reference);
        $this->assertStringNotContainsString('fixture-api-secret', $sms->fresh()->toJson());
        $this->travel(11)->minutes();
        $this->assertSame(0, app(InvitationSmsService::class)->dispatchPending());
        Http::assertSentCount(1);
    }

    public function test_explicit_provider_failure_is_recorded_and_not_automatically_retried(): void
    {
        $this->invitation();
        Http::fake(['rest.payamak-panel.com/*' => Http::response(['RetStatus' => 1, 'Value' => 2, 'StrRetStatus' => 'fixture-api-secret'])]);
        $sms = InvitationSms::firstOrFail();
        $this->send($sms);
        $this->send($sms);
        $this->assertSame('failed', $sms->fresh()->status);
        $this->assertSame('provider_rejected', $sms->fresh()->failure_code);
        Http::assertSentCount(1);
    }

    public function test_interrupted_attempt_becomes_unknown_and_repeated_job_cannot_resend(): void
    {
        $this->invitation();
        $sms = InvitationSms::firstOrFail();
        $sms->update(['status' => 'processing', 'attempted_at' => now()->subMinutes(11)]);
        Queue::fake();
        $this->artisan('platform:dispatch-invitation-sms')->expectsOutputToContain('interrupted sends marked unknown: 1')->assertSuccessful();
        $this->send($sms);
        $this->assertSame('unknown', $sms->fresh()->status);
        $this->assertSame('worker_interrupted', $sms->fresh()->failure_code);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_renewal_keeps_old_sms_history_and_queues_new_revision_only(): void
    {
        $invitation = $this->invitation();
        app(OrganizationMembership::class)->change($this->manager, $invitation, 1, 'revoke');
        app(OrganizationMembership::class)->change($this->manager, $invitation, 2, 'renew');
        $this->assertDatabaseCount('invitation_sms', 2);
        $old = InvitationSms::orderBy('id')->firstOrFail();
        $this->send($old);
        $this->assertSame('skipped', $old->fresh()->status);
        $this->assertSame(3, InvitationSms::latest('id')->first()->invitation_revision);
        Http::assertNothingSent();
    }

    public function test_csv_confirmation_queues_messages_atomically_without_network_wait(): void
    {
        $membership = app(OrganizationMembership::class);
        $preview = $membership->preview($this->manager, $this->organization, UploadedFile::fake()->createWithContent('members.csv', "phone\n09121234567\n09121234568\n"));
        $this->assertDatabaseCount('invitation_sms', 0);
        $membership->commit($this->manager, $preview);
        $membership->commit($this->manager, $preview);
        $this->assertDatabaseCount('invitation_sms', 2);
        Http::assertNothingSent();
    }

    public function test_missing_live_configuration_fails_without_using_log_driver_or_sending(): void
    {
        $this->invitation();
        config(['platform.sms.driver' => 'log']);
        $sms = InvitationSms::firstOrFail();
        $this->send($sms);
        $this->assertSame('failed', $sms->fresh()->status);
        $this->assertSame('unconfigured', $sms->fresh()->failure_code);
        Http::assertNothingSent();
    }

    public function test_organization_name_snapshot_cannot_inject_additional_pattern_arguments(): void
    {
        $this->organization->update(['name' => "سازمان;نمونه\nتازه"]);
        $this->invitation();
        $this->organization->update(['name' => 'نام بعدی']);
        Http::fake(['rest.payamak-panel.com/*' => Http::response(['RetStatus' => 1, 'Value' => '123456789012345678901'])]);
        $this->send(InvitationSms::firstOrFail());
        Http::assertSent(fn ($request) => $request['text'] === 'سازمان نمونه تازه' && $request['bodyId'] === 456);
    }

    public function test_manager_can_track_sms_but_student_props_exclude_provider_details_and_credentials(): void
    {
        $invitation = $this->invitation();
        InvitationSms::first()->update(['status' => 'accepted', 'provider_reference' => '123456789012345678901']);
        $this->actingAs($this->manager)->get('/organization/invitations')->assertInertia(fn (Assert $page) => $page->where('sms_enabled', true)->where('invitations.data.0.sms.status', 'accepted')->missing('invitations.data.0.sms.failure_code'))->assertDontSee('fixture-api-secret');
        $this->actingAs($this->student)->get('/organization-invitations')->assertInertia(fn (Assert $page) => $page->where('invitations.data.0.id', $invitation->id)->where('invitations.data.0.sms', null));
    }

    public function test_revocation_during_provider_request_does_not_reactivate_membership(): void
    {
        $invitation = $this->invitation();
        Http::fake(function () use ($invitation) {
            app(OrganizationMembership::class)->change($this->manager, $invitation, 1, 'revoke');

            return Http::response(['RetStatus' => 1, 'Value' => '123456789012345678901']);
        });
        $this->send(InvitationSms::firstOrFail());
        $this->assertSame('accepted', InvitationSms::first()->status);
        $this->assertSame('revoked', $invitation->fresh()->status);
        $this->actingAs($this->student)->post('/organization-invitations/'.$invitation->id.'/respond', ['revision' => 1, 'action' => 'accept', 'consent' => true])->assertSessionHasErrors('invitation');
        $this->assertDatabaseCount('organization_user', 1);
    }
}
