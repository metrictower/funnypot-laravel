<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\PolicyEngineAdapter;
use Funnypot\Laravel\Ports\CoreEvaluator;
use Funnypot\Laravel\Ports\LaravelGeoIp;
use Funnypot\Laravel\Ports\LaravelStateStore;
use Funnypot\Laravel\Ports\MainnetReputation;
use Funnypot\Laravel\Tests\TestCase;
use Funnypot\Policy\Port\EvaluatorInterface;
use Funnypot\Policy\Port\GeoIpInterface;
use Funnypot\Policy\Port\ReputationInterface;
use Funnypot\Policy\Port\StateStoreInterface;
use Funnypot\Policy\PolicyEngine;

final class ProviderTest extends TestCase
{
    public function test_policy_engine_resolves_wired_with_laravel_ports(): void
    {
        $this->assertInstanceOf(PolicyEngine::class, $this->app->make(PolicyEngine::class));
        $this->assertSame($this->app->make(PolicyEngine::class), $this->app->make('funnypot.policy'));

        $this->assertInstanceOf(PolicyEngineAdapter::class, $this->app->make(Engine::class));
        $this->assertInstanceOf(LaravelStateStore::class, $this->app->make(StateStoreInterface::class));
        $this->assertInstanceOf(MainnetReputation::class, $this->app->make(ReputationInterface::class));
        $this->assertInstanceOf(LaravelGeoIp::class, $this->app->make(GeoIpInterface::class));
        $this->assertInstanceOf(CoreEvaluator::class, $this->app->make(EvaluatorInterface::class));
    }

    public function test_funnypot_middleware_alias_is_registered(): void
    {
        $aliases = $this->app['router']->getMiddleware();
        $this->assertArrayHasKey('funnypot', $aliases);
        $this->assertSame(\Funnypot\Laravel\HoneypotMiddleware::class, $aliases['funnypot']);
    }

    public function test_config_is_merged_with_inert_defaults(): void
    {
        $this->assertSame('honeypot', config('funnypot.posture'));
        $this->assertFalse((bool) config('funnypot.check.enabled'));
        $this->assertSame('', (string) config('funnypot.mainnet.key'));

        // The default honeypot posture resolves to fallback-on / before-off (the preset).
        $policy = \Funnypot\Policy\PolicyConfig::fromArray(
            \Funnypot\Laravel\Support\PolicyConfigFactory::build((array) config('funnypot'))
        );
        $this->assertTrue($policy->positionEnabled(\Funnypot\Policy\PolicyConfig::POSITION_FALLBACK));
        $this->assertFalse($policy->positionEnabled(\Funnypot\Policy\PolicyConfig::POSITION_BEFORE));
    }

    public function test_vendor_publish_writes_the_config(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'funnypot-config'])->assertExitCode(0);
        $this->assertFileExists(config_path('funnypot.php'));
    }

    public function test_rules_update_status_command_is_registered_and_errors_without_data_dir(): void
    {
        $this->artisan('funnypot:rules-update', ['--status' => true])->assertExitCode(1);
    }
}
