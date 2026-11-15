<?php

namespace Tests\Feature;

use App\Jobs\RecordOperationalHeartbeat;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalHeartbeats;
use App\Services\OperationalReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class OperationalReadinessTest extends TestCase
{
    use RefreshDatabase;

    private ?string $temporary = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->temporary) {
            $link = $this->temporary.'/public/exposed';
            if (is_link($link)) {
                unlink($link);
            }
            File::deleteDirectory($this->temporary);
        }
        parent::tearDown();
    }

    private function check(string $id, ?array $report = null): array
    {
        return collect(($report ?? app(OperationalReadiness::class)->report())['checks'])->firstWhere('id', $id);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function filesystem(): string
    {
        $this->temporary = sys_get_temp_dir().'/kardorost-readiness-tests-'.Str::uuid();
        File::makeDirectory($this->temporary.'/public', 0700, true);
        File::makeDirectory($this->temporary.'/private', 0700, true);
        app()->usePublicPath($this->temporary.'/public');
        foreach (['learning', 'support', 'receipts', 'refunds'] as $disk) {
            config(['filesystems.disks.'.$disk => ['driver' => 'local', 'root' => $this->temporary.'/private/'.$disk, 'visibility' => 'private', 'serve' => false]]);
        }

        return $this->temporary;
    }

    public function test_only_active_current_admin_can_read_private_operational_report(): void
    {
        $this->get('/admin/readiness')->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/readiness')->assertForbidden();
        }
        $admin = $this->user('admin');
        $this->actingAs($admin)->get('/admin/readiness')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertInertia(fn (Assert $p) => $p->component('OperationalReadiness')->where('report.scope', 'configuration_and_local_runtime_only')->where('report.live_services_verified', false)->where('report.deployment_verified', false)->has('report.manual_acceptance', 4));
        $admin->roles()->detach();
        $this->get('/admin/readiness')->assertForbidden();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $admin->forceFill(['is_active' => false])->save();
        $this->get('/admin/readiness')->assertRedirect('/login');
    }

    public function test_inspection_is_read_only_without_external_calls_queue_dispatch_or_business_queries(): void
    {
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $report = app(OperationalReadiness::class)->report();
        $this->assertSame([], $writes);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertFalse($report['live_services_verified']);
        $this->assertFalse($report['deployment_verified']);
    }

    public function test_test_environment_disabled_sms_and_missing_signals_do_not_pass(): void
    {
        config(['app.debug' => true, 'platform.sms.driver' => 'disabled']);
        $report = app(OperationalReadiness::class)->report();
        $this->assertFalse($report['checks_passed']);
        foreach (['environment', 'debug', 'otp_configuration', 'scheduler', 'queue.default', 'queue.sms'] as $id) {
            $this->assertSame('fail', $this->check($id, $report)['state']);
        }
        $this->assertSame('warning', $this->check('gateway_configuration', $report)['state']);
    }

    public function test_secret_configuration_and_connection_errors_are_never_rendered_or_printed(): void
    {
        config(['app.url' => 'https://operator:never-print-url-secret@private.invalid?token=private-url-token', 'platform.sms.melipayamak.api_key' => 'never-print-sms-secret', 'platform.zibal.merchant' => 'never-print-bank-secret']);
        $admin = $this->user('admin');
        $response = $this->actingAs($admin)->get('/admin/readiness')->assertOk();
        $this->assertSame(1, Artisan::call('platform:readiness', ['--json' => true]));
        $output = Artisan::output();
        foreach (['never-print-url-secret', 'private-url-token', 'never-print-sms-secret', 'never-print-bank-secret', config('app.key'), storage_path(), config('database.connections.pgsql.password')] as $secret) {
            if (is_string($secret) && $secret !== '') {
                $this->assertStringNotContainsString($secret, $response->getContent());
                $this->assertStringNotContainsString($secret, $output);
            }
        }
        $json = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(0, $json['blocking_count']);
        $this->assertSame('fail', $this->check('public_url', $json)['state']);
        $broken = new OperationalReadiness;
        $connection = config('database.default');
        try {
            config(['database.default' => 'missing-connection-never-print-detail']);
            $report = $broken->report();
        } finally {
            config(['database.default' => $connection]);
        }
        $this->assertSame('fail', $this->check('database_inspection', $report)['state']);
        $this->assertStringNotContainsString('missing-connection-never-print-detail', json_encode($report));
    }

    public function test_public_url_rejects_credentials_development_hosts_and_private_addresses(): void
    {
        foreach (['http://kardorost.ir', 'https://localhost', 'https://kardorost.example.com', 'https://site.test', 'https://127.0.0.1', 'https://10.0.0.1', 'https://[::1]', 'https://user:password@kardorost.ir', 'https://kardorost.ir?token=private', 'https://kardorost.ir#private'] as $url) {
            config(['app.url' => $url]);
            $this->assertSame('fail', $this->check('public_url')['state'], $url);
        }
        config(['app.url' => 'https://kardorost.ir']);
        $this->assertSame('pass', $this->check('public_url')['state']);
    }

    public function test_invalid_encryption_key_is_reported_without_printing_it(): void
    {
        foreach (['', 'base64:not-a-valid-base64-key', 'too-short'] as $key) {
            config(['app.key' => $key]);
            $this->assertSame('fail', $this->check('encryption_key')['state']);
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->assertSame('pass', $this->check('encryption_key')['state']);
    }

    public function test_runtime_and_cookie_security_cannot_pass_using_ephemeral_or_unsafe_settings(): void
    {
        config(['session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax', 'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database', 'queue.connections.database.retry_after' => 90]);
        $this->assertSame('pass', $this->check('session_security')['state']);
        $this->assertSame('pass', $this->check('durable_drivers')['state']);
        config(['session.secure' => false, 'cache.default' => 'array', 'queue.default' => 'sync']);
        $this->assertSame('fail', $this->check('session_security')['state']);
        $this->assertSame('fail', $this->check('durable_drivers')['state']);
        config(['session.secure' => true, 'session.encrypt' => false]);
        $this->assertSame('fail', $this->check('session_security')['state']);
    }

    public function test_private_storage_rejects_public_roots_public_ancestors_and_exposing_symlinks(): void
    {
        $base = $this->filesystem();
        $this->assertSame('pass', $this->check('private_storage')['state']);
        config(['filesystems.disks.learning.root' => $base.'/public/private']);
        $this->assertSame('fail', $this->check('private_storage')['state']);
        config(['filesystems.disks.learning.root' => $base]);
        $this->assertSame('fail', $this->check('private_storage')['state']);
        config(['filesystems.disks.learning.root' => '/']);
        $this->assertSame('fail', $this->check('private_storage')['state']);
        config(['filesystems.disks.learning.root' => $base.'/private/learning']);
        symlink($base.'/private', $base.'/public/exposed');
        $this->assertSame('fail', $this->check('private_storage')['state']);
        unlink($base.'/public/exposed');
        config(['filesystems.disks.support.serve' => true]);
        $this->assertSame('fail', $this->check('private_storage')['state']);
    }

    public function test_build_requires_both_entrypoints_real_files_and_no_development_hot_file(): void
    {
        $base = $this->filesystem();
        $this->assertSame('fail', $this->check('built_assets')['state']);
        File::makeDirectory($base.'/public/build/assets', 0700, true);
        File::put($base.'/public/build/assets/app.js', '');
        File::put($base.'/public/build/assets/public.js', '');
        File::put($base.'/public/build/manifest.json', json_encode(['resources/js/app.js' => ['file' => 'assets/app.js'], 'resources/js/public.js' => ['file' => 'assets/public.js']]));
        $this->assertSame('pass', $this->check('built_assets')['state']);
        File::put($base.'/public/hot', 'http://localhost:5173');
        $this->assertSame('fail', $this->check('built_assets')['state']);
        unlink($base.'/public/hot');
        unlink($base.'/public/build/assets/public.js');
        $this->assertSame('fail', $this->check('built_assets')['state']);
        File::put($base.'/public/build/manifest.json', 'malformed');
        $this->assertSame('fail', $this->check('built_assets')['state']);
    }

    public function test_pending_migration_and_missing_current_admin_are_reported(): void
    {
        $report = app(OperationalReadiness::class)->report();
        $this->assertSame('pass', $this->check('migrations', $report)['state']);
        $this->assertSame('fail', $this->check('admin_account', $report)['state']);
        $admin = $this->user('admin');
        $this->assertSame('pass', $this->check('admin_account')['state']);
        $admin->forceFill(['phone_verified_at' => null])->save();
        $this->assertSame('fail', $this->check('admin_account')['state']);
        DB::table('migrations')->where('id', DB::table('migrations')->max('id'))->delete();
        $this->assertSame('fail', $this->check('migrations')['state']);
    }

    public function test_scheduler_probe_never_marks_queued_workers_as_executed(): void
    {
        $this->artisan('platform:dispatch-heartbeats')->assertSuccessful();
        Queue::assertPushed(RecordOperationalHeartbeat::class, 2);
        Queue::assertPushed(RecordOperationalHeartbeat::class, fn ($job) => $job->signalQueue === 'default' && $job->queue === 'default' && $job->connection === 'database');
        Queue::assertPushed(RecordOperationalHeartbeat::class, fn ($job) => $job->signalQueue === 'sms' && $job->queue === 'sms' && $job->connection === 'database');
        $service = app(OperationalHeartbeats::class);
        $this->assertTrue($service->status('scheduler')['fresh']);
        $this->assertFalse($service->status('queue.default')['fresh']);
        $this->assertFalse($service->status('queue.sms')['fresh']);
        (new RecordOperationalHeartbeat('default'))->handle($service);
        $this->assertTrue($service->status('queue.default')['fresh']);
        $this->assertFalse($service->status('queue.sms')['fresh']);
        Http::assertNothingSent();
    }

    public function test_old_expired_future_or_malformed_heartbeats_cannot_show_healthy_workers(): void
    {
        $service = app(OperationalHeartbeats::class);
        $service->record('scheduler');
        $this->assertTrue($service->status('scheduler')['fresh']);
        $this->travel(181)->seconds();
        $this->assertFalse($service->status('scheduler')['fresh']);
        $this->assertNotNull($service->status('scheduler')['last_seen_at']);
        $this->travel(600)->seconds();
        $this->assertNull($service->status('scheduler')['last_seen_at']);
        foreach ([now()->addHour()->timestamp, 'malformed', ['timestamp' => now()->timestamp]] as $value) {
            Cache::put('platform:operations:heartbeat:v1:queue.default', $value, 600);
            $this->assertFalse($service->status('queue.default')['fresh']);
            $this->assertNull($service->status('queue.default')['last_seen_at']);
        }
        $this->expectException(InvalidArgumentException::class);
        new RecordOperationalHeartbeat('scheduler');
    }

    public function test_old_ready_jobs_block_but_future_and_reserved_jobs_do_not_create_false_backlog(): void
    {
        $table = config('queue.connections.database.table');
        DB::table($table)->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(6)->timestamp, 'created_at' => now()->subMinutes(6)->timestamp]);
        $this->assertSame('fail', $this->check('queue_delay')['state']);
        DB::table($table)->update(['available_at' => now()->addHour()->timestamp]);
        $this->assertSame('pass', $this->check('queue_delay')['state']);
        DB::table($table)->update(['available_at' => now()->subMinutes(6)->timestamp, 'reserved_at' => now()->timestamp]);
        $this->assertSame('pass', $this->check('queue_delay')['state']);
    }

    public function test_enabled_sms_features_and_gateway_need_their_own_valid_configuration(): void
    {
        config(['platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'synthetic-operator', 'platform.sms.melipayamak.api_key' => 'synthetic-key', 'platform.sms.melipayamak.otp_body_id' => 1, 'platform.sms.melipayamak.invitations_enabled' => true, 'platform.sms.melipayamak.invitation_body_id' => null, 'platform.sms.melipayamak.reminders_enabled' => true, 'platform.sms.melipayamak.reminder_body_id' => 2, 'platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'zibal']);
        $report = app(OperationalReadiness::class)->report();
        $this->assertSame('pass', $this->check('otp_configuration', $report)['state']);
        $this->assertSame('fail', $this->check('sms_invitations', $report)['state']);
        $this->assertSame('pass', $this->check('sms_reminders', $report)['state']);
        $this->assertSame('fail', $this->check('gateway_configuration', $report)['state']);
        $this->assertFalse($report['live_services_verified']);
        config(['platform.sms.melipayamak.delivery_enabled' => true, 'platform.sms.melipayamak.mode' => 'console']);
        $this->assertSame('fail', $this->check('sms_delivery')['state']);
        config(['platform.sms.melipayamak.mode' => 'rest']);
        $this->assertSame('pass', $this->check('sms_delivery')['state']);
        Http::assertNothingSent();
    }

    public function test_successful_postgresql_configuration_report_still_requires_live_acceptance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Production readiness requires a real PostgreSQL connection.');
        }
        $base = $this->filesystem();
        File::makeDirectory($base.'/public/build/assets', 0700, true);
        File::put($base.'/public/build/assets/app.js', '');
        File::put($base.'/public/build/assets/public.js', '');
        File::put($base.'/public/build/manifest.json', json_encode(['resources/js/app.js' => ['file' => 'assets/app.js'], 'resources/js/public.js' => ['file' => 'assets/public.js']]));
        $this->user('admin');
        app()->instance('env', 'production');
        config(['app.debug' => false, 'app.url' => 'https://kardorost.ir', 'app.locale' => 'fa', 'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax', 'session.driver' => 'database', 'queue.default' => 'database', 'cache.default' => 'database', 'queue.connections.database.retry_after' => 90, 'platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'synthetic-operator', 'platform.sms.melipayamak.api_key' => 'synthetic-key', 'platform.sms.melipayamak.otp_body_id' => 1, 'platform.sms.melipayamak.delivery_enabled' => false, 'platform.sms.melipayamak.invitations_enabled' => false, 'platform.sms.melipayamak.reminders_enabled' => false, 'platform.zibal.enabled' => false]);
        $heartbeats = app(OperationalHeartbeats::class);
        foreach (['scheduler', 'queue.default', 'queue.sms'] as $signal) {
            $heartbeats->record($signal);
        }
        $this->assertSame(0, Artisan::call('platform:readiness', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['checks_passed']);
        $this->assertSame(0, $report['blocking_count']);
        $this->assertFalse($report['live_services_verified']);
        $this->assertFalse($report['deployment_verified']);
        $this->assertCount(4, $report['manual_acceptance']);
        Http::assertNothingSent();
    }

    public function test_failed_probe_dispatch_returns_a_safe_error_without_external_requests(): void
    {
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('private-cache-endpoint-never-print'));
        $this->assertSame(1, Artisan::call('platform:dispatch-heartbeats'));
        $output = Artisan::output();
        $this->assertStringContainsString('Heartbeat dispatch failed', $output);
        $this->assertStringNotContainsString('private-cache-endpoint-never-print', $output);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
