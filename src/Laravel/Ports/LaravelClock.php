<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Ports;

use Funnypot\Policy\Port\Clock;
use Illuminate\Support\Facades\Date;

/**
 * Policy Clock over Laravel's clock, so dwell/TTL/decay windows are testable via Date::setTestNow /
 * Carbon::setTestNow.
 */
final class LaravelClock implements Clock
{
    public function now(): int
    {
        return Date::now()->getTimestamp();
    }
}
