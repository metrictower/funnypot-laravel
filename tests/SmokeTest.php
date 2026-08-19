<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap smoke check: the autoloader wires the package + its path-repo dependencies before any
 * heavier testbench feature test runs.
 */
final class SmokeTest extends TestCase
{
    public function test_policy_and_core_and_mainnet_classes_autoload(): void
    {
        $this->assertTrue(class_exists(\Funnypot\Policy\PolicyEngine::class));
        $this->assertTrue(class_exists(\Funnypot\Honeypot::class));
        $this->assertTrue(class_exists(\Funnypot\Mainnet\Client::class));
    }
}
