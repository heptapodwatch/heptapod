<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface MonitorCountProvider
{
    /** @param list<int> $visibleSiteIds */
    public function counts(array $visibleSiteIds, CarbonImmutable $now, CarbonImmutable $until): MonitorCounts;
}
