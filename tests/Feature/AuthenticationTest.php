<?php

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\OtpService;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_login_page_renders_without_private_configuration(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('SMS_API_KEY');
    }

    public function test_registration_requires_phone_proof_and_never_accepts_a_client_role(): void
    {
        $this->post('/auth/complete', ['name' => 'مریم', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => 'admin'])->assertSessionHasErrors('phone');
        $code = null;
        $this->mock(SmsSender::class, function ($mock) use (&$code) {
            $mock->shouldReceive('sendCode')->once()->andReturnUsing(function ($phone, $value) use (&$code) {
                $code = $value;
            });
        });
        $this->post('/auth/send-code', ['phone' => '۰۹۱۲۳۴۵۶۷۸۹', 'mode' => 'register'])->assertRedirect('/register');
        $challenge = OtpChallenge::first();
        $this->assertTrue(Hash::check($code, $challenge->code_hash));
        $this->assertNotSame($code, $challenge->code_hash);
        $this->post('/auth/verify-code', ['code' => $code])->assertRedirect('/register');
        $this->post('/auth/complete', ['name' => 'مریم احمدی', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => 'admin'])->assertRedirect('/panel');
        $user = User::first();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('989123456789', $user->phone);
        $this->assertSame(['student'], $user->roleKeys());
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_wrong_codes_consume_attempts_and_code_cannot_be_replayed(): void
    {
        $challenge = OtpChallenge::create(['phone' => '989123456789', 'purpose' => 'register', 'code_hash' => Hash::make('12345'), 'expires_at' => now()->addMinutes(2)]);
        $service = app(OtpService::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($service->consume($challenge->id, $challenge->phone, 'register', '99999'));
        }
        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertFalse($service->consume($challenge->id, $challenge->phone, 'register', '12345'));
        $challenge->update(['attempts' => 0]);
        $this->assertTrue($service->consume($challenge->id, $challenge->phone, 'register', '12345'));
        $this->assertFalse($service->consume($challenge->id, $challenge->phone, 'register', '12345'));
    }

    public function test_expired_or_wrong_purpose_code_cannot_be_consumed(): void
    {
        $c = OtpChallenge::create(['phone' => '989123456789', 'purpose' => 'register', 'code_hash' => Hash::make('12345'), 'expires_at' => now()->subSecond()]);
        $this->assertFalse(app(OtpService::class)->consume($c->id, $c->phone, 'register', '12345'));
        $c->update(['expires_at' => now()->addMinutes(2)]);
        $this->assertFalse(app(OtpService::class)->consume($c->id, $c->phone, 'forgot-password', '12345'));
    }

    public function test_phone_formats_login_to_same_account_and_disabled_user_cannot_login(): void
    {
        $user = User::factory()->create(['phone' => '989123456789', 'password' => 'secret123']);
        $this->post('/login', ['phone' => '+98 912 345 6789', 'password' => 'secret123'])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        $user->forceFill(['is_active' => false])->save();
        $this->post('/login', ['phone' => '09123456789', 'password' => 'secret123'])->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_reset_requires_verified_flow_and_changes_existing_password(): void
    {
        $user = User::factory()->create(['phone' => '989123456789', 'password' => 'old-password']);
        $this->withSession(['verification' => ['phone' => $user->phone, 'mode' => 'forgot-password', 'id' => 1, 'verified_until' => time() - 1]])->post('/auth/complete', ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasErrors('phone');
        $code = null;
        $this->mock(SmsSender::class, function ($mock) use (&$code) {
            $mock->shouldReceive('sendCode')->once()->andReturnUsing(function ($phone, $value) use (&$code) {
                $code = $value;
            });
        });
        $this->post('/auth/send-code', ['phone' => '09123456789', 'mode' => 'forgot-password'])->assertRedirect('/forgot-password');
        $this->post('/auth/verify-code', ['code' => $code])->assertRedirect('/forgot-password');
        $this->post('/auth/complete', ['password' => 'new-password', 'password_confirmation' => 'new-password'])->assertRedirect('/panel');
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password', $user->fresh()->password));
        $this->assertCount(1, User::all());
    }

    public function test_missing_sms_configuration_never_claims_success(): void
    {
        config(['platform.sms.driver' => 'disabled']);
        $this->post('/auth/send-code', ['phone' => '09123456789', 'mode' => 'register'])->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_log_sms_cannot_run_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['platform.sms.driver' => 'log']);
        $this->expectException(ValidationException::class);
        app(SmsSender::class)->sendCode('989123456789', '12345');
    }

    public function test_repeated_login_failures_are_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['phone' => '09123456789', 'password' => 'wrong'])->assertSessionHasErrors('phone');
        }
        $this->assertTrue(RateLimiter::tooManyAttempts('login:989123456789:127.0.0.1', 5));
    }
}
