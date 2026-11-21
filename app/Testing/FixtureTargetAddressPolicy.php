<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\Http\TargetAddressPolicy;

/** Lets a test reach its own fixture servers' addresses; every other host and address keeps the wrapped policy. */
final readonly class FixtureTargetAddressPolicy implements TargetAddressPolicy
{
    /** @param list<string> $fixtureAddresses */
    public function __construct(private TargetAddressPolicy $policy, private array $fixtureAddresses) {}

    public function assertHostAllowed(string $host): void
    {
        $this->policy->assertHostAllowed($host);
    }

    public function assertAddressAllowed(string $ip, bool $permitsPrivate): void
    {
        if (! in_array($ip, $this->fixtureAddresses, true)) {
            $this->policy->assertAddressAllowed($ip, $permitsPrivate);
        }
    }

    public function assertPrivateAddress(string $ip): void
    {
        $this->policy->assertPrivateAddress($ip);
    }
}
