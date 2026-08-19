<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Closure;
use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Ports\LaravelLogger;
use Funnypot\Laravel\Reporting\ReportDispatcher;
use Funnypot\Policy\Decision;
use Illuminate\Http\Request;

/**
 * BEFORE-position Decision executor (design §4.2): normalize → evaluate → execute. Owns no decision
 * logic — it asks the policy engine and performs the effect the returned Decision names.
 *
 *  - allow → $next($request) (byte-identical to today's request path)
 *  - log   → record the (non-sensitive) reason, then $next($request)
 *  - block → an honest empty refusal at the app-chosen status (protect-mode only, invariant 5)
 *  - deceive → core's byte-exact fake, short-circuited (Content-Type matches the request)
 *  - report? → enqueue delivery (the policy already applied suppression)
 *
 * FAIL-SAFE (invariant 2): any thrown fault degrades to $next($request) — never a 500, never a spurious
 * block. A 500 is itself a tell. The engine is already fail-open; this try/catch is belt-and-suspenders.
 */
final class HoneypotMiddleware
{
    public const ATTRIBUTE_DECISION = 'funnypot.decision';

    public function __construct(
        private LaravelRequestMapper $mapper,
        private Engine $engine,
        private LaravelResponseMapper $responder,
        private ReportDispatcher $reports,
        private LaravelLogger $logger
    ) {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $normalized = $this->mapper->map($request);
            $decision = $this->engine->evaluate($normalized);
            $request->attributes->set(self::ATTRIBUTE_DECISION, $decision);

            $this->maybeReport($decision);

            return $this->execute($decision, $request, $next);
        } catch (\Throwable $ignored) {
            return $next($request); // fail open — never a 500, never a spurious block
        }
    }

    private function execute(Decision $decision, Request $request, Closure $next): mixed
    {
        switch ($decision->action()) {
            case Decision::DECEIVE:
                $fake = $decision->fakeHandle();
                if ($fake === null) {
                    return $next($request); // no fake to serve → degrade to the app (never a 500)
                }

                return $this->responder->fake($fake);

            case Decision::BLOCK:
                return $this->responder->block($decision->status() ?? 403);

            case Decision::LOG:
                $this->logger->log('info', 'funnypot', ['reason' => $decision->reason()]);

                return $next($request);

            case Decision::ALLOW:
            default:
                return $next($request);
        }
    }

    private function maybeReport(Decision $decision): void
    {
        $report = $decision->report();
        if ($report !== null) {
            $this->reports->dispatch($report);
        }
    }
}
