<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Enforcement;
use Funnypot\Laravel\HoneypotMiddleware;
use Funnypot\Laravel\Tests\Support\ScriptedEngine;
use Funnypot\Laravel\Tests\TestCase;
use Funnypot\Policy\Decision;
use Funnypot\Policy\FakeResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The BEFORE-position executor maps each Decision correctly and fails open. The engine seam is scripted
 * so every action + the fault path is exercised deterministically (design §7).
 */
final class MiddlewareDecisionTest extends TestCase
{
    private function route(): void
    {
        // This suite pins the ENFORCE executor mapping; the before position now defaults to OBSERVE.
        config(['funnypot.enforcement.before' => Enforcement::ENFORCE]);
        Route::middleware('funnypot')->get('/guarded', function (Request $r) {
            $decision = $r->attributes->get(HoneypotMiddleware::ATTRIBUTE_DECISION);

            return 'REAL APP:' . ($decision?->reason() ?? 'none');
        });
    }

    public function test_allow_passes_through_and_attaches_the_decision(): void
    {
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::allow('allow')));
        $this->route();

        $this->get('/guarded')->assertOk()->assertSee('REAL APP:allow');
    }

    public function test_log_passes_through(): void
    {
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::log('shadow')));
        $this->route();

        $this->get('/guarded')->assertOk()->assertSee('REAL APP:shadow');
    }

    public function test_block_short_circuits_with_the_app_chosen_status(): void
    {
        $engine = ScriptedEngine::returning(Decision::block(403, 'blocklist'));
        $this->app->instance(Engine::class, $engine);
        $this->route();

        $response = $this->get('/guarded');
        $response->assertStatus(403);
        $this->assertSame('', $response->getContent()); // $next never ran
        $this->assertSame(1, $engine->calls);
    }

    public function test_deceive_serves_the_fake_verbatim_with_its_content_type(): void
    {
        $fake = new FakeResponse(200, ['X-Fake' => 'yes'], 'FAKEBODY', 'application/xml');
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::deceive($fake, 3600, 'scanner-probe')));
        $this->route();

        $response = $this->get('/guarded');
        $response->assertOk();
        $this->assertSame('FAKEBODY', $response->getContent());
        // Content-Type survives verbatim + distinct from Laravel's text/html default (invariant 5).
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('content-type'));
        $this->assertSame('yes', $response->headers->get('x-fake'));
    }

    public function test_engine_fault_fails_open_to_the_app_never_a_500(): void
    {
        $this->app->instance(Engine::class, ScriptedEngine::throwing());
        $this->route();

        $this->get('/guarded')->assertOk()->assertSee('REAL APP:');
    }
}
