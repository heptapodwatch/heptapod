<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

final readonly class DnsLookupResult
{
    /**
     * Records preserve spatie/dns fields; raw lines supply DNS history evidence.
     * A null nameserver identifies the system resolver. Empty answers are valid.
     *
     * @param  list<array<string, mixed>>  $records
     * @param  list<string>  $rawLines
     */
    public function __construct(public array $records, public array $rawLines, public ?string $nameserver) {}
}
