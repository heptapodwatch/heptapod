<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Data\ServerThresholdsData;
use Heptapod\Addons\Servers\Server;
use Heptapod\Addons\Servers\ServerIssue;
use Heptapod\Addons\Servers\ServerIssueKind;
use Heptapod\Contracts\ServerIssueAlerts;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Opens and resolves server issues; every caller holds the server row FOR UPDATE, so one server is evaluated by one
 * transaction at a time. Disk (per watched mount), RAM and load open once every sample across their duration is at or
 * above the threshold, and only a fresh sample below it resolves them: an issue stays open while reports stop. A gap
 * longer than GapSeconds between two samples (a missed report) breaks a streak. "No report" opens on the scheduler's
 * pass once the newest report is older than the server's "No report after" and resolves with the next sample.
 *
 * @phpstan-type Metrics array{cpu: array{cores: int}, load: array{5: int|float}, memory: array{total_bytes: int, available_bytes: int}, filesystems: list<array{mount: string, total_bytes: int, free_bytes: int}>}
 */
final readonly class EvaluateServerIssues
{
    /** Samples further apart than this (one and a half report intervals) have a missed report between them. */
    public const int GapSeconds = 90;

    public function __construct(private ServerIssueAlerts $alerts) {}

    /**
     * After a stored sample (RecordServerReport, same transaction).
     *
     * @param  Metrics  $metrics
     */
    public function afterSample(Server $server, array $metrics, CarbonImmutable $observedAt): void
    {
        $open = $this->openIssues($server);
        $noReport = $open[ServerIssueKind::NoReport->value.':'] ?? null;
        if ($noReport instanceof ServerIssue) {
            $this->resolve($server, $noReport, $observedAt, ['reported_at' => $observedAt->utc()->toIso8601String()]);
        }
        $thresholds = ServerThresholdsData::of($server->settings);
        $ignored = $server->settings['ignored_mounts'] ?? [];
        $watched = [];
        foreach ($this->measures($metrics, $thresholds, $ignored) as $measure) {
            $key = $measure['kind']->value.':'.$measure['scope'];
            $watched[$key] = true;
            $issue = $open[$key] ?? null;
            $above = $measure['value'] >= $measure['threshold'];
            if ($issue instanceof ServerIssue) {
                if (! $above) {
                    $this->resolve($server, $issue, $observedAt, ['value' => round($measure['value'], 1)]);
                }

                continue;
            }
            if ($above && $this->heldFor($server, $observedAt, $measure['minutes'], $measure['kind'], $measure['scope'], $measure['threshold'])) {
                $this->open($server, $measure['kind'], $measure['scope'], $observedAt, ['value' => round($measure['value'], 1), 'threshold' => $measure['threshold'],
                    'minutes' => $measure['minutes'], ...$measure['extra']]);
            }
        }
        // A disk issue of a mount the report no longer has, or that is ignored now, cannot be measured below its threshold.
        foreach ($open as $key => $issue) {
            if ($issue->kind === ServerIssueKind::DiskFull && ! isset($watched[$key])) {
                $this->resolve($server, $issue, $observedAt, ['reason' => in_array($issue->scope, $ignored, true) ? 'mount_ignored' : 'mount_not_reported']);
            }
        }
    }

    /**
     * The scheduler's pass: "No report" for every server whose newest report is older than its "No report after", opened
     * at the moment it became due. Reads the servers through their row, so the servers table bounds it.
     */
    public function noReports(CarbonImmutable $now): void
    {
        $default = config()->integer('servers.thresholds.no_report_minutes');
        /** @var list<int> $due */
        $due = DB::table('servers')->whereNotNull('last_report_at')
            ->whereRaw("last_report_at < ?::timestamptz - make_interval(mins => coalesce((settings->>'no_report_minutes')::int, ?))", [$now->utc()->format('Y-m-d H:i:s.uP'), $default])
            ->whereNotExists(static fn (Builder $open): Builder => $open->from('server_issues')->whereColumn('server_id', 'servers.id')
                ->where('kind', ServerIssueKind::NoReport->value)->whereNull('resolved_at'))
            ->orderBy('id')->pluck('id')->all();
        foreach ($due as $serverId) {
            DB::transaction(function () use ($serverId, $now): void {
                $server = $this->lock($serverId);
                $last = $server?->last_report_at;
                if ($server === null || $last === null) {
                    return;
                }
                $minutes = ServerThresholdsData::of($server->settings)->no_report_minutes;
                // A report that committed meanwhile wins.
                if ($last->addMinutes($minutes)->greaterThanOrEqualTo($now)
                    || isset($this->openIssues($server)[ServerIssueKind::NoReport->value.':'])) {
                    return;
                }
                $this->open($server, ServerIssueKind::NoReport, '', $last->addMinutes($minutes), ['last_report_at' => $last->utc()->toIso8601String(), 'minutes' => $minutes]);
            });
        }
    }

    /**
     * Ends open issues without a recovery alert: a removed server, a mount that is ignored now. Called with the server
     * row locked.
     *
     * @param  ServerIssueKind|null  $kind  only issues of this kind
     * @param  list<string>|null  $scopes  only issues with these scopes
     */
    public function end(Server $server, string $reason, CarbonImmutable $at, ?ServerIssueKind $kind = null, ?array $scopes = null): void
    {
        foreach ($this->openIssues($server) as $issue) {
            if (($kind === null || $issue->kind === $kind) && ($scopes === null || in_array($issue->scope, $scopes, true))) {
                $this->resolve($server, $issue, $at, ['reason' => $reason], $reason);
            }
        }
    }

    /** Snooze set (an end) or ended (null): saved on the server, and its waiting openings follow. Called with the server row locked. */
    public function snooze(Server $server, ?CarbonImmutable $until): void
    {
        $server->forceFill(['snoozed_until' => $until])->save();
        $this->alerts->snoozeChanged(array_values(array_map(static fn (ServerIssue $issue): int => $issue->id, $this->openIssues($server))), $until);
    }

    public function lock(int $serverId): ?Server
    {
        $query = Server::query()->whereKey($serverId);
        $query->getQuery()->lockForUpdate();

        return $query->first();
    }

    /** @return array<string, ServerIssue> keyed by kind:scope */
    private function openIssues(Server $server): array
    {
        $issues = [];
        $query = ServerIssue::query()->where('server_id', $server->id);
        $query->getQuery()->whereNull('resolved_at')->orderBy('id');
        foreach ($query->get() as $issue) {
            $issues[$issue->kind->value.':'.$issue->scope] = $issue;
        }

        return $issues;
    }

    /**
     * The newest sample's values against the server's thresholds: each watched mount, RAM and load.
     *
     * @param  Metrics  $metrics
     * @param  list<string>  $ignored
     * @return list<array{kind: ServerIssueKind, scope: string, value: float, threshold: float|int, minutes: int, extra: array<string, int|float>}>
     */
    private function measures(array $metrics, ServerThresholdsData $thresholds, array $ignored): array
    {
        $measures = [];
        foreach ($metrics['filesystems'] as $filesystem) {
            if (in_array($filesystem['mount'], $ignored, true) || $filesystem['total_bytes'] === 0) {
                continue;
            }
            $custom = array_find($thresholds->mounts, static fn (array $mount): bool => $mount['mount'] === $filesystem['mount']);
            $measures[] = ['kind' => ServerIssueKind::DiskFull, 'scope' => $filesystem['mount'], 'value' => self::diskUsed($filesystem),
                'threshold' => $custom['percent'] ?? $thresholds->disk_percent, 'minutes' => $custom['minutes'] ?? $thresholds->disk_minutes, 'extra' => []];
        }
        if ($metrics['memory']['total_bytes'] > 0) {
            $measures[] = ['kind' => ServerIssueKind::MemoryLow, 'scope' => '', 'value' => self::memoryUsed($metrics),
                'threshold' => $thresholds->memory_percent, 'minutes' => $thresholds->memory_minutes, 'extra' => []];
        }
        $cores = $metrics['cpu']['cores'];
        $measures[] = ['kind' => ServerIssueKind::LoadHigh, 'scope' => '', 'value' => (float) $metrics['load'][5],
            'threshold' => round($thresholds->load_per_core * $cores, 2), 'minutes' => $thresholds->load_minutes,
            'extra' => ['per_core' => $thresholds->load_per_core, 'cores' => $cores]];

        return $measures;
    }

    /**
     * Whether the value stayed at or above the threshold in every sample of the last $minutes, without a missed report:
     * the server's samples of that window, newest first, through its (server_id, observed_at) index.
     */
    private function heldFor(Server $server, CarbonImmutable $observedAt, int $minutes, ServerIssueKind $kind, string $scope, float|int $threshold): bool
    {
        $since = $observedAt->subMinutes($minutes)->subSeconds(self::GapSeconds);
        $value = match ($kind) {
            ServerIssueKind::DiskFull => "(SELECT 100 - 100 * (f->>'free_bytes')::float8 / nullif((f->>'total_bytes')::float8, 0) FROM jsonb_array_elements(metrics->'filesystems') f WHERE f->>'mount' = ?)",
            ServerIssueKind::MemoryLow => "100 - 100 * (metrics->'memory'->>'available_bytes')::float8 / nullif((metrics->'memory'->>'total_bytes')::float8, 0)",
            ServerIssueKind::LoadHigh => "(metrics->'load'->>'5')::float8",
            ServerIssueKind::NoReport => throw new \LogicException('No report has no streak.'),
        };
        /** @var list<object{observed_at: string, value: float|null}> $samples */
        $samples = DB::select("SELECT observed_at, {$value} AS value FROM server_samples WHERE server_id = ? AND observed_at >= ? AND observed_at <= ?
            ORDER BY observed_at DESC", [...($kind === ServerIssueKind::DiskFull ? [$scope] : []), $server->id,
            $since->utc()->format('Y-m-d H:i:s.uP'), $observedAt->utc()->format('Y-m-d H:i:s.uP')]);
        $newer = $observedAt;
        foreach ($samples as $sample) {
            $at = CarbonImmutable::parse($sample->observed_at);
            if ($newer->diffInSeconds($at, true) > self::GapSeconds || $sample->value === null || $sample->value < $threshold) {
                return false;
            }
            if ($observedAt->diffInSeconds($at, true) >= $minutes * 60) {
                return true;
            }
            $newer = $at;
        }

        return false;
    }

    /** @param array<string, string|int|float> $values */
    private function open(Server $server, ServerIssueKind $kind, string $scope, CarbonImmutable $at, array $values): void
    {
        $issue = ServerIssue::query()->create(['server_id' => $server->id, 'kind' => $kind, 'scope' => $scope, 'opened_at' => $at, 'opened_values' => $values]);
        $this->alerts->opened($issue->id, $server->id, $server->name, $at, $this->facts($issue), $server->snoozed_until);
    }

    /** @param array<string, string|int|float> $values */
    private function resolve(Server $server, ServerIssue $issue, CarbonImmutable $at, array $values, ?string $silencedBy = null): void
    {
        $issue->update(['resolved_at' => $at, 'resolved_values' => $values]);
        // While snoozed an issue resolves without a recovery alert, as a snoozed check's events do.
        $snoozed = $server->snoozed_until !== null && $server->snoozed_until->isFuture();
        $this->alerts->resolved($issue->id, $server->id, $server->name, $at, $this->facts($issue), $silencedBy ?? ($snoozed ? 'snooze' : null));
    }

    /** @return array<string, string|int|float|null> what the alert is rendered from (ServerIssueTexts) */
    private function facts(ServerIssue $issue): array
    {
        $resolved = $issue->resolved_values ?? [];

        return ['kind' => $issue->kind->value, 'scope' => $issue->scope, 'opened_at' => $issue->opened_at->utc()->toIso8601String(), ...$issue->opened_values,
            ...($issue->resolved_at === null ? [] : ['resolved_at' => $issue->resolved_at->utc()->toIso8601String(),
                'resolved_value' => $resolved['value'] ?? null, 'resolution' => is_string($resolved['reason'] ?? null) ? $resolved['reason'] : 'recovered'])];
    }

    /** @param array{total_bytes: int, free_bytes: int} $filesystem */
    private static function diskUsed(array $filesystem): float
    {
        return 100 - 100 * $filesystem['free_bytes'] / $filesystem['total_bytes'];
    }

    /** @param Metrics $metrics */
    private static function memoryUsed(array $metrics): float
    {
        return 100 - 100 * $metrics['memory']['available_bytes'] / $metrics['memory']['total_bytes'];
    }
}
