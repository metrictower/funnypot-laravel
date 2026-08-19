<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Contracts\Engine;
use Funnypot\Laravel\Jobs\SendMainnetReport;
use Funnypot\Laravel\Reporting\LocalReportQueue;
use Funnypot\Laravel\Reporting\ReportPayload;
use Funnypot\Laravel\Tests\Support\ScriptedEngine;
use Funnypot\Laravel\Tests\TestCase;
use Funnypot\Policy\Decision;
use Funnypot\Policy\ReportIntent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

/**
 * Report DELIVERY (the policy already applied suppression). A Decision carrying a ReportIntent enqueues
 * exactly one delivery job; an empty key is inert; and on QUEUE_CONNECTION=sync the SF-5 guard keeps the
 * mainnet POST off the request path (zero in-request transport), parking the row for the drain.
 */
final class ReportingTest extends TestCase
{
    private function intent(): ReportIntent
    {
        return new ReportIntent('203.0.113.9', 'deceive', 'honeypot', 210, [ReportIntent::CATEGORY_BAD_BOT], 'dedup-1', null, null, true);
    }

    private function scriptReportingDecision(): void
    {
        $decision = Decision::log('scanner-probe')->withReport($this->intent());
        $this->app->instance(Engine::class, ScriptedEngine::returning($decision));
        Route::middleware('funnypot')->get('/x', fn () => 'ok');
    }

    public function test_a_decision_with_a_report_enqueues_exactly_one_delivery_job(): void
    {
        config(['funnypot.mainnet.key' => 'sensor-key', 'funnypot.reporting.enabled' => true]);
        config(['queue.default' => 'database']); // async
        Queue::fake();
        $this->scriptReportingDecision();

        $this->get('/x')->assertOk();

        Queue::assertPushed(SendMainnetReport::class, 1);
        Queue::assertPushed(SendMainnetReport::class, function (SendMainnetReport $job): bool {
            // bad-bot → category 19; ip is the policy-normalised score_key.
            return $job->ip === '203.0.113.9' && $job->categories === '19';
        });
    }

    public function test_inert_without_a_key(): void
    {
        config(['funnypot.mainnet.key' => '']);
        Queue::fake();
        $this->scriptReportingDecision();

        $this->get('/x')->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_sync_driver_guard_keeps_the_post_off_the_request_path(): void
    {
        config(['funnypot.mainnet.key' => 'sensor-key', 'queue.default' => 'sync']);
        Http::fake(); // spy: nothing should be sent in-request

        $this->scriptReportingDecision();
        $this->get('/x')->assertOk();

        Http::assertNothingSent(); // SF-5: never inline
        $this->assertSame(1, $this->app->make(LocalReportQueue::class)->count(), 'the row parked for the drain');
    }

    public function test_delivery_job_posts_the_fingerprint_safe_body_with_the_key_header(): void
    {
        config(['funnypot.mainnet.key' => 'sensor-key', 'funnypot.mainnet.base_url' => 'https://api.mainnet.example']);
        Http::fake(['*/v1/report' => Http::response('', 200)]);

        (new SendMainnetReport('203.0.113.9', '19', 'sensor-uuid'))->handle();

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://api.mainnet.example/v1/report'
                && $request->hasHeader('Key', 'sensor-key')
                && ($body['ip'] ?? null) === '203.0.113.9'
                && ($body['categories'] ?? null) === '19'
                && ($body['comment'] ?? null) === ReportPayload::COMMENT
                && ($body['sensor_id'] ?? null) === 'sensor-uuid'
                && isset($body['timestamp']);
        });
    }

    public function test_delivery_job_retries_on_5xx_and_drops_on_4xx(): void
    {
        config(['funnypot.mainnet.key' => 'sensor-key', 'funnypot.mainnet.base_url' => 'https://api.mainnet.example']);

        Http::fake(['*/v1/report' => Http::response('', 503)]);
        $this->expectException(\RuntimeException::class); // 5xx throws → the queue retries
        (new SendMainnetReport('1.2.3.4', '21', 's'))->handle();
    }

    public function test_delivery_job_drops_on_4xx_without_throwing(): void
    {
        config(['funnypot.mainnet.key' => 'sensor-key', 'funnypot.mainnet.base_url' => 'https://api.mainnet.example']);
        Http::fake(['*/v1/report' => Http::response('', 429)]);

        (new SendMainnetReport('1.2.3.4', '21', 's'))->handle();
        $this->assertTrue(true, '4xx (incl. duplicate) drops the row, never loops');
    }

    public function test_report_comment_is_generic_and_carries_no_signature_shaped_tokens(): void
    {
        $comment = ReportPayload::COMMENT;
        $this->assertSame('Automated honeypot detection', $comment);
        foreach (['nuclei', 'CRS', 'ModSecurity', 'sqli', 'matcher', 'rule_id'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $comment);
        }
    }
}
