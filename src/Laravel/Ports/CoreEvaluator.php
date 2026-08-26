<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Ports;

use Funnypot\Core\Contracts\Evaluator as CoreEvaluatorContract;
use Funnypot\Policy\BotSignals;
use Funnypot\Policy\FakeResponse;
use Funnypot\Policy\Port\EvaluatorInterface;
use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\SiteProfile as PolicySiteProfile;
use Funnypot\Policy\Verdict as PolicyVerdict;
use Funnypot\Core\FakeHandle;
use Funnypot\Core\RequestContext;
use Funnypot\Core\SiteProfile as CoreSiteProfile;
use Funnypot\Core\SynthesizedResponse;
use Funnypot\Core\Verdict as CoreVerdict;

/**
 * The engine port: bridges funnypot-core's two-phase `Funnypot\Core\Contracts\Evaluator`
 * (classify()/synthesize(), in core-namespace types) to the policy's `Port\EvaluatorInterface`
 * (policy-namespace types). E injects core's engine; it invents no policy.
 *
 * Core's classify() and synthesize() are two phases, and the policy's Verdict cannot hold a core
 * object — so the core FakeHandle rides across the boundary as the policy Verdict's opaque
 * engineHandle (a serialized FakeHandle) and comes back via synthesizeFromHandle(). No memo, no
 * second classify(), and nothing here is PHP-8-only, so the same code serves a 7.3 WordPress host.
 *
 * The policy's own synthetic probe verdicts (pin / sacrificial / country replay) carry no handle and
 * degrade to a minimal, deterministic upgrade-of-a-404 fake — never a 500 (invariant 2).
 */
final class CoreEvaluator implements EvaluatorInterface
{
    public function __construct(private CoreEvaluatorContract $engine)
    {
    }

    public function classify(RequestEvidence $request, PolicySiteProfile $profile): PolicyVerdict
    {
        // Same reasoning as synthesize(): a classify fault must not become Decision::allow.
        // A clean verdict is the safe degradation — the request is simply not deceived.
        try {
            $coreVerdict = $this->engine->classify(
                $this->toCoreContext($request),
                $this->toCoreProfile($profile)
            );
        } catch (\Throwable $e) {
            return new PolicyVerdict(
                PolicyVerdict::CLEAN, false, '', 0, PolicyVerdict::SEVERITY_LOW,
                $profile->routeExists($request->path()), null, ''
            );
        }

        return $this->toPolicyVerdict($coreVerdict, $request, $profile);
    }

    public function synthesize(PolicyVerdict $verdict, PolicySiteProfile $profile, string $seed): FakeResponse
    {
        $handle = self::decodeHandle($verdict->engineHandle());
        if ($handle !== null) {
            // A render fault MUST NOT escape this method. PolicyEngine::evaluate() wraps the whole
            // ladder in `catch (\Throwable) { return Decision::allow('failsafe') }`, so an exception
            // here does not degrade the deception — it turns a `deceive` into an `allow` and passes
            // the attack request straight through to the real application. Fail closed, not open.
            try {
                $built = $this->engine->synthesizeFromHandle($handle, $this->toCoreProfile($profile), $seed);
            } catch (\Throwable $e) {
                $built = null;
            }
            if ($built instanceof SynthesizedResponse) {
                return $this->toFakeResponse($built);
            }
        }

        // No core template for this path (or a synthetic probe verdict): degrade to a minimal,
        // deterministic fake. Still only ever an UPGRADE of a 404 — never a 500 (invariant 2).
        return $this->genericFake($seed);
    }

    // --- core <- policy ---------------------------------------------------------------------------

    private function toCoreContext(RequestEvidence $e): RequestContext
    {
        return new RequestContext(
            $e->method(),
            $e->path(),
            http_build_query($e->query()),
            $this->stringHeaders($e->headers()),
            null, // body-shape only crosses the boundary; core never parses a raw body here
            (string) ($e->header('host') ?? ''),
            'https',
            ''
        );
    }

    private function toCoreProfile(PolicySiteProfile $profile): CoreSiteProfile
    {
        return new CoreSiteProfile(
            [$profile->stack()],
            static fn (string $method, string $path): bool => $profile->routeExists($path)
        );
    }

    // --- policy <- core ---------------------------------------------------------------------------

