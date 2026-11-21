<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\Http\FetchException;

/** A discovered nameserver resolves to a private, loopback, link-local or metadata address, so it is not queried. */
final class DnsNameserverRefusedException extends ExternalNetworkException
{
    public function __construct(FetchException $refusal)
    {
        parent::__construct('DNS nameserver address is not public.', previous: $refusal);
    }
}
