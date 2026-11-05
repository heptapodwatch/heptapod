<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

final class QueryPlanWork
{
    /** @param list<mixed> $bindings */
    public static function forQuery(string $sql, array $bindings): int
    {
        return self::count(self::planForQuery($sql, $bindings));
    }

    /** @param list<mixed> $bindings */
    public static function scannedRows(string $sql, array $bindings): int
    {
        return self::countScans(self::planForQuery($sql, $bindings));
    }

    /** @param list<mixed> $bindings
     * @return array<string, mixed>
     */
    private static function planForQuery(string $sql, array $bindings): array
    {
        $row = DB::selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql, $bindings);
        $plans = json_decode($row->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR);

        return $plans[0]['Plan'];
    }

    /** @param array<string, mixed> $plan */
    private static function count(array $plan): int
    {
        $work = (int) (($plan['Rows Removed by Join Filter'] ?? 0) * $plan['Actual Loops']);
        foreach ($plan['Plans'] ?? [] as $index => $child) {
            if ($index === 1 && $plan['Node Type'] === 'Nested Loop') {
                $work += (int) ($child['Actual Rows'] * $child['Actual Loops']);
            }
            $work += self::count($child);
        }

        return $work;
    }

    /** @param array<string, mixed> $plan */
    private static function countScans(array $plan): int
    {
        $rows = 0;
        if (! isset($plan['Plans'])) {
            $rows = (int) (($plan['Actual Rows'] + ($plan['Rows Removed by Filter'] ?? 0)) * $plan['Actual Loops']);
        }
        foreach ($plan['Plans'] ?? [] as $child) {
            $rows += self::countScans($child);
        }

        return $rows;
    }
}
