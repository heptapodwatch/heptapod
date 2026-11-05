<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\EngineMessage;
use Heptapod\Contracts\OhDear\CheckSummaryData;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CheckSummaryController extends Controller
{
    public function __invoke(Site $monitor, string $checkType): JsonResponse
    {
        Gate::authorize('view', $monitor);
        $query = $monitor->checks()->getQuery();
        $query->getQuery()->where('type', $checkType);
        $check = $query->firstOrFail();

        return response()->json(new CheckSummaryData($check->latest_run_result === CheckResult::Pending ? null : $check->latest_run_result, $check->summary === null ? __('checks.pending') : __(...array_values(EngineMessage::normalize($check->summary, $check->summary_params ?? [])))));
    }
}
