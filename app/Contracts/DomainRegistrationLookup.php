<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Heptapod\Contracts\Http\FetchException;

interface DomainRegistrationLookup
{
    /** @throws ExternalNetworkException|FetchException */
    public function fetch(string $domain, CheckContext $context): DomainRegistrationResult;
}
