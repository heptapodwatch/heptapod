<?php

declare(strict_types=1);

namespace Heptapod\Actions;

use Carbon\CarbonImmutable;
use Heptapod\Enums\DatabaseDataClass;
use Illuminate\Support\Facades\DB;

/** Records at most one database size sample per UTC day; a later call on the same day keeps the first sample. */
final readonly class RecordDatabaseSize
{
    /** Samples are a few kilobytes each; three years keep every plateau of the default retention visible. */
    public const int SampleRetentionDays = 1095;

    /** @return int|null the recorded database size in bytes, or null when today's sample already exists */
    public function __invoke(CarbonImmutable $at): ?int
    {
        $at = $at->utc();
        if (DB::table('database_size_samples')->where('sampled_on', $at->toDateString())->exists()) {
            return null;
        }
        $intake = [];
        $intakeTables = [];
        foreach (DatabaseDataClass::cases() as $class) {
            $column = $class->intakeColumn();
            if ($column !== null) {
                $until = $column[2] === null ? $at : $at->startOf($column[2]);
                $intake[$class->value] = DB::table($column[0])->where($column[1], '>=', $until->subDay()->format('Y-m-d H:i:s.uP'))
                    ->where($column[1], '<', $until->format('Y-m-d H:i:s.uP'))->count();
                $intakeTables[] = $column[0];
            }
        }
        // Bytes per row divide by the planner's row estimate, which is unknown (-1) before a table's first ANALYZE and stale
        // until autovacuum catches up. ANALYZE reads a bounded sample per table (and partition), so a daily run is cheap.
        DB::statement('ANALYZE '.implode(', ', $intakeTables));
        /** @var int $totalBytes */
        $totalBytes = DB::scalar('SELECT pg_database_size(current_database())');
        DB::table('database_size_samples')->insertOrIgnore([
            'sampled_on' => $at->toDateString(),
            'sampled_at' => $at->format('Y-m-d H:i:s.uP'),
            'total_bytes' => $totalBytes,
            'tables' => json_encode($this->tables(), JSON_THROW_ON_ERROR),
            'intake' => json_encode($intake, JSON_THROW_ON_ERROR),
        ]);
        DB::table('database_size_samples')->where('sampled_on', '<', $at->subDays(self::SampleRetentionDays)->toDateString())->delete();

        return $totalBytes;
    }

    /** @return array<string, array{bytes: int, rows: int}> Catalog reads only: no table is scanned. Partitions count under their root. */
    private function tables(): array
    {
        /** @var list<object{name: string, bytes: int, rows: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT root.relname AS name, sum(pg_total_relation_size(leaf.oid))::bigint AS bytes,
                   sum(greatest(leaf.reltuples, 0))::bigint AS rows
            FROM pg_class leaf
            JOIN pg_namespace namespace ON namespace.oid = leaf.relnamespace
            JOIN pg_class root ON root.oid = coalesce(pg_partition_root(leaf.oid), leaf.oid)
            WHERE namespace.nspname = current_schema() AND leaf.relkind = 'r'
            GROUP BY root.relname
            ORDER BY root.relname
            SQL);
        $tables = [];
        foreach ($rows as $row) {
            $tables[$row->name] = ['bytes' => $row->bytes, 'rows' => $row->rows];
        }

        return $tables;
    }
}
