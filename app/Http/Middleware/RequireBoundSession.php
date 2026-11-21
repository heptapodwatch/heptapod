<?php

declare(strict_types=1);

namespace Heptapod\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;

/**
 * The framework's session password binding, made strict: every sign-in stores the password hash in its session
 * (SessionGuard::login), so a signed-in session without one predates the binding and must sign in again. The stock
 * middleware would bind such a session to the current hash instead, letting it survive a password reset. A remembered
 * sign-in carries the hash in its cookie, which the stock middleware checks.
 */
final class RequireBoundSession extends AuthenticateSession
{
    public function handle($request, Closure $next): mixed
    {
        if ($request->hasSession() && (string) $request->user()?->getAuthPassword() !== '' && ! Auth::viaRemember()
            && ! $request->session()->has('password_hash_'.Auth::getDefaultDriver())) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
