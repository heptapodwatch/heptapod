<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http;

use Closure;
use Heptapod\Contracts\ServersAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware for /agent, a namespace reserved for agent traffic: switched off, every method answers 404 before
 * routing; switched on, every refusal (from the controller, the throttle or the web fallback route for a wrong method)
 * gets one small JSON word as its body and no-store, never a page. The framework's other headers (Retry-After) stay.
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
        // Nothing an agent receives is cacheable, accepted reports included.
        $response->headers->set('Cache-Control', 'no-store, private');
        if ($response->getStatusCode() < 400) {
            return $response;
        }
        $response->setContent(self::body($response->getStatusCode()));
        $response->headers->remove('Content-Length');
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    private static function body(int $status): string
    {
        return json_encode(['error' => self::Errors[$status] ?? ($status >= 500 ? 'server_error' : 'refused')], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $headers */
    public static function error(int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(self::body($status), $status, [...$headers, 'Cache-Control' => 'no-store, private'], json: true);
    }
}
