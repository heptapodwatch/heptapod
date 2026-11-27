<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\ServerValuesData;
use Illuminate\Support\Facades\DB;

final readonly class ServerReader
{
    /** Up to this span the chart shows one point per minute, beyond it one per hour (at most 336 over 14 days). */
    private const int MinuteBucketsUpToSeconds = 6 * 3600;

    /**
     * Every server with its newest sample, or the one server; the newest sample is one index probe per server.
     *
     * @return list<ServerRowData>
     */
    public function rows(CarbonImmutable $now, ?int $serverId = null): array
    {
        /** @var list<object{id: int, name: string, last_report_at: string|null, agent_version: string|null, observed_at: string|null, metrics: string|null}> $rows */
        $rows = DB::select('SELECT servers.id, servers.name, servers.last_report_at, servers.agent_version, latest.observed_at, latest.metrics
            FROM servers LEFT JOIN LATERAL (SELECT observed_at, metrics FROM server_samples WHERE server_id = servers.id
                ORDER BY observed_at DESC LIMIT 1) latest ON true
            WHERE ?::bigint IS NULL OR servers.id = ? ORDER BY lower(servers.name), servers.id', [$serverId, $serverId]);

        return array_map(static function (object $row) use ($now): ServerRowData {
            $lastReportAt = $row->last_report_at === null ? null : CarbonImmutable::parse($row->last_report_at);

            return new ServerRowData($row->id, $row->name, ServerState::of($lastReportAt, $now), $lastReportAt?->toIso8601String(), $row->agent_version,
                $row->observed_at === null || $row->metrics === null ? null : ServerValuesData::fromSample($row->observed_at, $row->metrics));
        }, $rows);
    }

    /**
     * The peak per minute or hour of the server's raw samples inside the retention window, read through its
     * (server_id, observed_at) index.
     *
     * @return array{step_seconds: int, points: list<array{at: string, cpu: float, load: float, memory: float|null, disks: array<string, float>}>}
     */
    public function history(int $serverId, CarbonImmutable $now): array
    {
        $since = $now->subDays(config()->integer('servers.retention_days'))->utc()->format('Y-m-d H:i:s.uP');
        $first = DB::table('server_samples')->where('server_id', $serverId)->where('observed_at', '>=', $since)->min('observed_at');
        if (! is_string($first)) {
            return ['step_seconds' => 60, 'points' => []];
        }
        $minutes = CarbonImmutable::parse($first)->diffInSeconds($now) <= self::MinuteBucketsUpToSeconds;
        $unit = $minutes ? 'minute' : 'hour';
        $bucket = "date_trunc('{$unit}', observed_at, 'UTC')";
        /** @var list<object{bucket: string, cpu: float, load: float, memory: float|null}> $values */
        $values = DB::select("SELECT {$bucket} AS bucket, max((metrics->'cpu'->>'used_percent')::float8) AS cpu,
                max((metrics->'load'->>'5')::float8) AS load,
                max(100 - 100 * (metrics->'memory'->>'available_bytes')::float8 / nullif((metrics->'memory'->>'total_bytes')::float8, 0)) AS memory
            FROM server_samples WHERE server_id = ? AND observed_at >= ? GROUP BY 1 ORDER BY 1", [$serverId, $since]);
        /** @var list<object{bucket: string, mount: string, used: float|null}> $disks */
        $disks = DB::select("SELECT {$bucket} AS bucket, filesystem->>'mount' AS mount,
                max(100 - 100 * (filesystem->>'free_bytes')::float8 / nullif((filesystem->>'total_bytes')::float8, 0)) AS used
            FROM server_samples CROSS JOIN LATERAL jsonb_array_elements(metrics->'filesystems') AS filesystem
            WHERE server_id = ? AND observed_at >= ? GROUP BY 1, 2", [$serverId, $since]);
        $used = [];
        foreach ($disks as $disk) {
            if ($disk->used !== null) {
                $used[$disk->bucket][$disk->mount] = $disk->used;
            }
        }

        return ['step_seconds' => $minutes ? 60 : 3600, 'points' => array_map(static fn (object $value): array => [
            'at' => CarbonImmutable::parse($value->bucket)->toIso8601String(), 'cpu' => $value->cpu, 'load' => $value->load,
            'memory' => $value->memory, 'disks' => $used[$value->bucket] ?? [],
        ], $values)];
    }
}
