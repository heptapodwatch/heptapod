<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

enum DomainRegistrationSource: string
{
    case Rdap = 'rdap';
    case Whois = 'whois';
}
