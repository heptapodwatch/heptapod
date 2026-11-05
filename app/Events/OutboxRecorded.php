<?php

declare(strict_types=1);

namespace Heptapod\Events;

/** Latency trigger only; consumers must read the durable outbox. */
final readonly class OutboxRecorded
{
    public function __construct(public int $eventId) {}
}
