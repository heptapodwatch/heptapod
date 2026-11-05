<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\SystemHealth\CheckDataMaintenanceRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class CheckDataMaintenance
{
    /**
     * Upper bound of check runs one rollup statement reads. Expanding metrics with jsonb_each per row costs
     * backend memory that PostgreSQL only frees at statement end (about 280 bytes per JSON leaf), so a whole
     * window in one statement grows with volume until the backend is OOM-killed. A single (check, bucket)
     * group is never split, because its p95 needs every sample.
     */
    private const int RunsPerRollupStatement = 5000;

    public function __construct(private CheckRegistry $registry, private AttemptHistoryProjection $history, private CheckDataMaintenanceRecord $record, private HistoryRetention $retention) {}

    /** @return list<array{partition: string, missing_buckets: int, command: string}> */
    public function maintain(CarbonImmutable $at, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): array
    {
        $policy = RetentionPolicy::fromConfig();
        if ((! $from instanceof CarbonImmutable) !== (! $until instanceof CarbonImmutable) || ($from instanceof CarbonImmutable && $until instanceof CarbonImmutable && $from->greaterThanOrEqualTo($until))) {
            throw new InvalidArgumentException('Manual recomputation requires both bounds of a nonempty ordered window.');
        }

        // The session lock serializes maintenance only; partition creation commits before raw scans.
        // The outcome is recorded before the unlock, so overlapping runs count consecutive failures in run order.
        DB::select('SELECT pg_advisory_lock(73410201)');
        try {
            $started = hrtime(true);
            try {
                $skipped = $this->maintainLocked($at, $from, $until, $policy);
            } catch (Throwable $exception) {
                $failures = $this->record->recordFailure($at);
                Log::error('Check data maintenance failed.', ['consecutive_failures' => $failures, 'exception' => $exception->getMessage()]);

                throw $exception;
            }
            $this->record->recordSuccess($at, (hrtime(true) - $started) / 1e9);

            return $skipped;
        } finally {
            DB::select('SELECT pg_advisory_unlock(73410201)');
        }
    }

    /** @return list<array{partition: string, missing_buckets: int, command: string}> */
    private function maintainLocked(CarbonImmutable $at, ?CarbonImmutable $from, ?CarbonImmutable $until, RetentionPolicy $policy): array
    {
        do {
            $deletedAttempts = DB::transaction(function () use ($at, $policy): int {
                // Backfill and retention acquire overlapping attempt rows in ascending ID order.
                $expiredAttempts = DB::table('check_run_attempts')->where('requested_at', '<', $at->utc()->subDays($policy->attemptDays))
                    ->orderBy('id')->limit(1000)->lockForUpdate()->pluck('id')->all();

                return $expiredAttempts === [] ? 0 : DB::table('check_run_attempts')->whereIn('id', $expiredAttempts)->delete();
            }, 3);
        } while ($deletedAttempts === 1000);
        do {
            $deletedResponseBuckets = DB::affectingStatement(<<<'SQL'
                DELETE FROM list_response_buckets WHERE (check_id, bucket_start) IN (
                    SELECT check_id, bucket_start FROM list_response_buckets
                    WHERE bucket_start < ? ORDER BY bucket_start LIMIT 10000
                )
                SQL, [$at->utc()->subDays(2)->format('Y-m-d H:i:s.uP')]);
        } while ($deletedResponseBuckets === 10000);
        $month = $at->utc()->startOfMonth();
        $requiredPartitions = [];
        foreach ([-1, 0, 1, 2] as $offset) {
            $start = $month->addMonths($offset);
            $end = $start->addMonth();
            $name = 'check_runs_'.$start->format('Y_m');
            $requiredPartitions[$name] = true;
            DB::statement("CREATE TABLE IF NOT EXISTS {$name} PARTITION OF check_runs FOR VALUES FROM ('{$start->toIso8601String()}') TO ('{$end->toIso8601String()}')");
        }

        $closed = [];
        $expired = [];
        /** @var int $retentionDays */
        $retentionDays = config('monitoring.raw_retention_days');
        $cutoff = $at->utc()->subDays($retentionDays);
        foreach ($this->partitions() as $partition) {
            unset($requiredPartitions[$partition['name']]);
            $start = $partition['start'];
            $end = $partition['end'];
            if ($end->addMinutes(10)->lessThanOrEqualTo($at) && $this->unfinalisedBuckets($start, $end) > 0) {
                $closed[] = ['start' => $start, 'end' => $end];
            }
            if ($end->lessThanOrEqualTo($cutoff)) {
                $expired[] = $partition;
            }
        }
        if ($requiredPartitions !== []) {
            throw new RuntimeException('Required tables are not check_runs partitions: '.implode(', ', array_keys($requiredPartitions)));
        }

        // Accepted attempts arrive within the 390-second lease budget. Closed months are finalised once after that margin.
        // Each rollup statement commits on its own, so a failure keeps earlier buckets; it must not stop retention below.
        $rollupFailure = null;
        try {
            foreach ($closed as $partition) {
                foreach (['hourly' => 'hour', 'daily' => 'day'] as $period => $unit) {
                    $this->rollup($period, $unit, $at, $partition['start'], $partition['end']);
                }
            }
            foreach (['hourly' => 'hour', 'daily' => 'day'] as $period => $unit) {
                $windowStart = $from ?? ($period === 'hourly' ? $at->subHours(3) : $at->subDay());
                $windowEnd = $until ?? $at->addMicrosecond();
                $start = $period === 'hourly' ? $windowStart->utc()->startOfHour() : $windowStart->utc()->startOfDay();
                $end = $period === 'hourly' ? $windowEnd->utc()->startOfHour() : $windowEnd->utc()->startOfDay();
                if ($end->lessThan($windowEnd)) {
                    $end = $period === 'hourly' ? $end->addHour() : $end->addDay();
                }
                $this->rollup($period, $unit, $at, $start, $end);
            }
        } catch (Throwable $exception) {
            $rollupFailure = $exception;
            Log::error('Check run rollup computation failed before retention.', ['exception' => $exception]);
        }

        $skipped = [];
        foreach ($expired as $partition) {
            $missing = $this->unfinalisedBuckets($partition['start'], $partition['end']);
            if ($missing > 0) {
                $skipped[] = ['partition' => $partition['name'], 'missing_buckets' => $missing,
                    'command' => 'docker compose exec app php artisan heptapod:maintain-check-data --recompute-range='.$partition['start']->toIso8601String().','.$partition['end']->toIso8601String()];
            } else {
                DB::transaction(function () use ($partition): void {
                    foreach ($this->registry->keys() as $key) {
                        $this->registry->get($key)->persistence()?->prune($partition['end']);
                    }
                    // Fence only the physical predecessor change and set-based projection repair.
                    DB::statement('LOCK TABLE check_runs IN ACCESS EXCLUSIVE MODE');
                    /** @var list<object{check_id: int}> $affectedChecks */
                    $affectedChecks = DB::select('SELECT DISTINCT check_id FROM '.$partition['qualified_name'].' ORDER BY check_id');
                    DB::statement('DROP TABLE '.$partition['qualified_name']);
                    $this->history->repairAfterPruning(array_column($affectedChecks, 'check_id'), $partition['start'], $partition['end']);
                });
            }
        }

        $retention = $this->retention->pruneHourly($at, $policy);
        Log::info('Hourly rollup retention completed.', $retention);

        if ($rollupFailure instanceof Throwable) {
            throw $rollupFailure;
        }

        return $skipped;
    }

    /** @return list<array{partition: string, missing_buckets: int}> */
    public function previewExpiredPartitions(CarbonImmutable $at, RetentionPolicy $policy): array
    {
        $result = [];
        foreach ($this->partitions() as $partition) {
            if ($partition['end']->lessThanOrEqualTo($at->utc()->subDays($policy->rawDays))) {
                $result[] = ['partition' => $partition['name'], 'missing_buckets' => $this->unfinalisedBuckets($partition['start'], $partition['end'])];
            }
        }

        return $result;
    }

    /** @return list<array{name: string, qualified_name: string, start: CarbonImmutable, end: CarbonImmutable}> */
    private function partitions(): array
    {
        /** @var list<object{name: string, bounds: string, qualified_name: string}> $partitions */
        $partitions = DB::select(<<<'SQL'
            SELECT child.relname AS name, pg_get_expr(child.relpartbound, child.oid) AS bounds,
                   quote_ident(namespace.nspname) || '.' || quote_ident(child.relname) AS qualified_name
            FROM pg_inherits
            JOIN pg_class child ON child.oid = inhrelid
            JOIN pg_namespace namespace ON namespace.oid = child.relnamespace
            WHERE inhparent = 'check_runs'::regclass
            SQL);
        $result = [];
        foreach ($partitions as $partition) {
            if (preg_match('/^check_runs_\d{4}_\d{2}$/D', $partition->name) !== 1
                || preg_match("/^FOR VALUES FROM \\('([^']+)'\\) TO \\('([^']+)'\\)$/D", $partition->bounds, $bounds) !== 1) {
                throw new RuntimeException('Unexpected check_runs partition: '.$partition->name);
            }
            $start = CarbonImmutable::parse($bounds[1], 'UTC')->utc();
            $end = CarbonImmutable::parse($bounds[2], 'UTC')->utc();
            if ($start->startOfMonth()->notEqualTo($start) || $start->addMonth()->notEqualTo($end)
                || $partition->name !== 'check_runs_'.$start->format('Y_m')) {
                throw new RuntimeException('Non-monthly check_runs partition: '.$partition->name);
            }
            $result[] = ['name' => $partition->name, 'qualified_name' => $partition->qualified_name, 'start' => $start, 'end' => $end];
        }

        return $result;
    }

    private function unfinalisedBuckets(CarbonImmutable $start, CarbonImmutable $end): int
    {
        /** @var object{missing_buckets: int} $proof */
        $proof = DB::selectOne(<<<'SQL'
            WITH bounds AS (SELECT ?::timestamptz AS start, ?::timestamptz AS stop),
            observations AS MATERIALIZED (
                SELECT check_id, ended_at FROM check_runs CROSS JOIN bounds WHERE ended_at >= start AND ended_at < stop
            ), hours AS (SELECT DISTINCT check_id, date_trunc('hour', ended_at, 'UTC') AS bucket FROM observations),
            days AS (SELECT DISTINCT check_id, date_trunc('day', ended_at, 'UTC') AS bucket FROM observations)
            SELECT (
                SELECT count(*) FROM hours CROSS JOIN bounds LEFT JOIN check_run_rollups_hourly rollup
                    ON rollup.check_id = hours.check_id AND rollup.bucket_start = bucket
                WHERE computed_at IS NULL OR computed_at < bucket + interval '1 hour 10 minutes'
                    OR computed_at < stop + interval '10 minutes'
            ) + (
                SELECT count(*) FROM days CROSS JOIN bounds LEFT JOIN check_run_rollups_daily rollup
                    ON rollup.check_id = days.check_id AND rollup.bucket_start = bucket
                WHERE computed_at IS NULL OR computed_at < bucket + interval '1 day 10 minutes'
                    OR computed_at < stop + interval '10 minutes'
            ) AS missing_buckets
            SQL, [$start->format('Y-m-d H:i:s.uP'), $end->format('Y-m-d H:i:s.uP')]);

        return $proof->missing_buckets;
    }

    /**
     * Recomputes every (check, bucket) group with runs in [from, until), one bucket at a time and at most
     * RunsPerRollupStatement runs per statement (more only when a single check exceeds it within one bucket).
     */
    private function rollup(string $period, string $unit, CarbonImmutable $at, CarbonImmutable $from, CarbonImmutable $until): void
    {
        for ($bucket = $from->utc()->startOf($unit); $bucket->lessThan($until); $bucket = $bucket->add(1, $unit)) {
            $sliceStart = $bucket->max($from);
            $sliceEnd = $bucket->add(1, $unit)->min($until);
            /** @var list<object{check_id: int, runs: int}> $checks */
            $checks = DB::select(
                'SELECT check_id, count(*) AS runs FROM check_runs WHERE ended_at >= ?::timestamptz AND ended_at < ?::timestamptz GROUP BY check_id ORDER BY check_id',
                [$sliceStart->format('Y-m-d H:i:s.uP'), $sliceEnd->format('Y-m-d H:i:s.uP')],
            );
            $chunk = [];
            $chunkRuns = 0;
            foreach ($checks as $check) {
                if ($chunk !== [] && $chunkRuns + $check->runs > self::RunsPerRollupStatement) {
                    $this->rollupChecks($period, $unit, $at, $sliceStart, $sliceEnd, $chunk);
                    $chunk = [];
                    $chunkRuns = 0;
                }
                $chunk[] = $check->check_id;
                $chunkRuns += $check->runs;
            }
            if ($chunk !== []) {
                $this->rollupChecks($period, $unit, $at, $sliceStart, $sliceEnd, $chunk);
            }
        }
    }

    /** @param non-empty-list<int> $checkIds */
    private function rollupChecks(string $period, string $unit, CarbonImmutable $at, CarbonImmutable $from, CarbonImmutable $until, array $checkIds): void
    {
        DB::transaction(function () use ($period, $unit, $at, $from, $until, $checkIds): void {
            // The recursive CTE's row estimate is far too high, so PostgreSQL would JIT-compile every chunk
            // (about 225 ms and tens of MB per statement in profiling) for a statement that runs in under 300 ms.
            DB::statement('SET LOCAL jit = off');
            DB::statement(<<<SQL
            WITH RECURSIVE samples AS MATERIALIZED (
                SELECT id, ended_at, check_id, result, metrics, date_trunc('{$unit}', ended_at, 'UTC') AS bucket
                FROM check_runs WHERE check_id = ANY(?::bigint[]) AND ended_at >= ?::timestamptz AND ended_at < ?::timestamptz
            ), counts AS (
                SELECT check_id, bucket,
                       count(*) FILTER (WHERE result = 'succeeded') AS succeeded_count,
                       count(*) FILTER (WHERE result = 'warning') AS warning_count,
                       count(*) FILTER (WHERE result = 'failed') AS failed_count,
                       count(*) FILTER (WHERE result = 'errored-or-timed-out') AS errored_count
                FROM samples GROUP BY check_id, bucket
            ), leaves AS (
                SELECT check_id, bucket, ARRAY[]::text[] AS path, metrics AS value FROM samples WHERE metrics IS NOT NULL
                UNION ALL
                SELECT leaves.check_id, leaves.bucket, leaves.path || item.key, item.value
                FROM leaves CROSS JOIN LATERAL jsonb_each(
                    CASE WHEN jsonb_typeof(leaves.value) = 'object' THEN leaves.value ELSE '{}'::jsonb END
                ) AS item
            ), metrics AS (
                SELECT check_id, bucket, array_to_string(path, '.') AS name,
                       sum(value::text::double precision) AS sum, count(*) AS count,
                       percentile_cont(0.95) WITHIN GROUP (ORDER BY value::text::double precision) AS p95
                FROM leaves WHERE jsonb_typeof(value) = 'number' GROUP BY check_id, bucket, path
            ), metric_objects AS (
                SELECT check_id, bucket, jsonb_object_agg(name, sum) AS sums,
                       jsonb_object_agg(name, count) AS counts, jsonb_object_agg(name, p95) AS p95
                FROM metrics GROUP BY check_id, bucket
            )
            INSERT INTO check_run_rollups_{$period}
                (check_id, bucket_start, succeeded_count, warning_count, failed_count, errored_count,
                 metric_sums, metric_counts, metric_p95, computed_at)
            SELECT counts.check_id, bucket, succeeded_count, warning_count, failed_count, errored_count,
                   coalesce(sums, '{}'::jsonb), coalesce(metric_objects.counts, '{}'::jsonb), coalesce(p95, '{}'::jsonb), ?::timestamptz
            FROM counts LEFT JOIN metric_objects USING (check_id, bucket)
            ON CONFLICT (check_id, bucket_start) DO UPDATE SET
                succeeded_count = EXCLUDED.succeeded_count, warning_count = EXCLUDED.warning_count,
                failed_count = EXCLUDED.failed_count, errored_count = EXCLUDED.errored_count,
                metric_sums = EXCLUDED.metric_sums, metric_counts = EXCLUDED.metric_counts,
                metric_p95 = EXCLUDED.metric_p95, computed_at = EXCLUDED.computed_at
            SQL, ['{'.implode(',', $checkIds).'}', $from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP'), $at->format('Y-m-d H:i:s.uP')]);
        });
    }
}
