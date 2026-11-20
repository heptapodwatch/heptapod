<?php

declare(strict_types=1);

namespace Heptapod\Http;

use Carbon\CarbonImmutable;
use Heptapod\Data\Ui\ErrorPageData;
use Heptapod\Exceptions\ExpectedRefusal;
use Heptapod\Models\User;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Centralized web and Inertia error rendering policy.
 *
 * Expected refusals keep users in place (Inertia flash + back, JSON envelope for useHttpRequest, or in-shell
 * Inertia page for direct signed-in GETs). Unexpected 500/503 faults, disabled-account 401s, and guest error
 * responses stay on the zero-DB, zero-JS standalone Blade template.
 */
final class ErrorResponses
{
    /** @var list<int> */
    private const array BrandedStatuses = [401, 403, 404, 409, 410, 413, 419, 422, 429, 500, 503];

    /** @var list<int> */
    private const array InShellStatuses = [403, 404, 410, 413, 429];

    public static function renderExpectedRefusal(ExpectedRefusal $refusal, Request $request): ?Response
    {
        if ($request->is('api/*')) {
            return null;
        }

        self::resolveLocale($request, $refusal->status);
        $message = $refusal->translatedMessage();
        $headers = $refusal->headers();

        if ($request->expectsJson() && ! self::isInertia($request)) {
            return response()->json([
                'message' => $message,
                ...($refusal->errorCode !== null ? ['code' => $refusal->errorCode] : []),
                ...($refusal->retryAfterSeconds !== null ? ['retry_after' => max(1, $refusal->retryAfterSeconds)] : []),
            ], $refusal->status, $headers);
        }

        if (self::isInertia($request) && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return self::flashBack($request, $refusal->tone, $message);
        }

        return response('', $refusal->status, $headers);
    }

    public static function renderThrottle(ThrottleRequestsException $exception, Request $request): ?Response
    {
        if ($request->is('api/*')) {
            return response()->json(['error' => __('Too Many Attempts.')], 429, $exception->getHeaders());
        }

        self::resolveLocale($request, 429);
        $retryAfter = self::retryAfterFromHeaders($exception->getHeaders());
        $message = self::rateLimitMessage($retryAfter);

        if ($request->expectsJson() && ! self::isInertia($request)) {
            return response()->json([
                'message' => $message,
                'code' => 'rate_limited',
                ...($retryAfter !== null ? ['retry_after' => $retryAfter] : []),
            ], 429, $exception->getHeaders());
        }

        if (self::isInertia($request) && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return self::flashBack($request, 'warning', $message);
        }

        return null;
    }

    public static function renderPostTooLarge(PostTooLargeException $exception, Request $request): ?Response
    {
        if ($request->is('api/*')) {
            return null;
        }

        self::resolveLocale($request, 413);
        $message = self::text('errors.413.upload');

        if ($request->expectsJson() && ! self::isInertia($request)) {
            return response()->json(['message' => $message, 'code' => 'payload_too_large'], 413);
        }

        if (self::isInertia($request) && ! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return self::flashBack($request, 'warning', $message);
        }

        return null;
    }

    public static function respond(ExceptionResponse $exceptionResponse): Response
    {
        $response = $exceptionResponse->response;
        $request = $exceptionResponse->request;
        $exception = $exceptionResponse->exception;
        $status = $exceptionResponse->statusCode();

        if (
            $request->is('api/*')
            || ($request->expectsJson() && ! self::isInertia($request))
            || $response->headers->has('X-Inertia')
            || ($status === 500 && config()->boolean('app.debug'))
        ) {
            return $response;
        }

        // Inertia 419 CSRF expiry: bounce back so HandleInertiaRequests supplies a fresh token while preserveState keeps form inputs.
        if ($status === 419 && self::isInertia($request)) {
            self::resolveLocale($request, 419);

            return self::flashBack($request, 'warning', self::text('errors.419.refreshed'));
        }

        // Stale-tab Inertia mutation hitting 403 or 404: keep user in place and refresh props instead of opening an error modal.
        if (
            in_array($status, [403, 404], true)
            && self::isInertia($request)
            && ! $request->isMethod('GET')
            && ! $request->isMethod('HEAD')
        ) {
            self::resolveLocale($request, $status);

            return self::flashBack(
                $request,
                $status === 403 ? 'error' : 'warning',
                self::text($status === 403 ? 'errors.403.action' : 'errors.404.action'),
            );
        }

        if (! in_array($status, self::BrandedStatuses, true)) {
            return $response;
        }

        self::resolveLocale($request, $status);
        $signedIn = self::isSignedIn($request, $status);
        [$title, $description, $recoveryUrl, $recoveryLabel, $secondaryUrl, $secondaryLabel, $code, $retryAfter] = self::details(
            $status,
            $exception,
            $request,
            $response,
            $signedIn,
        );

        // Signed-in healthy GET dead ends stay inside AppLayout so sidebar navigation and ⌘K remain reachable.
        if (
            $signedIn
            && $request->isMethod('GET')
            && in_array($status, self::InShellStatuses, true)
            && $request->user() instanceof User
        ) {
            $pageData = new ErrorPageData(
                status: $status,
                title: $title,
                description: $description,
                recovery_url: $recoveryUrl,
                recovery_label: $recoveryLabel,
                secondary_url: $secondaryUrl,
                secondary_label: $secondaryLabel,
                code: $code,
                retry_after: $retryAfter,
            );
            /** @var array<string, mixed> $props */
            $props = $pageData->toArray();
            $rendered = $exceptionResponse
                ->render('errors/show', $props)
                ->withSharedData()
                ->toResponse($request);

            foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $header) {
                if ($response->headers->has($header)) {
                    $rendered->headers->set($header, (string) $response->headers->get($header));
                }
            }

            return $rendered;
        }

