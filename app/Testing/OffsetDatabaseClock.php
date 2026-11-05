<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Carbon\CarbonImmutable;
use Heptapod\Checks\DatabaseClock;

final readonly class OffsetDatabaseClock implements DatabaseClock
{
    public function __construct(private int $offsetSeconds) {}

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->addSeconds($this->offsetSeconds);
    }

    public static function use(int $offsetSeconds): CarbonImmutable
    {
        $clock = new self($offsetSeconds);
        app()->instance(DatabaseClock::class, $clock);

        return $clock->now();
    }
}
