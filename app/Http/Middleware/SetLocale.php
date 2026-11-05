<?php

declare(strict_types=1);

namespace Heptapod\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetLocale
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        // Symfony returns the first supported locale when the header is absent or has no match.
        $locale = $request->user()?->getAttribute('locale') ?? $request->cookie('locale') ?? $request->getPreferredLanguage([config()->string('app.locale'), 'en', 'de']);
        $locale = in_array($locale, ['en', 'de'], true) ? $locale : 'en';
        app()->setLocale($locale);
        $request->attributes->set('locale', $locale);

        return $next($request);
    }
}
