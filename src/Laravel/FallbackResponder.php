<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Reporting\ReportDispatcher;
use Funnypot\Policy\Decision;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * FALLBACK-position Decision executor (design §4.2b): the 404 entry point, registered as a
 * Route::fallback() action (or invoked from the exception Handler on a NotFoundHttpException). Same
 * normalize → evaluate → execute as the middleware, but at the fallback the counterfactual is already a
 * 404, so `deceive` is FP-free by construction (the classic honeypot upgrade of a 404).
 *
 * On `allow`/`log` it returns the app's own 404 — never a 500 (invariant 2). Any fault degrades to a
 * plain 404 as well.
 */
final class FallbackResponder
{
    public function __construct(
        private LaravelRequestMapper $mapper,
        private Engine $engine,
        private LaravelResponseMapper $responder,
        private ReportDispatcher $reports
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $normalized = $this->mapper->map($request);
            $decision = $this->engine->evaluate($normalized);
            $request->attributes->set(HoneypotMiddleware::ATTRIBUTE_DECISION, $decision);

            $report = $decision->report();
            if ($report !== null) {
                $this->reports->dispatch($report);
            }

            if ($decision->action() === Decision::DECEIVE && $decision->fakeHandle() !== null) {
                return $this->responder->fake($decision->fakeHandle());
            }
            if ($decision->action() === Decision::BLOCK) {
                return $this->responder->block($decision->status() ?? 403);
            }

            return $this->notFound();
        } catch (\Throwable $ignored) {
            return $this->notFound();
        }
    }

    private function notFound(): Response
    {
        return new Response('', 404);
    }
}
