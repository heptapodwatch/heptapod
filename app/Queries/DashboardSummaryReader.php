<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Data\Ui\DashboardTilesData;
use Heptapod\Enums\SiteStatus;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class DashboardSummaryReader
{
    /** Tile column per site state. Every SiteStatus needs one: read() fails loud otherwise. */
    private const array STATE_COLUMNS = ['down' => SiteStatus::Down->value, 'check_errors' => SiteStatus::CheckError->value, 'warning' => SiteStatus::Warning->value,
        'pending' => SiteStatus::Pending->value, 'paused' => SiteStatus::Paused->value, 'ok' => SiteStatus::Ok->value, 'failed' => SiteStatus::Failed->value,
        'maintenance' => SiteStatus::Maintenance->value, 'disabled' => SiteStatus::Disabled->value, 'unavailable' => SiteStatus::Unavailable->value];

    public function __construct(private SitesReader $sites, private UptimeReader $uptime, private MonitorCountsReader $monitorCounts) {}

    /** @param list<int>|null $clientIds Null aggregates the instance; a list groups by client.
     * @param  array<string, int>|null  $siteStates  State counts from the initial sites projection, scoped to this user.
     * @return array<int, DashboardTilesData>
     */
    public function read(User $user, ?array $clientIds = null, bool $includeArchived = false, int $uptimeDays = 1, ?array $siteStates = null): array
    {
        $untiled = array_diff(array_map(static fn (SiteStatus $status): string => $status->value, SiteStatus::cases()), self::STATE_COLUMNS);
        if ($untiled !== []) {
            throw new \LogicException('Site states without a dashboard tile: '.implode(', ', $untiled));
        }
        $sites = $this->sites->projected($user, $includeArchived)->getQuery();
        if ($clientIds !== null) {
            $sites->whereIn('client_id', $clientIds);
        }
        $group = $clientIds === null ? '0::int' : 'client_id';
        $stateCounts = [];
        foreach (self::STATE_COLUMNS as $column => $state) {
            $stateCounts[] = "count(*) FILTER (WHERE state = '{$state}')::int AS {$column}";
        }
        $stateCountsSql = implode(', ', $stateCounts);
        $statesSql = <<<SQL
            SELECT {$group} AS client_id, count(*)::int AS sites, {$stateCountsSql}
            FROM visible GROUP BY {$group}
            SQL;
        if ($siteStates !== null) {
            if ($clientIds !== null || $includeArchived) {
                throw new \LogicException('Sites index counts only aggregate active visible clients.');
            }
            // Selecting identifiers alone lets PostgreSQL discard the unused status expression.
            $sites->select('sites.id', 'sites.client_id');
            $columns = ['sites' => 'total', ...self::STATE_COLUMNS];
            $expressions = ['0::int AS client_id'];
            foreach ($columns as $column => $state) {
                $expressions[] = ($siteStates[$state] ?? throw new \LogicException("Sites index counts lack state {$state}.")).'::int AS '.$column;
            }
            $statesSql = 'SELECT '.implode(', ', $expressions);
        }
        $sql = $sites->toSql();
        $held = UptimeDependencies::heldAfterRecoverySql('incidents');
        /** @var list<object{client_id: int, sites: int, down: int, check_errors: int, warning: int, pending: int, paused: int, ok: int, failed: int, maintenance: int, disabled: int, unavailable: int, open_incidents: int, certificates_expiring: int, cron_jobs_failing: int}> $rows */
        $rows = DB::select(<<<SQL
            WITH visible AS MATERIALIZED ({$sql}), states AS (
                {$statesSql}
            ), incidents AS (
                SELECT {$group} AS client_id, count(*)::int AS total
                FROM incidents JOIN visible ON visible.id = incidents.site_id WHERE resolved_at IS NULL AND NOT ({$held}) GROUP BY {$group}
            ), checks_summary AS (
                SELECT {$group} AS client_id,
                    count(*) FILTER (WHERE checks.enabled AND checks.type = 'certificate_health' AND latest_run_result = 'warning')::int AS expiring,
                    coalesce(sum((check_runs.metrics->>'failed')::int) FILTER (WHERE checks.enabled AND checks.type = 'cron'), 0)::int AS failing
                FROM checks JOIN visible ON visible.id = checks.site_id
                LEFT JOIN check_runs ON check_runs.id = checks.latest_run_id AND check_runs.ended_at = checks.latest_run_ended_at
                GROUP BY {$group}
            ) SELECT states.*, coalesce(incidents.total, 0)::int AS open_incidents,
                coalesce(checks_summary.expiring, 0)::int AS certificates_expiring, coalesce(checks_summary.failing, 0)::int AS cron_jobs_failing
                FROM states LEFT JOIN incidents USING (client_id) LEFT JOIN checks_summary USING (client_id)
            SQL, $sites->getBindings());
        $ids = clone $sites;
        $ids->select('sites.id');
        /** @var list<int> $checkIds */
        $checkIds = Check::query()->getQuery()->whereIn('site_id', $ids)->where('type', CheckKey::Uptime)->pluck('id')->all();
        $until = CarbonImmutable::now('UTC');
        $checkClientIds = $clientIds === null ? [] : DB::table('checks')->join('sites', 'sites.id', '=', 'checks.site_id')->whereIn('checks.id', $checkIds)->pluck('sites.client_id', 'checks.id')->all();
        $durations = $this->uptime->durations($checkIds, $until->subDays($uptimeDays), $until);
        $totals = [];
        foreach ($durations as $checkId => [$monitored, $down]) {
            $clientId = $clientIds === null ? 0 : ($checkClientIds[$checkId] ?? throw new \LogicException('Uptime check must belong to a client.'));
            if (! is_int($clientId)) {
                throw new \LogicException('Uptime client ID must be an integer.');
            }
            $totals[$clientId][0] = ($totals[$clientId][0] ?? 0) + $monitored;
            $totals[$clientId][1] = ($totals[$clientId][1] ?? 0) + $down;
        }
        $monitor = $clientIds === null ? $this->monitorCounts->read($user) : null;
        $summaries = [];
        foreach ($clientIds ?? [0] as $id) {
            $summaries[$id] = new DashboardTilesData(0, 0, 0, 0, 0, 0, 0, null, 0, 0, 0, 0, 0, 0, 0,
                $monitor?->certificates, $monitor?->domains, $monitor?->scheduledTasks, $monitor?->overduePings);
        }
        foreach ($rows as $row) {
            $tiled = 0;
            foreach (array_keys(self::STATE_COLUMNS) as $column) {
                $tiled += $row->{$column};
            }
            if ($tiled !== $row->sites) {
                throw new \LogicException("Dashboard tiles count {$tiled} of {$row->sites} sites.");
            }
            [$monitored, $down] = $totals[$row->client_id] ?? [0, 0];
            $uptime = $monitored === 0 ? null : 100 * (1 - $down / $monitored);
            $summaries[$row->client_id] = new DashboardTilesData($row->sites, $row->down, $row->check_errors, $row->warning, $row->pending, $row->paused,
                $row->open_incidents, $uptime, $row->certificates_expiring, $row->cron_jobs_failing,
                $row->ok, $row->failed, $row->maintenance, $row->disabled, $row->unavailable,
                $monitor?->certificates, $monitor?->domains, $monitor?->scheduledTasks, $monitor?->overduePings);
        }

        return $summaries;
    }
}
