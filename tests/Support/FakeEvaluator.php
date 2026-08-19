<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Support;

use Funnypot\Policy\FakeResponse;
use Funnypot\Policy\Port\EvaluatorInterface;
use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\SiteProfile;
use Funnypot\Policy\Verdict;

/**
 * A scripted policy evaluator: classify() returns a fixed Verdict so the two-axis combination can be
 * driven deterministically (e.g. a content-suspicious request that reputation may promote to block).
 */
final class FakeEvaluator implements EvaluatorInterface
{
    public function __construct(private Verdict $verdict)
    {
    }

    public function classify(RequestEvidence $request, SiteProfile $profile): Verdict
    {
        return $this->verdict;
    }

    public function synthesize(Verdict $verdict, SiteProfile $profile, string $seed): FakeResponse
    {
        return new FakeResponse(200, ['Content-Type' => 'text/html'], 'fake', 'text/html');
    }
}
