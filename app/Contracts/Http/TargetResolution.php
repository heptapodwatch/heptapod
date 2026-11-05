<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

final readonly class TargetResolution
{
    /** @param non-empty-list<string> $addresses */
    public function __construct(public array $addresses, public float $dnsSeconds) {}
}
