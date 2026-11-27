<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Closure;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Switched off, every server route answers exactly like a route that does not exist (the router's own 404, message
 * included), so an instance with servers off cannot be told apart from one without the addon; the data stays.
 */
final readonly class EnsureServersEnabled
{
    public function __construct(private ServersAccess $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->access->enabled()) {
            throw new NotFoundHttpException(sprintf('The route %s could not be found.', $request->path()));
        }

        return $next($request);
    }
}
