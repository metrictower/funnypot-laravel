<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Jobs;

use Funnypot\Laravel\Reporting\ReportPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Deliver a policy Decision's ReportIntent to `${MAINNET_BASE_URL}/v1/report` (design §4.5). Queued so
 * mainnet latency never touches request latency. The policy already applied the 4-layer suppression;
 * this job only DELIVERS what the Decision decided to report.
 *
 * Key-gated (D2): inert whenever `mainnet.key` is unset. Status branching: 2xx ok; a 5xx / transport
 * fault throws so the queue retries with backoff; a 4xx (client error, incl. a duplicate) drops the row
 * rather than looping. No secret is serialized onto the queue — base_url/key are read from config at run
 * time. `ip` is the policy-normalised score_key; `sensor_id` is the persisted install UUID (D3).
 */
final class SendMainnetReport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $ip,
        public string $categories,
        public string $sensorId
    ) {
    }

    public function handle(): void
    {
        $key = (string) config('funnypot.mainnet.key', '');
        if ($key === '') {
            return; // inert without a key
        }
        $base = rtrim((string) config('funnypot.mainnet.base_url', ''), '/');
        if ($base === '') {
            return;
        }

        $response = Http::withHeaders(['Key' => $key, 'Accept' => 'application/json'])
            ->asForm()
            ->post($base . '/v1/report', [
                'ip'         => $this->ip,
                'categories' => $this->categories,
                'comment'    => ReportPayload::COMMENT,
                'timestamp'  => Carbon::now()->toIso8601String(),
                'sensor_id'  => $this->sensorId,
            ]);

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            return; // delivered
        }
        if ($status >= 400 && $status < 500) {
            return; // client error / duplicate → drop, never loop
        }

        // 5xx / transport fault → throw so the queue retries this row later (breaker-guarded in prod).
        throw new \RuntimeException('mainnet report delivery failed with status ' . $status);
    }
}
