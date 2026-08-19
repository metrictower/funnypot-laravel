<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\Ports\LaravelGeoIp;
use Funnypot\Laravel\Ports\LaravelStateStore;
use Funnypot\Laravel\Support\PolicyConfigFactory;
use Funnypot\Laravel\Tests\Support\FakeEvaluator;
use Funnypot\Laravel\Tests\Support\FakeReputation;
use Funnypot\Laravel\Tests\Support\FixedClock;
use Funnypot\Policy\Decision;
use Funnypot\Policy\Geo\NullGeoIp;
use Funnypot\Policy\Log\NullLogger;
use Funnypot\Policy\PolicyConfig;
use Funnypot\Policy\PolicyEngine;
use Funnypot\Policy\Port\EvaluatorInterface;
use Funnypot\Policy\Port\ReputationInterface;
use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\ReputationVerdict;
use Funnypot\Policy\SiteProfile;
use Funnypot\Policy\Verdict;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The country gate (R) and reputation-block (F) are policy actions the E adapter executes. These tests
 * wire the REAL PolicyEngine with E's config mapping + E's Laravel GeoIP/StateStore ports (and scripted
 * evaluator/reputation for determinism) and assert the policy decides — E owns no gate logic.
 */
final class PolicyGatesTest extends TestCase
{
    private const DB = __DIR__ . '/../fixtures/geoip.json';

    /**
     * @param array<string,mixed> $funnypotConfig
     */
    private function engine(array $funnypotConfig, EvaluatorInterface $evaluator, ReputationInterface $reputation, $geo): PolicyEngine
    {
        return new PolicyEngine(
            $evaluator,
            $reputation,
            new LaravelStateStore(new Repository(new ArrayStore()), new FixedClock()),
            $geo,
            new FixedClock(),
            new NullLogger(),
            PolicyConfig::fromArray(PolicyConfigFactory::build($funnypotConfig)),
            'test-salt',
            'honeypot'
        );
    }

    private function evidence(string $ip): RequestEvidence
    {
        return new RequestEvidence('GET', '/x', [], ['user-agent' => 'Mozilla/5.0'], [], $ip);
    }

    private function cleanVerdict(): Verdict
    {
        return new Verdict(Verdict::CLEAN, false, '', 0, Verdict::SEVERITY_LOW, true);
    }

    public function test_country_denylist_block_is_decided_by_the_policy_from_the_local_geoip(): void
    {
        $config = [
            'posture' => 'WAF', // before position, protect mode → a block ceiling
            'country' => ['enabled' => true, 'posture' => 'denylist', 'action' => 'block', 'list' => ['CN']],
            'geoip'   => ['enabled' => true, 'database' => self::DB],
        ];
        $engine = $this->engine(
            $config,
            new FakeEvaluator($this->cleanVerdict()),
            new FakeReputation(ReputationVerdict::absent()),
            new LaravelGeoIp(['geoip' => ['enabled' => true, 'database' => self::DB]])
        );

        $decision = $engine->evaluate($this->evidence('203.0.113.10'), new SiteProfile('laravel'));

        $this->assertSame(Decision::BLOCK, $decision->action());
        $this->assertSame('country', $decision->reason());
    }

    public function test_country_modifier_default_does_not_block_alone(): void
    {
        $config = [
            'posture' => 'WAF',
            'country' => ['enabled' => true, 'posture' => 'denylist', 'action' => 'modifier', 'list' => ['CN']],
            'geoip'   => ['enabled' => true, 'database' => self::DB],
        ];
        $engine = $this->engine(
            $config,
            new FakeEvaluator($this->cleanVerdict()),
            new FakeReputation(ReputationVerdict::absent()),
            new LaravelGeoIp(['geoip' => ['enabled' => true, 'database' => self::DB]])
        );

        $decision = $engine->evaluate($this->evidence('203.0.113.10'), new SiteProfile('laravel'));
        $this->assertNotSame(Decision::BLOCK, $decision->action(), 'a country modifier never blocks on its own (R3/M6)');
    }

    public function test_reputation_promotes_suspicious_content_to_block_when_enabled(): void
    {
        $config = [
            'posture' => 'WAF', // before position → protect mode
            'mainnet' => ['base_url' => 'https://api.mainnet.example', 'key' => 'sensor-key'],
            'check'   => ['enabled' => true, 'block_verdicts' => ['malicious', 'critical']],
        ];
        // Content is suspicious (base action = log); a malicious cached reputation promotes it to block.
        $suspicious = new Verdict(Verdict::SUSPICIOUS, false, '', 0, Verdict::SEVERITY_MEDIUM, true);
        $engine = $this->engine(
            $config,
            new FakeEvaluator($suspicious),
            new FakeReputation(new ReputationVerdict(ReputationVerdict::VERDICT_MALICIOUS, 90, ReputationVerdict::SOURCE_CACHE)),
            new NullGeoIp()
        );

        $decision = $engine->evaluate($this->evidence('203.0.113.50'), new SiteProfile('laravel'));

        $this->assertSame(Decision::BLOCK, $decision->action());
        $this->assertSame('reputation-block', $decision->reason());
    }

    public function test_clean_content_is_never_blocked_on_reputation_alone(): void
    {
        $config = [
            'posture' => 'WAF',
            'mainnet' => ['base_url' => 'https://api.mainnet.example', 'key' => 'sensor-key'],
            'check'   => ['enabled' => true, 'block_verdicts' => ['malicious', 'critical']],
        ];
        $engine = $this->engine(
            $config,
            new FakeEvaluator($this->cleanVerdict()),
            new FakeReputation(new ReputationVerdict(ReputationVerdict::VERDICT_MALICIOUS, 90, ReputationVerdict::SOURCE_CACHE)),
            new NullGeoIp()
        );

        $decision = $engine->evaluate($this->evidence('203.0.113.51'), new SiteProfile('laravel'));
        $this->assertNotSame(Decision::BLOCK, $decision->action(), 'reputation is a modifier, never primary (§4)');
    }
}
