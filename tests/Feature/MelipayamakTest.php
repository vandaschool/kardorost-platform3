<?php

namespace Tests\Feature;

use App\Exceptions\SmsFailure;
use App\Models\OtpChallenge;
use App\Services\MelipayamakClient;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MelipayamakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'fixture-account', 'platform.sms.melipayamak.api_key' => 'fixture-api-secret', 'platform.sms.melipayamak.otp_body_id' => '123', 'platform.sms.melipayamak.invitation_body_id' => '456']);
    }

    public function test_rest_otp_sends_one_normalized_recipient_and_server_generated_code_to_approved_pattern(): void
    {
        Http::fake(['rest.payamak-panel.com/*' => Http::response(['RetStatus' => 1, 'Value' => '123456789012345678901'])]);
        $this->post('/auth/send-code', ['phone' => '۰۹۱۲۳۴۵۶۷۸۹', 'mode' => 'register'])->assertRedirect('/register')->assertSessionHasNoErrors();
        $challenge = OtpChallenge::firstOrFail();
        Http::assertSent(fn ($request) => $request->url() === 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber' && $request['username'] === 'fixture-account' && $request['password'] === 'fixture-api-secret' && $request['bodyId'] === 123 && $request['to'] === '09123456789' && preg_match('/^[0-9]{5}$/D', $request['text']) && Hash::check($request['text'], $challenge->code_hash));
        Http::assertSentCount(1);
        $this->assertArrayNotHasKey('code_hash', $challenge->toArray());
    }

    public function test_console_mode_uses_json_positional_arguments_and_does_not_require_username(): void
    {
        config(['platform.sms.melipayamak.mode' => 'console', 'platform.sms.melipayamak.username' => null]);
        Http::fake(['console.melipayamak.com/*' => Http::response(['recId' => 3741437414, 'status' => ''])]);
        app(SmsSender::class)->sendCode('+98 912 345 6789', '12345');
        Http::assertSent(fn ($request) => $request->url() === 'https://console.melipayamak.com/api/send/shared/fixture-api-secret' && $request['bodyId'] === 123 && $request['to'] === '09123456789' && $request['args'] === ['12345'] && ! isset($request['username']) && $request->hasHeader('Content-Type', 'application/json'));
        Http::assertSentCount(1);
    }

    public function test_message_reference_larger_than_php_integer_is_preserved_exactly(): void
    {
        Http::fake(['rest.payamak-panel.com/*' => Http::response('{"RetStatus":1,"Value":123456789012345678901}', 200, ['Content-Type' => 'application/json'])]);
        $this->assertSame('123456789012345678901', app(SmsSender::class)->sendInvitation('989123456789', 'سازمان آزمایشی'));
    }

    public function test_positive_provider_error_codes_are_not_mistaken_for_message_references(): void
    {
        foreach ([0, 2, 6, 7, 10, 11, 12, 16, 17, 18, 19, -110] as $code) {
            Http::fake(['rest.payamak-panel.com/*' => Http::response(['RetStatus' => 1, 'Value' => $code])]);
            try {
                app(SmsSender::class)->sendCode('989123456789', '12345');
                $this->fail('Provider error must not be accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('phone', $exception->errors());
            }
        }
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_provider_rejection_does_not_create_an_otp_challenge_or_verified_flow(): void
    {
        Http::fake(['rest.payamak-panel.com/*' => Http::response(['RetStatus' => 0, 'Value' => '0', 'StrRetStatus' => 'fixture-api-secret'])]);
        $this->post('/auth/send-code', ['phone' => '09123456789', 'mode' => 'register'])->assertSessionHasErrors('phone')->assertSessionMissing('verification');
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->get('/register')->assertDontSee('fixture-api-secret')->assertDontSee('fixture-account');
    }

    public function test_missing_credentials_template_or_unknown_mode_never_make_a_network_request(): void
    {
        foreach ([['platform.sms.melipayamak.api_key' => null], ['platform.sms.melipayamak.username' => null], ['platform.sms.melipayamak.otp_body_id' => null], ['platform.sms.melipayamak.otp_body_id' => '2147483648'], ['platform.sms.melipayamak.mode' => 'soap']] as $override) {
            config(['platform.sms.melipayamak.api_key' => 'fixture-api-secret', 'platform.sms.melipayamak.username' => 'fixture-account', 'platform.sms.melipayamak.otp_body_id' => '123', 'platform.sms.melipayamak.mode' => 'rest', ...$override]);
            try {
                app(SmsSender::class)->sendCode('989123456789', '12345');
                $this->fail('Incomplete configuration must fail closed.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('phone', $exception->errors());
            }
        }
        Http::assertNothingSent();
    }

    public function test_console_api_key_cannot_change_the_request_path_or_host(): void
    {
        config(['platform.sms.melipayamak.mode' => 'console', 'platform.sms.melipayamak.api_key' => 'secret/../../other?to=someone']);
        $this->assertFalse(app(MelipayamakClient::class)->configured('123'));
        Http::assertNothingSent();
    }

    public function test_timeouts_are_classified_as_unknown_and_never_retried_or_logged_with_credentials(): void
    {
        Log::spy();
        Http::fake(['rest.payamak-panel.com/*' => Http::failedConnection('fixture-api-secret transport detail')]);
        try {
            app(SmsSender::class)->sendInvitation('989123456789', 'سازمان');
            $this->fail('Connection loss must be uncertain.');
        } catch (SmsFailure $failure) {
            $this->assertTrue($failure->uncertain);
            $this->assertSame('transport_unknown', $failure->failureCode);
            $this->assertStringNotContainsString('fixture-api-secret', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
        }
        Http::assertSentCount(1);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('info');
    }

    public function test_redirect_server_error_malformed_or_missing_result_remain_unknown(): void
    {
        foreach ([[302, ''], [408, 'request timeout'], [503, 'server error'], [200, 'not JSON'], [200, '{"RetStatus":1}'], [200, '{"RetStatus":1,"Value":1.2}'], [200, '{"RetStatus":1,"Value":true}']] as [$status, $body]) {
            Http::fake(['rest.payamak-panel.com/*' => Http::response($body, $status, ['Location' => 'https://untrusted.example'])]);
            try {
                app(SmsSender::class)->sendInvitation('989123456789', 'سازمان');
                $this->fail('An ambiguous response must not count as sent.');
            } catch (SmsFailure $failure) {
                $this->assertTrue($failure->uncertain);
            }
        }
    }

    public function test_http_client_rejection_is_a_definite_failure(): void
    {
        Http::fake(['rest.payamak-panel.com/*' => Http::response('rejected fixture-api-secret', 401)]);
        try {
            app(SmsSender::class)->sendInvitation('989123456789', 'سازمان');
            $this->fail('A rejection must fail.');
        } catch (SmsFailure $failure) {
            $this->assertFalse($failure->uncertain);
            $this->assertSame('provider_rejected', $failure->failureCode);
        }
    }

    public function test_otp_log_driver_still_works_locally_and_no_provider_secret_reaches_login_props(): void
    {
        config(['platform.sms.driver' => 'log']);
        Log::shouldReceive('channel')->with('single')->once()->andReturnSelf();
        Log::shouldReceive('info')->once();
        app(SmsSender::class)->sendCode('989123456789', '12345');
        Http::assertNothingSent();
        $this->get('/login')->assertDontSee('fixture-api-secret')->assertDontSee('fixture-account');
    }

    public function test_status_command_does_not_print_secrets_or_send_sms(): void
    {
        $this->artisan('platform:sms-status')->expectsOutputToContain('OTP configuration ready')->doesntExpectOutputToContain('fixture-api-secret')->doesntExpectOutputToContain('fixture-account')->assertSuccessful();
        Http::assertNothingSent();
    }
}
