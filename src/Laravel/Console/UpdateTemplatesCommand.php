<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Console;

use Funnypot\Laravel\Support\CorePaths;
use Illuminate\Console\Command;

/**
 * `php artisan funnypot:update` — recompile the template index by shelling out to core's own
 * `bin/funnypot compile` (moved from core, re-pointed via CorePaths). Runs in a subprocess so a bad /
 * huge corpus can't take the web app down mid-request. `bin/funnypot` + `resources/compiled/` live in
 * CORE, not this package.
 */
final class UpdateTemplatesCommand extends Command
{
    /** @var string */
    protected $signature = 'funnypot:update
        {templates : Path to a local nuclei-templates checkout (or its http/ subdir)}
        {--out= : Compiled index output path (defaults to core resources/compiled/nuclei-index.full.php)}';

    /** @var string */
    protected $description = 'Recompile the funnypot template index from a nuclei-templates checkout.';

    public function handle(): int
    {
        $binary = CorePaths::binary();
        if (!is_file($binary)) {
            $this->error("funnypot binary not found at {$binary}.");

            return 1;
        }

        $templatesDir = (string) $this->argument('templates');
        $out = $this->option('out');
        $out = is_string($out) && $out !== ''
            ? $out
            : CorePaths::root() . '/resources/compiled/nuclei-index.full.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary)
            . ' compile ' . escapeshellarg($templatesDir)
            . ' --out=' . escapeshellarg($out);

        $this->info("funnypot: {$command}");

        passthru($command, $exitCode);

        return (int) $exitCode;
    }
}
