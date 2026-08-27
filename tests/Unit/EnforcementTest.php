<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\Enforcement;
use PHPUnit\Framework\TestCase;

/**
 * The per-position enforcement mode value object: a closed set of constants (typo-proof, discoverable)
 * with a safe-by-default normaliser — anything unrecognised becomes OBSERVE, never ENFORCE.
 */
final class EnforcementTest extends TestCase
{
    public function test_values_lists_the_three_modes(): void
    {
        self::assertSame(
            [Enforcement::OFF, Enforcement::OBSERVE, Enforcement::ENFORCE],
            Enforcement::values()
        );
    }

    public function test_normalize_accepts_each_mode(): void
    {
        self::assertSame(Enforcement::OFF, Enforcement::normalize('off'));
        self::assertSame(Enforcement::OBSERVE, Enforcement::normalize('observe'));
        self::assertSame(Enforcement::ENFORCE, Enforcement::normalize('enforce'));
    }

    public function test_normalize_maps_anything_invalid_to_observe_the_safe_mode(): void
    {
        self::assertSame(Enforcement::OBSERVE, Enforcement::normalize('block'));
        self::assertSame(Enforcement::OBSERVE, Enforcement::normalize(''));
        self::assertSame(Enforcement::OBSERVE, Enforcement::normalize(null));
    }
}
