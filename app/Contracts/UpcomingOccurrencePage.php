<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

final readonly class UpcomingOccurrencePage
{
    /** @param list<UpcomingOccurrence> $occurrences ordered by (at, key), at most the requested limit */
    public function __construct(public array $occurrences, public bool $hasMore) {}
}
