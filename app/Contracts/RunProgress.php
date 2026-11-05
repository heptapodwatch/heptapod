<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface RunProgress
{
    /** Reports bounded public counters, at most once per second. */
    public function report(int $done, int $total, string $phase): void;
}
