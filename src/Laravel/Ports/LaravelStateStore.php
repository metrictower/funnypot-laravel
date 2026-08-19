<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Ports;

use Funnypot\Policy\ActorFacts;
use Funnypot\Policy\AggScore;
use Funnypot\Policy\Net;
use Funnypot\Policy\Pin;
use Funnypot\Policy\Port\Clock;
use Funnypot\Policy\Port\StateStoreInterface;
use Funnypot\Policy\ReputationVerdict;
use Funnypot\Policy\RuleState;
use Illuminate\Contracts\Cache\Repository;

/**
 * The one persistence seam (policy §2.3) over a Laravel cache store. Backs pins, the local blocklist +
 * O1 mirror, learn-then-enforce per-rule state, the 4-layer suppression ledger, and the rolling
 * per-actor counters. A persistent store (redis/database/memcached) is recommended; the array driver
 * works for a single-process test (but is per-process, so the shared breaker marker is inert with it).
 *
 * All mutable funnypot state lives here — E never writes into its own package dir (RS-10).
 */
final class LaravelStateStore implements StateStoreInterface
{
    private const PIN      = 'funnypot:pin:';
    private const BLOCK    = 'funnypot:block:';
    private const MIRROR   = 'funnypot:mirror';
    private const RULE     = 'funnypot:rule:';
    private const RULEHITS = 'funnypot:rulehits:';
    private const DEDUP    = 'funnypot:dedup:';
    private const ALERT    = 'funnypot:alert:';
    private const BUFFER   = 'funnypot:sup:buffer';
    private const AGG      = 'funnypot:agg:';
    private const DECAY    = 'funnypot:decay:';
    private const FACTS    = 'funnypot:facts:';
    private const COUNTER  = 'funnypot:ctr:';

    public function __construct(private Repository $cache, private Clock $clock)
    {
    }

    // --- pins + local blocklist -------------------------------------------------------------------

    public function getPin(string $ip)
    {
        $row = $this->cache->get(self::PIN . $ip);
        if (!is_array($row) || !isset($row['action'], $row['seed'], $row['exp'])) {
            return null;
        }
        if ((int) $row['exp'] <= $this->clock->now()) {
            return null;
        }

        return new Pin((string) $row['action'], (string) $row['seed'], (int) $row['exp']);
    }

    public function setPin(string $ip, string $action, string $seed, int $ttlSeconds)
    {
        $ttl = max(1, $ttlSeconds);
        $this->cache->put(self::PIN . $ip, [
            'action' => $action,
            'seed'   => $seed,
            'exp'    => $this->clock->now() + $ttl,
        ], $ttl);
    }

    public function isBlocked(string $ip)
    {
        return (bool) $this->cache->get(self::BLOCK . $ip, false);
    }

    /** Local blocklist write helper (used by mirror-sync / operator tooling; not a policy port method). */
    public function setBlocked(string $ip, int $ttlSeconds): void
    {
        $this->cache->put(self::BLOCK . $ip, true, max(1, $ttlSeconds));
    }

    // --- O1 local blacklist mirror ----------------------------------------------------------------

