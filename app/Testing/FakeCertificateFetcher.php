<?php

namespace Heptapod\Testing;

use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\ExternalNetworkException;

final readonly class FakeCertificateFetcher implements CertificateFetcher
{
    /** @var FakeResponses<CertificateFetchResult> */
    private FakeResponses $responses;

    public function __construct()
    {
        $this->responses = new FakeResponses(CertificateFetchResult::class);
    }

    /** Configure both the display capture and the native handshake's verdict explicitly. */
    public function respondWith(string $hostname, CertificateFetchResult|ExternalNetworkException $response, int $port = 443, ?string $siteHost = null): self
    {
        $this->responses->add('fetch', [$hostname, $port, $siteHost ?? $hostname], $response);

        return $this;
    }

    public function fetch(string $hostname, int $port = 443, ?string $siteHost = null): CertificateFetchResult
    {
        return $this->responses->get('fetch', [$hostname, $port, $siteHost ?? $hostname]);
    }
}
