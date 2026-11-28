<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Closure;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Switched off, servers answer exactly like a route that does not exist (the router's own 404, message included), so an
 * instance with servers off cannot be told apart from one without the addon; the data stays. Route middleware on the
 * server pages, and global middleware (the last one, right before routing, inside CORS and the API entry throttle like
 * the router) for every method on api/servers, so OPTIONS and wrong methods never reach the router's method handling.
 */
final readonly class EnsureServersEnabled
{
    public function __construct(private ServersAccess $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // As global middleware the request has no route yet: only the API prefix is gated there.
        $gated = $request->route() !== null || $request->is('api/servers', 'api/servers/*');
        if ($gated && ! $this->access->enabled()) {
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return $next($request);
    }
}
