<?php

declare(strict_types=1);

namespace Heptapod\Http\Controllers\Api;

use Dedoc\Scramble\Attributes\Group;
use Heptapod\Checks\History\RollupReader;
use Heptapod\Contracts\CheckKey;
use Heptapod\Http\Controllers\Controller;
use Heptapod\Http\Requests\Api\MetricRangeRequest;
use Heptapod\Models\Site;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

#[Group('Uptime')]
final class UptimeMetricsController extends Controller
{
    public function __invoke(MetricRangeRequest $request, Site $monitor, RollupReader $reader, InstanceSettings $instance): JsonResponse
    {
        Gate::authorize('view', $monitor);
        $request->validateWindow(true);
        $query = $monitor->checks()->getQuery();
        $query->getQuery()->where('type', CheckKey::Uptime->value);
        $check = $query->firstOrFail();
        $period = $request->string('filter.group_by', 'hour')->toString();
        $buckets = $period === 'minute' ? $reader->recent($check->id, $request->start(true), $request->end(true)) : $reader->series($check->id, $period, $request->start(true), $request->end(true), $instance->timezone);
        $data = [];
        $timings = ['dns_time_in_seconds', 'tcp_time_in_seconds', 'ssl_handshake_time_in_seconds', 'remote_server_processing_time_in_seconds', 'download_time_in_seconds', 'total_time_in_seconds'];
        foreach ($buckets as $bucket) {
            $metrics = $bucket['metric_averages'];
            if (array_diff($timings, array_keys($metrics)) !== []) {
                continue;
            }
            foreach (['curl.header_size', 'curl.size_download', 'curl.speed_download'] as $name) {
                if (isset($metrics[$name])) {
                    $metrics[$name] = (int) round($metrics[$name], 0, \RoundingMode::HalfAwayFromZero);
                }
            }
            $data[] = [...Arr::undot($metrics), 'date' => $bucket['bucket_start']->format('Y-m-d H:i:s')];
        }

        return response()->json(['data' => $data]);
    }
}
