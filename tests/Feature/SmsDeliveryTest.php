<?php

namespace Tests\Feature;

use App\Jobs\PollSmsDelivery;
use App\Jobs\SendInvitationSms;
use App\Jobs\SendReminderSms;
use App\Models\AuditEvent;
use App\Models\InvitationSms;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\SmsDelivery;
use App\Models\SmsDeliveryAttempt;
use App\Models\User;
use App\Services\InvitationSmsService;
use App\Services\MelipayamakClient;
use App\Services\SmsDeliveryService;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        Http::preventStrayRequests();
        Queue::fake();
        config(['platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'fixture-sender', 'platform.sms.melipayamak.api_key' => 'fixture-api-secret', 'platform.sms.melipayamak.delivery_enabled' => true]);
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function delivery(array $changes = []): SmsDelivery
    {
        return SmsDelivery::factory()->create(['provider_reference' => '12345678901234567', ...$changes]);
    }

    private function poll(SmsDelivery $delivery, mixed $value, mixed $retStatus = 1): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2' => Http::response(json_encode(['RetStatus' => $retStatus, 'Value' => $value, 'StrRetStatus' => 'fixture-api-secret must never be persisted'], JSON_PRESERVE_ZERO_FRACTION), 200, ['Content-Type' => 'application/json'])]);
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
    }

    public function test_provider_acceptance_is_not_delivery_and_query_preserves_large_reference_string(): void
    {
        $delivery = $this->delivery();
        $this->assertSame('pending', $delivery->status);
        $this->poll($delivery, '1');
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->last_code);
        Http::assertSent(fn ($request) => $request->url() === 'https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2' && $request['recId'] === '12345678901234567');
        Http::assertSentCount(1);
        $this->assertSame('delivered', $delivery->attemptsLog()->firstOrFail()->status);
    }

    public function test_official_carrier_and_queued_codes_remain_pending_until_handset_receipt(): void
    {
        foreach ([0, 8, 100, 200, 400, 5] as $index => $code) {
            $delivery = $this->delivery(['provider_reference' => (string) (1234567800 + $index)]);
            $this->poll($delivery, $code);
            $this->assertSame('pending', $delivery->fresh()->status);
            $this->assertSame($code, $delivery->fresh()->last_code);
            $this->assertNotNull($delivery->fresh()->next_poll_at);
        }
    }

    public function test_official_non_delivery_codes_do_not_resend_the_original_message(): void
    {
        foreach ([2, 3, 16, 35, 300, 500] as $index => $code) {
            $delivery = $this->delivery(['provider_reference' => (string) (1234567900 + $index)]);
            $this->poll($delivery, (string) $code);
            $this->assertSame('undelivered', $delivery->fresh()->status);
            $this->assertSame($code, $delivery->fresh()->last_code);
            $this->assertNull($delivery->fresh()->next_poll_at);
        }
        Queue::assertNotPushed(SendInvitationSms::class);
        Queue::assertNotPushed(SendReminderSms::class);
    }

    public function test_bad_credentials_and_reporting_access_errors_are_not_delivery_failures(): void
    {
        foreach ([-110, -109, -108, -3] as $index => $code) {
            $delivery = $this->delivery(['provider_reference' => (string) (1234568000 + $index)]);
            $this->poll($delivery, $code);
            $this->assertSame('unavailable', $delivery->fresh()->status);
            $this->assertSame('provider_query_error', $delivery->fresh()->error_code);
        }
        $missing = $this->delivery(['provider_reference' => '1234568999']);
        $this->poll($missing, null);
        $this->assertSame('unavailable', $missing->fresh()->status);
        $this->assertSame('delivery_access_unavailable', $missing->fresh()->error_code);
    }

    public function test_malformed_types_and_failed_ret_status_never_confirm_delivery(): void
    {
        foreach ([[true, 1], [1.0, 1], [['1'], 1], ['1e0', 1], [1, false], [1, 0]] as $index => [$value, $retStatus]) {
            $delivery = $this->delivery(['provider_reference' => (string) (1234569000 + $index)]);
            $this->poll($delivery, $value, $retStatus);
            $this->assertSame('pending', $delivery->fresh()->status);
            $this->assertSame('invalid_query_response', $delivery->fresh()->error_code);
        }
    }

    public function test_http_failure_html_and_timeout_stay_pending_without_leaking_transport_secrets(): void
    {
        foreach (['http', 'html', 'timeout'] as $index => $kind) {
            $delivery = $this->delivery(['provider_reference' => (string) (1234570000 + $index)]);
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2' => match ($kind) {
                'http' => Http::response('fixture-api-secret', 500),
                'html' => Http::response('<html>fixture-api-secret</html>', 200),
                default => fn () => throw new ConnectionException('fixture-api-secret in transport URL'),
            }]);
            app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
            $this->assertSame('pending', $delivery->fresh()->status);
            $this->assertStringNotContainsString('fixture-api-secret', json_encode($delivery->fresh()->toArray()));
            $this->assertStringNotContainsString('fixture-api-secret', json_encode($delivery->attemptsLog()->get()->toArray()));
        }
    }

    public function test_account_or_mode_change_cannot_query_a_reference_under_another_sender(): void
    {
        $delivery = $this->delivery();
        config(['platform.sms.melipayamak.api_key' => 'different-fixture-secret']);
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
        $this->assertSame('unavailable', $delivery->fresh()->status);
        $this->assertSame('sender_account_changed', $delivery->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_console_mode_and_out_of_range_ids_are_preserved_without_guessing_an_endpoint(): void
    {
        config(['platform.sms.melipayamak.mode' => 'console', 'platform.sms.melipayamak.api_key' => 'fixture-console-key']);
        $console = $this->delivery(['provider_mode' => 'console']);
        app(SmsDeliveryService::class)->poll($console->id, app(MelipayamakClient::class));
        $this->assertSame('unsupported_delivery_mode', $console->fresh()->error_code);
        config(['platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.api_key' => 'fixture-api-secret']);
        $large = $this->delivery(['provider_reference' => '123456789012345678901']);
        app(SmsDeliveryService::class)->poll($large->id, app(MelipayamakClient::class));
        $this->assertSame('unsupported_reference', $large->fresh()->error_code);
        $this->assertSame('123456789012345678901', $large->fresh()->provider_reference);
        Http::assertNothingSent();
    }

    public function test_disabled_or_unconfigured_polling_does_not_contact_provider(): void
    {
        $delivery = $this->delivery();
        config(['platform.sms.melipayamak.delivery_enabled' => false]);
        $this->assertSame(0, app(SmsDeliveryService::class)->dispatchPending());
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
        $this->assertSame(0, SmsDeliveryAttempt::count());
        $this->actingAs($this->admin)->post('/admin/communications/'.$delivery->id.'/query', ['revision' => 1])->assertSessionHasErrors('delivery');
        config(['platform.sms.melipayamak.delivery_enabled' => true, 'platform.sms.driver' => 'disabled']);
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
        $this->assertSame('unconfigured', $delivery->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_duplicate_dispatchers_and_workers_do_not_duplicate_active_queries(): void
    {
        $delivery = $this->delivery();
        $service = app(SmsDeliveryService::class);
        $this->assertSame(1, $service->dispatchPending());
        $this->assertSame(0, $service->dispatchPending());
        Queue::assertPushed(PollSmsDelivery::class, 1);
        $attempt = $service->begin($delivery->id);
        $this->assertNotNull($attempt);
        $this->assertNull($service->begin($delivery->id));
        $this->assertSame(1, SmsDeliveryAttempt::count());
    }

    public function test_interrupted_query_is_recoverable_but_its_late_reply_cannot_override_newer_receipt(): void
    {
        $delivery = $this->delivery();
        $service = app(SmsDeliveryService::class);
        $old = $service->begin($delivery->id);
        $this->travel(11)->minutes();
        $this->assertSame(1, $service->reconcile());
        $new = $service->begin($delivery->id);
        $service->finish($new, ['state' => 'delivered', 'code' => 1, 'error' => null]);
        $service->finish($old, ['state' => 'undelivered', 'code' => 2, 'error' => null]);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->last_code);
        $this->assertSame('interrupted', $old->fresh()->status);
    }

    public function test_confirmed_receipt_is_terminal_for_automatic_and_admin_queries(): void
    {
        $delivery = $this->delivery();
        $this->poll($delivery, 1);
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
        Http::assertSentCount(1);
        $this->actingAs($this->admin)->post('/admin/communications/'.$delivery->id.'/query', ['revision' => $delivery->fresh()->revision])->assertSessionHasErrors('delivery');
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_expired_tracking_becomes_unknown_without_claiming_failed_delivery(): void
    {
        $delivery = $this->delivery(['expires_at' => now()->subMinute()]);
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
        $this->assertSame('unknown', $delivery->fresh()->status);
        $this->assertSame('tracking_window_expired', $delivery->fresh()->error_code);
        $this->assertSame(0, SmsDeliveryAttempt::count());
        Http::assertNothingSent();
    }

    public function test_manual_query_is_versioned_and_cannot_force_delivery_or_resend_sms(): void
    {
        $delivery = $this->delivery(['status' => 'unavailable', 'next_poll_at' => null]);
        $this->actingAs($this->admin)->post('/admin/communications/'.$delivery->id.'/query', ['revision' => 1, 'status' => 'delivered'])->assertRedirect();
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->revision);
        Queue::assertPushed(PollSmsDelivery::class, 1);
        Queue::assertNotPushed(SendInvitationSms::class);
        $this->post('/admin/communications/'.$delivery->id.'/query', ['revision' => 1])->assertSessionHasErrors('delivery');
        Http::assertNothingSent();
    }

    public function test_operations_page_and_query_actions_are_admin_only_and_redact_account_identity(): void
    {
        $delivery = $this->delivery();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/communications')->assertForbidden();
            $this->post('/admin/communications/'.$delivery->id.'/query', ['revision' => 1])->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/admin/communications?delivery='.$delivery->id)->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 1)->missing('deliveries.data.0.provider_account')->missing('deliveries.data.0.lease_token')->where('deliveries.data.0.provider_reference', '12345678901234567'))->assertDontSee('fixture-api-secret');
    }

    public function test_registration_deduplicates_origin_and_isolates_same_reference_under_other_accounts(): void
    {
        $account = app(MelipayamakClient::class)->account();
        $service = app(SmsDeliveryService::class);
        $a = $service->register('invitation', 1, '12345678901234567', $account);
        $again = $service->register('invitation', 1, '12345678901234567', $account);
        $this->assertSame($a->id, $again->id);
        $other = ['mode' => 'rest', 'fingerprint' => hash('sha256', 'different-account')];
        $b = $service->register('invitation', 2, '12345678901234567', $other);
        $this->assertNotSame($a->id, $b->id);
    }

    public function test_duplicate_provider_reference_across_invitations_is_quarantined_without_losing_send_state(): void
    {
        config(['platform.sms.melipayamak.invitation_body_id' => 123, 'platform.sms.melipayamak.invitations_enabled' => true]);
        $organization = Organization::factory()->create();
        foreach (['989121234567', '989121234568'] as $phone) {
            $invitation = OrganizationInvitation::factory()->create(['organization_id' => $organization->id, 'phone' => $phone]);
            app(InvitationSmsService::class)->enqueue($invitation, $organization);
        }
        Http::fake(['https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber' => Http::response(['RetStatus' => 1, 'Value' => '12345678901234567'])]);
        foreach (InvitationSms::get() as $sms) {
            app(InvitationSmsService::class)->send($sms->id, app(SmsSender::class));
        }
        $this->assertSame(1, InvitationSms::where('status', 'accepted')->count());
        $this->assertSame(1, InvitationSms::where('status', 'unknown')->where('failure_code', 'provider_reference_conflict')->count());
        $this->assertSame(1, SmsDelivery::count());
        Http::assertSentCount(2);
    }

    public function test_query_backoff_avoids_immediate_repoll_and_raw_provider_text_is_not_saved(): void
    {
        $delivery = $this->delivery();
        $this->poll($delivery, 0);
        $this->assertTrue($delivery->fresh()->next_poll_at->equalTo(now()->addMinutes(5)));
        app(SmsDeliveryService::class)->poll($delivery->id, app(MelipayamakClient::class));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('fixture-api-secret', json_encode(AuditEvent::get()->toArray()));
    }
}
