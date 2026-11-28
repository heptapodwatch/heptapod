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
    /**
     * False in maintenance mode or after a gap without /up; true without a heartbeat (no healthcheck, or none for so long
     * that it expired), where nothing tells the instance's downtime apart.
     */
    public function serving(CarbonImmutable $now): bool;

    /** The start of the current serving period: the first /up after maintenance or a gap. Null without a heartbeat. */
    public function servingSince(): ?CarbonImmutable;
}
