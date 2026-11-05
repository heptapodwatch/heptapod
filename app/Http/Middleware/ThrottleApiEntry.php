<?php

namespace Heptapod\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ThrottleApiEntry
{
    public function __construct(private ThrottleRequestsExactly $throttle) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response Laravel's throttle forwards the HTTP response unchanged. */
        $response = $request->is('api/*') ? $this->throttle->handle($request, $next, 'api-entry') : $next($request);

        return $response;
    }
}
