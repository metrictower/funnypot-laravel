<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Support;

use Funnypot\Policy\Port\Clock;

/** A movable clock so TTL/decay windows are deterministic in tests. */
final class FixedClock implements Clock
{
    public function __construct(private int $now = 1_700_000_000)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
