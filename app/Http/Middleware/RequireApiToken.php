<?php

declare(strict_types=1);

namespace Heptapod\Http\Middleware;

use Closure;
use Heptapod\Actions\RecordUserActivity;
use Heptapod\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireApiToken
{
    public function __construct(private RecordUserActivity $activity) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->disabled_at !== null || $request->bearerToken() === null) {
            throw new AuthenticationException;
        }
        // Sanctum may authenticate a session as TransientToken even when a Bearer header is present.
        if (! $user->currentAccessToken() instanceof PersonalAccessToken) { // @phpstan-ignore instanceof.alwaysTrue
            throw new AuthenticationException;
        }
        ($this->activity)($user);
        app()->setLocale($user->locale);

        return $next($request);
    }
}
