<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Closure;
use Funnypot\Laravel\Ports\LaravelLogger;
use Funnypot\Policy\Decision;
use Illuminate\Http\Request;

/**
 * BEFORE-position executor (design §4.2). Detection is delegated to Inspector; this class only decides,
 * per the `enforcement.before` mode, whether to PERFORM the Decision or OBSERVE it:
 *
 *  - off     → pass straight through, never evaluate (a per-position kill switch)
 *  - observe → detect + report (in Inspector), keep LOG-band visibility, log a withheld block/deceive,
 *              then $next — the app owns the response
 *  - enforce → allow → $next; log → record + $next; block → honest refusal; deceive → core's fake
 *
 * FAIL-SAFE (invariant 2): any fault degrades to $next — never a 500, never a spurious block.
 */
final class HoneypotMiddleware
{
    public const ATTRIBUTE_DECISION = 'funnypot.decision';

    public function __construct(
        private Inspector $inspector,
        private LaravelResponseMapper $responder,
        private LaravelLogger $logger
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $mode = Enforcement::normalize(config('funnypot.enforcement.before', Enforcement::OBSERVE));
            if ($mode === Enforcement::OFF) {
                return $next($request);
            }

            $decision = $this->inspector->inspect($request);
            if ($decision === null) {
                return $next($request); // fail open
            }

            // LOG-band telemetry fires in enforce AND observe — a below-block "suspicious" decision never
            // carried a response, so observe must not also swallow its visibility.
            if ($decision->action() === Decision::LOG) {
                EnforcementLog::suspicious($this->logger, $decision);
            }

            if ($mode === Enforcement::OBSERVE) {
                EnforcementLog::withheld($this->logger, $decision);

                return $next($request);
            }

            return $this->enforce($decision, $request, $next);
        } catch (\Throwable $ignored) {
            return $next($request); // belt-and-suspenders: a responder fault must not become a 500
        }
    }

    private function enforce(Decision $decision, Request $request, Closure $next): mixed
    {
        switch ($decision->action()) {
            case Decision::DECEIVE:
                $fake = $decision->fakeHandle();

                return $fake === null ? $next($request) : $this->responder->fake($fake);

            case Decision::BLOCK:
                return $this->responder->block($decision->status() ?? 403);

            case Decision::LOG:   // already recorded in handle()
            case Decision::ALLOW:
            default:
                return $next($request);
        }
    }
}
