<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

final readonly class LighthouseReport
{
    /** @param array<string, mixed> $raw Untouched LHR payload; the Lighthouse addon maps audits/metrics. */
    public function __construct(public array $raw, public string $finalUrl) {}
}
