<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

final readonly class UpcomingOccurrence
{
    public function __construct(
        public int $checkId,
        public string $key,
        public CarbonImmutable $at,
        public string $type,
        public string $detail,
    ) {}
}
