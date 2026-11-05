<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** A deployment capability, not an observation of the monitored site. */
final readonly class CheckAvailability
{
    public function __construct(public ?string $reason = null) {}
}
