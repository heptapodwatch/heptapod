<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * The Owner's switch for the experimental servers, stored by the servers addon. Without the addon nothing binds it and
 * servers are off. heptapod.servers.enabled_by_server is a hard off above it (ServersAccess::enabled).
 */
interface ServersSwitch
{
    public function isOn(): bool;

    public function turn(bool $on): void;
}
