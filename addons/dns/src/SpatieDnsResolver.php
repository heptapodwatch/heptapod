<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Illuminate\Support\Facades\Config;
use Spatie\Dns\Dns;
use Spatie\Dns\Records\Record;
use Spatie\Dns\Support\Factory;

final class SpatieDnsResolver implements DnsResolver
{
    public function resolve(string $hostname, string $recordType, ?string $nameserver = null): DnsLookupResult
    {
        if (! in_array($recordType, [...DnsCheck::Types, 'SOA'], true)) {
            throw new \InvalidArgumentException('Unsupported DNS record type.');
        }
        $server = $nameserver ?? Config::get('dns.resolver');
        if ($server !== null && (! is_string($server) || preg_match('/^[a-zA-Z0-9.:_-]+$/D', $server) !== 1)) {
            throw new \InvalidArgumentException('Invalid DNS nameserver.');
        }
        $handler = new CheckedDig(new Factory, requireAuthoritative: $nameserver !== null);
        $query = Dns::query()->useHandlers([$handler])->setTimeout(3)->setRetries(1);
        if ($server !== null) {
            $query->useNameserver($server);
        }
        /** @var array<Record> $answer */
        $answer = $query->getRecords($hostname, $recordType);
        /** @var list<array<string, int|string>> $records */
        $records = array_values(array_map(static fn (Record $record): array => $record->toArray(), $answer));

        return new DnsLookupResult($records, $handler->rawLines, $nameserver);
    }
}
