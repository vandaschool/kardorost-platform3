<?php

namespace Tests\Feature;

use App\Models\CoachCredential;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActionThrottleRequestsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function endpoints(string $middleware = 'throttle:2,1'): void
    {
        Route::middleware(['web', $middleware])->group(function (): void {
            Route::get('/__rate/records/{record}', fn () => response()->json(['ok' => true]));
            Route::post('/__rate/records/{record}', fn () => response()->json(['ok' => true]));
            Route::get('/__rate/other/{record}', fn () => response()->json(['ok' => true]));
        });
    }

    public function test_other_actual_resume_actions_do_not_consume_private_credential_upload_budget(): void
    {
        Storage::fake('learning');
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $this->actingAs($coach);
        for ($number = 1; $number <= 10; $number++) {
            $this->post('/career/resumes', ['title' => 'Software rate fixture '.$number])->assertRedirect();
        }
        $this->post('/career/resumes', ['title' => 'No further resume request'])->assertTooManyRequests();
        $file = UploadedFile::fake()->createWithContent('software-only.pdf', "%PDF-1.4\n% NOT-A-REAL-CREDENTIAL\n%%EOF\n");
        $this->post('/coach-credentials', ['title' => 'Software-only cross-action rate fixture', 'consent' => true, 'request_key' => (string) Str::uuid(), 'file' => $file])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('coach_credentials', 1);
        Storage::disk('learning')->assertExists(CoachCredential::sole()->file_path);
        $this->post('/career/resumes', ['title' => 'Original action still exhausted'])->assertTooManyRequests();
        Http::assertNothingSent();
    }

    public function test_anonymous_contact_hour_and_login_minute_budgets_keep_independent_counts_and_expiries(): void
    {
        $this->travelTo(now()->startOfSecond());
        for ($number = 0; $number < 5; $number++) {
            $this->postJson('/contact', [])->assertUnprocessable();
        }
        $contact = $this->postJson('/contact', [])->assertTooManyRequests();
        $this->assertGreaterThan(3000, (int) $contact->headers->get('Retry-After'));
        for ($number = 0; $number < 20; $number++) {
            $this->postJson('/login', ['phone' => 'invalid', 'password' => 'invalid'])->assertUnprocessable();
        }
        $login = $this->postJson('/login', ['phone' => 'invalid', 'password' => 'invalid'])->assertTooManyRequests();
        $this->assertLessThanOrEqual(60, (int) $login->headers->get('Retry-After'));
        $this->travel(61)->seconds();
        $this->postJson('/login', ['phone' => 'invalid', 'password' => 'invalid'])->assertUnprocessable();
        $this->postJson('/contact', [])->assertTooManyRequests();
        Http::assertNothingSent();
    }

    public function test_record_ids_query_strings_and_head_do_not_create_fresh_get_budgets(): void
    {
        $this->endpoints();
        $this->getJson('/__rate/records/1?page=1')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->getJson('/__rate/records/2?page=9')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $this->getJson('/__rate/records/999?new=true')->assertTooManyRequests();
        $this->head('/__rate/records/1000')->assertTooManyRequests();
        $this->getJson('/__rate/other/1')->assertOk();
    }

    public function test_different_http_actions_at_same_uri_have_independent_budgets(): void
    {
        $this->endpoints();
        $this->getJson('/__rate/records/1')->assertOk();
        $this->getJson('/__rate/records/2')->assertOk();
        $this->getJson('/__rate/records/3')->assertTooManyRequests();
        $this->postJson('/__rate/records/3')->assertOk();
        $this->postJson('/__rate/records/4')->assertOk();
        $this->postJson('/__rate/records/5')->assertTooManyRequests();
    }

    public function test_authenticated_actor_identity_remains_shared_across_ips_and_separate_between_accounts(): void
    {
        $this->endpoints();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first)->getJson('/__rate/records/1')->assertOk();
        $this->getJson('/__rate/records/2')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.7'])->getJson('/__rate/records/3')->assertTooManyRequests();
        $this->actingAs($second)->getJson('/__rate/records/3')->assertOk();
        $this->actingAs($first)->getJson('/__rate/other/3')->assertOk();
    }

    public function test_guest_ip_identity_still_limits_each_action_across_record_ids(): void
    {
        $this->endpoints();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->getJson('/__rate/records/1')->assertOk();
        $this->getJson('/__rate/records/2')->assertOk();
        $this->getJson('/__rate/records/3')->assertTooManyRequests();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.11'])->getJson('/__rate/records/3')->assertOk();
    }

    public function test_explicit_shared_prefix_keeps_intentional_cross_route_budget(): void
    {
        $this->endpoints('throttle:2,1,intentional-shared:');
        $this->getJson('/__rate/records/1')->assertOk();
        $this->getJson('/__rate/other/2')->assertOk();
        $this->postJson('/__rate/records/3')->assertTooManyRequests();
    }

    public function test_named_limiter_keeps_its_intentional_group_and_callback_semantics(): void
    {
        RateLimiter::for('test-intentional-group', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));
        $this->endpoints('throttle:test-intentional-group');
        $this->getJson('/__rate/records/1')->assertOk();
        $this->getJson('/__rate/other/2')->assertOk();
        $this->postJson('/__rate/records/3')->assertTooManyRequests();
    }
}
