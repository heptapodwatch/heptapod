<?php

declare(strict_types=1);

namespace Heptapod\Http\Outbound;

use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\TransferStats;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PacedHttpClient;
use Heptapod\Contracts\Http\TargetHost;
use Illuminate\Support\Sleep;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class GuzzlePacedHttpClient implements PacedHttpClient
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<int, CurlMultiHandler> */
    private array $drivers = [];

    /** @var array<int, array{message: RequestInterface, options: array<array-key, mixed>, next: callable(RequestInterface, array<array-key, mixed>): PromiseInterface, deferred: Promise}> */
    private array $pending = [];

    /** @var array<int, PromiseInterface> */
    private array $transfers = [];

    /** @var array<string, int> */
    private array $active = [];

    /** @var array<string, float> */
    private array $lastStart = [];

    private readonly int $intervalMilliseconds;

    private int $ordinal = 0;

    public function __construct(private readonly OutboundHttp $http, #[\SensitiveParameter] private readonly CheckContext $context, int $additionalDelayMilliseconds)
    {
        if (! in_array($additionalDelayMilliseconds, [0, 10, 25, 50, 100], true)) {
            throw new \InvalidArgumentException('Invalid additional sitemap request delay.');
        }
        $this->intervalMilliseconds = 110 + $additionalDelayMilliseconds;
    }

    public function fetch(#[\SensitiveParameter] FetchRequest $request): ?FetchResult
    {
        return $this->fetchMany([$request])[0];
    }

    public function fetchMany(#[\SensitiveParameter] array $requests): array
    {
        if (count($requests) > 500) {
            throw new \InvalidArgumentException('Paced HTTP batches are bounded to 500 requests.');
        }
        $promises = [];
        try {
            foreach ($requests as $key => $request) {
                $this->context->runControl?->assertActive();
                $promises[$key] = $this->send($request);
            }
            $this->pump();
            $results = [];
            foreach ($promises as $key => $promise) {
                $result = $promise->wait();
                if ($result !== null && ! $result instanceof FetchResult) {
                    throw new \LogicException('Paced HTTP settled with an invalid result.');
                }
                $results[$key] = $result;
            }

            return $results;
        } finally {
            // Cancellation or unexpected errors must not leave native transfers running behind the attempt.
            foreach ($this->transfers as $transfer) {
                $transfer->cancel();
            }
            foreach ($this->pending as $hop) {
                $hop['deferred']->cancel();
            }
            $this->transfers = $this->pending = $this->active = [];
        }
    }

    private function send(#[\SensitiveParameter] FetchRequest $request): PromiseInterface
    {
        $ceiling = min(10.0, $request->timeoutSeconds ?? 10.0);
        $state = (object) ['requestCeiling' => $ceiling, 'deadlineMilliseconds' => null, 'budgetLimited' => $this->remaining() - 0.5 < $ceiling];
        $transfers = [];
        $effectiveUrl = null;
        $certificateLeaf = null;

        return $this->client($request)->requestAsync($request->method, $request->url, [
            'headers' => $request->headers, 'body' => $request->body,
            'heptapod_paced_state' => $state,
            'on_stats' => static function (TransferStats $stats) use (&$transfers, &$effectiveUrl, &$certificateLeaf): void {
                if (! $stats->hasResponse()) {
                    return;
                }
                $values = $stats->getHandlerStats();
                $transfers[] = array_filter(array_intersect_key($values, array_flip(GuzzleFetchResult::TIMINGS)), static fn (mixed $value): bool => is_int($value) || is_float($value));
                $effectiveUrl = (string) $stats->getEffectiveUri();
                $certInfo = $values['certinfo'] ?? null;
                $leaf = is_array($certInfo) ? ($certInfo[0] ?? null) : null;
                $pem = is_array($leaf) ? ($leaf['Cert'] ?? null) : null;
                $certificateLeaf = is_string($pem) ? ['Cert' => $pem] : null;
            },
        ])->then(function (ResponseInterface $response) use ($request, &$transfers, &$effectiveUrl, &$certificateLeaf): ?FetchResult {
            try {
                if ($this->remaining() <= 0) {
                    return null;
                }

                return GuzzleFetchResult::response($request, $response, $transfers, $effectiveUrl, $certificateLeaf);
            } finally {
                $response->getBody()->close();
            }
        }, function (mixed $reason) use ($state, &$transfers): ?FetchResult {
            if (! $reason instanceof FetchException) {
                throw $reason instanceof \Throwable ? $reason : new \LogicException('Paced HTTP rejected without an exception.');
            }
            if ($this->remaining() <= 0 || ($reason->failure->kind === FetchFailureKind::Timeout && $state->budgetLimited)) {
                return null;
            }

            return new FetchResult(status: $reason->failure->status, failure: $reason->failure, transfers: $transfers);
        });
    }

    private function client(#[\SensitiveParameter] FetchRequest $request): Client
    {
        // Different header/origin policies and response bounds never share a template's middleware closure.
        $key = hash('sha256', serialize([$request->headers, $request->body, $request->maxWireBytes, $request->maxDecodedBytes, $request->redirects, $request->maxRedirects, $request->siteHost, $request->timeoutSeconds, $request->acceptedStatuses, $request->strictRedirects, $request->withCertificateInfo, $request->publicTargetsOnly, $request->refusalIsFinding]));
        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }
        $config = $this->http->clientConfig($request, $this->context, selectTimeoutSeconds: 0.0);
        $stack = $config['handler'];
        if (! $stack instanceof HandlerStack) {
            throw new \LogicException('Paced HTTP requires the existing guarded handler stack.');
        }
        $stack->push(function (callable $next): callable {
            if ($next instanceof CurlMultiHandler) {
                $this->drivers[spl_object_id($next)] = $next;
            }

            return function (RequestInterface $message, array $options) use ($next): PromiseInterface {
                $state = $options['heptapod_paced_state'];
                /** @var object{budgetLimited: bool}&\stdClass $state */
                if ($this->remaining() < 1.5) {
                    $state->budgetLimited = true;

                    return Create::rejectionFor(new FetchException(new FetchFailure(FetchFailureKind::Timeout)));
                }

                /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
                return $next($message, $options);
            };
        }, 'paced_driver');
        $stack->before('prepare_body', fn (callable $next): \Closure => function (RequestInterface $message, array $options) use ($next): PromiseInterface {
            /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
            $deferred = new Promise;
            $this->pending[$this->ordinal++] = ['message' => $message, 'options' => $options, 'next' => $next, 'deferred' => $deferred];

            return $deferred;
        }, 'sitemap_admission');

        return $this->clients[$key] = new Client($config);
    }

    private function pump(): void
    {
        while (true) {
            Utils::queue()->run();
            if ($this->pending === [] && array_sum($this->active) === 0) {
                return;
            }
            $this->context->runControl?->assertActive();
            foreach ($this->drivers as $driver) {
                $driver->tick();
            }
            $wait = null;
            // Finish started redirect chains first. The retained hop limit bounds their priority.
            uasort($this->pending, static function (array $a, array $b): int {
                /** @var object{deadlineMilliseconds: int|null} $first */
                $first = $a['options']['heptapod_paced_state'];
                /** @var object{deadlineMilliseconds: int|null} $second */
                $second = $b['options']['heptapod_paced_state'];

                return ($first->deadlineMilliseconds === null ? 1 : 0) <=> ($second->deadlineMilliseconds === null ? 1 : 0);
            });
            foreach ($this->pending as $id => $hop) {
                $host = TargetHost::normalize($hop['message']->getUri()->getHost());
                $now = CarbonImmutable::now()->getTimestampMs();
                $usable = $this->remaining() - 0.5;
                /** @var object{requestCeiling: float, deadlineMilliseconds: int|null, budgetLimited: bool}&\stdClass $state */
                $state = $hop['options']['heptapod_paced_state'];
                if ($usable < 1.0 || ($state->deadlineMilliseconds !== null && $state->deadlineMilliseconds <= $now)) {
                    unset($this->pending[$id]);
                    $state->budgetLimited = true;
                    $hop['deferred']->reject(new FetchException(new FetchFailure(FetchFailureKind::Timeout)));

                    continue;
                }
                if (($this->active[$host] ?? 0) >= 4) {
                    continue;
                }
                $untilStart = ($this->lastStart[$host] ?? 0) + $this->intervalMilliseconds - CarbonImmutable::now()->getPreciseTimestamp() / 1000;
                if ($untilStart > 0) {
                    if (array_sum($this->active) === 0) {
                        $wait = min($wait ?? $untilStart, $untilStart);
                    }

                    continue;
                }
                unset($this->pending[$id]);
                $options = $hop['options'];
                $state->deadlineMilliseconds ??= min($this->context->deadline->getTimestampMs() - 500, $now + (int) floor($state->requestCeiling * 1000));
                $state->budgetLimited = $state->budgetLimited || $usable < $state->requestCeiling;
                // Queue time precedes the ceiling; redirect hops retain the first admission's deadline.
                // Guard and bounded-transfer middleware run after this gate on every admitted hop.
                $options['heptapod_deadline'] = $state->deadlineMilliseconds / 1000;
                $this->active[$host] = ($this->active[$host] ?? 0) + 1;
                $next = $hop['next'];
                $this->transfers[$id] = $next($hop['message'], $options);
                $this->transfers[$id]->then(function (ResponseInterface $response) use ($host, $id, $hop): void {
                    $this->active[$host]--;
                    unset($this->transfers[$id]);
                    $hop['deferred']->resolve($response);
                }, function (mixed $reason) use ($host, $id, $hop): void {
                    $this->active[$host]--;
                    unset($this->transfers[$id]);
                    $hop['deferred']->reject($reason);
                });
                foreach ($this->drivers as $driver) {
                    $driver->tick();
                }
                // Guard lookup and native enqueue can block; neither consumes the next host's interval.
                $this->lastStart[$host] = CarbonImmutable::now()->getPreciseTimestamp() / 1000;
            }
            Utils::queue()->run();
            if ($this->pending !== [] || array_sum($this->active) > 0) {
                if (array_sum($this->active) === 0 && $this->remaining() < 1.5) {
                    continue;
                }
                Sleep::for($wait ?? 1)->milliseconds();
            }
        }
    }

    private function remaining(): float
    {
        return ($this->context->deadline->getTimestampMs() - CarbonImmutable::now()->getTimestampMs()) / 1000;
    }
}
