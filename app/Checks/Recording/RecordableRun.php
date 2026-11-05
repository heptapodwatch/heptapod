<?php

declare(strict_types=1);

namespace Heptapod\Checks\Recording;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\NoResultReason;

final readonly class RecordableRun
{
    public function __construct(
        public int $checkId,
        public string $claimToken,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public CheckOutcome|NoResultReason|null $outcome,
        public ?int $acceptanceRevision = null,
    ) {}
}
