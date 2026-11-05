<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Mail;

use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\SuppressionComponent;

/** Retained event identity and payload; a null run means its exact observation is no longer available. */
final readonly class MailEventFacts
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $type,
        public array $payload,
        public int $checkId,
        public ?SuppressionComponent $componentType,
        public ?int $componentId,
        public ?RunReference $run,
    ) {}
}
