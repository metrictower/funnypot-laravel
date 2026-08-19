<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\Ports\LaravelGeoIp;
use PHPUnit\Framework\TestCase;

/**
 * The country port (decision R): resolves from a LOCAL DB, IPv4 + IPv6, and fails OPEN (null → the gate
 * skips) on a miss / unreadable DB / disabled gate — never a throw, never a network call.
 */
final class LaravelGeoIpTest extends TestCase
{
    private const DB = __DIR__ . '/../fixtures/geoip.json';

    /** @param array<string,mixed> $overrides */
    private function geo(array $overrides = []): LaravelGeoIp
    {
        return new LaravelGeoIp(array_replace_recursive([
            'geoip' => ['enabled' => true, 'database' => self::DB],
        ], $overrides));
    }

    public function test_resolves_ipv4_ipv6_and_cidr_entries(): void
    {
        $geo = $this->geo();
        $this->assertSame('CN', $geo->country('203.0.113.10'));   // exact IPv4
        $this->assertSame('CN', $geo->country('2001:db8::1'));    // IPv6 (R2)
        $this->assertSame('RU', $geo->country('198.51.100.42'));  // inside the CIDR entry
    }

    public function test_null_for_an_absent_ip(): void
    {
        $this->assertNull($this->geo()->country('8.8.8.8'));
    }

    public function test_inert_null_when_disabled_or_db_missing(): void
    {
        $this->assertNull($this->geo(['geoip' => ['enabled' => false]])->country('203.0.113.10'));
        $this->assertNull($this->geo(['geoip' => ['database' => '/no/such/file.json']])->country('203.0.113.10'));
    }
}