        $response->setContent(view('errors.error', [
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'recoveryUrl' => $recoveryUrl,
            'recoveryLabel' => $recoveryLabel,
        ])->render());
        $response->headers->set('Content-Type', 'text/html; charset=UTF-8');

        return $response;
    }

    /**
     * Format a human-friendly retry time in the viewer's timezone ("14:07 · in 4 min" / "in 25 sec").
     *
     * @return array{time: string, wait: string}
     */
    public static function cooldownParts(int $retryAfterSeconds, ?User $user = null): array
    {
        $seconds = max(1, $retryAfterSeconds);
        $timezone = $user instanceof User ? $user->resolvedTimezone() : 'UTC';
        $at = CarbonImmutable::now('UTC')->addSeconds($seconds)->setTimezone($timezone);

        if ($seconds < 60) {
            $wait = trans_choice('errors.wait_seconds', $seconds, ['count' => $seconds]);
        } else {
            $minutes = (int) ceil($seconds / 60);
            $wait = trans_choice('errors.wait_minutes', $minutes, ['count' => $minutes]);
        }

        return [
            'time' => $at->format('H:i'),
            'wait' => $wait,
        ];
    }

    /** @param 'info'|'warning'|'error' $type */
    private static function flashBack(Request $request, string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);
        $status = in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true) ? 303 : 302;

        return redirect()->back(status: $status, fallback: route('sites.index'));
    }

    private static function resolveLocale(Request $request, int $status): void
    {
        $locale = ($status === 503 ? null : $request->attributes->get('locale'))
            ?? $request->cookie('locale')
            ?? $request->getPreferredLanguage([config()->string('app.locale'), 'en', 'de']);
        app()->setLocale(in_array($locale, ['en', 'de'], true) ? $locale : 'en');
    }

    private static function isSignedIn(Request $request, int $status): bool
    {
        // Never resolve a user or start a session on 401/500/503: DB outages and disabled accounts need zero-DB Blade.
        if (in_array($status, [401, 500, 503], true)) {
            return false;
        }
        $guard = Auth::guard('web');

        return $request->hasSession() && $request->session()->has($guard->getName());
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: ?string, 6: ?string, 7: ?int}
     */
    private static function details(
        int $status,
        Throwable $exception,
        Request $request,
        Response $response,
        bool $signedIn,
    ): array {
        $retryAfter = self::retryAfterFromHeaders($response->headers->all());

        if ($exception instanceof ExpectedRefusal) {
            $title = self::text($exception->titleKey ?? 'errors.'.$status.'.title');
            $description = $exception->translatedMessage();
            $recoveryUrl = $exception->recoveryRoute !== null
                ? route($exception->recoveryRoute, $exception->recoveryRouteParams)
                : ($signedIn ? route('sites.index') : route('login'));
            $recoveryLabel = self::text($exception->recoveryLabelKey ?? ($signedIn ? 'Go to Sites' : 'Sign in'));
            $secondaryUrl = $exception->recoveryRoute !== null && $signedIn ? route('sites.index') : null;
            $secondaryLabel = $secondaryUrl !== null ? self::text('Go to Sites') : null;

            return [$title, $description, $recoveryUrl, $recoveryLabel, $secondaryUrl, $secondaryLabel, $exception->errorCode, $exception->retryAfterSeconds ?? $retryAfter];
        }

        $title = self::text('errors.'.$status.'.title');
        $description = $status === 429
            ? self::rateLimitMessage($retryAfter)
            : self::text('errors.'.$status.'.description');

        [$recoveryUrl, $recoveryLabelKey] = match (true) {
            $status === 503 => [$request->fullUrl(), 'Try again'],
            $status === 401 => [route('login'), 'Sign in'],
            $status === 500 || ! $request->hasSession() => [route('sites.index'), 'Go to Sites'],
            $signedIn => [route('sites.index'), 'Go to Sites'],
            default => [route('login'), 'Sign in'],
        };

        $secondaryUrl = null;
        $secondaryLabel = null;
        if ($signedIn && $status === 404) {
            $secondaryUrl = route('incidents.index');
            $secondaryLabel = self::text('Open Incidents');
        }

        return [$title, $description, $recoveryUrl, self::text($recoveryLabelKey), $secondaryUrl, $secondaryLabel, null, $retryAfter];
    }

    private static function rateLimitMessage(?int $retryAfter): string
    {
        if ($retryAfter === null) {
            return self::text('errors.429.description');
        }

        $wait = $retryAfter < 60
            ? trans_choice('errors.wait_seconds', $retryAfter, ['count' => $retryAfter])
            : trans_choice('errors.wait_minutes', (int) ceil($retryAfter / 60), ['count' => (int) ceil($retryAfter / 60)]);

        return self::text('errors.429.retry_in', ['wait' => $wait]);
    }

    private static function isInertia(Request $request): bool
    {
        return $request->headers->has('X-Inertia');
    }

    /** @param array<mixed, mixed> $headers */
    private static function retryAfterFromHeaders(array $headers): ?int
    {
        $raw = $headers['Retry-After'] ?? $headers['retry-after'] ?? null;
        if (is_array($raw)) {
            $raw = $raw[0] ?? null;
        }
        if (is_numeric($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return null;
    }

    /** @param array<string, int|float|string> $params */
    private static function text(string $key, array $params = []): string
    {
        $translated = __($key, $params);

        return is_string($translated) ? $translated : $key;
    }
}
