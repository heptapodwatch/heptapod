<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface DnsResolver
{
    /** @throws ExternalNetworkException */
    public function resolve(string $hostname, string $recordType, ?string $nameserver = null): DnsLookupResult;
}
