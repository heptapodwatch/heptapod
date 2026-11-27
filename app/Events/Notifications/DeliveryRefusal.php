<?php

declare(strict_types=1);

namespace Heptapod\Events\Notifications;

use Carbon\CarbonImmutable;

/** Why a delivery may not go out now: suppressed for good, or held until $retryAt (a snoozed server). */
final readonly class DeliveryRefusal
{
    public function __construct(public string $reason, public ?CarbonImmutable $retryAt = null) {}
}
