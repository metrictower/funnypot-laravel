<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Reputation;

use Funnypot\Mainnet\Cache\Psr16Cache;
use Funnypot\Mainnet\Client;
use Funnypot\Mainnet\Config as MainnetConfig;
use Illuminate\Contracts\Cache\Repository;

/**
 * Build piece F's Funnypot\Mainnet\Client from the Laravel `funnypot` config, backed by the app's cache
 * (via F's Psr16Cache — the same store the policy StateStore uses, distinct key namespaces). The Client
 * powers the request-path cache read (cachedVerdict, no socket) plus the out-of-band warm / mirror-sync
 * (check, which opens a socket and must never run on the request path — M5).
 */
final class ClientFactory
{
    /** @param array<string,mixed> $c the `funnypot` config array */
    public static function build(array $c, Repository $cache): Client
    {
        $config = MainnetConfig::fromArray([
            'base_url'            => (string) ($c['mainnet']['base_url'] ?? ''),
            'key'                 => (string) ($c['mainnet']['key'] ?? ''),
            'check_enabled'       => (bool) ($c['check']['enabled'] ?? false),
            'fail_mode'           => (string) ($c['check']['fail_mode'] ?? 'open'),
            'block_verdicts'      => array_values((array) ($c['check']['block_verdicts'] ?? ['malicious', 'critical'])),
            'min_block_score'     => $c['check']['min_block_score'] ?? null,
            'cache_ttl_hours'     => (int) ($c['check']['cache_ttl_hours'] ?? 12),
            'timeout_ms'          => (int) ($c['check']['timeout_ms'] ?? 1500),
            'breaker_threshold'   => (int) ($c['breaker']['threshold_transport'] ?? 5),
            'breaker_cooldown_secs' => (int) ($c['breaker']['cooldown_secs'] ?? 60),
            'quota_park_cap_secs' => (int) ($c['breaker']['quota_park_cap_secs'] ?? 21600),
            'self_ips'            => array_values((array) ($c['reporting']['self_ips'] ?? [])),
        ]);

        // The concrete Laravel cache repository implements PSR-16; F's Psr16Cache wraps it so the
        // verdict cache + shared breaker marker live in the operator's chosen store.
        return new Client($config, null, new Psr16Cache($cache));
    }
}
