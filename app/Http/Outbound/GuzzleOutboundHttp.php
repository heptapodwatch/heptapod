<?php

namespace Heptapod\Http\Outbound;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\InvalidArgumentException as GuzzleInvalidArgumentException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriComparator;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\TransferStats;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\ConnectionFailureReason;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PacedHttpClient;
use Heptapod\Contracts\Http\PrivateTargetGuard;
use Heptapod\Contracts\Http\PublicRequestProfile;
use Heptapod\Contracts\Http\RedirectPolicy;
use Heptapod\Contracts\Http\TargetAddressScope;
use Heptapod\Contracts\Http\TargetHost;
use Heptapod\Contracts\Http\TargetResolutionException;
use Heptapod\Contracts\TlsFailureReason;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

final readonly class GuzzleOutboundHttp implements OutboundHttp
{
    /** @var \WeakMap<CheckContext, TargetAddressScope> */
    private \WeakMap $targetScopes;

    public function __construct(private PrivateTargetGuard $guard)
    {
        $this->targetScopes = new \WeakMap;
    }

    public function fetch(#[\SensitiveParameter] FetchRequest $request, #[\SensitiveParameter] CheckContext $context): FetchResult
    {
        $transfers = [];
        $effectiveUrl = null;
        /** @var array{Cert: string}|null $certificateLeaf */
        $certificateLeaf = null;
        try {
            $config = $this->config($request, $context, $transfers, $effectiveUrl, $certificateLeaf);
            try {
                $response = new Client($config)->request($request->method, $request->url, [
                    'headers' => $request->headers,
                    'body' => $request->body,
                ]);
            } catch (RequestException|GuzzleInvalidArgumentException $exception) {
                throw $this->invalidRequest($exception);
            }
        } catch (FetchException $exception) {
            if (! $request->refusalIsFinding) {
                $context->policyRefusals->record($exception->failure);
            }

            return new FetchResult(status: $exception->failure->status, failure: $exception->failure, transfers: $transfers);
        }

        return GuzzleFetchResult::response($request, $response, $transfers, $effectiveUrl, $certificateLeaf);
    }

    public function clientConfig(#[\SensitiveParameter] FetchRequest $request, #[\SensitiveParameter] CheckContext $context, ?float $selectTimeoutSeconds = null): array
    {
        if ($selectTimeoutSeconds !== null && (! is_finite($selectTimeoutSeconds) || $selectTimeoutSeconds < 0)) {
            throw new \InvalidArgumentException('HTTP select timeout must be finite and nonnegative.');
        }
        $transfers = [];
        $effectiveUrl = null;
        /** @var array{Cert: string}|null $certificateLeaf */
        $certificateLeaf = null;

        return $this->config($request, $context, $transfers, $effectiveUrl, $certificateLeaf, $selectTimeoutSeconds);
    }

    public function pacedClient(CheckContext $context, int $additionalDelayMilliseconds = 0): PacedHttpClient
    {
        return new GuzzlePacedHttpClient($this, $context, $additionalDelayMilliseconds);
    }

    /** @param list<array<string, int|float>> $transfers
     * @param  array{Cert: string}|null  $certificateLeaf
     * @return array{handler: HandlerStack, http_errors: false, headers: array<array<string>>}
     */
    private function config(#[\SensitiveParameter] FetchRequest $request, #[\SensitiveParameter] CheckContext $context, array &$transfers, ?string &$effectiveUrl, ?array &$certificateLeaf, ?float $selectTimeoutSeconds = null): array
    {
        $this->validateHeaders($request->headers);
        try {
            // PSR7 owns header token/value validation; never expose its diagnostic values.
            $message = new Request($request->method, $request->url, $request->headers);
            foreach (PublicRequestProfile::headers() as $name => $value) {
                if (! $message->hasHeader($name)) {
                    $message = $message->withHeader($name, $value);
                }
            }
            $siteHost = $request->redirects === RedirectPolicy::Untrusted ? $request->siteHost : TargetHost::fromUrl($request->url);
        } catch (\InvalidArgumentException $exception) {
            throw $this->invalidRequest($exception);
        }
        $deadline = $context->deadline->getTimestampMs() / 1000;
        $scope = $this->targetScopes[$context] ??= new TargetAddressScope;
        $config = $this->guard->clientConfig($siteHost, $request->publicTargetsOnly, $deadline, $selectTimeoutSeconds, $scope);
        /** @var HandlerStack $stack */
        $stack = $config['handler'];
        $ca = config('certificate_health.ca_file');
        if ($ca !== null && (! is_string($ca) || ! is_file($ca) || ! is_readable($ca))) {
            throw new \InvalidArgumentException('Invalid outbound HTTP CA file.');
        }

        // Runs outside Guzzle redirects: request-level overrides cannot weaken policy.
        $stack->unshift(fn (callable $next): \Closure => function (#[\SensitiveParameter] RequestInterface $message, #[\SensitiveParameter] array $options) use ($next, $request, $context, $deadline, $ca): PromiseInterface {
            /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
            $this->validateHeaders($message->getHeaders(), $message->getUri()->getAuthority());
            if (array_key_exists('delay', $options)) {
                throw new \InvalidArgumentException('Outbound HTTP does not support delayed dispatch.');
            }
            foreach (['on_trailers', 'on_headers', 'progress'] as $callback) {
                if (array_key_exists($callback, $options)) {
                    throw new \InvalidArgumentException('Unsupported outbound HTTP callback: '.$callback.'.');
                }
            }
            $options['heptapod_redirect_state'] = (object) ['stripped' => false];
            $options['heptapod_policy_hops'] = (object) ['count' => 0];
            $options['heptapod_origin'] = $this->origin($request->redirects === RedirectPolicy::Untrusted ? new Uri($context->url) : $message->getUri());
            $trackRedirects = is_array($options['allow_redirects'] ?? null) && ($options['allow_redirects']['track_redirects'] ?? false) === true;
            $options['allow_redirects'] = $request->redirects === RedirectPolicy::Off || $request->maxRedirects === 0 ? false : [
                'max' => $request->maxRedirects,
                'protocols' => ['http', 'https'],
                'strict' => $request->strictRedirects,
                'track_redirects' => $trackRedirects,
            ];
            if (is_array($options['allow_redirects'])) {
                $options['allow_redirects']['on_redirect'] = static function (RequestInterface $from, ResponseInterface $response, UriInterface $to): void {
                    if ($from->getBody()->getSize() !== 0 && UriComparator::isCrossOrigin($from->getUri(), $to)) {
                        throw new FetchException(new FetchFailure(FetchFailureKind::RedirectBlocked));
                    }
                };
            }
            $options['http_errors'] = false;
            $options['verify'] = $ca ?? true;
            $options['decode_content'] = true;
            $options['stream'] = false;
            $options['proxy'] = '';
            $options['curl'] = [];
            $options['cookies'] = false;
            $options['debug'] = false;
            unset($options['auth']);
            $options['heptapod_deadline'] = min($deadline, microtime(true) + ($request->timeoutSeconds ?? INF));

            try {
                $promise = $next($message, $options);
            } catch (TargetResolutionException $exception) {
                return Create::rejectionFor($this->failure($exception));
            }

            return $promise->then(static fn (ResponseInterface $response): ResponseInterface => $request->acceptsStatus($response->getStatusCode()) ? $response : new Response($response->getStatusCode()), function (#[\SensitiveParameter] mixed $reason) use ($context, $request): never {
                if (! $reason instanceof \Throwable) {
                    throw new \LogicException('HTTP handler rejected without an exception.');
                }
                $failure = $this->failure($reason);
                if ($failure instanceof FetchException && ! $request->refusalIsFinding) {
                    $context->policyRefusals->record($failure->failure);
                }
                throw $failure;
            });
        }, 'outbound_policy');

        // Runs inside redirects, with a fresh bounded sink and remaining budget per hop.
        $stack->push(function (callable $next) use ($request, &$transfers, &$effectiveUrl, &$certificateLeaf): \Closure {
            return function (#[\SensitiveParameter] RequestInterface $message, #[\SensitiveParameter] array $options) use ($next, $request, &$transfers, &$effectiveUrl, &$certificateLeaf): PromiseInterface {
                /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
                $uri = $message->getUri();
                if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== '') {
                    return Create::rejectionFor(new FetchException(new FetchFailure(FetchFailureKind::RedirectBlocked)));
                }
                /** @phpstan-var object{stripped: bool}&\stdClass $redirectState */
                $redirectState = $options['heptapod_redirect_state'];
                $redirectState->stripped = $redirectState->stripped || $this->origin($uri) !== $options['heptapod_origin'];
                if ($redirectState->stripped) {
                    foreach (array_keys($message->getHeaders()) as $name) {
                        $message = $message->withoutHeader($name);
                    }
                    // Header names do not establish safety: even User-Agent can be a secret.
                    // Off-origin hops get only the app-owned public profile, never configured headers.
                    $message = $message->withHeader('Host', $uri->getAuthority());
                    foreach (PublicRequestProfile::headers() as $name => $value) {
                        $message = $message->withHeader($name, $value);
                    }
                }
                /** @var float $deadline */
                $deadline = $options['heptapod_deadline'];
                $remaining = $deadline - microtime(true);
                if ($remaining < 0.001) {
                    return Create::rejectionFor(new FetchException(new FetchFailure(FetchFailureKind::Timeout)));
                }
                $options['timeout'] = $remaining;
                $options['connect_timeout'] = min(10.0, $remaining);
                if (strtoupper($message->getMethod()) !== 'HEAD') {
                    /** @var array<int, mixed> $curlOptions */
                    $curlOptions = $options['curl'] ?? [];
                    $curlOptions[CURLOPT_MAXFILESIZE_LARGE] = $request->maxWireBytes;
                    $options['curl'] = $curlOptions;
                }
                if ($request->withCertificateInfo) {
                    /** @var array<int, mixed> $curlOptions */
                    $curlOptions = $options['curl'] ?? [];
                    $curlOptions[CURLOPT_CERTINFO] = true;
                    $options['curl'] = $curlOptions;
                }
                $state = new OutboundTransferState;
                $sink = Utils::streamFor();
                // An exception from a curl callback escapes the shared multi loop and rejects another transfer's promise.
                $options['sink'] = FnStream::decorate($sink, [
                    'write' => static function (#[\SensitiveParameter] string $bytes) use ($sink, $state, $request): int {
                        if ($state->overflow || $state->decoded + strlen($bytes) > $request->maxDecodedBytes) {
                            $state->overflow = true;

                            return 0;
                        }
                        try {
                            $written = $sink->write($bytes);
                            $state->decoded += $written;

                            return $written;
                        } catch (\Throwable $exception) {
                            $state->callbackError = $exception;

                            return 0;
                        }
                    },
                ]);
                $options['on_headers'] = static function (ResponseInterface $response) use ($state, $request, $message): void {
                    try {
                        $state->status = $response->getStatusCode();
                        $length = $response->getHeaderLine('Content-Length');
                        // HEAD/304 lengths describe a representation, not incoming body bytes.
                        $hasBody = strtoupper($message->getMethod()) !== 'HEAD' && $state->status >= 200 && ! in_array($state->status, [204, 304], true);
                        if ($hasBody && preg_match('/^[0-9]+$/D', $length) === 1 && (float) $length > $request->maxWireBytes) {
                            $state->overflow = true;
                        }
                    } catch (\Throwable $exception) {
                        $state->callbackError = $exception;
                    }
                };
                $options['progress'] = static function (int $total, int $downloaded) use ($request, $state): void {
                    if ($downloaded > $request->maxWireBytes) {
                        $state->overflow = true;
                    }
                };
                $onStats = $options['on_stats'] ?? null;
                /** @var float $dnsSeconds */
                $dnsSeconds = $options['heptapod_dns_seconds'];
                $options['on_stats'] = static function (TransferStats $stats) use (&$transfers, &$effectiveUrl, &$certificateLeaf, $request, $state, $onStats, $dnsSeconds): void {
                    try {
                        $stats = GuzzleFetchResult::withDnsTime($stats, $dnsSeconds);
                        $error = $stats->getHandlerErrorData();
                        $state->errno = is_int($error) ? $error : null;
                        if ($state->errno !== null && $state->errno !== 0 && $stats->getEffectiveUri()->getScheme() === 'https') {
                            $state->tlsTarget = (string) $stats->getEffectiveUri()->withPath('')->withQuery('')->withFragment('')->withUserInfo('');
                        }
                        if ($state->errno === CURLE_FILESIZE_EXCEEDED) {
                            $state->overflow = true;
                            $code = $stats->getHandlerStats()['http_code'] ?? null;
                            $state->status = is_int($code) && $code >= 100 ? $code : null;
                        }
                        $nativeStatus = $stats->getHandlerStats()['http_code'] ?? null;
                        // PSR-7 rejects statuses above 599 (LinkedIn's 999); keep the native status for the failure.
                        if (! $stats->hasResponse() && is_int($nativeStatus) && $nativeStatus > 599 && $nativeStatus <= 999) {
                            $state->status = $nativeStatus;
                        }
                        if ($stats->hasResponse()) {
                            $effectiveUrl = (string) $stats->getEffectiveUri();
                            $certInfo = $stats->getHandlerStats()['certinfo'] ?? null;
                            $leaf = is_array($certInfo) ? ($certInfo[0] ?? null) : null;
                            $pem = is_array($leaf) ? ($leaf['Cert'] ?? null) : null;
                            $certificateLeaf = $request->withCertificateInfo && is_string($pem) ? ['Cert' => $pem] : null;
                            $timings = [];
                            foreach (GuzzleFetchResult::TIMINGS as $key) {
                                $value = $stats->getHandlerStats()[$key] ?? null;
                                if (is_int($value) || is_float($value)) {
                                    $timings[$key] = $value;
                                }
                            }
                            $transfers[] = $timings;
                        }
                        if (is_callable($onStats)) {
                            $onStats($stats);
                        }
                    } catch (\Throwable $exception) {
                        $state->callbackError = $exception;
                    }
                };

                try {
                    $promise = $next($message, $options);
                } catch (RequestException|GuzzleInvalidArgumentException $exception) {
                    return Create::rejectionFor($this->invalidRequest($exception));
                } catch (TransferException $exception) {
                    return Create::rejectionFor($this->failure($exception, $state->status, $state->errno, $state->tlsTarget));
                }

                return $promise->then(function (ResponseInterface $response) use ($state): ResponseInterface {
                    if ($state->callbackError instanceof \Throwable) {
                        throw $state->callbackError;
                    }
                    if ($state->overflow) {
                        throw new FetchException(new FetchFailure(FetchFailureKind::TooLarge, $state->status));
                    }

                    return $response;
                }, function (#[\SensitiveParameter] mixed $reason) use ($state): never {
                    if ($state->callbackError instanceof \Throwable) {
                        throw $state->callbackError;
                    }
                    if ($state->overflow) {
                        throw new FetchException(new FetchFailure(FetchFailureKind::TooLarge, $state->status));
                    }
                    if (! $reason instanceof \Throwable) {
                        throw new \LogicException('HTTP handler rejected without an exception.');
                    }
                    throw $this->failure($reason, $state->status, $state->errno, $state->tlsTarget);
                });
            };
        }, 'bounded_transfer');

        return ['handler' => $stack, 'http_errors' => false, 'headers' => array_diff_key($message->getHeaders(), ['Host' => true])];
    }

    private function origin(UriInterface $uri): string
    {
        return $uri->getScheme().'://'.TargetHost::normalize($uri->getHost()).':'.($uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80));
    }

    /**
     * Configured headers cannot set Host; dispatched Host must come from the URL.
     *
     * @param  array<array-key, string|array<string>>  $headers
     */
    private function validateHeaders(#[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $authority = null): void
    {
        foreach (array_keys($headers) as $position => $name) {
            $values = $headers[$name];
            if (strcasecmp((string) $name, 'Host') === 0 && ($authority === null || $values !== [$authority])) {
                throw $this->invalidHeader((string) $name, $position);
            }
            try {
                new Request('GET', '/', [$name => '']);
            } catch (\InvalidArgumentException) {
                throw $this->invalidHeader((string) $name, $position);
            }
            if ($values === []) {
                throw $this->invalidHeader((string) $name, $position);
            }
            foreach (array_values(is_array($values) ? $values : [$values]) as $valuePosition => $value) {
                try {
                    if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                        throw new \InvalidArgumentException;
                    }
                    new Request('GET', '/', [$name => $value]);
                } catch (\InvalidArgumentException) {
                    throw $this->invalidHeader((string) $name, $position, $valuePosition);
                }
            }
        }
    }

    private function invalidHeader(#[\SensitiveParameter] string $name, int $position, ?int $valuePosition = null): FetchException
    {
        return new FetchException(new FetchFailure(
            FetchFailureKind::InvalidRequest,
            exceptionClass: 'InvalidArgumentException',
            headerName: substr(addcslashes($name, "\0..\37\177..\377"), 0, 128),
            headerPosition: $position,
            headerValuePosition: $valuePosition,
        ));
    }

    private function invalidRequest(#[\SensitiveParameter] \Throwable $exception): FetchException
    {
        return new FetchException(new FetchFailure(FetchFailureKind::InvalidRequest, exceptionClass: class_basename($exception)));
    }

    private function failure(#[\SensitiveParameter] \Throwable $exception, ?int $status = null, ?int $errno = null, ?string $tlsTarget = null): \Throwable
    {
        if ($errno === CURLE_SSL_CACERT_BADFILE) {
            return new \InvalidArgumentException('Invalid outbound HTTP CA file.');
        }
        if ($exception instanceof FetchException) {
            return $exception;
        }
        for ($cause = $exception->getPrevious(); $cause instanceof \Throwable; $cause = $cause->getPrevious()) {
            if ($cause instanceof FetchException) {
                return new FetchException($cause->failure);
            }
        }
        $class = substr($exception::class, (int) strrpos($exception::class, '\\') + 1);
        if ($exception instanceof RequestException && ! $exception->hasResponse() && $status !== null && $status > 599) {
            return new FetchException(new FetchFailure(FetchFailureKind::HttpStatus, $status, $class));
        }
        $kind = match (true) {
            $exception instanceof TargetResolutionException => FetchFailureKind::Dns,
            $exception instanceof TooManyRedirectsException => FetchFailureKind::TooManyRedirects,
            $exception instanceof BadResponseException => FetchFailureKind::RedirectBlocked,
            $exception instanceof TransferException => $this->transferFailureKind($errno),
            default => null,
        };
        if (! $kind instanceof FetchFailureKind) {
            return $exception;
        }

        return new FetchException(new FetchFailure($kind, $status ?? ($exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null), $class,
            tlsReason: $kind === FetchFailureKind::Tls ? TlsFailureReason::fromNativeError($exception->getMessage()) : null,
            tlsTarget: $kind === FetchFailureKind::Tls ? $tlsTarget : null,
            connectionReason: $kind === FetchFailureKind::Connect ? $this->connectionFailureReason($errno) : null));
    }

    private function transferFailureKind(?int $errno): FetchFailureKind
    {
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => FetchFailureKind::Timeout,
            CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_RESOLVE_PROXY => FetchFailureKind::Dns,
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CACERT, CURLE_SSL_PINNEDPUBKEYNOTMATCH => FetchFailureKind::Tls,
            default => FetchFailureKind::Connect,
        };
    }

    private function connectionFailureReason(?int $errno): ?ConnectionFailureReason
    {
        return match ($errno) {
            CURLE_COULDNT_CONNECT => ConnectionFailureReason::ConnectFailed,
            CURLE_GOT_NOTHING, CURLE_SEND_ERROR, CURLE_RECV_ERROR => ConnectionFailureReason::Reset,
            default => null,
        };
    }
}
