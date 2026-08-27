<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Reporting;

use Funnypot\Mainnet\Transport\Transport;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wraps Laravel's Http facade so the SDK's ReportSender keeps Http::fake()/assertSent()/assertNothingSent()
 * working in this package (FP-0060) — funnypot-mainnet-client's own CurlTransport would make those
 * assertions silently pass regardless of what actually happened.
 *
 * Honours the Transport contract's fail-open rule: a transport failure/timeout returns status 0 with an
 * empty body and no headers, never an exception, so a mainnet outage never turns into a host-side error.
 */
final class LaravelHttpTransport implements Transport
{
    public function get(string $url, array $headers): array
    {
        try {
            return $this->toArray(Http::withHeaders($this->parseHeaders($headers))->get($url));
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => '', 'headers' => []];
        }
    }

    public function post(string $url, array $headers, string $body): array
    {
        try {
            $response = Http::withHeaders($this->parseHeaders($headers))
                ->withBody($body, 'application/x-www-form-urlencoded')
                ->post($url);

            return $this->toArray($response);
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => '', 'headers' => []];
        }
    }

    /** @param string[] $headers e.g. ['Key: sensor-key', 'Accept: application/json'] */
    private function parseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $h) {
            $pos = strpos($h, ':');
            if ($pos === false) {
                continue;
            }
            $out[trim(substr($h, 0, $pos))] = trim(substr($h, $pos + 1));
        }

        return $out;
    }

    /**
     * Laravel returns headers as array<string, array<int,string>> with mixed-case names; the SDK keys on
     * lower-cased single-value 'retry-after' / 'x-ratelimit-reset', so normalise to that shape.
     */
    private function toArray(\Illuminate\Http\Client\Response $response): array
    {
        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[strtolower((string) $name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return ['status' => $response->status(), 'body' => $response->body(), 'headers' => $headers];
    }
}
