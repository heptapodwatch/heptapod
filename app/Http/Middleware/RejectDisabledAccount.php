<?php

declare(strict_types=1);

namespace Heptapod\Http\Middleware;

use Closure;
use Heptapod\Actions\RecordUserActivity;
use Heptapod\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class RejectDisabledAccount
{
    public function __construct(private RecordUserActivity $activity) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->disabled_at !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(401);
        }

        if ($user instanceof User) {
            ($this->activity)($user);
        }

        return $next($request);
    }
}