    public function mirrorVerdict(string $ip)
    {
        $rows = $this->cache->get(self::MIRROR);
        if (!is_array($rows) || $rows === []) {
            return null;
        }

        $now = $this->clock->now();
        $normalised = Net::normaliseV6($ip);
        $best = null;
        $bestLen = -1;
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['score_key'])) {
                continue;
            }
            $expires = $row['expires_at'] ?? null;
            if ($expires !== null && (int) $expires <= $now) {
                continue; // honour each row's TTL
            }
            $key = (string) $row['score_key'];
            // ASN rows can't be matched from an IP alone here; skip (containment covers IP/CIDR).
            if (stripos($key, 'AS') === 0 && !str_contains($key, '.') && !str_contains($key, ':')) {
                continue;
            }
            $len = max(Net::containment($key, $ip), Net::containment($key, $normalised));
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $row;
            }
        }
        if ($best === null) {
            return null;
        }

        return new ReputationVerdict(
            (string) ($best['verdict'] ?? ReputationVerdict::VERDICT_MALICIOUS),
            isset($best['score']) ? (int) $best['score'] : null,
            ReputationVerdict::SOURCE_MIRROR,
            isset($best['usage_type']) ? (string) $best['usage_type'] : null
        );
    }

    /**
     * Replace the mirror set (used by funnypot:mirror-sync). Rows: {score_key, verdict, expires_at}.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function putMirror(array $rows, int $ttlSeconds): void
    {
        $this->cache->put(self::MIRROR, array_values($rows), max(60, $ttlSeconds));
    }

    // --- learn-then-enforce per-rule state --------------------------------------------------------

    public function ruleState(string $ruleId)
    {
        $row = $this->cache->get(self::RULE . $ruleId);
        if (!is_array($row)) {
            return new RuleState();
        }

        return new RuleState(
            (string) ($row['phase'] ?? RuleState::SHADOW),
            (int) ($row['since'] ?? 0),
            (int) ($row['count'] ?? 0),
            (array) ($row['exclusions'] ?? []),
            (bool) ($row['human_approved'] ?? false)
        );
    }

    public function putRuleState(string $ruleId, RuleState $s)
    {
        $this->cache->forever(self::RULE . $ruleId, [
            'phase'          => $s->phase(),
            'since'          => $s->since(),
            'count'          => $s->count(),
            'exclusions'     => $s->exclusions(),
            'human_approved' => $s->humanApproved(),
        ]);
    }

    public function bumpRuleEvaluated(string $ruleId, int $n = 1)
    {
        $this->addToCounter(self::RULEHITS . $ruleId, $n, 30 * 86400);
    }

    // --- suppression ledger -----------------------------------------------------------------------

    public function seenVerdict(string $dedupKey, int $ttlSeconds)
    {
        // add() sets the key only if absent, returning true on the FIRST sighting → not-yet-seen.
        $added = $this->cache->add(self::DEDUP . $dedupKey, 1, max(1, $ttlSeconds));

        return $added === false;
    }

    public function incrAlertCount(string $ip, int $windowSeconds)
    {
        return $this->addToCounter(self::ALERT . $ip, 1, $windowSeconds);
    }

    public function bufferReport(string $groupKey, array $report, int $ttlSeconds)
    {
        $buffer = $this->cache->get(self::BUFFER, []);
        if (!is_array($buffer)) {
            $buffer = [];
        }
        if (!isset($buffer[$groupKey]) || !is_array($buffer[$groupKey])) {
            $buffer[$groupKey] = [];
        }
        $buffer[$groupKey][] = $report;
        $this->cache->put(self::BUFFER, $buffer, max(1, $ttlSeconds));

        return count($buffer[$groupKey]);
    }

    public function takeReportBuffer()
    {
        $buffer = $this->cache->get(self::BUFFER, []);
        $this->cache->forget(self::BUFFER);

        return is_array($buffer) ? $buffer : [];
    }

    public function aggregateScore(string $scoreKey, int $windowDays)
    {
        $row = $this->cache->get(self::AGG . Net::normaliseV6($scoreKey));
        if (!is_array($row)) {
            return new AggScore([], 0);
        }

        return new AggScore((array) ($row['sources'] ?? []), (int) ($row['total'] ?? 0));
    }

    public function decayScore(string $key, int $inc, int $baseTtlSeconds, int $capTtlSeconds)
    {
        $now = $this->clock->now();
        $row = $this->cache->get(self::DECAY . $key);
        $decayed = 0.0;
        if (is_array($row) && isset($row['score'], $row['ts'])) {
            $age = $now - (int) $row['ts'];
            if ($age < $capTtlSeconds && $baseTtlSeconds > 0) {
                $decayed = (float) $row['score'] * exp(-$age / $baseTtlSeconds);
            }
        }
        $next = $decayed + $inc;
        $this->cache->put(self::DECAY . $key, ['score' => $next, 'ts' => $now], max(1, $capTtlSeconds));

        return (int) round($next);
    }

    // --- rolling per-actor counters ---------------------------------------------------------------

    public function actorFacts(string $ip)
    {
        $row = $this->cache->get(self::FACTS . $ip);
        if (!is_array($row)) {
            return new ActorFacts();
        }

        return new ActorFacts(
            (bool) ($row['auth_session'] ?? false),
            (bool) ($row['loads_assets'] ?? false),
            (int) ($row['matches_30d'] ?? 0),
            (int) ($row['first_seen'] ?? 0)
        );
    }

    public function incr(string $counterKey, int $windowSeconds)
    {
        return $this->addToCounter(self::COUNTER . $counterKey, 1, $windowSeconds);
    }

    // --- internals --------------------------------------------------------------------------------

    /** A windowed counter: the first write in a window seeds the TTL, later writes add within it. */
    private function addToCounter(string $key, int $n, int $windowSeconds): int
    {
        if ($this->cache->add($key, 0, max(1, $windowSeconds)) === false) {
            $value = $this->cache->increment($key, $n);

            return is_int($value) ? $value : (int) $this->cache->get($key, $n);
        }
        $value = $this->cache->increment($key, $n);

        return is_int($value) ? $value : $n;
    }
}
