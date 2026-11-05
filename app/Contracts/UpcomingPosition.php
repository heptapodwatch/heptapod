<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

/** Upcoming page position: sources compare `key` byte by byte (COLLATE "C") after equal instants. */
final readonly class UpcomingPosition
{
    public function __construct(public CarbonImmutable $at, public string $key) {}

    public function before(UpcomingOccurrence $occurrence): bool
    {
        return [$this->at->utc()->format('Y-m-d H:i:s.u'), $this->key] < [$occurrence->at->utc()->format('Y-m-d H:i:s.u'), $occurrence->key];
    }
}
