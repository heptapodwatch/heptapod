<?php

declare(strict_types=1);

namespace Heptapod\Events;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;

/** The stored run a finished attempt produced, carried on its run.finished signal. */
final readonly class RecordedRunSignal
{
    public function __construct(
        public int $checkRunId,
        public CarbonImmutable $endedAt,
        public CheckResult $result,
    ) {}
}
