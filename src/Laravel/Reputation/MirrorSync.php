<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Reputation;

use Funnypot\Laravel\Ports\LaravelStateStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Http;

/**
 * O1 local-mirror-lite: the PRIMARY reputation fresh-read. A scheduled conditional GET of the thin
 * blacklist artifact (`GET ${base}/v1/blacklist?format=json&variant=thin`, ETag/If-None-Match → a 304
 * spends no bandwidth) into the state store as the authoritative local blocklist mirror. Fleet reads
 * then scale as CDN egress, not origin QPS.
 *
 * Inert unless check.enabled + a mainnet key. A failed pull leaves the prior mirror intact.
 */
final class MirrorSync
{
    private const ETAG = 'funnypot:mirror:etag';

    /** @param array<string,mixed> $config the `funnypot` config array */
    public function __construct(
        private LaravelStateStore $store,
        private Repository $cache,
        private array $config
    ) {
    }

    /** @return array{status:string,rows?:int,http?:int} */
    public function sync(): array
    {
        if (!$this->active()) {
            return ['status' => 'inert'];
        }

        $base = rtrim((string) ($this->config['mainnet']['base_url'] ?? ''), '/');
        $variant = (string) ($this->config['mirror']['variant'] ?? 'thin');
        $url = $base . '/v1/blacklist?format=json&variant=' . rawurlencode($variant);

        $headers = ['Key' => (string) ($this->config['mainnet']['key'] ?? ''), 'Accept' => 'application/json'];
        $etag = $this->cache->get(self::ETAG);
        if (is_string($etag) && $etag !== '') {
            $headers['If-None-Match'] = $etag;
        }

        try {
            $response = Http::withHeaders($headers)->get($url);
        } catch (\Throwable $ignored) {
            return ['status' => 'error']; // leave the mirror intact
        }

        if ($response->status() === 304) {
            return ['status' => 'not-modified'];
        }
        if (!$response->successful()) {
            return ['status' => 'error', 'http' => $response->status()];
        }

        $rows = $this->parse($response->json());
        $ttl = max(120, (int) ($this->config['mirror']['sync_minutes'] ?? 60) * 60 * 2);
        $this->store->putMirror($rows, $ttl);

        $newEtag = $response->header('ETag');
        if (is_string($newEtag) && $newEtag !== '') {
            $this->cache->forever(self::ETAG, $newEtag);
        }

        return ['status' => 'synced', 'rows' => count($rows)];
    }

    private function active(): bool
    {
        return (bool) ($this->config['mirror']['enabled'] ?? true)
            && (bool) ($this->config['check']['enabled'] ?? false)
            && (string) ($this->config['mainnet']['key'] ?? '') !== '';
    }

    /**
     * Normalise the artifact into store rows {score_key, verdict, expires_at(epoch|null)}. The `ip` field
     * may carry a CIDR or ASN score_key (P2/Q2/Q1) — stored verbatim, matched by containment at lookup.
     *
     * @param mixed $json
     * @return array<int,array<string,mixed>>
     */
    private function parse($json): array
    {
        $list = [];
        if (is_array($json)) {
            $list = isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;
        }
        $rows = [];
        foreach ((array) $list as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $entry['score_key'] ?? ($entry['ip'] ?? ($entry['cidr'] ?? null));
            if (!is_string($key) || $key === '') {
                continue;
            }
            $rows[] = [
                'score_key'  => $key,
                'verdict'    => (string) ($entry['verdict'] ?? 'malicious'),
                'expires_at' => $this->epoch($entry['expires_at'] ?? null),
            ];
        }

        return $rows;
    }

    /** @param mixed $value */
    private function epoch($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }
}