    private function toPolicyVerdict(CoreVerdict $v, RequestEvidence $e, PolicySiteProfile $profile): PolicyVerdict
    {
        return new PolicyVerdict(
            $this->classification($v->classification),
            $v->detection->matched,
            $this->signal($v),
            $v->anomaly,
            $this->severity($v->severity),
            $profile->routeExists($e->path()),
            $this->botSignals($v),
            self::encodeHandle($v->fakeHandle)
        );
    }

    /** Flatten core's opaque FakeHandle so the policy Verdict can carry it as bytes. */
    private static function encodeHandle(?FakeHandle $handle): string
    {
        return $handle === null ? '' : (string) json_encode($handle->toArray());
    }

    /** Rebuild it on the far side. Anything malformed degrades to null, never an exception. */
    private static function decodeHandle(string $encoded): ?FakeHandle
    {
        if ($encoded === '') {
            return null;
        }
        $data = json_decode($encoded, true);

        return is_array($data) && isset($data['kind']) ? FakeHandle::fromArray($data) : null;
    }

    private function classification(string $core): string
    {
        // Core and policy share the classification vocabulary by value; map defensively.
        return match ($core) {
            CoreVerdict::SCANNER_PROBE => PolicyVerdict::SCANNER_PROBE,
            CoreVerdict::ATTACK_CLASS  => PolicyVerdict::ATTACK_CLASS,
            CoreVerdict::SUSPICIOUS    => PolicyVerdict::SUSPICIOUS,
            default                    => PolicyVerdict::CLEAN,
        };
    }

    /** The opaque rule handle used for learn-then-enforce — never a signature string (policy §10). */
    private function signal(CoreVerdict $v): string
    {
        if ($v->detection->clusterKey !== '') {
            return $v->detection->clusterKey;
        }
        $ids = $v->detection->templateIds();

        return $ids === [] ? '' : (string) $ids[0];
    }

    private function severity(string $core): string
    {
        return match ($core) {
            'critical', 'high' => PolicyVerdict::SEVERITY_HIGH,
            'medium'           => PolicyVerdict::SEVERITY_MEDIUM,
            default            => PolicyVerdict::SEVERITY_LOW,
        };
    }

    private function botSignals(CoreVerdict $v): BotSignals
    {
        $set = $v->signals;
        $flags = [];
        foreach ((array) $set->flags as $token => $on) {
            if ($on === true) {
                $flags[] = (string) $token;
            }
        }

        return new BotSignals($this->uaClass($set->uaClass), $flags, (string) $set->fingerprint);
    }

    private function uaClass(string $core): string
    {
        // The UA-class vocabularies match by value across core/policy; map defensively.
        // Closed whitelist with a LOSSY default: every class core learns must be added here, or a
        // Laravel host silently sees UA_UNKNOWN where core said something more specific.
        // good-bot is written out rather than named: BotSignals mirrors the constant only on
        // untagged policy, and referencing it would fatal on the 0.2 releases installed today.
        return match ($core) {
            'browser'  => BotSignals::UA_BROWSER,
            'script'   => BotSignals::UA_SCRIPT,
            'scanner'  => BotSignals::UA_SCANNER,
            'good-bot' => 'good-bot',
            'empty'    => BotSignals::UA_EMPTY,
            default    => BotSignals::UA_UNKNOWN,
        };
    }

    private function toFakeResponse(SynthesizedResponse $r): FakeResponse
    {
        $contentType = '';
        foreach ($r->headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                $contentType = (string) $value;
                break;
            }
        }

        return new FakeResponse($r->status, $r->headers, $r->body, $contentType);
    }

    private function genericFake(string $seed): FakeResponse
    {
        // Deterministic-from-seed so a multi-step actor sees a coherent page; a bland, generic body.
        $token = substr(sha1($seed), 0, 8);
        $body = "<!doctype html><html><head><title>Not available</title></head>"
            . "<body><p>The requested resource is not available.</p><!-- {$token} --></body></html>";

        return new FakeResponse(200, ['Content-Type' => 'text/html; charset=UTF-8'], $body, 'text/html; charset=UTF-8');
    }

    /**
     * @param array<string,mixed> $headers
     * @return array<string,string>
     */
    private function stringHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[(string) $name] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        return $out;
    }
}
