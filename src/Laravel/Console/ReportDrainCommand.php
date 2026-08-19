<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Console;

use Funnypot\Laravel\Reporting\LocalReportQueue;
use Funnypot\Laravel\Reporting\ReportPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * `php artisan funnypot:report-drain` — the SF-5 scheduler-drained delivery for report rows parked by
 * the sync-driver guard. Carries decision N's drain-side budget (N6): a per-tick WALL-CLOCK budget +
 * early-abort after N consecutive transport fails + per-tick row limit + attempts cap on re-queued
 * rows. The net invariant: a mainnet outage bounds work + storage and never slows the protected app.
 */
final class ReportDrainCommand extends Command
{
    /** @var string */
    protected $signature = 'funnypot:report-drain';

    /** @var string */
    protected $description = 'Deliver mainnet report rows parked by the sync-driver guard (SF-5).';

    private const MAX_ATTEMPTS = 10;

    public function handle(LocalReportQueue $queue): int
    {
        $key = (string) config('funnypot.mainnet.key', '');
        $base = rtrim((string) config('funnypot.mainnet.base_url', ''), '/');
        if ($key === '' || $base === '') {
            $this->line('report-drain: inert (no key/base_url).');

            return 0;
        }

        $budget = (int) config('funnypot.breaker.drain_budget_secs', 10);
        $maxFails = (int) config('funnypot.breaker.drain_max_fails', 3);
        $limit = (int) config('funnypot.breaker.drain_limit', 200);

        $rows = $queue->all();
        $deadline = microtime(true) + max(1, $budget);
        $consecutiveFails = 0;
        $processed = 0;
        $delivered = 0;
        $survivors = [];

        foreach ($rows as $i => $row) {
            $overBudget = microtime(true) >= $deadline;
            if ($processed >= $limit || $consecutiveFails >= $maxFails || $overBudget) {
                // Keep every not-yet-processed row for the next tick.
                $survivors[] = $row;
                continue;
            }
            $processed++;

            $outcome = $this->deliver($base, $key, $row);
            if ($outcome === 'ok' || $outcome === 'drop') {
                $consecutiveFails = 0;
                if ($outcome === 'ok') {
                    $delivered++;
                }
                continue;
            }

            // transport / 5xx: re-queue with an attempts cap, count toward the early-abort.
            $consecutiveFails++;
            $row['attempts'] = (int) ($row['attempts'] ?? 0) + 1;
            if ($row['attempts'] < self::MAX_ATTEMPTS) {
                $survivors[] = $row;
            }
        }

        $queue->replace($survivors);
        $this->line(sprintf('report-drain: processed=%d delivered=%d remaining=%d', $processed, $delivered, count($survivors)));

        return 0;
    }

    /**
     * @param array<string,mixed> $row
     * @return string 'ok' | 'drop' | 'fail'
     */
    private function deliver(string $base, string $key, array $row): string
    {
        try {
            $response = Http::withHeaders(['Key' => $key, 'Accept' => 'application/json'])
                ->asForm()
                ->post($base . '/v1/report', [
                    'ip'         => (string) ($row['ip'] ?? ''),
                    'categories' => (string) ($row['categories'] ?? '21'),
                    'comment'    => ReportPayload::COMMENT,
                    'timestamp'  => Carbon::now()->toIso8601String(),
                    'sensor_id'  => (string) ($row['sensor_id'] ?? ''),
                ]);
        } catch (\Throwable $ignored) {
            return 'fail';
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status >= 400 && $status < 500) {
            return 'drop';
        }

        return 'fail';
    }
}
