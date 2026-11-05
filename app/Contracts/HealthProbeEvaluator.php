<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

interface HealthProbeEvaluator
{
    public function probeComponentCount(string $body, CarbonImmutable $now): ?int;
}
