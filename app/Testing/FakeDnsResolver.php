<?php

namespace Heptapod\Testing;

use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\ExternalNetworkException;

final readonly class FakeDnsResolver implements DnsResolver
{
    /** @var FakeResponses<DnsLookupResult> */
    private FakeResponses $responses;

    public function __construct()
    {
        $this->responses = new FakeResponses(DnsLookupResult::class);
    }

    public function respondWith(string $hostname, string $recordType, DnsLookupResult|ExternalNetworkException $response, ?string $nameserver = null): self
    {
        $this->responses->add('resolve', [$hostname, $recordType, $nameserver], $response);

        return $this;
    }

    public function resolve(string $hostname, string $recordType, ?string $nameserver = null): DnsLookupResult
    {
        return $this->responses->get('resolve', [$hostname, $recordType, $nameserver]);
    }
}
