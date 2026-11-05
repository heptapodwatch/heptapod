<?php

declare(strict_types=1);

namespace Heptapod\Http\Outbound;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Heptapod\Contracts\Http\DeliveryHttp;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\PublicRequestProfile;
use Heptapod\Contracts\Http\TargetAddressScope;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\Http\TargetResolutionException;
use Psr\Http\Message\RequestInterface;

final readonly class GuardedDeliveryHttp implements DeliveryHttp
{
    public function __construct(private PrivateTargetGuard $guard) {}

    public function handler(string $destinationUrl, float $timeoutSeconds = 20.0, int $maxResponseBytes = 1048576): callable
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $maxResponseBytes < 1) {
            throw new \InvalidArgumentException('Delivery HTTP requires a positive finite timeout and response limit.');
        }
        $host = TargetHost::fromUrl($destinationUrl);
        $privateAllowed = in_array($host, config()->array('heptapod.outbound.delivery_private_hosts'), true);
        $deadline = microtime(true) + $timeoutSeconds;
        $config = $this->guard->clientConfig($privateAllowed ? $host : null, ! $privateAllowed, $deadline, null, new TargetAddressScope);
        /** @var HandlerStack $stack */
        $stack = $config['handler'];
        // Inside the guard: merge the cap after it replaces curl options, preserving the resolved-IP pins.
        $stack->push(static fn (callable $next): \Closure => static function (RequestInterface $request, array $options) use ($next, $maxResponseBytes, $deadline): PromiseInterface {
            $remaining = $deadline - microtime(true);
            if ($remaining < 0.001) {
                return Create::rejectionFor(new FetchException(new FetchFailure(FetchFailureKind::Timeout)));
            }
            $options['timeout'] = $remaining;
            $options['connect_timeout'] = min(5.0, $remaining);
            /** @var array<int, mixed> $curl */
            $curl = $options['curl'] ?? [];
            $options['curl'] = $curl + [
                CURLOPT_MAXFILESIZE_LARGE => $maxResponseBytes,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ];

            $request = $request->withHeader('User-Agent', PublicRequestProfile::headers()['User-Agent']);

            /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
            return $next($request, $options)->otherwise(static function (mixed $reason): PromiseInterface {
                if ($reason instanceof RequestException && ($reason->getHandlerContext()['errno'] ?? null) === CURLE_FILESIZE_EXCEEDED) {
                    return Create::rejectionFor(new FetchException(new FetchFailure(FetchFailureKind::TooLarge)));
                }

                return Create::rejectionFor($reason);
            });
        }, 'delivery_limits');

        // Resolution happens synchronously in the guard; convert only that known network failure at this seam.
        $stack->unshift(static fn (callable $next): \Closure => static function (RequestInterface $request, array $options) use ($next): PromiseInterface {
            try {
                /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
                return $next($request, $options);
            } catch (TargetResolutionException) {
                return Create::rejectionFor(new FetchException(new FetchFailure(FetchFailureKind::Dns)));
            }
        }, 'delivery_dns_failure');

        return $stack;
    }
}
