<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Closure;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware for /agent/*: switched off, every method answers 404 before routing; switched on, every refusal
 * (the controller's, the throttle's, the fallback's 404 for a wrong method) is one small JSON word, never an HTML page.
 */
final readonly class AgentResponses
{
    /** The agent reads only the status and Retry-After; the body never echoes input. */
    private const array Errors = [401 => 'unauthorized', 404 => 'not_found', 413 => 'too_large', 415 => 'unsupported_media_type',
        422 => 'invalid_report', 429 => 'too_many_reports'];

    public function __construct(private ServersAccess $access) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('agent', 'agent/*')) {
            return $next($request);
        }
        if (! $this->access->enabled()) {
            return self::error(404);
        }
        $response = $next($request);
        if ($response->getStatusCode() < 400) {
            return $response;
        }
        $retryAfter = $response->headers->get('Retry-After');

        return self::error($response->getStatusCode(), $retryAfter === null ? [] : ['Retry-After' => $retryAfter]);
    }

    /** @param array<string, string> $headers */
    public static function error(int $status, array $headers = []): JsonResponse
    {
        return response()->json(['error' => self::Errors[$status] ?? ($status >= 500 ? 'server_error' : 'refused')], $status,
            [...$headers, 'Cache-Control' => 'no-store, private']);
    }
}
