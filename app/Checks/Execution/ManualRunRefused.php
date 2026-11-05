<?php

declare(strict_types=1);

namespace Heptapod\Checks\Execution;

use Illuminate\Http\JsonResponse;

final class ManualRunRefused extends \RuntimeException
{
    /** @param 'cooldown'|'queue_admission_limited'|'manual_run_budget' $reason */
    public function __construct(public readonly string $reason, public readonly int $retryAfterSeconds)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->reason, 'code' => $this->reason, 'details' => ['retry_after_seconds' => $this->retryAfterSeconds]], 429)
            ->header('Retry-After', (string) $this->retryAfterSeconds)->header('Cache-Control', 'private, no-store');
    }
}
