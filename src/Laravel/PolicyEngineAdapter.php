<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Policy\Decision;
use Funnypot\Policy\PolicyEngine;

/**
 * Forwards the normalised request to the real position-blind PolicyEngine. This is the whole of E's
 * "call the engine" contract — a three-line pass-through with no decision logic of its own.
 */
final class PolicyEngineAdapter implements Engine
{
    public function __construct(private PolicyEngine $policy)
    {
    }

    public function evaluate(NormalizedRequest $request): Decision
    {
        return $this->policy->evaluate($request->evidence(), $request->profile());
    }
}
