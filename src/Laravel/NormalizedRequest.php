<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\SiteProfile;

/**
 * The neutral pair the request mapper hands the policy engine: the position-blind RequestEvidence and
 * the SiteProfile (declared stack + real-route oracle). Immutable value holder — no behaviour.
 */
final class NormalizedRequest
{
    public function __construct(
        private RequestEvidence $evidence,
        private SiteProfile $profile
    ) {
    }

    public function evidence(): RequestEvidence
    {
        return $this->evidence;
    }

    public function profile(): SiteProfile
    {
        return $this->profile;
    }

    /** The server-observed source IP threaded onto the evidence (D7) — read by the reporter/pin. */
    public function ip(): string
    {
        return $this->evidence->ip();
    }
}
