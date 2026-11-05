<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\Http\TargetResolver;

/** Delay the first selected fixture lookup, without changing any native resolver answer. */
final class DelayedTargetResolver implements TargetResolver
{
    private bool $delayed = false;

    public function __construct(private readonly TargetResolver $resolver, private readonly string $host, private readonly int $microseconds) {}

    public function resolve(string $host, ?float $deadline = null): array
    {
        if ($host === $this->host && ! $this->delayed) {
            $this->delayed = true;
            usleep($this->microseconds);
        }

        return $this->resolver->resolve($host, $deadline);
    }
}
