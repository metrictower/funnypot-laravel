<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Tests\Feature;

use Funnypot\Laravel\Support\CorePaths;
use Funnypot\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The artisan surface is registered and safe to run inert (no key / no data dir), and the moved
 * `funnypot:update` resolves core's bin via CorePaths — not E's own root.
 */
final class CommandsTest extends TestCase
{
    public function test_mirror_sync_is_inert_without_a_key_and_makes_no_request(): void
    {
        Http::fake();
        $this->artisan('funnypot:mirror-sync')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_report_drain_is_inert_without_a_key(): void
    {
        Http::fake();
        $this->artisan('funnypot:report-drain')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_update_command_resolves_cores_binary_not_es_root(): void
    {
        $binary = CorePaths::binary();
        $this->assertStringEndsWith('/funnypot-core/bin/funnypot', $binary);
        $this->assertFileExists($binary, 'the moved funnypot:update points at core bin/funnypot (CorePaths)');
    }
}
