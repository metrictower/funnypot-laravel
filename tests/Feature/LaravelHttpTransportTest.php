<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Reporting\LaravelHttpTransport;
use Funnypot\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * FP-0060 Step 3, verified FIRST (spec.md §4/§10 Q1): once report delivery routes through the SDK's
 * Transport, funnypot-mainnet-client's own CurlTransport would make Http::fake()/assertSent() silent
 * no-ops. LaravelHttpTransport wraps the Http facade so those assertions keep working — and this pins
 * the named risk: that a pre-encoded application/x-www-form-urlencoded body sent via withBody() is what
 * $request->data() parses, and that ReportSender's lower-cased retry-after / x-ratelimit-reset lookups
 * see the response headers.
 */
final class LaravelHttpTransportTest extends TestCase
{
    public function test_post_round_trips_a_preencoded_form_body_and_headers(): void
    {
        Http::fake([
            '*/v1/report' => Http::response('{"ok":true}', 200, ['X-RateLimit-Reset' => '999', 'Retry-After' => '120']),
        ]);

        $out = (new LaravelHttpTransport())->post(
            'https://api.mainnet.example/v1/report',
            ['Key: sensor-key', 'Accept: application/json'],
            http_build_query(['ip' => '203.0.113.9', 'categories' => '21'])
        );

        $this->assertSame(200, $out['status']);
        $this->assertSame('{"ok":true}', $out['body']);
        // Normalised to lower-cased single-value headers — the shape ReportSender::retryAfter()/
        // rateLimitReset() key on.
        $this->assertSame('999', $out['headers']['x-ratelimit-reset'] ?? null);
        $this->assertSame('120', $out['headers']['retry-after'] ?? null);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Key', 'sensor-key')
                && $request->hasHeader('Accept', 'application/json')
                && ($request->data()['ip'] ?? null) === '203.0.113.9'
                && ($request->data()['categories'] ?? null) === '21';
        });
    }

    public function test_a_transport_failure_reports_status_zero_not_an_exception(): void
    {
        // The SDK contract: status 0 on transport failure, never a thrown exception (fail-open).
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('boom');
        });

        $out = (new LaravelHttpTransport())->post('https://api.mainnet.example/v1/report', ['Key: k'], 'ip=203.0.113.9');

        $this->assertSame(0, $out['status']);
        $this->assertSame('', $out['body']);
        $this->assertSame([], $out['headers']);
    }
}
