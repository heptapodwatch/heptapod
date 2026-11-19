<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Optional check contract: the scores the Sites list shows in its own column, from the latest run's metrics. */
interface ListScoresProvider
{
    /**
     * @param  array<string, mixed>|null  $metrics  The latest run's stored metrics; null when it recorded none.
     * @return list<ListScore> Empty when the run has no scores (a check error).
     */
    public function listScores(?array $metrics): array;
}
