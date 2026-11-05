<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PostgresDatabaseClock implements DatabaseClock
{
    public function now(): CarbonImmutable
    {
        /** @var string $timestamp */
        $timestamp = DB::scalar('SELECT clock_timestamp()::text');

        return CarbonImmutable::parse($timestamp)->utc();
    }
}
