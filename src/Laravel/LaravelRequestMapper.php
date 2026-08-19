<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Policy\RequestEvidence;
use Funnypot\Policy\SiteProfile;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * Illuminate\Http\Request -> the neutral RequestEvidence + SiteProfile the policy engine consumes
 * (design §4.3).
 *
 *  - Source IP (D7): $request->ip() honours the app's TrustProxies, so X-Forwarded-For is trusted only
 *    behind a configured proxy. There is no ip_header knob; the pin, the reputation port, and the
 *    reporter all read this single server-observed REMOTE_ADDR.
 *  - SiteProfile: a `laravel` stack + a real-route oracle backed by the router. `routeExists()` probes
 *    the compiled routes WITHOUT dispatching, so a fake path never collides with a route that actually
 *    exists (the FP-safety input only the host can supply).
 *  - Body-shape only: never the raw body — nothing here can carry an attacker payload verbatim.
 */
final class LaravelRequestMapper
{
    /** @param array<string,mixed> $config the `funnypot` config array */
    public function __construct(private ?Router $router = null, private array $config = [])
    {
    }

    public function map(Request $request): NormalizedRequest
    {
        $path = $request->getPathInfo();

        $evidence = new RequestEvidence(
            $request->getMethod(),
            $path,
            $this->query($request),
            $this->headers($request),
            $this->bodyShape($request),
            (string) $request->ip(),
            null,   // actorId: the IP is the actor id (no session token threaded in v1)
            null    // asn: no enrichment in v1
        );

        $profile = new SiteProfile(
            'laravel',
            $this->routeExists($request, $path) ? [$path] : [],
            $this->sacrificialFor($path)
        );

        return new NormalizedRequest($evidence, $profile);
    }

    /** @return array<string,mixed> */
    private function query(Request $request): array
    {
        return $request->query->all();
    }

    /**
     * Fold Illuminate headers into a lower-cased assoc of comma-joined values (policy re-lowers keys).
     *
     * @return array<string,string>
     */
    private function headers(Request $request): array
    {
        $out = [];
        foreach ($request->headers->all() as $name => $values) {
            $out[strtolower((string) $name)] = implode(', ', array_map('strval', (array) $values));
        }

        return $out;
    }

    /**
     * A SHAPE descriptor of the body, never the raw bytes (OAST hygiene §9). Enough for the engine's
     * request-shape signals without carrying a payload into a log/report.
     *
     * @return array<string,mixed>
     */
    private function bodyShape(Request $request): array
    {
        $content = $request->getContent();
        $len = is_string($content) ? strlen($content) : 0;

        return [
            'present'      => $len > 0,
            'length'       => $len,
            'content_type' => (string) $request->headers->get('content-type', ''),
            'param_count'  => count($request->request->all()),
        ];
    }

    /**
     * Does this path resolve to a route that actually EXISTS (and is not the catch-all fallback)? Probes
     * the compiled routes against a cloned request so the live request's route binding is untouched. Any
     * routing exception (no match / method not allowed) means "no real route" — the safe default.
     */
    private function routeExists(Request $request, string $path): bool
    {
        if ($this->router === null) {
            return false;
        }
        try {
            $route = $this->router->getRoutes()->match(Request::createFromBase(clone $request));

            return $route !== null && !$route->isFallback;
        } catch (\Throwable $ignored) {
            return false;
        }
    }

    /**
     * The configured sacrificial set restricted to the current path (the policy checks exact membership
     * for `isSacrificialPath($path)`). Supports `*` globs in the config.
     *
     * @return array<int,string>
     */
    private function sacrificialFor(string $path): array
    {
        $configured = (array) ($this->config['sacrificial_paths'] ?? []);
        foreach ($configured as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === $path) {
                return [$path];
            }
            if (strpos($pattern, '*') !== false) {
                $re = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';
                if (preg_match($re, $path) === 1) {
                    return [$path];
                }
            }
        }

        return [];
    }
}
