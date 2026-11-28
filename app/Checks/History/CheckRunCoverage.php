<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What check data maintenance has proven about the rollups: the instant before which every bucket is final, where the
 * automatic windows resume after an outage of any length, and the closed run partitions whose buckets were all proven
 * final, which are never scanned again. An edit of existing runs invalidates both from the edited instant.
 */
final class CheckRunCoverage
{
    /** Session lock of a maintenance run; edits take it per transaction so a run cannot record coverage over them. */
    public const int MaintenanceLock = 73410201;

    public function coveredUntil(): ?CarbonImmutable
    {
        /** @var string|null $until */
        $until = DB::table('check_run_rollup_coverage')->value('covered_until');

        return $until === null ? null : CarbonImmutable::parse($until)->utc();
    }

    public function cover(CarbonImmutable $until): void
    {
        DB::table('check_run_rollup_coverage')->upsert(['singleton' => true, 'covered_until' => $until->format('Y-m-d H:i:s.uP')], ['singleton']);
    }

    /** @return array<string, true> starts (ISO 8601) of the finalised partitions */
    public function finalisedPartitions(): array
    {
        /** @var list<string> $starts */
        $starts = DB::table('check_run_partition_finalisations')->pluck('partition_start')->all();

        return array_fill_keys(array_map(static fn (string $start): string => CarbonImmutable::parse($start)->utc()->toIso8601String(), $starts), true);
    }

    public function finalise(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $at): void
    {
        DB::table('check_run_partition_finalisations')->upsert(['partition_start' => $start->format('Y-m-d H:i:s.uP'),
            'partition_end' => $end->format('Y-m-d H:i:s.uP'), 'finalised_at' => $at->format('Y-m-d H:i:s.uP')], ['partition_start']);
    }

    /** Forgets the finalisation of every partition that overlaps [from, until). */
    public function forget(CarbonImmutable $from, CarbonImmutable $until): void
    {
        DB::table('check_run_partition_finalisations')->where('partition_start', '<', $until->format('Y-m-d H:i:s.uP'))
            ->where('partition_end', '>', $from->format('Y-m-d H:i:s.uP'))->delete();
    }

    /**
     * Serializes the caller's transaction with maintenance. Take it before the transaction reads or writes check_runs:
     * a maintenance run holds the lock while it waits for ACCESS EXCLUSIVE on that table.
     */
    public function lockAgainstMaintenance(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Coverage invalidation needs the transaction that edits the runs.');
        }
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::MaintenanceLock]);
    }

    /** Runs ended at or after $from changed: the next maintenance run recomputes their buckets and proves their months again. */
    public function invalidateFrom(CarbonImmutable $from): void
    {
        $this->lockAgainstMaintenance();
        DB::statement(<<<'SQL'
            INSERT INTO check_run_rollup_coverage (singleton, covered_until) VALUES (true, ?)
            ON CONFLICT (singleton) DO UPDATE SET covered_until = least(check_run_rollup_coverage.covered_until, EXCLUDED.covered_until)
            SQL, [$from->format('Y-m-d H:i:s.uP')]);
        DB::table('check_run_partition_finalisations')->where('partition_end', '>', $from->format('Y-m-d H:i:s.uP'))->delete();
    }
}
