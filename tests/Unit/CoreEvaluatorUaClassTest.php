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
use Funnypot\Policy\BotSignals;
use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\SiteProfile;
use PHPUnit\Framework\TestCase;

/**
 * The UA class must survive the core→policy boundary intact.
 *
 * CoreEvaluator maps it through a closed whitelist whose default is UA_UNKNOWN, so a class core
 * knows and the mapper does not is not an error anywhere — it is silently downgraded, and the
 * Laravel host never learns what core decided. Every core class is pinned here so adding one
 * without adding the mapping fails loudly.
 */
final class CoreEvaluatorUaClassTest extends TestCase
{
    private function evaluatorReturning(string $uaClass): CoreEvaluator
    {
        $engine = new class ($uaClass) implements Evaluator {
            /** @var string */
            private $uaClass;

            public function __construct(string $uaClass)
            {
                $this->uaClass = $uaClass;
            }

            public function classify(RequestContext $r, CoreProfile $p): CoreVerdict
            {
                return new CoreVerdict(
                    CoreVerdict::CLEAN,
                    Detection::none(),
                    '',
                    0,
                    new BotSignalSet([], 0, $this->uaClass, '')
                );
            }

            public function synthesize(CoreVerdict $v, CoreProfile $p, string $seed): ?SynthesizedResponse
            {
                return null;
            }

            public function synthesizeFromHandle(?FakeHandle $h, CoreProfile $p, string $seed): ?SynthesizedResponse
            {
                return null;
            }
        };

        return new CoreEvaluator($engine);
    }

    public function test_every_core_ua_class_crosses_the_boundary_unchanged(): void
    {
        // Wire values on the left: the classes core emits, named as strings so this test does not
        // depend on which release of core and policy happens to be installed.
        $expected = [
            'browser' => BotSignals::UA_BROWSER,
            'script' => BotSignals::UA_SCRIPT,
            'scanner' => BotSignals::UA_SCANNER,
            'good-bot' => 'good-bot',
            'empty' => BotSignals::UA_EMPTY,
            'unknown' => BotSignals::UA_UNKNOWN,
        ];

        $evidence = new RequestEvidence('GET', '/', [], [], [], '203.0.113.5');
        $profile = new SiteProfile('unknown', [], []);

        foreach ($expected as $core => $policy) {
            $verdict = $this->evaluatorReturning($core)->classify($evidence, $profile);

            self::assertSame($policy, $verdict->botSignals()->uaClass(), $core . ' must not be downgraded');
        }
    }
}
