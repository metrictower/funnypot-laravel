<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Funnypot\Policy\FakeResponse;
use Illuminate\Http\Response;

/**
 * Turn a policy outcome into an Illuminate response (design §4.3).
 *
 *  - deceive: a FakeResponse from core's synthesize() → status + Content-Type + headers copied VERBATIM
 *    (never re-derived — invariant 5: Content-Type matches the request, status is app/engine-chosen,
 *    never model-chosen), preserving core's CRLF/NUL header-injection guard.
 *  - block: a plain empty body at the app-chosen status (an honest refusal).
 */
final class LaravelResponseMapper
{
    public function fake(FakeResponse $fake): Response
    {
        $headers = [];
        foreach ($fake->headers() as $name => $value) {
            // Defence-in-depth, mirroring core's Http\ResponseEmitter guard.
            if (preg_match('/[\r\n\x00]/', (string) $name) === 1
                || preg_match('/[\r\n\x00]/', (string) $value) === 1) {
                continue;
            }
            $headers[(string) $name] = $value;
        }

        // The Content-Type must survive verbatim even if the header set omitted it.
        if (!$this->hasContentType($headers) && $fake->contentType() !== '') {
            $headers['Content-Type'] = $fake->contentType();
        }

        return new Response($fake->body(), $fake->status(), $headers);
    }

    public function block(int $status = 403): Response
    {
        return new Response('', $status);
    }

    /** @param array<string,mixed> $headers */
    private function hasContentType(array $headers): bool
    {
        foreach ($headers as $name => $_) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                return true;
            }
        }

        return false;
    }
}
