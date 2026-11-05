<?php

declare(strict_types=1);

namespace Heptapod\Testing;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransferStats;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PacedHttpClient;
use Psr\Http\Message\RequestInterface;

/** Observe the real HTTP seam without changing its handler, responses or request policy. */
final class RecordingOutboundHttp implements OutboundHttp
{
    /** @var list<array<string, int|float|string|bool|null>> */
    public array $transfers = [];

    /** @var list<array{select_timeout: float|null, policy: string}> */
    public array $configurations = [];

    public function __construct(private readonly OutboundHttp $http) {}

    public function fetch(FetchRequest $request, CheckContext $context): FetchResult
    {
        return $this->http->fetch($request, $context);
    }

    public function pacedClient(CheckContext $context, int $additionalDelayMilliseconds = 0): PacedHttpClient
    {
        return app(PacedHttpClient::class, [
            'http' => $this,
            'context' => $context,
            'additionalDelayMilliseconds' => $additionalDelayMilliseconds,
        ]);
    }

    public function clientConfig(FetchRequest $request, CheckContext $context, ?float $selectTimeoutSeconds = null): array
    {
        $config = $this->http->clientConfig($request, $context, $selectTimeoutSeconds);
        $this->configurations[] = ['select_timeout' => $selectTimeoutSeconds, 'policy' => $request->redirects->value];
        $stack = $config['handler'];
        if (! $stack instanceof HandlerStack) {
            throw new \LogicException('HTTP observation requires the retained handler stack.');
        }
        $stack->push(fn (callable $next): \Closure => function (RequestInterface $message, array $options) use ($next): PromiseInterface {
            /** @var callable(RequestInterface, array<array-key, mixed>): PromiseInterface $next */
            $parts = explode('/', trim($message->getUri()->getPath(), '/'));
            $pageId = (int) end($parts);
            $candidate = count($parts) > 1 ? $parts[count($parts) - 2] : '';
            $kind = in_array($candidate, ['page', 'document', 'target'], true) ? $candidate : 'other';
            $admitted = microtime(true);
            $dispatched = null;
            $onStats = $options['on_stats'];
            $timeoutAtDispatch = $options['timeout'];
            if (! is_callable($onStats) || (! is_int($timeoutAtDispatch) && ! is_float($timeoutAtDispatch))) {
                throw new \LogicException('Native HTTP observation requires bounded timing callbacks.');
            }
            $options['on_stats'] = function (TransferStats $stats) use ($pageId, $kind, $admitted, &$dispatched, $timeoutAtDispatch, $onStats): void {
                $onStats($stats);
                $finished = microtime(true);
                $duration = $stats->getTransferTime();
                if ($duration === null) {
                    throw new \LogicException('Native HTTP transfer timing is unavailable.');
                }
                $errno = $stats->getHandlerErrorData();
                $this->transfers[] = [
                    'page_id' => $pageId, 'hop' => $kind, 'admitted_epoch' => $admitted, 'dispatched_epoch' => $dispatched,
                    'finished_epoch' => $finished, 'transport_start_epoch' => $finished - $duration,
                    'transport_seconds' => $duration, 'timeout_at_dispatch_seconds' => $timeoutAtDispatch,
                    'status' => $stats->hasResponse() ? $stats->getResponse()?->getStatusCode() : null,
                    'curl_errno' => is_int($errno) ? $errno : null,
                ];
            };

            $promise = $next($message, $options);
            // The native handler has attached its curl handle before returning the promise.
            $dispatched = microtime(true);

            return $promise;
        }, 'test_transfer_observation');

        return $config;
    }
}
