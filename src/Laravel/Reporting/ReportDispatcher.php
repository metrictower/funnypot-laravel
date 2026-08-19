<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Reporting;

use Funnypot\Laravel\Jobs\SendMainnetReport;
use Funnypot\Laravel\SensorId;
use Funnypot\Policy\ReportIntent;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Deliver a Decision's ReportIntent, applying the SF-5 sync-driver guard. The policy already decided
 * WHETHER to report (suppression); this only routes DELIVERY:
 *
 *  - inert unless reporting is enabled AND a mainnet key is set (D2);
 *  - if the queue connection resolves to `sync`, the row is written to a local scheduler-drained queue
 *    (funnypot:report-drain) instead of running inline — never inline, never dispatchAfterResponse()
 *    (both would pin the FPM worker on a mainnet outage). A one-time warning names the sync driver;
 *  - otherwise the queued SendMainnetReport job is dispatched onto the configured queue.
 */
final class ReportDispatcher
{
    private const SYNC_WARNED = 'funnypot:sync-report-warned';

    public function __construct(
        private Repository $cache,
        private LocalReportQueue $localQueue,
        private SensorId $sensorId
    ) {
    }

    public function dispatch(ReportIntent $intent): void
    {
        if (!$this->enabled()) {
            return;
        }

        $fallback = (array) config('funnypot.reporting.categories', [21]);
        $payload = ReportPayload::fromIntent($intent, $fallback);
        $row = [
            'ip'         => $payload['ip'],
            'categories' => $payload['categories'],
            'sensor_id'  => $this->sensorId->value(),
        ];

        if ($this->connectionIsSync()) {
            $this->warnOnce();
            $this->localQueue->push($row);

            return;
        }

        SendMainnetReport::dispatch($row['ip'], $row['categories'], $row['sensor_id'])
            ->onQueue(config('funnypot.reporting.queue'));
    }

    private function enabled(): bool
    {
        return (bool) config('funnypot.reporting.enabled', true)
            && (string) config('funnypot.mainnet.key', '') !== '';
    }

    private function connectionIsSync(): bool
    {
        $connection = config('queue.default');
        $driver = config('queue.connections.' . $connection . '.driver');

        return $driver === 'sync' || $connection === 'sync';
    }

    private function warnOnce(): void
    {
        if ($this->cache->add(self::SYNC_WARNED, 1, 86400) === true) {
            Log::channel('funnypot')->warning(
                'QUEUE_CONNECTION=sync: mainnet report delivery falls back to the local drain '
                . '(funnypot:report-drain); it will NOT run inline on the request path.'
            );
        }
    }
}
