<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Ports\LaravelStateStore;
use Funnypot\Laravel\Reputation\MirrorSync;
use Funnypot\Laravel\Tests\Support\FixedClock;
use Funnypot\Laravel\Tests\TestCase;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Http;

/**
 * O1 local-mirror-lite: the scheduled conditional GET pulls the thin blacklist artifact into the state
 * store; a 304 leaves the mirror intact; it is inert without check.enabled + a key. A synced CIDR row is
 * matched for a contained visitor IP (P2/Q2).
 */
final class MirrorSyncTest extends TestCase
{
    private Repository $cache;
    private LaravelStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new Repository(new ArrayStore());
        $this->store = new LaravelStateStore($this->cache, new FixedClock());
    }

    /** @param array<string,mixed> $overrides */
    private function sync(array $overrides = []): MirrorSync
    {
        $config = array_replace_recursive([
            'mainnet' => ['base_url' => 'https://api.mainnet.example', 'key' => 'sensor-key'],
            'check'   => ['enabled' => true],
            'mirror'  => ['enabled' => true, 'variant' => 'thin', 'sync_minutes' => 60],
        ], $overrides);

        return new MirrorSync($this->store, $this->cache, $config);
    }

    public function test_first_sync_writes_rows_and_a_later_lookup_serves_from_the_mirror(): void
    {
        Http::fake([
            '*/v1/blacklist*' => Http::response([
                'data' => [
                    ['ip' => '203.0.113.0/24', 'verdict' => 'malicious', 'expires_at' => null],
                    ['ip' => '198.51.100.7', 'verdict' => 'critical', 'expires_at' => null],
                ],
            ], 200, ['ETag' => '"v1-etag"']),
        ]);

        $result = $this->sync()->sync();
        $this->assertSame('synced', $result['status']);
        $this->assertSame(2, $result['rows']);

        // A contained visitor IP matches the synced /24 CIDR row (not just an exact IP).
        $hit = $this->store->mirrorVerdict('203.0.113.42');
        $this->assertNotNull($hit);
        $this->assertSame('malicious', $hit->verdict());
    }

    public function test_conditional_get_sends_the_stored_etag_and_a_304_leaves_the_mirror_intact(): void
    {
        // First pull returns rows + an ETag; the second pull answers 304 Not Modified.
        Http::fakeSequence('*/v1/blacklist*')
            ->push(['data' => [['ip' => '203.0.113.5', 'verdict' => 'malicious']]], 200, ['ETag' => '"v1-etag"'])
            ->push('', 304);

        $this->sync()->sync();
        $result = $this->sync()->sync();

        $this->assertSame('not-modified', $result['status']);
        $this->assertNotNull($this->store->mirrorVerdict('203.0.113.5'), 'the mirror is left intact on a 304');

        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1-etag"'));
    }

    public function test_inert_without_a_key_or_when_checking_is_disabled(): void
    {
        Http::fake();

        $this->assertSame('inert', $this->sync(['mainnet' => ['key' => '']])->sync()['status']);
        $this->assertSame('inert', $this->sync(['check' => ['enabled' => false]])->sync()['status']);

        Http::assertNothingSent();
    }
}
