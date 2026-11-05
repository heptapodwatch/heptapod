<?php

declare(strict_types=1);

use Heptapod\Http\Controllers\CronPingController;
use Heptapod\Http\Middleware\HandleAppearance;
use Heptapod\Http\Middleware\HandleInertiaRequests;
use Heptapod\Http\Middleware\RejectDisabledAccount;
use Heptapod\Http\Middleware\SetLocale;
use Heptapod\Http\Middleware\ThrottleApiEntry;
use Heptapod\Http\Middleware\ThrottleRequestsExactly;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Psr\Http\Client\NetworkExceptionInterface;
use Pusher\ApiErrorException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: static function (): void {
            // Machine-to-machine bearer pings bypass browser sessions and CSRF.
            Route::match(['GET', 'POST'], 'cron/{uuid}/{phase?}', CronPingController::class)
                ->whereUuid('uuid')->where('phase', 'starting|finished|failed')
                ->middleware('throttle:cron-pings')->name('cron.ping');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        // Global entry throttle runs before route authentication, so invalid tokens are bounded too.
        $middleware->append(ThrottleApiEntry::class);
        $middleware->alias(['throttle' => ThrottleRequestsExactly::class]);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/*')]);

        // Resolve locale after cookie/session setup, before CSRF and route throttles can reject the request.
        $middleware->appendToPriorityList(ShareErrorsFromSession::class, SetLocale::class);
        $middleware->web(append: [
            HandleAppearance::class,
            RejectDisabledAccount::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::using(limit: 4),
        ], prepend: [SetLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Validation redirects flash input into the session; third-party secrets must never be flashed.
        $exceptions->dontFlash(['ohdear_token']);
        $exceptions->throttle(fn (ApiErrorException $exception): ?Limit => $exception->getCode() === 0 && $exception->getPrevious() instanceof NetworkExceptionInterface
            ? Limit::perMinute(1)->by('live:reverb:transport') : null);
        $exceptions->render(fn (AuthenticationException $exception, Request $request): ?\Illuminate\Http\JsonResponse => $request->is('api/*') ? response()->json(['error' => __('Unauthenticated.')], 401) : null);
        $exceptions->render(fn (ThrottleRequestsException $exception, Request $request): ?\Illuminate\Http\JsonResponse => $request->is('api/*') ? response()->json(['error' => __('Too Many Attempts.')], 429, $exception->getHeaders()) : null);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $status = $response->getStatusCode();
            if (! in_array($status, [403, 404, 419, 500, 503], true) || ($status === 500 && config()->boolean('app.debug')) || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $locale = ($status === 503 ? null : $request->attributes->get('locale')) ?? $request->cookie('locale') ?? $request->getPreferredLanguage([config()->string('app.locale'), 'en', 'de']);
            app()->setLocale(in_array($locale, ['en', 'de'], true) ? $locale : 'en');
            // Never resolve a user or start a session here: database failures need a working error page too.
            $guard = in_array($status, [500, 503], true) ? null : Auth::guard('web');
            $signedIn = $guard instanceof SessionGuard && $request->hasSession() && $request->session()->has($guard->getName());
            [$recoveryUrl, $recoveryLabel] = match (true) {
                $status === 503 => [$request->fullUrl(), 'Try again'],
                $status === 500 || ! $request->hasSession() => [route('sites.index'), 'Go to Sites'],
                $signedIn => [route('sites.index'), 'Sites'],
                default => [route('login'), 'Sign in'],
            };
            $response->setContent(view('errors.error', ['status' => $status, 'recoveryUrl' => $recoveryUrl, 'recoveryLabel' => $recoveryLabel])->render());
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

            return $response;
        });
    })->create();
