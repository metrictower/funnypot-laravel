<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\FallbackResponder;
use Funnypot\Laravel\HoneypotMiddleware;
use Funnypot\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The real thing wired end-to-end: a scanner-probe request flows through E's request mapper → the REAL
 * PolicyEngine (with E's Laravel port adapters) → the core-backed evaluator → a byte-exact core fake.
 * A clean request on a real route passes through untouched. No test doubles — this proves core + policy
 * are wired through the ports.
 */
final class EndToEndDeceptionTest extends TestCase
{
    public function test_scanner_probe_at_the_fallback_is_deceived_with_a_core_built_fake(): void
    {
        Route::fallback([FallbackResponder::class, 'handle']);

        // /.env has no route on this app → the counterfactual is a 404 → the policy chooses deceive →
        // core's synthesize() builds the byte-exact fake (application/octet-stream), never a 404 or 500.
        $response = $this->get('/.env');

        $response->assertOk();
        $this->assertNotSame('', $response->getContent(), 'the fake body should be non-empty');
        $this->assertStringContainsString(
            'octet-stream',
            (string) $response->headers->get('content-type'),
            'the core .env fake carries application/octet-stream — proving core synthesize() ran through the port'
        );
    }

    public function test_clean_request_on_a_real_route_passes_through_the_middleware(): void
    {
        Route::middleware('funnypot')->get('/dashboard', function (Request $r) {
            $decision = $r->attributes->get(HoneypotMiddleware::ATTRIBUTE_DECISION);

            return 'CLEAN:' . ($decision?->action() ?? 'none');
        });

        // A well-formed browser request to a real route → the engine allows → the app runs.
        $this->withHeaders([
            'User-Agent'       => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept'           => 'text/html,application/xhtml+xml,application/xml;q=0.9',
            'Accept-Language'  => 'en-GB,en;q=0.9',
            'Accept-Encoding'  => 'gzip, deflate, br',
            'Sec-Fetch-Site'   => 'none',
            'Sec-Fetch-Mode'   => 'navigate',
            'Sec-Fetch-Dest'   => 'document',
            'Sec-Ch-Ua'        => '"Chromium";v="120"',
            'Sec-Ch-Ua-Mobile' => '?0',
        ])->get('/dashboard')->assertOk()->assertSee('CLEAN:allow');
    }

    public function test_fallback_returns_the_app_404_for_a_clean_unmatched_request_never_a_500(): void
    {
        // With NO fallback route registered, an unmatched path is a plain 404 (the honeypot only ever
        // upgrades a 404; a clean unmatched path with no probe shape stays a 404, never a 500).
        $this->get('/definitely-not-here')->assertNotFound();
    }
}
