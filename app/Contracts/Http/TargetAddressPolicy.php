<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

/** Shared destination classification for HTTP, certificate handshakes and WHOIS sockets. */
interface TargetAddressPolicy
{
    /** @throws FetchException */
    public function assertHostAllowed(string $host): void;

    /** Private configured targets may be allowed; metadata, link-local and tunnels never are.
     * @throws FetchException
     */
    public function assertAddressAllowed(string $ip, bool $permitsPrivate): void;
}
