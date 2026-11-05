<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Carbon\CarbonImmutable;
use Heptapod\Checks\History\RetentionPolicy;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\MetricRangeRequest;
use Heptapod\Models\Site;
use Heptapod\Queries\UptimeReader;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UptimeController extends Controller
{
    public function __invoke(MetricRangeRequest $request, Site $monitor, RollupReader $reader, UptimeReader $uptime, InstanceSettings $instance): JsonResponse
    {
        Gate::authorize('view', $monitor);
        $request->validateWindow();
        $query = $monitor->checks()->getQuery();
        $query->getQuery()->where('type', CheckKey::Uptime->value);
        $check = $query->firstOrFail();
        $split = $request->string('split', 'hour')->toString();
        $buckets = $reader->series($check->id, $split, $request->start(), $request->end(), $instance->timezone, startField: 'filter.started_at');
        $until = $request->end()->min(CarbonImmutable::now('UTC'));
        $windows = [];
        $selected = [];
        $historyDays = RetentionPolicy::fromConfig()->historyDays;
        $historyCutoff = CarbonImmutable::now('UTC')->subDays($historyDays);
        foreach ($buckets as $bucket) {
            $start = $bucket['bucket_start'];
            $bucketEnd = match ($split) {
                'hour' => $start->addHour(),
                'day' => $start->endOfHour()->setTimezone($instance->timezone)->startOfDay()->addDay()->utc()->startOfHour(),
                'month' => $start->endOfHour()->setTimezone($instance->timezone)->startOfMonth()->addMonth()->utc()->startOfHour(),
                default => throw new \LogicException('Validated uptime split must be hour, day or month.'),
            };
            $end = $bucketEnd->min($until);
            if ($start->lessThan($end)) {
                // Calendar splits can widen a valid raw start beyond retained incident history.
                if ($start->lessThan($historyCutoff)) {
                    throw ValidationException::withMessages(['filter.started_at' => __('api.history_retention', ['days' => $historyDays])]);
                }
                $windows[] = [$start, $end];
                $selected[] = $bucket;
            }
        }
        $percentages = $uptime->series([$check->id], $windows);
        $data = [];
        foreach ($selected as $index => $bucket) {
            $start = $bucket['bucket_start'];
            $percentage = $percentages[$index];
            if ($percentage !== null) {
                $data[] = ['datetime' => $start->format('Y-m-d H:i:s'), 'uptime_percentage' => $percentage];
            }
        }

        return response()->json($data);
    }
}
