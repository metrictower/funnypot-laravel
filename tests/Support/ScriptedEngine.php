<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Support;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\NormalizedRequest;
use Funnypot\Policy\Decision;

/**
 * A test double for the E-side engine seam: returns a scripted Decision, or throws — so the middleware
 * executor can be proven against each action and the fail-open path without the real PolicyEngine (which
 * is final and never throws). Records how many times it was consulted.
 */
final class ScriptedEngine implements Engine
{
    public int $calls = 0;

    private function __construct(
        private ?Decision $decision,
        private bool $throws
    ) {
    }

    public static function returning(Decision $decision): self
    {
        return new self($decision, false);
    }

    public static function throwing(): self
    {
        return new self(null, true);
    }

    public function evaluate(NormalizedRequest $request): Decision
    {
        $this->calls++;
        if ($this->throws) {
            throw new \RuntimeException('scripted engine fault');
        }

        return $this->decision;
    }
}
