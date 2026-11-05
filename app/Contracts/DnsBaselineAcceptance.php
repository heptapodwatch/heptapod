<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Accepts the reviewed DNS observation only if it is still the latest, returning its physical run ID. */
interface DnsBaselineAcceptance
{
    public function accept(int $checkId, int $actorId, string $actorName, int $expectedRunId): int;
}
