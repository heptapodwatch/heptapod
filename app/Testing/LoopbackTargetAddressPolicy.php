<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\Http\TargetAddressPolicy;

/** Lets a test reach its own 127.0.0.1 fixture server; every other host and address keeps the wrapped policy. */
final readonly class LoopbackTargetAddressPolicy implements TargetAddressPolicy
{
    public function __construct(private TargetAddressPolicy $policy) {}

    public function assertHostAllowed(string $host): void
    {
        $this->policy->assertHostAllowed($host);
    }

    public function assertAddressAllowed(string $ip, bool $permitsPrivate): void
    {
        if ($ip !== '127.0.0.1') {
            $this->policy->assertAddressAllowed($ip, $permitsPrivate);
        }
    }
}
