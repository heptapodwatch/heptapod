<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

interface CertificateFetcher
{
    /** Native verified TLS decides validity, and a verified chain comes from that same handshake; only a rejected
     * certificate is captured by a second, display-only handshake. Total transport budget is 10 seconds.
     * Private targets require a matching configured siteHost; absent or foreign scopes are public-only.
     *
     * @throws ExternalNetworkException
     */
    public function fetch(string $hostname, int $port = 443, ?string $siteHost = null): CertificateFetchResult;
}
