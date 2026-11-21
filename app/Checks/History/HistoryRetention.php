<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\Notifications\DeliveryState;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class HistoryRetention
{
    private const int HourlyWindowSize = 10000;

    /** @return array{deleted: int, uncovered: int} */
    public function pruneHourly(CarbonImmutable $at, RetentionPolicy $policy): array
    {
        $cutoff = $at->utc()->subDays($policy->hourlyDays)->format('Y-m-d H:i:s.uP');
        if (! $policy->hourlyDeletionEnabled) {
            return ['deleted' => 0, 'uncovered' => $this->summarizeExpiredHourly($cutoff)['uncovered']];
        }

        $deleted = 0;
        $uncovered = 0;
        $afterBucketStart = null;
        $afterId = null;
        $hasMore = false;

        while ($deleted < RetentionPolicy::PerTableCap) {
            $rows = $this->readHourlyWindow($cutoff, $afterBucketStart, $afterId);
            if ($rows === []) {
                $hasMore = false;

                break;
            }

            $coveredIds = [];
            foreach ($rows as $row) {
                if ($row->covered) {
                    $coveredIds[] = $row->id;
                } else {
                    $uncovered++;
                }
            }

            $last = array_last($rows);
            $afterBucketStart = $last->bucket_start;
            $afterId = $last->id;
            $hasMore = count($rows) === self::HourlyWindowSize;

            if ($coveredIds !== []) {
                $toDelete = array_slice($coveredIds, 0, RetentionPolicy::PerTableCap - $deleted);
                // Run each batch delete inside a savepoint/transaction so a failure rolls back cleanly before
                // CheckDataMaintenance unlocks its advisory lock instead of masking the error with 25P02.
                $deleted += DB::transaction(static fn (): int => DB::affectingStatement(
                    'DELETE FROM check_run_rollups_hourly WHERE id = ANY(?::bigint[])',
                    ['{'.implode(',', $toDelete).'}'],
                ));
            }

            if (! $hasMore) {
                break;
            }
        }

        if ($hasMore) {
            $uncovered += $this->summarizeExpiredHourly($cutoff, $afterBucketStart, $afterId)['uncovered'];
        }

        return ['deleted' => $deleted, 'uncovered' => $uncovered];
    }

    /** @return list<array{table: string, eligible: int, oldest: ?string, capped: int, cap: ?int, exemptions: string, deletion_enabled: bool}> */
    public function report(CarbonImmutable $at, RetentionPolicy $policy): array
    {
        $cutoff = $at->utc()->subDays($policy->historyDays);
        $incidents = DB::table('incidents as incident')->where('incident.resolved_at', '<', $cutoff)
            ->whereNotExists(function (Builder $query): void {
                $query->selectRaw('1')->from('incidents as open')->whereNull('open.resolved_at')
                    ->whereColumn('open.check_id', 'incident.check_id')->whereColumn('open.alert_episode_started_at', 'incident.alert_episode_started_at');
            });
        $keptIncident = DB::table('incidents')->select('incidents.id')->whereNotIn('incidents.id', (clone $incidents)->select('incident.id'));
        $oldEvents = DB::table('events as event')->where('event.occurred_at', '<', $cutoff);
        $keptIncidentEvents = (clone $oldEvents)->whereIn('event.incident_id', $keptIncident);
        $pendingEvents = (clone $oldEvents)->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('deliveries')->whereIn('state', [DeliveryState::Pending->value, DeliveryState::Sending->value])
                ->where(function (Builder $query): void {
                    $query->whereColumn('deliveries.event_id', 'event.id')->orWhereColumn('deliveries.digest_event_id', 'event.id');
                });
        });
        // Retain digests with source deliveries so deleting a digest cannot cascade into a kept event's delivery.
        $digestEvents = (clone $oldEvents)->whereExists(function (Builder $query): void {
            $query->selectRaw('1')->from('deliveries')->whereColumn('deliveries.digest_event_id', 'event.id');
        });
        $events = (clone $oldEvents)->whereNotIn('event.id', (clone $keptIncidentEvents)->select('event.id'))
            ->whereNotIn('event.id', (clone $pendingEvents)->select('event.id'))
            ->whereNotIn('event.id', (clone $digestEvents)->select('event.id'));
        $deliveries = DB::table('deliveries')->whereIn('event_id', (clone $events)->select('event.id'));
        $exemptDeliveries = DB::table('deliveries')->whereIn('event_id', (clone $oldEvents)->select('event.id'))->count() - $deliveries->count();
        $hourly = $this->summarizeExpiredHourly($at->utc()->subDays($policy->hourlyDays)->format('Y-m-d H:i:s.uP'));
        $open = DB::table('incidents')->whereNull('resolved_at')->count();
        $episode = DB::table('incidents')->where('resolved_at', '<', $cutoff)->count() - $incidents->count();

        return [
            [
                'table' => 'check_run_rollups_hourly',
                'eligible' => $hourly['eligible'],
                'oldest' => $hourly['oldest'],
                'capped' => min($hourly['eligible'], RetentionPolicy::PerTableCap),
                'cap' => RetentionPolicy::PerTableCap,
                'exemptions' => 'daily coverage missing='.$hourly['uncovered'],
                'deletion_enabled' => $policy->hourlyDeletionEnabled,
            ],
            $this->reportRow('check_run_rollups_daily', DB::table('check_run_rollups_daily')->where('bucket_start', '<', $at->utc()->subDays($policy->dailyDays)), 'bucket_start'),
            $this->reportRow('events', $events, 'event.occurred_at', 'kept incident='.$keptIncidentEvents->count().'; pending/sending='.$pendingEvents->count().'; digest sources retained='.$digestEvents->count()),
            $this->reportRow('deliveries', $deliveries->join('events', 'events.id', '=', 'deliveries.event_id'), 'events.occurred_at', 'retained with exempt events='.$exemptDeliveries),
            $this->reportRow('incidents', $incidents, 'incident.resolved_at', 'open='.$open.'; open episode='.$episode),
            $this->reportRow('activity_log', DB::table('activity_log')->where('created_at', '<', $cutoff), 'created_at', deletionEnabled: true, capped: false),
            $this->reportRow('failed_jobs', DB::table('failed_jobs')->where('failed_at', '<', $at->utc()->subDays($policy->failedJobDays)), 'failed_at', deletionEnabled: true, capped: false),
            $this->reportRow('job_batches', DB::table('job_batches')->whereNotNull('finished_at')->where('finished_at', '<', $at->subDays($policy->failedJobDays)->getTimestamp()), 'finished_at'),
        ];
    }

    /**
     * Uncapped tables are deleted by the scheduled framework/Spatie prune commands, which remove every expired row per run.
     *
     * @return array{table: string, eligible: int, oldest: ?string, capped: int, cap: ?int, exemptions: string, deletion_enabled: bool}
     */
    private function reportRow(string $table, Builder $query, string $timestamp, string $exemptions = '', bool $deletionEnabled = false, bool $capped = true): array
    {
        $count = (clone $query)->count();
        /** @var int|string|null $oldest */
        $oldest = (clone $query)->min($timestamp);
        if (is_int($oldest)) {
            $oldest = CarbonImmutable::createFromTimestampUTC($oldest)->toIso8601String();
        }

        return ['table' => $table, 'eligible' => $count, 'oldest' => $oldest,
            'capped' => $capped ? min($count, RetentionPolicy::PerTableCap) : $count, 'cap' => $capped ? RetentionPolicy::PerTableCap : null, 'exemptions' => $exemptions, 'deletion_enabled' => $deletionEnabled];
    }

    /**
     * Classifies at most HourlyWindowSize rows per (bucket_start, id) cursor window using distinct daily keys.
     *
     * @return list<object{id: int, bucket_start: string, covered: bool}>
     */
    private function readHourlyWindow(string $cutoff, ?string $afterBucketStart, ?int $afterId): array
    {
        ['sql' => $windowQuery, 'bindings' => $bindings] = $this->hourlyWindowQuery($cutoff, $afterBucketStart, $afterId);

        /** @var list<object{id: int, bucket_start: string, covered: bool}> $rows */
        $rows = DB::select(<<<SQL
            {$windowQuery}
            SELECT w.id, w.bucket_start, (c.check_id IS NOT NULL) AS covered
            FROM window_rows w
            LEFT JOIN covered_days c
              ON c.check_id = w.check_id AND c.day_start = w.day_start
            ORDER BY w.bucket_start, w.id
            SQL, $bindings);

        return $rows;
    }

    /**
     * Summarizes expired hourly rows across bounded cursor windows; best-effort under READ COMMITTED while maintenance runs.
     *
     * @return array{eligible: int, uncovered: int, oldest: ?string}
     */
    private function summarizeExpiredHourly(string $cutoff, ?string $afterBucketStart = null, ?int $afterId = null): array
    {
        $eligible = 0;
        $uncovered = 0;
        $oldest = null;

        do {
            ['sql' => $windowQuery, 'bindings' => $bindings] = $this->hourlyWindowQuery($cutoff, $afterBucketStart, $afterId);
            /** @var object{scanned: int, eligible: int, uncovered: int, oldest_covered: ?string, last_bucket_start: ?string, last_id: ?int} $window */
            $window = DB::selectOne(<<<SQL
                {$windowQuery},
                last_row AS MATERIALIZED (
                    SELECT bucket_start, id FROM window_rows ORDER BY bucket_start DESC, id DESC LIMIT 1
                )
                SELECT
                    count(*)::int AS scanned,
                    count(*) FILTER (WHERE c.check_id IS NOT NULL)::int AS eligible,
                    count(*) FILTER (WHERE c.check_id IS NULL)::int AS uncovered,
                    min(w.bucket_start) FILTER (WHERE c.check_id IS NOT NULL) AS oldest_covered,
                    (SELECT bucket_start FROM last_row) AS last_bucket_start,
                    (SELECT id FROM last_row) AS last_id
                FROM window_rows w
                LEFT JOIN covered_days c
                  ON c.check_id = w.check_id AND c.day_start = w.day_start
                SQL, $bindings);
            $eligible += $window->eligible;
            $uncovered += $window->uncovered;
            if ($oldest === null && $window->oldest_covered !== null) {
                $oldest = $window->oldest_covered;
            }
            $afterBucketStart = $window->last_bucket_start;
            $afterId = $window->last_id;
        } while ($window->scanned === self::HourlyWindowSize);

        return ['eligible' => $eligible, 'uncovered' => $uncovered, 'oldest' => $oldest];
    }

    /** @return array{sql: string, bindings: list<int|string>} */
    private function hourlyWindowQuery(string $cutoff, ?string $afterBucketStart, ?int $afterId): array
    {
        if ($afterBucketStart !== null && $afterId !== null) {
            $predicate = 'WHERE bucket_start >= ?::timestamptz AND bucket_start < ?::timestamptz AND (bucket_start > ?::timestamptz OR id > ?)';
            $bindings = [$afterBucketStart, $cutoff, $afterBucketStart, $afterId, self::HourlyWindowSize];
        } else {
            $predicate = 'WHERE bucket_start < ?::timestamptz';
            $bindings = [$cutoff, self::HourlyWindowSize];
        }

        $sql = <<<SQL
            WITH window_rows AS MATERIALIZED (
                SELECT id, check_id, bucket_start, date_trunc('day', bucket_start, 'UTC') AS day_start
                FROM check_run_rollups_hourly
                {$predicate}
                ORDER BY bucket_start, id
                LIMIT ?
            ),
            days AS MATERIALIZED (
                SELECT DISTINCT check_id, day_start FROM window_rows
            ),
            covered_days AS MATERIALIZED (
                SELECT d.check_id, d.day_start
                FROM days d
                JOIN LATERAL (
                    SELECT 1
                    FROM check_run_rollups_daily daily
                    WHERE daily.check_id = d.check_id
                      AND daily.bucket_start = d.day_start
                      AND daily.computed_at >= daily.bucket_start + interval '1 day 10 minutes'
                    LIMIT 1
                ) cov ON true
            )
            SQL;

        return ['sql' => $sql, 'bindings' => $bindings];
    }
}
