<?php

namespace Tests\Feature;

use App\Services\OperationalReadiness;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/proxy-probe-for-test', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->secure(), 'host' => $request->host(), 'port' => $request->getPort(), 'base' => $request->getBaseUrl()]));
    }

    private function headers(): array
    {
        return ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'attacker.invalid', 'X-Forwarded-Prefix' => '/forged'];
    }

    public function test_untrusted_peer_cannot_spoof_ip_https_port_host_or_prefix(): void
    {
        config(['trustedproxy.proxies' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->withHeaders($this->headers())->get('http://kardorost.ir/proxy-probe-for-test')->assertOk()->assertExactJson(['ip' => '198.51.100.10', 'secure' => false, 'host' => 'kardorost.ir', 'port' => 80, 'base' => '']);
    }

    public function test_explicit_trusted_proxy_can_forward_client_ip_https_and_port_only(): void
    {
        config(['trustedproxy.proxies' => ['172.16.0.0/12']]);
        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.1'])->withHeaders($this->headers())->get('http://kardorost.ir/proxy-probe-for-test')->assertOk()->assertExactJson(['ip' => '203.0.113.9', 'secure' => true, 'host' => 'kardorost.ir', 'port' => 443, 'base' => '']);
    }

    public function test_trusted_ip_ranges_do_not_trust_a_different_public_peer(): void
    {
        config(['trustedproxy.proxies' => ['172.16.0.0/12']]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->withHeaders($this->headers())->get('http://kardorost.ir/proxy-probe-for-test')->assertJsonPath('ip', '198.51.100.10')->assertJsonPath('secure', false);
    }

    public function test_hostname_suffix_does_not_implicitly_enable_trust_with_empty_configuration(): void
    {
        config(['trustedproxy.proxies' => []]);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])->withHeaders($this->headers())->get('http://untrusted.on-forge.com/proxy-probe-for-test')->assertJsonPath('ip', '198.51.100.10')->assertJsonPath('secure', false);
    }

    public function test_only_valid_explicit_ip_and_nonzero_cidr_entries_are_accepted_from_environment(): void
    {
        $repository = Env::getRepository();
        $previous = $repository->get('TRUSTED_PROXIES');
        try {
            $repository->set('TRUSTED_PROXIES', '172.16.0.0/12, 192.0.2.4,2001:db8::/32,*,**,REMOTE_ADDR,0.0.0.0/0,::/0,invalid/24,192.0.2.1/33,::1/129');
            $settings = require config_path('trustedproxy.php');
            $this->assertSame(['172.16.0.0/12', '192.0.2.4', '2001:db8::/32'], $settings['proxies']);
            $this->assertSame(8, $settings['invalid_count']);
            config(['trustedproxy.invalid_count' => $settings['invalid_count']]);
            $row = collect(app(OperationalReadiness::class)->report()['checks'])->firstWhere('id', 'trusted_proxy_configuration');
            $this->assertSame('fail', $row['state']);
            $repository->set('TRUSTED_PROXIES', '');
            $this->assertSame(['proxies' => [], 'invalid_count' => 0], require config_path('trustedproxy.php'));
        } finally {
            if ($previous === null) {
                $repository->clear('TRUSTED_PROXIES');
            } else {
                $repository->set('TRUSTED_PROXIES', $previous);
            }
        }
    }
}
