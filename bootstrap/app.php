<?php

declare(strict_types=1);

use Heptapod\Http\Controllers\CronPingController;
use Heptapod\Http\Controllers\HealthController;
use Heptapod\Http\ErrorResponses;
use Heptapod\Http\Middleware\HandleAppearance;
use Heptapod\Http\Middleware\HandleInertiaRequests;
use Heptapod\Http\Middleware\RejectDisabledAccount;
use Heptapod\Http\Middleware\RequireBoundSession;
use Heptapod\Http\Middleware\SetLocale;
use Heptapod\Http\Middleware\ThrottleApiEntry;
use Heptapod\Http\Middleware\ThrottleRequestsExactly;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Psr\Http\Client\NetworkExceptionInterface;
use Pusher\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        then: static function (): void {
            Route::get('/up', HealthController::class)->name('health');
            // Machine-to-machine bearer pings bypass browser sessions and CSRF.
            Route::match(['GET', 'POST'], 'cron/{uuid}/{phase?}', CronPingController::class)
                ->whereUuid('uuid')->where('phase', 'starting|finished|failed')
                ->middleware('throttle:cron-pings')->name('cron.ping');
            // Fortify's vendor routes omit throttles on password confirmation and reset endpoints.
            $routes = Route::getRoutes();
            if ($routes instanceof RouteCollection) {
                $routes->refreshNameLookups();
            }
            foreach ([
                'password.confirm.store' => 'throttle:password-confirm',
                'password.email' => 'throttle:password-reset-link',
                'password.update' => 'throttle:password-reset',
            ] as $routeName => $middleware) {
                $route = $routes->getByName($routeName);
                if ($route !== null && ! in_array($middleware, $route->middleware(), true)) {
                    $route->middleware($middleware);
                }
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestsDuringMaintenance(except: ['/up']);
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        // Global entry throttle runs before route authentication, so invalid tokens are bounded too.
        $middleware->append(ThrottleApiEntry::class);
        $middleware->alias(['throttle' => ThrottleRequestsExactly::class, 'auth.session' => RequireBoundSession::class]);
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', 'list_page_sizes']);
        // Settings → Email refuses a sender name or footer with any line break, also at the edge, so those keep their raw value.
        $middleware->trimStrings(except: [fn (Request $request): bool => $request->is('api/*'), 'sender_name', 'footer']);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request): bool => $request->is('api/*')]);

        // Resolve locale after cookie/session setup, before CSRF and route throttles can reject the request.
        $middleware->appendToPriorityList(ShareErrorsFromSession::class, SetLocale::class);
        // Sessions live in Redis, so a password change ends the other sessions through the password hash stored at sign-in.
        $middleware->authenticateSessions();
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
        // Report 5xx HttpExceptions (including abort(500)/abort(503) and maintenance outages) while keeping 4xx silent.
        $exceptions->stopIgnoring(HttpException::class);
        $exceptions->dontReportWhen(static fn (Throwable $exception): bool => $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
        $exceptions->throttle(fn (ApiErrorException $exception): ?Limit => $exception->getCode() === 0 && $exception->getPrevious() instanceof NetworkExceptionInterface
            ? Limit::perMinute(1)->by('live:reverb:transport') : null);
        $exceptions->render(fn (AuthenticationException $exception, Request $request): ?\Illuminate\Http\JsonResponse => $request->is('api/*') ? response()->json(['error' => __('Unauthenticated.')], 401) : null);
        // Expected web/Inertia refusals and framework throttles/size limits are rendered centrally; final Inertia vs Blade
        // error page selection is registered via Inertia::handleExceptionsUsing(ErrorResponses::respond(...)) in AppServiceProvider.
        $exceptions->render(ErrorResponses::renderExpectedRefusal(...));
        $exceptions->render(ErrorResponses::renderThrottle(...));
        $exceptions->render(ErrorResponses::renderPostTooLarge(...));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
