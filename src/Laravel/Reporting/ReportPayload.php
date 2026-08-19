<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Reporting;

use Funnypot\Policy\ReportIntent;

/**
 * Turn a policy ReportIntent into the fingerprint-safe wire fields (design §4.5, invariant 1).
 *
 *  - `comment` is a FIXED generic string — never a nuclei matcher word / CRS rule id / ModSecurity
 *    marker / raw payload.
 *  - `categories` derive from the intent's OPAQUE category tokens via a coarse numeric map (fallback
 *    the configured web-app-attack id), never a signature string.
 *  - `ip` is the policy-normalised score_key (an IPv6 already folded to its /64 — P2).
 */
final class ReportPayload
{
    /** A fixed, generic, fingerprint-safe report comment. */
    public const COMMENT = 'Automated honeypot detection';

    /** Coarse token → numeric category map (kept small + generic). */
    private const CATEGORY_MAP = [
        ReportIntent::CATEGORY_BAD_BOT => 19, // "Bad Web Bot"
    ];

    /**
     * @param array<int,int> $fallbackCategories
     * @return array{ip:string,categories:string,comment:string}
     */
    public static function fromIntent(ReportIntent $intent, array $fallbackCategories = [21]): array
    {
        $ids = [];
        foreach ($intent->categories() as $token) {
            if (isset(self::CATEGORY_MAP[$token])) {
                $ids[self::CATEGORY_MAP[$token]] = true;
            }
        }
        if ($ids === []) {
            foreach ($fallbackCategories as $id) {
                $ids[(int) $id] = true;
            }
        }

        return [
            'ip'         => $intent->ip(),
            'categories' => implode(',', array_map('strval', array_keys($ids))),
            'comment'    => self::COMMENT,
        ];
    }
}
