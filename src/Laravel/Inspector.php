<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Reporting\ReportDispatcher;
use Funnypot\Policy\Decision;
use Illuminate\Http\Request;

/**
 * The shared detection head (FP-0118) reused by HoneypotMiddleware, FallbackResponder, and the Funnypot
 * facade: normalize → evaluate → stash the Decision on the request → dispatch any report → return it.
 *
 * Serves NO response — the caller decides what to do with the Decision. Fail-safe: any fault returns
 * null (the caller degrades to $next / a plain 404), never a 500.
 */
final class Inspector
{
    public function __construct(
        private LaravelRequestMapper $mapper,
        private Engine $engine,
        private ReportDispatcher $reports
    ) {
    }

    public function inspect(Request $request): ?Decision
    {
        try {
            $decision = $this->engine->evaluate($this->mapper->map($request));
            $request->attributes->set(HoneypotMiddleware::ATTRIBUTE_DECISION, $decision);

            $report = $decision->report();
            if ($report !== null) {
                $this->reports->dispatch($report);
            }

            return $decision;
        } catch (\Throwable $ignored) {
            return null; // fail open — never a 500; the caller passes through
        }
    }
}
