<?php

namespace Heptapod\Http\Outbound;

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\TargetAddressPolicy;
use Heptapod\Contracts\Http\TargetAddressScope;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\Http\TargetResolver;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

final readonly class NativePrivateTargetGuard implements PrivateTargetGuard
{
    public function __construct(private TargetResolver $resolver, private TargetAddressPolicy $policy) {}

    public function clientConfig(?string $siteHost, bool $publicTargetsOnly = false, ?float $deadline = null, ?float $selectTimeoutSeconds = null, ?TargetAddressScope $scope = null): array
    {
        if ($selectTimeoutSeconds !== null && (! is_finite($selectTimeoutSeconds) || $selectTimeoutSeconds < 0)) {
            throw new \InvalidArgumentException('HTTP select timeout must be finite and nonnegative.');
        }
        $siteHost = $siteHost === null ? null : TargetHost::normalize($siteHost);
        $scope ??= new TargetAddressScope;
        $stack = HandlerStack::create(new CurlMultiHandler($selectTimeoutSeconds === null ? [] : ['select_timeout' => $selectTimeoutSeconds]));
        $stack->push(fn (callable $next): \Closure => function (RequestInterface $request, array $options) use ($next, $siteHost, $publicTargetsOnly, $deadline, $scope): PromiseInterface {
            try {
                /** @phpstan-var object{count: int}&\stdClass $hops */
                $hops = $options['heptapod_policy_hops'] ?? (object) ['count' => 0];
                $redirected = $hops->count++ > 0;
                $host = TargetHost::normalize($request->getUri()->getHost());
                $request = $request->withUri($request->getUri()->withHost(str_contains($host, ':') ? '['.$host.']' : $host));
                $requestDeadline = $options['heptapod_deadline'] ?? null;
                $target = $this->pin($request->getUri(), $siteHost, $publicTargetsOnly,
                    $deadline !== null && is_float($requestDeadline) ? min($requestDeadline, $deadline) : $deadline, $scope);
            } catch (FetchException $exception) {
                if ($exception->failure->isPolicyRefusal()) {
                    $failure = $exception->failure;
                    $exception = new FetchException(new FetchFailure($failure->kind,
                        targetAddress: $failure->targetAddress, targetClass: $failure->targetClass, redirected: $redirected));
                }

                return Create::rejectionFor($exception);
            }
            // Replace rather than merge: a redirect must never retain the previous host's pins.
            $options['curl'] = [CURLOPT_RESOLVE => $target['pins']];
            $options['heptapod_dns_seconds'] = $target['dnsSeconds'];
            $options['proxy'] = '';

            /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
            return $next($request, $options);
        }, 'private_target_guard');

        return ['handler' => $stack, 'proxy' => '', 'allow_redirects' => [
            'max' => 5, 'protocols' => ['http', 'https'], 'track_redirects' => true,
        ]];
    }

    /** @return array{pins: list<string>, dnsSeconds: float} */
    private function pin(UriInterface $uri, ?string $siteHost, bool $publicTargetsOnly, ?float $deadline, TargetAddressScope $scope): array
    {
        if (! in_array($uri->getScheme(), ['http', 'https'], true)) {
            throw new FetchException(new FetchFailure(FetchFailureKind::PrivateTarget, targetClass: 'unsupported_scheme'));
        }
        if ($uri->getUserInfo() !== '') {
            throw new FetchException(new FetchFailure(FetchFailureKind::PrivateTarget, targetClass: 'credentials'));
        }
        $host = TargetHost::normalize($uri->getHost());
        $this->policy->assertHostAllowed($host);
        $resolved = $scope->resolve($host, $this->resolver, $deadline);
        $ips = $resolved['addresses'];
        $initialAddresses = $host === $siteHost ? $scope->initialAddresses($host, $ips) : [];
        $initialPacked = array_map(inet_pton(...), $initialAddresses);
        foreach ($ips as $ip) {
            $permitsPrivate = ! $publicTargetsOnly && $host === $siteHost && in_array(inet_pton($ip), $initialPacked, true);
            $this->policy->assertAddressAllowed($ip, $permitsPrivate);
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['pins' => [], 'dnsSeconds' => $resolved['dnsSeconds']];
        }
        $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
        $formatted = array_map(static fn (string $ip): string => str_contains($ip, ':') ? '['.$ip.']' : $ip, $ips);

        return ['pins' => [$host.':'.$port.':'.implode(',', $formatted)], 'dnsSeconds' => $resolved['dnsSeconds']];
    }
}
