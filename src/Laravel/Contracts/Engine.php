<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Contracts;

use Funnypot\Laravel\NormalizedRequest;
use Funnypot\Policy\Decision;

/**
 * The seam the middleware + fallback responder call. Its only production implementation
 * (PolicyEngineAdapter) forwards straight to Funnypot\Policy\PolicyEngine::evaluate() — E owns no
 * decision logic. The seam exists so the executor can be tested against scripted / throwing engines
 * (the real PolicyEngine is final and never throws — it fails open internally).
 */
interface Engine
{
    public function evaluate(NormalizedRequest $request): Decision;
}
