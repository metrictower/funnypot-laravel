<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Unit;

use Funnypot\Laravel\InspectionResult;
use Funnypot\Laravel\LaravelResponseMapper;
use Funnypot\Policy\Decision;
use Funnypot\Policy\FakeResponse;
use PHPUnit\Framework\TestCase;

/**
 * The consumer-facing result (FP-0123): wraps a policy Decision so an integrator asks isSuspicious() and
 * gets a ready-to-return Response from toResponse(), never touching Decision/fakeHandle/ResponseMapper.
 */
final class InspectionResultTest extends TestCase
{
    private function result(?Decision $decision): InspectionResult
    {
        return new InspectionResult($decision, new LaravelResponseMapper());
    }

    public function test_a_deceive_decision_is_suspicious_and_renders_the_fake_verbatim(): void
    {
        $fake = new FakeResponse(200, ['X-Fake' => 'yes'], 'FAKEBODY', 'application/xml');
        $result = $this->result(Decision::deceive($fake, null, 'scanner-probe'));

        self::assertTrue($result->isSuspicious());
        self::assertFalse($result->isClean());

        $response = $result->toResponse();
        self::assertNotNull($response);
        self::assertSame('FAKEBODY', $response->getContent());
        self::assertStringContainsString('application/xml', (string) $response->headers->get('content-type'));
    }

    public function test_a_block_decision_is_suspicious_and_renders_an_honest_refusal(): void
    {
        $result = $this->result(Decision::block(403, 'blocklist'));

        self::assertTrue($result->isSuspicious());
        $response = $result->toResponse();
        self::assertNotNull($response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    public function test_allow_log_or_null_is_clean_and_renders_nothing(): void
    {
        foreach ([Decision::allow(), Decision::log('shadow'), null] as $decision) {
            $result = $this->result($decision);
            self::assertTrue($result->isClean());
            self::assertFalse($result->isSuspicious());
            self::assertNull($result->toResponse(), 'a clean verdict has nothing to serve');
        }
    }

    public function test_it_exposes_action_reason_and_the_raw_decision(): void
    {
        $decision = Decision::block(403, 'blocklist');
        $result = $this->result($decision);

        self::assertSame(Decision::BLOCK, $result->action());
        self::assertSame('blocklist', $result->reason());
        self::assertSame($decision, $result->decision());

        // a null verdict degrades to a clean, non-throwing shape
        $clean = $this->result(null);
        self::assertSame(Decision::ALLOW, $clean->action());
        self::assertNull($clean->decision());
    }
}
