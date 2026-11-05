<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\Http\TargetResolver;

/** TLS and WHOIS connect only to addresses returned by this resolver seam. */
final readonly class FixtureOnlyTargetResolver implements TargetResolver
{
    public function __construct(private TargetResolver $resolver) {}

    public function resolve(string $host, ?float $deadline = null): array
    {
        FixtureOnlyNetwork::assertHostAllowed($host, 'resolver');

        return $this->resolver->resolve($host, $deadline);
    }
}
