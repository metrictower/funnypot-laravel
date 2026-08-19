<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\Ports\LaravelStateStore;
use Funnypot\Laravel\Tests\Support\FixedClock;
use Funnypot\Policy\Decision;
use Funnypot\Policy\ReputationVerdict;
use Funnypot\Policy\RuleState;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The StateStore port round-trips every persisted shape over an array cache, with TTLs driven by the
 * injected clock.
 */
final class LaravelStateStoreTest extends TestCase
{
    private function store(FixedClock $clock): LaravelStateStore
    {
        return new LaravelStateStore(new Repository(new ArrayStore()), $clock);
    }

    public function test_pin_round_trips_and_expires_by_the_clock(): void
    {
        $clock = new FixedClock();
        $store = $this->store($clock);

        $store->setPin('10.0.0.1', Decision::DECEIVE, 'seed-123', 3600);
        $pin = $store->getPin('10.0.0.1');
        $this->assertNotNull($pin);
        $this->assertSame(Decision::DECEIVE, $pin->action());
        $this->assertSame('seed-123', $pin->seed());

        $clock->advance(3601);
        $this->assertNull($store->getPin('10.0.0.1'), 'the pin is expired by the clock');
    }

    public function test_blocklist_membership(): void
    {
        $store = $this->store(new FixedClock());
        $this->assertFalse($store->isBlocked('1.2.3.4'));
        $store->setBlocked('1.2.3.4', 600);
        $this->assertTrue($store->isBlocked('1.2.3.4'));
    }

    public function test_mirror_matches_a_contained_ip_by_cidr_containment(): void
    {
        $store = $this->store(new FixedClock());
        $store->putMirror([
            ['score_key' => '203.0.113.0/24', 'verdict' => 'malicious', 'expires_at' => null],
        ], 3600);

        $hit = $store->mirrorVerdict('203.0.113.7');
        $this->assertNotNull($hit);
        $this->assertSame('malicious', $hit->verdict());
        $this->assertSame(ReputationVerdict::SOURCE_MIRROR, $hit->source());

        $this->assertNull($store->mirrorVerdict('198.51.100.7'), 'an uncovered IP misses the mirror');
    }

    public function test_mirror_honours_row_expiry(): void
    {
        $clock = new FixedClock();
        $store = $this->store($clock);
        $store->putMirror([
            ['score_key' => '203.0.113.9', 'verdict' => 'critical', 'expires_at' => $clock->now() + 100],
        ], 3600);

        $this->assertNotNull($store->mirrorVerdict('203.0.113.9'));
        $clock->advance(200);
        $this->assertNull($store->mirrorVerdict('203.0.113.9'), 'the expired row is ignored');
    }

    public function test_rule_state_transition_round_trips(): void
    {
        $store = $this->store(new FixedClock());
        $this->assertSame(RuleState::SHADOW, $store->ruleState('rule-x')->phase());

        $store->putRuleState('rule-x', new RuleState(RuleState::ENFORCED, 123, 5000, [], true));
        $state = $store->ruleState('rule-x');
        $this->assertSame(RuleState::ENFORCED, $state->phase());
        $this->assertTrue($state->humanApproved());
    }

    public function test_seen_verdict_dedups_within_the_window(): void
    {
        $store = $this->store(new FixedClock());
        $this->assertFalse($store->seenVerdict('key-1', 3600), 'first sighting is not-yet-seen');
        $this->assertTrue($store->seenVerdict('key-1', 3600), 'second sighting is deduped');
    }

    public function test_alert_counter_and_generic_counter_increment(): void
    {
        $store = $this->store(new FixedClock());
        $this->assertSame(1, $store->incrAlertCount('9.9.9.9', 600));
        $this->assertSame(2, $store->incrAlertCount('9.9.9.9', 600));
        $this->assertSame(1, $store->incr('velocity:9.9.9.9', 60));
    }

    public function test_decay_score_accumulates_then_decays_toward_zero(): void
    {
        $clock = new FixedClock();
        $store = $this->store($clock);

        $this->assertSame(100, $store->decayScore('actor', 100, 600, 86400));
        // Immediately adding again roughly sums (no elapsed time → no decay yet).
        $this->assertGreaterThanOrEqual(190, $store->decayScore('actor', 100, 600, 86400));

        $clock->advance(86_401); // past the cap → the prior contribution has fully decayed
        $this->assertSame(1, $store->decayScore('actor', 1, 600, 86400));
    }

    public function test_report_buffer_collapses_a_group_and_drains(): void
    {
        $store = $this->store(new FixedClock());
        $this->assertSame(1, $store->bufferReport('grp', ['ip' => 'a'], 900));
        $this->assertSame(2, $store->bufferReport('grp', ['ip' => 'b'], 900));

        $drained = $store->takeReportBuffer();
        $this->assertArrayHasKey('grp', $drained);
        $this->assertCount(2, $drained['grp']);
        $this->assertSame([], $store->takeReportBuffer(), 'the buffer is cleared after a drain');
    }
}
