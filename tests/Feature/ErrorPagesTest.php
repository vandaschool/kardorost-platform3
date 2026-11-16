<?php

namespace Tests\Feature;

use App\Services\ErrorPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.debug' => false]);
        Http::preventStrayRequests();
        Route::middleware('web')->get('/__problem/status/{status}', fn ($status) => abort((int) $status, 'PRIVATE EXCEPTION DETAIL'));
    }

    public function test_html_errors_are_persian_standalone_private_and_do_not_include_exception_or_url_details(): void
    {
        foreach ([403, 404, 405, 419, 429, 500, 503] as $status) {
            $this->get('/__problem/status/'.$status.'?private_marker=PRIVATE_URL_VALUE')->assertStatus($status)
                ->assertSee('کاردرست')->assertSee('رفتن به پنل')->assertSee('صفحه اصلی')->assertSee('data-problem-page', false)
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
                ->assertDontSee('PRIVATE EXCEPTION DETAIL')->assertDontSee('PRIVATE_URL_VALUE')->assertDontSee('<form', false)->assertDontSee('/build/', false);
        }
        Http::assertNothingSent();
    }

    public function test_unknown_route_without_session_middleware_gets_the_same_generic_html_page(): void
    {
        $this->get('/missing-PERSONAL-OPAQUE-LINK')->assertNotFound()->assertSee('این صفحه در دسترس نیست')->assertDontSee('PERSONAL-OPAQUE-LINK');
    }

    public function test_inertia_error_has_minimal_page_props_without_private_or_database_shared_data(): void
    {
        $this->get('/login')->assertOk();
        $calls = 0;
        Inertia::share('private_shared', function () use (&$calls) {
            $calls++;

            return DB::table('users')->count();
        });
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'Accept' => 'text/html'])->get('/__problem/status/403')->assertForbidden()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'ErrorPage')->assertJsonPath('props.status', 403)->assertJsonPath('props.retry_after', null)->assertJsonMissingPath('props.auth')->assertJsonMissingPath('props.private_shared')->assertJsonMissingPath('props.flash')->assertJsonMissingPath('props.unreadNotifications');
        $this->assertSame(0, $calls);
    }

    public function test_json_clients_and_api_paths_keep_original_error_contracts(): void
    {
        $this->getJson('/__problem/status/403')->assertForbidden()->assertJsonPath('message', 'PRIVATE EXCEPTION DETAIL')->assertDontSee('data-problem-page', false);
        Route::get('/api/__problem', fn () => abort(404, 'API error contract'));
        $this->get('/api/__problem')->assertNotFound()->assertJsonPath('message', 'API error contract');
        $original = response()->json(['business_error' => 'stable'], 403);
        $result = app(ErrorPages::class)->respond($original, new RuntimeException('private'), Request::create('/business'));
        $this->assertSame($original, $result);
    }

    public function test_validation_errors_and_redirects_keep_inline_form_behavior(): void
    {
        Route::middleware('web')->post('/__problem/validation', function (Request $request) {
            $request->validate(['note' => 'required|string']);

            return back();
        });
        $this->from('/register')->post('/__problem/validation')->assertRedirect('/register')->assertSessionHasErrors('note');
        $this->postJson('/__problem/validation')->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_debug_server_errors_and_unhandled_statuses_remain_available_to_developers(): void
    {
        $request = Request::create('/broken');
        config(['app.debug' => true]);
        $original = new Response('developer diagnostics', 500);
        $this->assertSame($original, app(ErrorPages::class)->respond($original, new RuntimeException('debug'), $request));
        $other = new Response('custom response', 418);
        $this->assertSame($other, app(ErrorPages::class)->respond($other, new RuntimeException('custom'), $request));
    }

    public function test_retry_after_and_rate_headers_are_preserved_and_only_numeric_delay_is_displayed(): void
    {
        $original = new Response('original error', 429, ['Retry-After' => '45', 'X-RateLimit-Limit' => '2', 'X-RateLimit-Remaining' => '0']);
        $request = Request::create('/limited');
        $result = app(ErrorPages::class)->respond($original, new RuntimeException('private'), $request);
        $this->assertSame('45', $result->headers->get('Retry-After'));
        $this->assertSame('2', $result->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $result->headers->get('X-RateLimit-Remaining'));
        $this->assertStringContainsString('45', $result->getContent());
        $date = 'Wed, 21 Oct 2037 07:28:00 GMT';
        $result = app(ErrorPages::class)->respond(new Response('', 503, ['Retry-After' => $date]), new RuntimeException('private'), $request);
        $this->assertSame($date, $result->headers->get('Retry-After'));
        $this->assertStringNotContainsString('زمان انتظار اعلام‌شده', $result->getContent());
    }

    public function test_real_throttle_inertia_response_keeps_status_headers_and_wait_time(): void
    {
        $this->get('/login')->assertOk();
        Route::middleware(['web', 'throttle:1,1'])->get('/__problem/limited', fn () => response('ok'));
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => Inertia::getVersion(), 'Accept' => 'text/html']);
        $this->get('/__problem/limited')->assertOk();
        $result = $this->get('/__problem/limited')->assertTooManyRequests()->assertHeader('X-Inertia', 'true')->assertJsonPath('component', 'ErrorPage')->assertJsonPath('props.status', 429);
        $delay = $result->json('props.retry_after');
        $this->assertIsInt($delay);
        $this->assertGreaterThan(0, $delay);
        $this->assertLessThanOrEqual(60, $delay);
        $this->assertSame('0', $result->headers->get('X-RateLimit-Remaining'));
    }

    public function test_method_not_allowed_keeps_allow_header_and_head_has_no_body(): void
    {
        Route::get('/__problem/get-only', fn () => response('ok'));
        $this->post('/__problem/get-only')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')->assertSee('از مسیر دیگری ادامه دهید');
        $this->head('/__problem/status/403')->assertForbidden()->assertContent('');
    }

    public function test_uncertain_failed_mutation_is_not_replayed_or_redirected_by_error_page(): void
    {
        $executions = 0;
        Route::middleware('web')->post('/__problem/uncertain', function () use (&$executions) {
            $executions++;
            abort(419);
        });
        $this->post('/__problem/uncertain')->assertStatus(419)->assertDontSee('<form', false)->assertDontSee('location.reload', false);
        $this->assertSame(1, $executions);
    }

    public function test_plain_server_error_rendering_has_no_database_queries_or_asset_dependencies(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $result = app(ErrorPages::class)->respond(new Response('PRIVATE SERVER DETAIL', 500), new RuntimeException('PRIVATE EXCEPTION DETAIL'), Request::create('/broken'));
        $this->assertSame(500, $result->getStatusCode());
        $this->assertSame(0, $queries);
        $this->assertStringContainsString('کاردرست', $result->getContent());
        $this->assertStringNotContainsString('PRIVATE', $result->getContent());
        $this->assertStringNotContainsString('<script', $result->getContent());
    }
}
