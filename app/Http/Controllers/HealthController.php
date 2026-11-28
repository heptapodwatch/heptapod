<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers;

use Carbon\CarbonImmutable;
use Heptapod\SystemHealth\WebHeartbeat;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class HealthController
{
    public function __invoke(Request $request, WebHeartbeat $heartbeat): Response|JsonResponse
    {
        // Bookkeeping, not readiness: a failure is reported, never an unhealthy /up.
        try {
            $heartbeat->record(CarbonImmutable::now('UTC'), app()->isDownForMaintenance());
        } catch (Throwable $exception) {
            report($exception);
        }
        $healthy = true;

        try {
            event(new DiagnosingHealth);
        } catch (Throwable $exception) {
            if (app()->hasDebugModeEnabled()) {
                throw $exception;
            }

            report($exception);
            $healthy = false;
        }

        $status = $healthy ? 200 : 500;
        $headers = [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($request->expectsJson()) {
            return response()->json(['status' => $healthy ? 'up' : 'down'], $status, $headers);
        }

        return response(
            $healthy ? 'OK' : 'ERROR',
            $status,
            ['Content-Type' => 'text/plain; charset=UTF-8', ...$headers],
        );
    }
}
