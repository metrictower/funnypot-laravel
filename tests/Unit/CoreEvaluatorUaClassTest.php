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

    /**
     * Derived from core by reflection, NOT listed here: a hard-coded list cannot fail when core
     * gains a class, which is the whole mechanism this test exists to catch.
     *
     * The UA_* constants cover two different things — the coarse UA classes, and signal flags like
     * UA_CLAIMS_BROWSER_NO_HINTS. The classes are told apart by their VALUE: a flag's value is
     * itself `ua_`-prefixed, a class's is not.
     *
     * @return array<string,string> constant name => wire value
     */
    private function coreUaClasses(): array
    {
        $classes = [];
        foreach ((new \ReflectionClass(BotSignalSet::class))->getConstants() as $name => $value) {
            if (strpos($name, 'UA_') === 0 && is_string($value) && strpos($value, 'ua_') !== 0) {
                $classes[$name] = $value;
            }
        }

        return $classes;
    }

    public function test_every_core_ua_class_crosses_the_boundary_unchanged(): void
    {
        $classes = $this->coreUaClasses();

        // Guard the guard: if the filter ever matches nothing, every assertion below would pass
        // vacuously and the boundary would be unprotected while the suite stayed green.
        self::assertGreaterThanOrEqual(6, count($classes), 'reflection found too few UA classes in core — the filter is wrong');
        self::assertContains('good-bot', $classes, 'core must still expose the good-bot class');

        $evidence = new RequestEvidence('GET', '/', [], [], [], '203.0.113.5');
        $profile = new SiteProfile('unknown', [], []);

        foreach ($classes as $name => $wire) {
            $got = $this->evaluatorReturning($wire)->classify($evidence, $profile)->botSignals()->uaClass();

            // The mapper's default is UA_UNKNOWN, so a class it does not know is silently
            // downgraded rather than erroring. Only 'unknown' itself may map to unknown.
            self::assertSame(
                $wire,
                $got,
                BotSignalSet::class . '::' . $name . " ('" . $wire . "') was downgraded to '" . $got
                    . "' crossing the boundary — add it to CoreEvaluator::uaClass()"
            );
        }
    }

}
