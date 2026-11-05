<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

interface TimedTargetResolver extends TargetResolver
{
    /** Lookup duration excludes process startup, parent communication and address classification. */
    public function resolveTimed(string $host, ?float $deadline = null): TargetResolution;
}
