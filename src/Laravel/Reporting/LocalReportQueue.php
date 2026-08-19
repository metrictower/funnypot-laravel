<?php

declare(strict_types=1);

namespace Funnypot\Laravel\Reporting;

use Illuminate\Contracts\Cache\Repository;

/**
 * The SF-5 sync-driver fallback: a small durable queue of pending report rows in the state cache,
 * drained out-of-band by `funnypot:report-drain`. Used ONLY when the report queue connection resolves
 * to `sync` (running a ShouldQueue job inline would put the mainnet POST + its outage timeout back on
 * the request path). A hard size cap bounds storage under an outage (oldest dropped first — N6).
 */
final class LocalReportQueue
{
    private const KEY = 'funnypot:reportq';

    public function __construct(private Repository $cache, private int $maxRows = 1000)
    {
    }

    /** @param array<string,mixed> $row */
    public function push(array $row): void
    {
        $rows = $this->all();
        $rows[] = $row;
        if (count($rows) > $this->maxRows) {
            $rows = array_slice($rows, count($rows) - $this->maxRows); // drop oldest first
        }
        $this->cache->forever(self::KEY, $rows);
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->cache->get(self::KEY, []);

        return is_array($rows) ? array_values($rows) : [];
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function replace(array $rows): void
    {
        $this->cache->forever(self::KEY, array_values($rows));
    }

    public function count(): int
    {
        return count($this->all());
    }
}
