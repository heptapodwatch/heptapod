<?php

declare(strict_types=1);

namespace Heptapod\Http\Middleware;

use Closure;
use Heptapod\Support\AtomicRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/** The framework throttle middleware with check and hit as one atomic step, so parallel requests cannot pass a cap together. */
final class ThrottleRequestsExactly extends ThrottleRequests
{
    public function __construct(private readonly AtomicRateLimiter $atomicLimiter)
    {
        parent::__construct($atomicLimiter);
    }

    /**
     * @param  Request  $request
     * @param  array<mixed>  $limits
     */
    protected function handleRequest($request, Closure $next, array $limits): Response
    {
        /** @var list<object{key: string, maxAttempts: int, decaySeconds: int, afterCallback: callable|null, responseCallback: callable|null}> $limits The parent builds these from Limit objects. */
        foreach ($limits as $limit) {
            if ($limit->afterCallback !== null) {
                throw new \LogicException('Limit::after() hits after the response and cannot be enforced exactly.');
            }
        }
        $exhausted = $this->atomicLimiter->consume(array_map(static fn (object $limit): array => [
            'key' => $limit->key, 'maxAttempts' => $limit->maxAttempts, 'decaySeconds' => $limit->decaySeconds,
        ], $limits));
        if ($exhausted !== null) {
            $limit = $limits[$exhausted];

            throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
        }
        /** @var Response $response */
        $response = $next($request);
        foreach ($limits as $limit) {
            $response = $this->addHeaders($response, $limit->maxAttempts, $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts));
        }

        return $response;
    }
}
