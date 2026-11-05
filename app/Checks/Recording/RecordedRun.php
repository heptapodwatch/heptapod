<?php

declare(strict_types=1);

namespace Heptapod\Checks\Recording;

final readonly class RecordedRun
{
    public function __construct(public bool $accepted, public ?int $runId) {}
}
