<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface UpcomingEventSource
{
    /** @param list<int> $visibleCheckIds
     * @return list<UpcomingOccurrence>
     */
    public function read(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): array;

    /** @param list<int> $visibleCheckIds */
    public function count(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until): int;

    /** First `limit` occurrences in [from, until] strictly after `after`, ordered by (at, key).
     * @param  list<int>  $visibleCheckIds
     */
    public function page(array $visibleCheckIds, CarbonImmutable $from, CarbonImmutable $until, ?UpcomingPosition $after, int $limit): UpcomingOccurrencePage;
}
