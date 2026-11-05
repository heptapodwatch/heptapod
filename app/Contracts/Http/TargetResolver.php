<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

interface TargetResolver
{
    /** @return non-empty-list<string> */
    public function resolve(string $host, ?float $deadline = null): array;
}
