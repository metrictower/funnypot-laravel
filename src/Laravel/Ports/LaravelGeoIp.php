<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Ports;

use Funnypot\Policy\Net;
use Funnypot\Policy\Port\GeoIpInterface;

/**
 * The country-resolution port (decision R). Resolves a visitor IP's ISO-3166 alpha-2 country from a
 * LOCAL GeoIP DB — socket-free, NEVER a network call (M5/R2), IPv4 + IPv6. A miss / unreadable DB /
 * disabled gate returns null, so the policy's country gate simply SKIPS (fail-open, never a block or a
 * 500 — R4).
 *
 * Two local backends, picked by the database path:
 *  - a MaxMind/DB-IP `.mmdb` read through MaxMind\Db\Reader when that library is installed;
 *  - a `.json` map of {ip-or-CIDR: "CC"} for simple installs and tests (matched by containment).
 */
final class LaravelGeoIp implements GeoIpInterface
{
    /** @param array<string,mixed> $config the `funnypot` config array */
    public function __construct(private array $config)
    {
    }

    public function country(string $ip): ?string
    {
        $geo = (array) ($this->config['geoip'] ?? []);
        if (!($geo['enabled'] ?? false)) {
            return null; // inert
        }
        $path = $geo['database'] ?? null;
        if (!is_string($path) || $path === '' || !is_readable($path)) {
            return null; // unreadable DB → skip, never throw
        }

        try {
            if (str_ends_with($path, '.mmdb')) {
                return $this->fromMmdb($path, $ip);
            }
            if (str_ends_with($path, '.json')) {
                return $this->fromJsonMap($path, $ip);
            }
        } catch (\Throwable $ignored) {
            return null; // any read fault → unknown → the gate skips
        }

        return null;
    }

    private function fromMmdb(string $path, string $ip): ?string
    {
        $reader = '\\MaxMind\\Db\\Reader';
        if (!class_exists($reader)) {
            return null; // no reader library available → skip
        }
        $r = new $reader($path);
        $record = $r->get($ip);
        $r->close();
        if (is_array($record) && isset($record['country']['iso_code'])) {
            return strtoupper((string) $record['country']['iso_code']);
        }

        return null;
    }

    private function fromJsonMap(string $path, string $ip): ?string
    {
        $raw = file_get_contents($path);
        $map = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($map)) {
            return null;
        }
        if (isset($map[$ip])) {
            return strtoupper((string) $map[$ip]);
        }
        // CIDR entries: most-specific containment wins.
        $best = null;
        $bestLen = -1;
        foreach ($map as $key => $code) {
            $len = Net::containment((string) $key, $ip);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $code;
            }
        }

        return $best === null ? null : strtoupper((string) $best);
    }
}
