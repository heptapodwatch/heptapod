<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\ServerThresholdsData;
use Heptapod\Addons\Servers\Data\ServerValuesData;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ServerReader
{
    /** Up to this span the chart shows one point per minute, beyond it one per hour (at most 336 over 14 days). */
    private const int MinuteBucketsUpToSeconds = 6 * 3600;

    /**
     * The list's sort keys and their SQL order; `-key` reverses one. The first click puts what needs attention on top:
     * status No report, Awaiting, Reporting; disk (the fullest shown mount), RAM and load per vCPU highest first; last
     * report oldest first. Servers without the value come last either way; ties go by name.
     */
    public const array SortKeys = [
        'name' => 'lower(name)',
        'status' => "CASE state WHEN 'no_report' THEN 0 WHEN 'awaiting' THEN 1 ELSE 2 END",
        'disk' => '-disk_used',
        'memory' => '-memory_used',
        'load' => '-load_per_core',
        'last_report' => 'last_report_at',
    ];

    public function __construct(private ListPaging $paging) {}

    /**
     * One page of the list, filtered and sorted in SQL over every server with its newest sample (one index probe each).
     *
     * @param  array{search?: string|null, state?: string|null}  $filters
     * @return array{servers: list<ServerRowData>, pagination: PaginationData}
     */
    public function page(CarbonImmutable $now, array $filters, string $sort): array
    {
        $query = DB::query()->fromSub($this->query($now), 'servers');
        $search = $filters['search'] ?? null;
        if ($search !== null && $search !== '') {
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(static fn (Builder $where): Builder => $where->where('name', 'ilike', $pattern)->orWhere('hostname', 'ilike', $pattern));
        }
        if (($filters['state'] ?? null) !== null) {
            $query->where('state', $filters['state']);
        }
        $descending = str_starts_with($sort, '-');
        // A leading minus on a numeric expression puts the highest first in the ascending order.
        $query->orderByRaw(self::SortKeys[ltrim($sort, '-')].($descending ? ' DESC' : ' ASC').' NULLS LAST')->orderByRaw('lower(name)')->orderBy('id');
        $page = $this->paging->paginate($query, 'servers');
        // A page beyond the end (a server removed meanwhile, an old link) shows the last page.
        if ($page->currentPage() > $page->lastPage()) {
            $page = $this->paging->paginate($query, 'servers', $page->lastPage());
        }

        /** @var list<object> $items */
        $items = array_values($page->items());

        return ['servers' => array_map($this->row(...), $items), 'pagination' => PaginationData::fromPaginator($page)];
    }

    /** The one server, with its newest sample. */
    public function server(CarbonImmutable $now, int $serverId): ServerRowData
    {
        return $this->row($this->query($now)->where('servers.id', $serverId)->sole());
    }

    /** The server the site runs on; null while it is not linked. */
    public function ofSite(CarbonImmutable $now, int $siteId): ?ServerRowData
    {
        $row = $this->query($now)->whereRaw('servers.id = (SELECT server_id FROM server_sites WHERE site_id = ?)', [$siteId])->first();

        return $row === null ? null : $this->row($row);
    }

    /**
     * The sites linked to the server, oldest link first.
     *
     * @return list<int>
     */
    public function linkedSiteIds(int $serverId): array
    {
        /** @var list<int> */
        return DB::table('server_sites')->where('server_id', $serverId)->orderBy('created_at')->orderBy('site_id')->pluck('site_id')->all();
    }

    /**
     * Every server with its newest sample, its state and the values the list sorts by. "No report" once the newest
     * report is older than the server's "No report after".
     */
    private function query(CarbonImmutable $now): Builder
    {
        $latest = DB::table('server_samples')->select(['observed_at', 'metrics'])->whereColumn('server_id', 'servers.id')
            ->orderByDesc('observed_at')->limit(1);

        return DB::table('servers')->leftJoinLateral($latest, 'latest')
            ->select(['servers.id', 'servers.name', 'servers.last_report_at', 'servers.agent_version', 'servers.settings', 'servers.hostname',
                'servers.agent_os', 'servers.agent_arch', 'servers.agent_distro', 'servers.agent_kernel', 'servers.ips', 'latest.observed_at', 'latest.metrics'])
            ->selectRaw('(SELECT count(*) FROM server_sites WHERE server_id = servers.id) AS site_count')
            ->selectRaw("CASE WHEN servers.last_report_at IS NULL THEN 'awaiting'
                WHEN servers.last_report_at < ?::timestamptz - make_interval(mins => coalesce((servers.settings->>'no_report_minutes')::int, ?)) THEN 'no_report'
                ELSE 'reporting' END AS state", [$now->utc()->format('Y-m-d H:i:s.uP'), config()->integer('servers.thresholds.no_report_minutes')])
            ->selectRaw("(SELECT max(100 - 100 * (f->>'free_bytes')::float8 / nullif((f->>'total_bytes')::float8, 0))
                FROM jsonb_array_elements(latest.metrics->'filesystems') f
                WHERE NOT jsonb_exists(coalesce(servers.settings->'ignored_mounts', '[]'::jsonb), f->>'mount')) AS disk_used")
            ->selectRaw("100 - 100 * (latest.metrics->'memory'->>'available_bytes')::float8 / nullif((latest.metrics->'memory'->>'total_bytes')::float8, 0) AS memory_used")
            ->selectRaw("(latest.metrics->'load'->>'5')::float8 / nullif((latest.metrics->'cpu'->>'cores')::float8, 0) AS load_per_core");
    }

    private function row(object $row): ServerRowData
    {
        /** @var object{id: int, name: string, last_report_at: string|null, agent_version: string|null, settings: string, site_count: int, hostname: string|null, agent_os: string|null, agent_arch: string|null, agent_distro: string|null, agent_kernel: string|null, ips: string|null, observed_at: string|null, metrics: string|null, state: string} $row */
        /** @var array{mounts?: list<array{mount: string, percent?: int, minutes?: int}>, memory_percent?: int, memory_minutes?: int, load_per_core?: int|float, load_minutes?: int, no_report_minutes?: int, ignored_mounts?: list<string>} $settings */
        $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
        $ignored = $settings['ignored_mounts'] ?? [];
        /** @var list<string> $ips */
        $ips = $row->ips === null ? [] : json_decode($row->ips, true, flags: JSON_THROW_ON_ERROR);

        return new ServerRowData($row->id, $row->name, ServerState::from($row->state),
            $row->last_report_at === null ? null : CarbonImmutable::parse($row->last_report_at)->toIso8601String(), $row->agent_version,
            $row->observed_at === null || $row->metrics === null ? null : ServerValuesData::fromSample($row->observed_at, $row->metrics, $ignored),
            ServerThresholdsData::of($settings), $ignored, $row->site_count, $row->hostname, $row->agent_os, $row->agent_arch, $row->agent_distro,
            $row->agent_kernel, $ips);
    }

    /**
     * The peak per minute or hour of the server's raw samples inside the retention window, read through its
     * (server_id, observed_at) index. The ignored mounts are left out.
     *
     * @param  list<string>  $ignoredMounts
     * @return array{step_seconds: int, points: list<array{at: string, cpu: float, load: float, memory: float|null, disks: array<string, float>}>}
     */
    public function history(int $serverId, array $ignoredMounts, CarbonImmutable $now): array
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
            if ($disk->used !== null && ! in_array($disk->mount, $ignoredMounts, true)) {
                $used[$disk->bucket][$disk->mount] = $disk->used;
            }
        }

        return ['step_seconds' => $minutes ? 60 : 3600, 'points' => array_map(static fn (object $value): array => [
            'at' => CarbonImmutable::parse($value->bucket)->toIso8601String(), 'cpu' => $value->cpu, 'load' => $value->load,
            'memory' => $value->memory, 'disks' => $used[$value->bucket] ?? [],
        ], $values)];
    }
}
