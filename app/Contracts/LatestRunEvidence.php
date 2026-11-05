<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Accepted physical run data for addon detail projections, never a comparison baseline. */
final readonly class LatestRunEvidence
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public RunReference $run,
        public RunResult $result,
        public array $evidence,
    ) {}

    /** @return array<string, mixed> */
    public function observationEvidence(): array
    {
        return CheckRunEvidence::observation($this->evidence);
    }
}
