<?php

declare(strict_types=1);

namespace Heptapod\Contracts\Http;

interface PrivateTargetGuard
{
    /**
     * Guzzle CLIENT config includes a handler that validates and pins every dispatch,
     * including redirects. Keep this handler intact.
     * Same-host private targets must retain an address from their first resolution
     * in this execution. Third-party targets require public addresses.
     * Metadata names and link-local addresses are always refused.
     * Native flags permit multicast addresses, which cannot serve these TCP requests.
     *
     * @return array<string, mixed>
     *
     * @throws FetchException
     */
    public function clientConfig(?string $siteHost, bool $publicTargetsOnly = false, ?float $deadline = null, ?float $selectTimeoutSeconds = null, ?TargetAddressScope $scope = null): array;
}
