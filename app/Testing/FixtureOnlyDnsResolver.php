<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Illuminate\Support\Facades\Config;

/** dig bypasses TargetResolver, so guard both the question and the server here. */
final readonly class FixtureOnlyDnsResolver implements DnsResolver
{
    public function __construct(private DnsResolver $resolver) {}

    public function resolve(string $hostname, string $recordType, ?string $nameserver = null): DnsLookupResult
    {
        FixtureOnlyNetwork::assertHostAllowed($hostname, 'dig');
        $server = $nameserver ?? Config::get('dns.resolver');
        FixtureOnlyNetwork::assertHostAllowed(is_string($server) ? $server : '(OS resolver)', 'dig');

        return $this->resolver->resolve($hostname, $recordType, $nameserver);
    }
}
