<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

final readonly class CronTaskData
{
    /** @param array<string, mixed> $definition */
    public function __construct(
        public int $id,
        public int $checkId,
        public string $uuid,
        public array $definition,
        public string $latestResult,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $latestPingAt,
    ) {}
}
