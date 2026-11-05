<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

final readonly class MonitorCounts
{
    public function __construct(
        public int $certificates = 0,
        public int $domains = 0,
        public int $scheduledTasks = 0,
        public int $overduePings = 0,
    ) {}
}
