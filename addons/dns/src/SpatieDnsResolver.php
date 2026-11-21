<?php

declare(strict_types=1);

namespace Heptapod\Addons\Dns;

use Heptapod\Contracts\DnsLookupResult;
use Heptapod\Contracts\DnsResolver;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\TargetAddressPolicy;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\Http\TargetResolver;
use Illuminate\Support\Facades\Config;
use Spatie\Dns\Dns;
use Spatie\Dns\Records\Record;
use Spatie\Dns\Support\Factory;

final class SpatieDnsResolver implements DnsResolver
{
    /**
     * Discovered nameserver => the public address its queries are pinned to during one check run.
     *
     * @var array<string, string>
     */
    private array $pinned = [];

    public function __construct(private readonly TargetResolver $targets, private readonly TargetAddressPolicy $policy) {}

    public function resolve(string $hostname, string $recordType, ?string $nameserver = null): DnsLookupResult
    {
        if (! in_array($recordType, [...DnsCheck::Types, 'SOA'], true)) {
            throw new \InvalidArgumentException('Unsupported DNS record type.');
        }
        // A discovered nameserver comes from the monitored domain's own NS records; the configured resolver is the operator's.
        $server = $nameserver === null ? Config::get('dns.resolver') : $this->publicAddress($nameserver);
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

    /** Resolve once and query the checked address itself, so a rebinding answer cannot redirect dig afterwards. */
    private function publicAddress(string $nameserver): string
    {
        if (isset($this->pinned[$nameserver])) {
            return $this->pinned[$nameserver];
        }
        $host = TargetHost::normalize($nameserver);
        try {
            $this->policy->assertHostAllowed($host);
            $addresses = $this->targets->resolve($host, microtime(true) + 3);
            foreach ($addresses as $address) {
                $this->policy->assertAddressAllowed($address, false);
            }
        } catch (TargetResolutionException $exception) {
            throw new ExternalNetworkException('Target DNS lookup failed.', $exception->getCode(), previous: $exception);
        } catch (FetchException $exception) {
            if ($exception->failure->isPolicyRefusal()) {
                throw new DnsNameserverRefusedException($exception);
            }
            if ($exception->failure->kind === FetchFailureKind::Timeout) {
                throw new ExternalTimeoutException('DNS query timed out.', $exception->getCode(), previous: $exception);
            }
            throw $exception;
        }

        // dig takes one server; IPv4 is reachable from every container network, IPv6 not always.
        return $this->pinned[$nameserver] = array_find($addresses, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) ?? $addresses[0];
    }
}
