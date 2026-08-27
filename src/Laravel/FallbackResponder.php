<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Laravel\Ports\LaravelLogger;
use Funnypot\Policy\Decision;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * NOT_FOUND-position executor (design §4.2b): the 404 entry point, registered as a Route::fallback()
 * action (or invoked from the exception Handler on a NotFoundHttpException). Detection is delegated to
 * Inspector; this class only decides, per the `enforcement.not_found` mode, whether to PERFORM the
 * Decision or OBSERVE it. At the fallback the counterfactual is already a 404, so `deceive` is FP-free.
 *
 *  - off     → the app's own 404, never evaluate
 *  - observe → detect + report (in Inspector), log a withheld block/deceive, then the app's own 404
 *  - enforce → deceive → core's byte-exact fake; block → honest refusal; else the app's own 404
 *
 * Any fault degrades to a plain 404 (invariant 2) — never a 500.
 */
final class FallbackResponder
{
    public function __construct(
        private Inspector $inspector,
        private LaravelResponseMapper $responder,
        private LaravelLogger $logger
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $mode = Enforcement::normalize(config('funnypot.enforcement.not_found', Enforcement::ENFORCE));
            if ($mode === Enforcement::OFF) {
                return $this->notFound();
            }

            $decision = $this->inspector->inspect($request);
            if ($decision === null) {
                return $this->notFound(); // fail open
            }

            if ($mode === Enforcement::OBSERVE) {
                HoneypotMiddleware::logWithheld($this->logger, $decision);

                return $this->notFound();
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
