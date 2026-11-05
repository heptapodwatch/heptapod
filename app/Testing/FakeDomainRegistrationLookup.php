<?php

namespace Heptapod\Testing;

use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\Http\FetchException;

final readonly class FakeDomainRegistrationLookup implements DomainRegistrationLookup
{
    /** @var FakeResponses<DomainRegistrationResult> */
    private FakeResponses $responses;

    public function __construct()
    {
        $this->responses = new FakeResponses(DomainRegistrationResult::class);
    }

    public function respondWith(string $domain, DomainRegistrationResult|ExternalNetworkException|FetchException $response): self
    {
        $this->responses->add('fetch', [$domain], $response);

        return $this;
    }

    public function fetch(string $domain, CheckContext $context): DomainRegistrationResult
    {
        return $this->responses->get('fetch', [$domain]);
    }
}
