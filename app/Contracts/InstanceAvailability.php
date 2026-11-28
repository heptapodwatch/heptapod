<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

/**
 * Since when this instance could serve requests, as its web's /up (the container healthcheck, every 30 s) sees it.
 * The servers add-on reads it to tell an agent's silence from the instance's downtime ("No report").
 */
interface InstanceAvailability
{
    /** One reading of the heartbeat: both fields come from the same record. */
    public function current(CarbonImmutable $now): InstanceServing;
}
