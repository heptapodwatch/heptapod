<?php

namespace Heptapod\Testing;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\Http\FetchException;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\Http\PacedHttpClient;
use Heptapod\Contracts\Http\PublicRequestProfile;
use Psr\Http\Message\RequestInterface;

final class FakeOutboundHttp implements OutboundHttp
{
    /** @var FakeResponses<FetchResult> */
    private readonly FakeResponses $responses;

    /** @var ?\Closure(FetchRequest, CheckContext): FetchResult */
    private ?\Closure $callback = null;

    /** @var list<array{request: FetchRequest, context: CheckContext}> */
    public array $requests = [];

    public function __construct()
    {
        $this->responses = new FakeResponses(FetchResult::class);
    }

    public function respondWith(FetchRequest $request, FetchResult $result): self
    {
        $this->responses->add('fetch', [$request], $result);

        return $this;
    }

    /** @param \Closure(FetchRequest, CheckContext): FetchResult $callback */
    public function respondUsing(\Closure $callback): self
    {
        $this->callback = $callback;

        return $this;
    }

    public function fetch(FetchRequest $request, CheckContext $context): FetchResult
    {
        $this->requests[] = ['request' => $request, 'context' => $context];

        return $this->callback instanceof \Closure ? ($this->callback)($request, $context) : $this->responses->get('fetch', [$request]);
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
        if ($selectTimeoutSeconds !== null && (! is_finite($selectTimeoutSeconds) || $selectTimeoutSeconds < 0)) {
            throw new \InvalidArgumentException('HTTP select timeout must be finite and nonnegative.');
        }

        // Same client defaults as GuzzleOutboundHttp: request headers plus the public profile, without Host.
        $defaults = new Request($request->method, $request->url, $request->headers);
        foreach (PublicRequestProfile::headers() as $name => $value) {
            if (! $defaults->hasHeader($name)) {
                $defaults = $defaults->withHeader($name, $value);
            }
        }

        return ['http_errors' => false, 'headers' => array_diff_key($defaults->getHeaders(), ['Host' => true]), 'handler' => HandlerStack::create(function (RequestInterface $message) use ($request, $context): PromiseInterface {
            $headers = array_filter($message->getHeaders(), static fn (array $values): bool => $values !== []);
            $fetch = new FetchRequest(
                (string) $message->getUri(), $message->getMethod(), $headers, (string) $message->getBody(),
                $request->maxWireBytes, $request->maxDecodedBytes, $request->redirects, $request->maxRedirects,
                $request->siteHost, $request->timeoutSeconds, $request->acceptedStatuses, $request->strictRedirects, $request->withCertificateInfo, $request->publicTargetsOnly,
            );
            $result = $this->fetch($fetch, $context);
            if ($result->failure instanceof FetchFailure && ($result->failure->kind !== FetchFailureKind::HttpStatus
                || ($result->failure->status > 599 && $result->failure->status <= 999))) {
                return Create::rejectionFor(new FetchException($result->failure));
            }

            return Create::promiseFor(new Response($result->status ?? throw new \LogicException('Fake HTTP response requires a status.'), $result->headers, $result->body));
        })];
    }
}
