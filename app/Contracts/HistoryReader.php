<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface HistoryReader
{
    /**
     * Accepted observations only, ascending ended_at then id, [from, until).
     * Implementations reject limits outside 1..10000; empty means no observation, never success.
     * Up to seven additional windows each have their own limit. Reaching that limit throws:
     * incomplete history must not silently cut the current window or bias its baseline.
     * Overlapping windows return each physical observation once in global ended_at/id order.
     * Performance requests max(60 minutes, twice the uptime interval), capped at 23 hours, plus the same
     * UTC hour on seven prior days in one read, then compares medians and the baseline p90.
     * DNS requests its previous baseline with no additional windows.
     *
     * @param  list<array{from: CarbonImmutable, until: CarbonImmutable}>  $additionalWindows
     * @return list<PreviousResult>
     */
    public function read(int $siteId, CheckKey $type, CarbonImmutable $from, CarbonImmutable $until, int $limit, array $additionalWindows = []): array;
}
