<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Policy\Decision;
use Illuminate\Http\Response;

/**
 * The consumer-facing result of Funnypot::inspectRequest() (FP-0123). It wraps a policy Decision so an
 * integrator writes `if ($r->isSuspicious()) return $r->toResponse();` and never touches Decision,
 * fakeHandle, or LaravelResponseMapper directly.
 *
 * A null Decision (detection off or a fault) degrades to a CLEAN, non-throwing result — treat "clean"
 * as "no opinion / do your own thing", never as a guarantee the request is safe.
 */
final class InspectionResult
{
    public function __construct(
        private ?Decision $decision,
        private LaravelResponseMapper $responder
    ) {
    }

    /** A deceive or block verdict — the caller should serve toResponse(). */
    public function isSuspicious(): bool
    {
        return $this->decision !== null
            && in_array($this->decision->action(), [Decision::DECEIVE, Decision::BLOCK], true);
    }

    public function isClean(): bool
    {
        return !$this->isSuspicious();
    }

    public function action(): string
    {
        return $this->decision === null ? Decision::ALLOW : $this->decision->action();
    }

    public function reason(): string
    {
        return $this->decision === null ? 'allow' : $this->decision->reason();
    }

    /** The raw policy Decision, or null — the escape hatch for callers that want the underlying object. */
    public function decision(): ?Decision
    {
        return $this->decision;
    }

    /**
     * The response to serve: funnypot's byte-exact fake (deceive) or an honest refusal (block), already
     * mapped to an Illuminate response with headers copied verbatim. Null when there is nothing to serve
     * (clean / allow / log) — the caller falls through to its own handling.
     */
    public function toResponse(): ?Response
    {
        if ($this->decision === null) {
            return null;
        }

        if ($this->decision->action() === Decision::DECEIVE && $this->decision->fakeHandle() !== null) {
            return $this->responder->fake($this->decision->fakeHandle());
        }

        if ($this->decision->action() === Decision::BLOCK) {
            return $this->responder->block($this->decision->status() ?? 403);
        }

        return null;
    }
}
