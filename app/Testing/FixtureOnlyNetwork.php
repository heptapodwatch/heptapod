<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\TargetAddressScope;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;

/** One set of allowed fixtures for HTTP, native resolution, and dig in tests. */
final readonly class FixtureOnlyNetwork implements PrivateTargetGuard
{
    /** @var list<string> */
    private const array Hosts = [
        'fixtures.test', 'second.fixtures.test', 'external.fixtures.test',
        'alert-quality-robots.fixtures.test', 'alert-quality-missing.fixtures.test',
        'expired.fixtures.test', 'wrong-host.fixtures.test', 'names.fixtures.test',
        'wildcard.fixtures.test', 'www.wildcard.fixtures.test', 'nested.www.wildcard.fixtures.test',
        'alt.fixtures.test', 'self-signed.fixtures.test',
        'dns-fixtures', 'ns.dns.fixture.test', 'ns.provider.test',
        // Names answered only by the offline CoreDNS zones in addons/dns/tests/Fixtures/zones.
        'dns.fixture.test', 'alias.dns.fixture.test', 'missing.dns.fixture.test', 'oversize.dns.fixture.test', 'servfail.dns.fixture.test',
        'example.test', 'www.example.test', 'provider.test',
        '127.0.0.1', '127.0.0.2', '::1',
    ];

    /** Default and published fixture HTTP(S) ports in compose.yaml. @var list<int> */
    private const array HttpPorts = [80, 443, 444, 8480, 8481];

    public function __construct(private PrivateTargetGuard $guard) {}

    public static function assertHostAllowed(string $host, string $seam): void
    {
        $host = strtolower(trim(rtrim($host, '.'), '[]'));
        $allowed = in_array($host, self::Hosts, true);
        Log::debug('Test fixture outbound '.($allowed ? 'allowed' : 'blocked'), ['host' => $host, 'seam' => $seam]);
        if (! $allowed) {
            throw new \LogicException('Blocked non-fixture host '.$host);
        }
    }

    public function clientConfig(?string $siteHost, bool $publicTargetsOnly = false, ?float $deadline = null, ?float $selectTimeoutSeconds = null, ?TargetAddressScope $scope = null): array
    {
        $config = $this->guard->clientConfig($siteHost, $publicTargetsOnly, $deadline, $selectTimeoutSeconds, $scope);
        $stack = $config['handler'];
        if (! $stack instanceof HandlerStack) {
            throw new \LogicException('Fixture-only network requires the production handler stack.');
        }
        // Before native DNS and inside redirect handling: a fixture redirect cannot escape the allowlist.
        $stack->before('private_target_guard', static fn (callable $next): \Closure => static function (RequestInterface $request, array $options) use ($next): PromiseInterface {
            self::assertHostAllowed($request->getUri()->getHost(), 'http');

            /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
            return $next($request, $options);
        }, 'fixture_only_network');

        return $config;
    }

    /** @return list<string> */
    public static function allowedHttpUrls(): array
    {
        $urls = [];
        foreach (self::Hosts as $host) {
            $authority = str_contains($host, ':') ? '['.$host.']' : $host;
            foreach (['http', 'https'] as $scheme) {
                $urls[] = $scheme.'://'.$authority.'/*';
                foreach (self::HttpPorts as $port) {
                    $urls[] = $scheme.'://'.$authority.':'.$port.'/*';
                }
            }
        }

        return $urls;
    }
}
