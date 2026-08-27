<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Facades\Funnypot;
use Funnypot\Laravel\InspectionResult;
use Funnypot\Laravel\Tests\Support\ScriptedEngine;
use Funnypot\Laravel\Tests\TestCase;
use Funnypot\Policy\Decision;
use Funnypot\Policy\FakeResponse;
use Illuminate\Http\Request;

/**
 * The consumer-facing facade (FP-0123): a one-line detect-and-respond, and a result DTO — proven through
 * the real container wiring with a scripted engine.
 */
final class FunnypotFacadeTest extends TestCase
{
    public function test_handle_request_returns_funnypots_fake_for_a_probe(): void
    {
        $this->app->instance(Engine::class, ScriptedEngine::returning(
            Decision::deceive(new FakeResponse(200, [], 'FAKEBODY', 'text/html'), null, 'scanner-probe')
        ));

        $response = Funnypot::handleRequest(Request::create('/wp-login.php'));

        self::assertNotNull($response);
        self::assertSame('FAKEBODY', $response->getContent());
    }

    public function test_handle_request_returns_null_for_a_clean_request(): void
    {
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::allow()));

        self::assertNull(Funnypot::handleRequest(Request::create('/dashboard')));
    }

    public function test_inspect_request_returns_a_result_dto(): void
    {
        $this->app->instance(Engine::class, ScriptedEngine::returning(Decision::block(403, 'blocklist')));

        $result = Funnypot::inspectRequest(Request::create('/wp-login.php'));

        self::assertInstanceOf(InspectionResult::class, $result);
        self::assertTrue($result->isSuspicious());
        self::assertSame(403, $result->toResponse()->getStatusCode());
    }
}
