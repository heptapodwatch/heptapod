<?php

declare(strict_types=1);

namespace Heptapod\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs before web/StartSession so throttled passkey floods cannot create Redis sessions. */
final readonly class ThrottlePasskeyEntry
{
    public function __construct(private ThrottleRequestsExactly $throttle) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $isPasskeyEntry = $request->is('passkeys/*', 'user/passkeys/options')
            || ($request->is('user/passkeys') && $request->isMethod('POST'));

        /** @var Response $response Laravel's throttle forwards the HTTP response unchanged. */
        $response = $isPasskeyEntry ? $this->throttle->handle($request, $next, 'passkeys-entry') : $next($request);

        return $response;
    }
}
