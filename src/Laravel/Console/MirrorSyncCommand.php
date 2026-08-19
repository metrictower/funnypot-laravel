<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Console;

use Funnypot\Laravel\Ports\LaravelStateStore;
use Funnypot\Laravel\Reputation\MirrorSync;
use Funnypot\Policy\Port\StateStoreInterface;
use Illuminate\Console\Command;

/**
 * `php artisan funnypot:mirror-sync` (O1) — pull the thin blacklist artifact into the local mirror on
 * cron (schedule per `funnypot.mirror.sync_minutes`, ~24×/day). Inert unless check.enabled + a key.
 * A failed pull leaves the prior mirror intact (exit 0 either way — a scheduler ->onFailure() alerts).
 */
final class MirrorSyncCommand extends Command
{
    /** @var string */
    protected $signature = 'funnypot:mirror-sync';

    /** @var string */
    protected $description = 'Pull the mainnet thin blacklist artifact into the local reputation mirror (O1).';

    public function handle(): int
    {
        $store = $this->laravel->make(StateStoreInterface::class);
        if (!$store instanceof LaravelStateStore) {
            $this->error('The configured state store does not support the local mirror.');

            return 1;
        }

        $sync = new MirrorSync(
            $store,
            $this->laravel->make('funnypot.cache'),
            (array) config('funnypot', [])
        );
        $result = $sync->sync();

        $this->line('mirror-sync: ' . json_encode($result, JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
