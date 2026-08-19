<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\Ports\MainnetReputation;
use Funnypot\Laravel\Reputation\ClientFactory;
use Funnypot\Mainnet\Client;
use Funnypot\Policy\ReputationVerdict;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The reputation port over piece F. Proves F is wired through the port: a verdict primed into the
 * Laravel-backed F cache is returned by lookup() with ZERO network calls (request-path-safe, M5), and
 * the port is inert (fail-open unknown) when checking is off.
 */
final class MainnetReputationTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'mainnet' => ['base_url' => 'https://api.mainnet.example', 'key' => 'sensor-key'],
            'check'   => ['enabled' => true, 'cache_ttl_hours' => 12, 'timeout_ms' => 1500],
            'breaker' => ['threshold_transport' => 5, 'cooldown_secs' => 60, 'quota_park_cap_secs' => 21600],
            'reporting' => ['self_ips' => []],
        ], $overrides);
    }

    public function test_returns_a_cached_verdict_from_the_f_client_with_no_network_call(): void
    {
        $cache = new Repository(new ArrayStore());
        // Prime F's bulk local mirror (its 'mnc:mirror' key) inside the Laravel cache the client wraps.
        $cache->forever('mnc:mirror', [
            ['cidr' => '203.0.113.5', 'verdict' => 'malicious', 'score' => 90],
        ]);

        $client = ClientFactory::build($this->config(), $cache);
        $this->assertInstanceOf(Client::class, $client);
        $port = new MainnetReputation($client);

        $verdict = $port->lookup('203.0.113.5');
        $this->assertTrue($verdict->isMalicious());
        $this->assertSame(ReputationVerdict::SOURCE_CACHE, $verdict->source());
    }

    public function test_fail_open_unknown_on_a_cache_miss(): void
    {
        $client = ClientFactory::build($this->config(), new Repository(new ArrayStore()));
        $verdict = (new MainnetReputation($client))->lookup('198.51.100.1');

        $this->assertTrue($verdict->isUnknown());
        $this->assertSame(ReputationVerdict::SOURCE_FAIL_OPEN, $verdict->source());
    }

    public function test_inert_unknown_when_checking_is_disabled(): void
    {
        $config = $this->config(['check' => ['enabled' => false]]);
        $cache = new Repository(new ArrayStore());
        $cache->forever('mnc:mirror', [['cidr' => '203.0.113.5', 'verdict' => 'malicious']]);

        $client = ClientFactory::build($config, $cache);
        $verdict = (new MainnetReputation($client))->lookup('203.0.113.5');

        $this->assertTrue($verdict->isUnknown(), 'checking off → inert, even with a primed cache');
    }
}
