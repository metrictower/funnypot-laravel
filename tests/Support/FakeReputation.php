<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Support;

use Funnypot\Policy\Port\ReputationInterface;
use Funnypot\Policy\ReputationVerdict;

/** A scripted reputation port returning a fixed cached verdict (no network). */
final class FakeReputation implements ReputationInterface
{
    public function __construct(private ReputationVerdict $verdict)
    {
    }

    public function lookup(string $ip): ReputationVerdict
    {
        return $this->verdict;
    }
}
