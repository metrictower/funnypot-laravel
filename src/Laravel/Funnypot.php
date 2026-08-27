<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Policy\Decision;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The detection facade (FP-0118/FP-0123) for apps that own their own response. Two tiers of convenience:
 *
 *  - handleRequest(): detect AND respond in one line — returns funnypot's fake/block Response for a
 *    suspicious request, or null when clean (the caller serves its own).
 *  - inspectRequest(): detect and hand back an InspectionResult, for callers that want control.
 *  - inspect(): the raw ?Decision, kept for callers wired to the policy object directly.
 *
 * None of these serve a response by themselves (except handleRequest with $die, an opt-in raw-PHP escape
 * hatch); in Laravel you RETURN what they give you.
 */
final class Funnypot
{
    public function __construct(
        private Inspector $inspector,
        private LaravelResponseMapper $responder
    ) {
    }

    /** Tier 1 — detect + respond. Returns the fake/block Response for a probe, or null when clean. */
    public function handleRequest(Request $request, bool $die = false): ?Response
    {
        $response = $this->inspectRequest($request)->toResponse();
        if ($response === null) {
            return null;
        }

        if ($die) {
            // Raw-PHP entry points only (no framework to return into). In Laravel, leave $die false and
            // RETURN the response — die() breaks terminable middleware, logging and tests.
            $response->send();
            exit;
        }

        return $response;
    }

    /** Tier 2 — detect + report and hand back a result DTO (serves nothing). */
    public function inspectRequest(Request $request): InspectionResult
    {
        return new InspectionResult($this->inspector->inspect($request), $this->responder);
    }

    /** The raw policy Decision (map -> evaluate -> report), or null on a fault / when detection is off. */
    public function inspect(Request $request): ?Decision
    {
        return $this->inspector->inspect($request);
    }
}
