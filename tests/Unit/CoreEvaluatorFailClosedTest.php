<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Core\BotSignalSet;
use Funnypot\Core\Contracts\Evaluator;
use Funnypot\Core\Detection;
use Funnypot\Core\FakeHandle;
use Funnypot\Core\RequestContext;
use Funnypot\Core\SiteProfile as CoreProfile;
use Funnypot\Core\SynthesizedResponse;
use Funnypot\Core\Verdict as CoreVerdict;
use Funnypot\Laravel\Ports\CoreEvaluator;
use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\SiteProfile;
use Funnypot\Policy\Verdict as PolicyVerdict;
use PHPUnit\Framework\TestCase;

/**
 * A render or classify fault must never escape this adapter.
 *
 * PolicyEngine::evaluate() wraps the whole ladder in
 * `catch (\Throwable) { return Decision::allow('failsafe') }`. So an exception escaping here does
 * not merely spoil one deception — it converts a `deceive` into an `allow` and passes the attack
 * request through to the real application. Fail closed.
 */
final class CoreEvaluatorFailClosedTest extends TestCase
{
    private function evaluator(): CoreEvaluator
    {
        return new CoreEvaluator(new class implements Evaluator {
            public function classify(RequestContext $r, CoreProfile $p): CoreVerdict
            {
                throw new \RuntimeException('engine blew up');
            }

            public function synthesize(CoreVerdict $v, CoreProfile $p, string $seed): ?SynthesizedResponse
            {
                throw new \RuntimeException('render blew up');
            }

            public function synthesizeFromHandle(?FakeHandle $h, CoreProfile $p, string $seed): ?SynthesizedResponse
            {
                throw new \RuntimeException('render blew up');
            }
        });
    }

    private function profile(): SiteProfile
    {
        return new SiteProfile('unknown', [], []);
    }

    public function test_a_render_fault_does_not_escape_synthesize(): void
    {
        $handle = json_encode(['kind' => 'route', 'key' => 'GET /.env', 'ruleId' => null, 'captures' => []]);
        $verdict = new PolicyVerdict(
            PolicyVerdict::SCANNER_PROBE, true, 'rule', 0,
            PolicyVerdict::SEVERITY_HIGH, false, null, (string) $handle
        );

        $fake = $this->evaluator()->synthesize($verdict, $this->profile(), 'seed');

        // Whatever it returns, it must RETURN — an escaping throw becomes Decision::allow upstream.
        self::assertNotNull($fake);
    }

    public function test_a_classify_fault_does_not_escape_classify(): void
    {
        $evidence = new RequestEvidence('GET', '/.env', [], ['User-Agent' => 'curl/8'], [], '203.0.113.5');

        $verdict = $this->evaluator()->classify($evidence, $this->profile());

        self::assertSame(PolicyVerdict::CLEAN, $verdict->classification(), 'a fault must degrade to clean, never deceive');
        self::assertFalse($verdict->matched());
    }
}
