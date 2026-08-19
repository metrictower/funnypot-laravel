<?php

declare(strict_types=1);

namespace Funnypot\Laravel;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * A stable install UUID (D3) generated + persisted on first use (cache key `funnypot:sensor_id`), sent
 * as `sensor_id` on every report. A convenience label only — the mainnet server computes sensor
 * distinctness on the server-observed source IP, not on this client-supplied id. Never a hardware id.
 */
final class SensorId
{
    private const KEY = 'funnypot:sensor_id';

    public function __construct(private Repository $cache)
    {
    }

    public function value(): string
    {
        $id = $this->cache->get(self::KEY);
        if (is_string($id) && $id !== '') {
            return $id;
        }
        $id = (string) Str::uuid();
        $this->cache->forever(self::KEY, $id);

        return $id;
    }
}
