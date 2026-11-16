<?php

namespace Tests\Feature;

use App\Services\OperationalHeartbeats;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationalHeartbeatStatusTest extends TestCase
{
    public function test_liveness_fails_before_execution_and_after_signal_expiry(): void
    {
        Http::preventStrayRequests();
        $this->artisan('platform:heartbeat-status', ['signal' => 'queue.sms'])->assertFailed();
        app(OperationalHeartbeats::class)->record('queue.sms');
        $this->artisan('platform:heartbeat-status', ['signal' => 'queue.sms'])->assertSuccessful();
        $this->travel(181)->seconds();
        $this->artisan('platform:heartbeat-status', ['signal' => 'queue.sms'])->assertFailed();
        Http::assertNothingSent();
    }

    public function test_invalid_signal_does_not_read_an_arbitrary_cache_key(): void
    {
        Cache::shouldReceive('get')->never();
        $this->assertSame(2, Artisan::call('platform:heartbeat-status', ['signal' => 'secret-config-key']));
        $this->assertStringContainsString('Unsupported operational signal', Artisan::output());
    }

    public function test_cache_failure_returns_safe_failed_health_without_exception_details(): void
    {
        Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('never-print-private-cache-endpoint'));
        $this->assertSame(1, Artisan::call('platform:heartbeat-status', ['signal' => 'scheduler']));
        $output = Artisan::output();
        $this->assertStringContainsString('Operational signal unavailable', $output);
        $this->assertStringNotContainsString('never-print-private-cache-endpoint', $output);
    }
}
