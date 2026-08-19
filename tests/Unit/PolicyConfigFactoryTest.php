<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\Support\PolicyConfigFactory;
use Funnypot\Policy\PolicyConfig;
use Funnypot\Policy\ReputationVerdict;
use PHPUnit\Framework\TestCase;

/**
 * The Laravel config → policy config-array mapping (design §5): E's vocabulary is translated onto the
 * policy's, the removed legacy keys never leak through, and the result is accepted by PolicyConfig.
 */
final class PolicyConfigFactoryTest extends TestCase
{
    /** @return array<string,mixed> the funnypot config as published defaults */
    private function defaults(): array
    {
        return require __DIR__ . '/../../config/funnypot.php';
    }

    public function test_maps_the_core_blocks_with_inert_defaults(): void
    {
        $arr = PolicyConfigFactory::build($this->defaults());

        $this->assertSame('honeypot', $arr['posture']);
        $this->assertTrue($arr['position']['fallback']);
        $this->assertFalse($arr['position']['before']);
        $this->assertSame(['clean' => 'allow', 'suspicious' => 'log', 'attack_class' => 'block', 'scanner_probe' => 'deceive'], $arr['actions']);

        // check → reputation; verdict-first, no score `block_threshold`.
        $this->assertFalse($arr['reputation']['enabled']);
        $this->assertSame([ReputationVerdict::VERDICT_MALICIOUS, ReputationVerdict::VERDICT_CRITICAL], $arr['reputation']['block_verdicts']);
        $this->assertNull($arr['reputation']['min_block_score']);
        $this->assertArrayNotHasKey('block_threshold', $arr['reputation']);
    }

    public function test_removed_legacy_keys_are_absent(): void
    {
        $arr = PolicyConfigFactory::build($this->defaults());
        foreach (['mainnet_host', 'endpoint', 'ip_header', 'block_threshold'] as $removed) {
            $this->assertArrayNotHasKey($removed, $arr);
        }
    }

    public function test_country_posture_and_list_map_onto_mode_and_countries(): void
    {
        $config = $this->defaults();
        $config['country'] = ['enabled' => true, 'posture' => 'allowlist', 'action' => 'block', 'modifier' => 25, 'list' => ['gb', 'ie']];

        $country = PolicyConfigFactory::build($config)['country'];
        $this->assertTrue($country['enabled']);
        $this->assertSame('allow', $country['mode']);        // allowlist → allow
        $this->assertSame(['GB', 'IE'], $country['countries']); // list → countries, upper-cased
        $this->assertSame('block', $country['action']);
    }

    public function test_self_ips_come_from_the_reporting_block(): void
    {
        $config = $this->defaults();
        $config['reporting']['self_ips'] = ['203.0.113.1', '203.0.113.2'];

        $this->assertSame(['203.0.113.1', '203.0.113.2'], PolicyConfigFactory::build($config)['self_ips']);
    }

    public function test_the_mapped_array_is_accepted_by_policy_config(): void
    {
        $config = PolicyConfig::fromArray(PolicyConfigFactory::build($this->defaults()));
        $this->assertSame('honeypot', $config->posture());
        $this->assertTrue($config->positionEnabled(PolicyConfig::POSITION_FALLBACK));
    }
}
