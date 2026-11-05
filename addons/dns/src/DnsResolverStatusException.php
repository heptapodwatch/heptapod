<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\ExternalNetworkException;

final class DnsResolverStatusException extends ExternalNetworkException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct('DNS resolver returned '.$status.'.', previous: new \UnexpectedValueException('DNS response status indicates a resolver error.'));
    }
}
