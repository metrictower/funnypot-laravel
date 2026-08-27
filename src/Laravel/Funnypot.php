<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Policy\Decision;
use Illuminate\Http\Request;

/**
 * The detection facade (FP-0118) for apps that own their own response. Classify + report a request and
 * return the Decision WITHOUT serving anything — the app reads the Decision (or the `funnypot.decision`
 * request attribute) and responds however it likes.
 *
 * Returns null when detection faults or is unavailable — treat that as "no opinion", never "clean".
 */
final class Funnypot
{
    public function __construct(private Inspector $inspector)
    {
    }

    public function inspect(Request $request): ?Decision
    {
        return $this->inspector->inspect($request);
    }
}
