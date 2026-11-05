<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;

interface DatabaseClock
{
    public function now(): CarbonImmutable;
}
