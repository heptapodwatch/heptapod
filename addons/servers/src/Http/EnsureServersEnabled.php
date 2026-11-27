<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Closure;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Switched off, every server route answers like a route that does not exist; the data stays. */
final readonly class EnsureServersEnabled
{
    public function __construct(private ServersAccess $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->access->enabled(), 404);

        return $next($request);
    }
}
