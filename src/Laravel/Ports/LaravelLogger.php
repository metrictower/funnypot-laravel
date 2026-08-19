<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Ports;

use Funnypot\Policy\Port\Logger;
use Illuminate\Support\Facades\Log;

/**
 * Policy Logger over the `funnypot` log channel. The policy engine hands this port only non-sensitive
 * labels (never a signature string, raw payload, or secret — policy §10); this adapter forwards them
 * verbatim and never throws (a logging fault must not turn a fail-open into a 500).
 */
final class LaravelLogger implements Logger
{
    /** @param array<string,mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        try {
            Log::channel('funnypot')->log($this->normalise($level), $message, $context);
        } catch (\Throwable $ignored) {
            // never let a logging fault escape onto the request path
        }
    }

    private function normalise(string $level): string
    {
        $known = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

        return in_array($level, $known, true) ? $level : 'info';
    }
}
