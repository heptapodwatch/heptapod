<?php

declare(strict_types=1);

namespace Heptapod\Addons\Domain;

use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\DomainRegistrationLookup;
use Heptapod\Contracts\DomainRegistrationResult;
use Heptapod\Contracts\DomainRegistrationSource;
use Heptapod\Contracts\ExternalNetworkException;
use Heptapod\Contracts\ExternalTimeoutException;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\RedirectPolicy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Klkvsk\Whoeasy\Client\Exception\ClientTimeoutException;
use Klkvsk\Whoeasy\QueryMode;
use Klkvsk\Whoeasy\QueryOptions;
use Klkvsk\Whoeasy\Registry\ServerRegistry;
use Klkvsk\Whoeasy\Whoeasy;
use Pdp\Domain;
use Spatie\Rdap\Exceptions\InvalidRdapResponse;

final readonly class RdapWhoisLookup implements DomainRegistrationLookup
{
    public function __construct(private OutboundHttp $http, private Whoeasy $whois, private ServerRegistry $registry) {}

    public function fetch(string $domain, CheckContext $context): DomainRegistrationResult
    {
        $topLevelDomain = Domain::fromIDNA2008($domain)->label(0);
        if ($topLevelDomain === null) {
            throw new \InvalidArgumentException('Domain lookup requires a top-level domain.');
        }
        if (! in_array($topLevelDomain, Config::array('domain.whois_only'), true)) {
            try {
                $server = $this->server($topLevelDomain, $context);
                if ($server !== null) {
                    $raw = $this->json(rtrim($server, '/').'/domain/'.$domain, $context);
                    $name = $raw['ldhName'] ?? null;
                    $danger = is_string($name) && strtolower(rtrim($name, '.')) === $domain
                        && DomainStatuses::dangerous(DomainStatuses::normalize($raw['status'] ?? []));
                    $events = $raw['events'] ?? [];
                    if (! is_array($events)) {
                        throw InvalidRdapResponse::make($domain);
                    }
                    $hasExpiry = array_any($events, static fn (mixed $event): bool => is_array($event)
                        && ($event['eventAction'] ?? null) === 'expiration' && is_string($event['eventDate'] ?? null) && $event['eventDate'] !== '');
                    if ($danger || $hasExpiry || in_array($topLevelDomain, Config::array('domain.registry_does_not_publish'), true)) {
                        return new DomainRegistrationResult(DomainRegistrationSource::Rdap, $raw, $server);
                    }
                }
            } catch (FetchException $exception) {
                if (! in_array($exception->failure->kind, [FetchFailureKind::HttpStatus, FetchFailureKind::Timeout, FetchFailureKind::Tls, FetchFailureKind::Dns, FetchFailureKind::Connect], true)) {
                    throw $exception;
                }
                // WHOIS is the alternate protocol for registry HTTP availability failures, not policy failures.
            } catch (InvalidRdapResponse) {
                // Unpublished or malformed RDAP coverage can still have a usable WHOIS registration.
            }
        }

        if ($this->registry->resolve($domain)->hasHttpWhois()) {
            throw new HttpWhoisUnsupported('registry only offers HTTP WHOIS (unsupported)');
        }
        $result = $this->whois->domain($domain, new QueryOptions(mode: QueryMode::WhoisOnly, whoisTimeout: 10, recursive: false, maxReferrals: 0));
        $hop = $result->whois?->hops[0] ?? null;
        if ($hop === null) {
            throw new ExternalNetworkException('WHOIS lookup returned no response.');
        }
        if ($hop->error !== null) {
            if ($hop->error instanceof FetchException) {
                throw $hop->error;
            }
            if ($hop->error instanceof ClientTimeoutException) {
                throw new ExternalTimeoutException('WHOIS lookup timed out.', previous: $hop->error);
            }
            throw new ExternalNetworkException('WHOIS lookup failed.', previous: $hop->error);
        }

        return new DomainRegistrationResult(DomainRegistrationSource::Whois, $hop->response, $hop->server);
    }

    /** @return array<string, mixed> */
    private function json(string $url, CheckContext $context): array
    {
        $host = parse_url($context->url, PHP_URL_HOST);
        if (! is_string($host)) {
            throw new \InvalidArgumentException('Domain check requires a URL hostname.');
        }
        try {
            // RFC 7480 4.2: ask for RDAP JSON explicitly; the shared request profile is HTML-first.
            $request = new FetchRequest($url, headers: ['Accept' => 'application/rdap+json'], maxWireBytes: 262144, maxDecodedBytes: 262144,
                redirects: RedirectPolicy::Untrusted, maxRedirects: 3, siteHost: $host, timeoutSeconds: 10);
        } catch (\InvalidArgumentException $exception) {
            throw new FetchException(new FetchFailure(FetchFailureKind::InvalidRequest, exceptionClass: $exception::class));
        }
        $response = $this->http->fetch($request, $context);
        if ($response->failure instanceof FetchFailure) {
            throw new FetchException($response->failure);
        }
        try {
            $raw = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw InvalidRdapResponse::make('registry');
        }
        if (! is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw InvalidRdapResponse::make('registry');
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    private function server(string $topLevelDomain, CheckContext $context): ?string
    {
        $override = Config::array('domain.rdap_overrides')[$topLevelDomain] ?? null;
        if ($override !== null) {
            if (! is_string($override)) {
                throw new \InvalidArgumentException('RDAP registry override must be a URL string.');
            }

            return $override;
        }
        /** @var ?string $store */
        $store = Config::get('rdap.tld_servers_cache.store_name');
        /** @var ?int $ttl */
        $ttl = Config::get('rdap.tld_servers_cache.duration_in_seconds');
        $services = $this->bootstrap(Cache::store($store)->remember('laravel-rdap-servers', $ttl,
            fn (): array => $this->bootstrap($this->json('https://data.iana.org/rdap/dns.json', $context)['services'] ?? null)));

        return array_find($services, static fn (array $service): bool => in_array($topLevelDomain, $service[0], true))[1][0] ?? null;
    }

    /** @return list<array{list<string>, list<string>}> */
    private function bootstrap(mixed $services): array
    {
        if (! is_array($services) || ! array_is_list($services) || $services === []) {
            throw InvalidRdapResponse::make('IANA bootstrap');
        }
        foreach ($services as $service) {
            if (! is_array($service) || ! array_is_list($service) || count($service) !== 2) {
                throw InvalidRdapResponse::make('IANA bootstrap');
            }
            foreach ($service as $strings) {
                if (! is_array($strings) || ! array_is_list($strings) || $strings === [] || ! array_all($strings, static fn (mixed $value): bool => is_string($value) && $value !== '')) {
                    throw InvalidRdapResponse::make('IANA bootstrap');
                }
            }
        }

        /** @var list<array{list<string>, list<string>}> $services */
        return $services;
    }
}
