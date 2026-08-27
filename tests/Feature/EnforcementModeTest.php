<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Enforcement;
use Funnypot\Laravel\FallbackResponder;
use Funnypot\Laravel\Funnypot;
use Funnypot\Laravel\HoneypotMiddleware;
use Funnypot\Laravel\Jobs\SendMainnetReport;
use Funnypot\Laravel\Tests\Support\ScriptedEngine;
use Funnypot\Laravel\Tests\TestCase;
use Funnypot\Policy\Decision;
use Funnypot\Policy\FakeResponse;
use Funnypot\Policy\ReportIntent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;

/**
 * Per-position enforcement: for each position (before / not_found) the adapter either ENFORCEs the
 * decision (serve the fake / block), OBSERVEs it (detect + report + log, pass through), or is OFF
 * (short-circuit without evaluating). Each assertion fails on the pre-feature code.
 */
final class EnforcementModeTest extends TestCase
{
    private function guardedRoute(): void
    {
        Route::middleware('funnypot')->get('/guarded', function (Request $r) {
            $d = $r->attributes->get(HoneypotMiddleware::ATTRIBUTE_DECISION);

            return 'REAL APP:' . ($d?->reason() ?? 'none');
        });
    }

    private function reportIntent(): ReportIntent
    {
        return new ReportIntent('203.0.113.9', 'deceive', 'honeypot', 210, [ReportIntent::CATEGORY_BAD_BOT], 'dedup-1', null, null, true);
    }

    // --- before position ---

    public function test_before_observe_withholds_the_block_and_passes_through(): void
    {
        config(['funnypot.enforcement.before' => Enforcement::OBSERVE]);
        $engine = ScriptedEngine::returning(Decision::block(403, 'blocklist'));
        $this->app->instance(Engine::class, $engine);
        $this->guardedRoute();

        $this->get('/guarded')->assertOk()->assertSee('REAL APP:blocklist');
        self::assertSame(1, $engine->calls, 'observe still evaluates');
    }

    public function test_before_enforce_serves_the_block(): void
    {
        config(['funnypot.enforcement.before' => Enforcement::ENFORCE]);
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::block(403, 'blocklist')));
        $this->guardedRoute();

        $response = $this->get('/guarded');
        $response->assertStatus(403);
        self::assertSame('', $response->getContent());
    }

    public function test_before_off_passes_through_without_evaluating(): void
    {
        config(['funnypot.enforcement.before' => Enforcement::OFF]);
        $engine = ScriptedEngine::returning(Decision::block(403, 'blocklist'));
        $this->app->instance(Engine::class, $engine);
        $this->guardedRoute();

        $this->get('/guarded')->assertOk();
        self::assertSame(0, $engine->calls, 'OFF must not evaluate at all');
    }

    public function test_before_observe_still_reports_and_logs_a_warning(): void
    {
        config([
            'funnypot.enforcement.before' => Enforcement::OBSERVE,
            'funnypot.mainnet.key'        => 'sensor-key',
            'funnypot.reporting.enabled'  => true,
            'queue.default'               => 'database',
        ]);
        Queue::fake();
        $handler = new TestHandler();
        Log::channel('funnypot')->getLogger()->pushHandler($handler);

        $decision = Decision::block(403, 'blocklist')->withReport($this->reportIntent());
        $this->app->instance(Engine::class, ScriptedEngine::returning($decision));
        $this->guardedRoute();

        $this->get('/guarded')->assertOk();                  // response withheld
        Queue::assertPushed(SendMainnetReport::class, 1);    // report still fired
        self::assertTrue($handler->hasWarningThatContains('block'), 'observe logs the withheld action at warning');
    }

    public function test_observe_log_level_is_configurable(): void
    {
        config([
            'funnypot.enforcement.before'    => Enforcement::OBSERVE,
            'funnypot.enforcement_log_level' => 'notice',
        ]);
        $handler = new TestHandler();
        Log::channel('funnypot')->getLogger()->pushHandler($handler);
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::block(403, 'blocklist')));
        $this->guardedRoute();

        $this->get('/guarded')->assertOk();
        self::assertTrue($handler->hasNoticeThatContains('block'));
        self::assertFalse($handler->hasWarningRecords());
    }

    // --- not_found position ---

    public function test_not_found_observe_returns_the_app_404_not_the_fake(): void
    {
        config(['funnypot.enforcement.not_found' => Enforcement::OBSERVE]);
        $engine = ScriptedEngine::returning(Decision::deceive(new FakeResponse(200, [], 'FAKEBODY', 'application/xml'), null, 'scanner-probe'));
        $this->app->instance(Engine::class, $engine);
        Route::fallback([FallbackResponder::class, 'handle']);

        $response = $this->get('/nope');
        $response->assertNotFound();
        self::assertStringNotContainsString('FAKEBODY', $response->getContent());
        self::assertSame(1, $engine->calls);
    }

    public function test_not_found_enforce_serves_the_fake(): void
    {
        config(['funnypot.enforcement.not_found' => Enforcement::ENFORCE]);
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::deceive(new FakeResponse(200, [], 'FAKEBODY', 'application/xml'), null, 'scanner-probe')));
        Route::fallback([FallbackResponder::class, 'handle']);

        $response = $this->get('/nope');
        $response->assertOk();
        self::assertSame('FAKEBODY', $response->getContent());
    }

    public function test_not_found_off_passes_through_without_evaluating(): void
    {
        config(['funnypot.enforcement.not_found' => Enforcement::OFF]);
        $engine = ScriptedEngine::returning(Decision::deceive(new FakeResponse(200, [], 'FAKEBODY', 'application/xml'), null, 'scanner-probe'));
        $this->app->instance(Engine::class, $engine);
        Route::fallback([FallbackResponder::class, 'handle']);

        $this->get('/nope')->assertNotFound();
        self::assertSame(0, $engine->calls);
    }

    // --- the detection facade ---

    public function test_inspect_returns_the_decision_stashes_it_reports_and_serves_nothing(): void
    {
        config([
            'funnypot.mainnet.key'       => 'sensor-key',
            'funnypot.reporting.enabled' => true,
            'queue.default'              => 'database',
        ]);
        Queue::fake();
        $decision = Decision::deceive(new FakeResponse(200, [], 'FAKEBODY', 'application/xml'), null, 'scanner-probe')->withReport($this->reportIntent());
        $this->app->instance(Engine::class, ScriptedEngine::returning($decision));

        $request = Request::create('/whatever');
        $returned = $this->app->make(Funnypot::class)->inspect($request);

        self::assertInstanceOf(Decision::class, $returned);
        self::assertSame(Decision::DECEIVE, $returned->action());
        self::assertSame($returned, $request->attributes->get(HoneypotMiddleware::ATTRIBUTE_DECISION));
        Queue::assertPushed(SendMainnetReport::class, 1);
    }
}
