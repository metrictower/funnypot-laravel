<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

/**
 * Per-position enforcement mode (FP-0118). A closed set of string constants so config is typo-proof and
 * every option is discoverable from one class.
 *
 * Adapter-layer only: it decides whether the executor PERFORMS the engine's Decision or merely OBSERVEs
 * it. It never feeds the policy engine — `posture`/`position` keep their own meaning there.
 */
final class Enforcement
{
    /** Position disabled: the executor short-circuits and never evaluates. */
    public const OFF = 'off';

    /** Detect + report + log the withheld action, then pass through — the app owns the response. */
    public const OBSERVE = 'observe';

    /** Perform the Decision: serve the fake / the block (the pre-FP-0118 behaviour). */
    public const ENFORCE = 'enforce';

    /** @return array<int,string> */
    public static function values(): array
    {
        return [self::OFF, self::OBSERVE, self::ENFORCE];
    }

    public static function isValid(string $v): bool
    {
        return in_array($v, self::values(), true);
    }

    /**
     * Coerce a config value to a known mode. Anything unrecognised — a typo, an empty string, a
     * non-string — becomes OBSERVE, the safe mode, never ENFORCE.
     *
     * @param mixed $value
     */
    public static function normalize($value): string
    {
        $v = is_string($value) ? $value : '';

        return self::isValid($v) ? $v : self::OBSERVE;
    }
}
