<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use InvalidArgumentException;

final readonly class DomainRegistrationResult
{
    /**
     * The addon parses the untouched response. Server is the RDAP URL or WHOIS host.
     *
     * @param  array<string, mixed>|string  $raw
     */
    public function __construct(public DomainRegistrationSource $source, public array|string $raw, public string $server)
    {
        if (($source === DomainRegistrationSource::Rdap) !== is_array($raw)) {
            throw new InvalidArgumentException('RDAP requires a raw JSON object; WHOIS requires raw text.');
        }
    }
}
