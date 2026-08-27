<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Laravel\Ports\LaravelLogger;
use Funnypot\Policy\Decision;

/**
 * Server-side observation logging shared by the two executors (FP-0118). Both the action name and the
 * closed-set reason label are fingerprint-safe (policy §10) — nothing here can leak a payload.
 */
final class EnforcementLog
{
    /**
     * A LOG-band decision ("suspicious, below block"). Recorded in BOTH enforce and observe — it never
     * carried a response, so withholding it (observe) must not also withhold its visibility.
     */
    public static function suspicious(LaravelLogger $logger, Decision $decision): void
    {
        $logger->log('info', 'funnypot', ['reason' => $decision->reason()]);
    }

    /**
     * OBSERVE only: a block/deceive the engine chose but the adapter WITHHELD. Logged at
     * enforcement_log_level (default warning) so an operator running observe sees what would have run.
     */
    public static function withheld(LaravelLogger $logger, Decision $decision): void
    {
        $action = $decision->action();
        if ($action === Decision::DECEIVE || $action === Decision::BLOCK) {
            $level = (string) config('funnypot.enforcement_log_level', 'warning');
            $logger->log($level, 'funnypot observe: ' . $action, ['reason' => $decision->reason()]);
        }
    }
}
