<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\IncidentCause;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\MetricRangeRequest;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class DowntimeController extends Controller
{
    public function __invoke(MetricRangeRequest $request, Site $monitor): JsonResponse
    {
        Gate::authorize('view', $monitor);
        $request->validateWindow();
        $query = $monitor->checks()->getQuery();
        $query->getQuery()->where('type', CheckKey::Uptime->value);
        $check = $query->firstOrFail();
        $query = $check->incidents()->getQuery();
        $query->getQuery()->where('cause', IncidentCause::Failed->value)->where('first_failure_at', '<', $request->end())
            ->where(fn (Builder $query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $request->start()))->orderByDesc('first_failure_at');
        $incidents = $query->get();

        return response()->json(['data' => $incidents->map(fn (Incident $incident): array => ['id' => $incident->id,
            'started_at' => $incident->first_failure_at->utc()->format('Y-m-d H:i:s'), 'ended_at' => $incident->resolved_at?->utc()->format('Y-m-d H:i:s'),
            'notes_markdown' => '', 'notes_html' => ''])->all()]);
    }
}
