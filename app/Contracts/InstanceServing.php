<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Carbon\CarbonImmutable;

/**
 * One reading of InstanceAvailability. Not serving: maintenance mode or a gap without /up. Serving without $since: no
 * heartbeat (no healthcheck, or none for so long that it expired), where nothing tells the instance's downtime apart.
 */
final readonly class InstanceServing
{
    private function __construct(public bool $serving, public ?CarbonImmutable $since) {}

    public static function down(): self
    {
        return new self(false, null);
    }

    /** @param ?CarbonImmutable $since the first /up after maintenance or a gap; null without a heartbeat */
    public static function since(?CarbonImmutable $since): self
    {
        return new self(true, $since);
    }
}
