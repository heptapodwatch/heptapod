<?php

declare(strict_types=1);

namespace Heptapod\Checks\History;

use Carbon\CarbonImmutable;
use Heptapod\Enums\BucketStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ListResponseReader
{
    /**
     * The lower bound snaps up to the UTC epoch grid; the newest bucket remains partial.
     *
     * @param  list<int>  $checkIds
     * @return array<int, list<array{bucket_start: string, maximum_ms: ?float, failed_count: int, errored_count: int, warning_count: int, failure_status: BucketStatus|null}>>
     */
    public function read(array $checkIds, CarbonImmutable $from, CarbonImmutable $until): array
    {
        if ($from->greaterThanOrEqualTo($until) || $from->diffInHours($until) > 24 || $from->lessThan(CarbonImmutable::now('UTC')->subDay()->subMinute())) {
            throw new InvalidArgumentException('List response peaks require a recent window of at most 24 hours.');
        }
        if ($checkIds === []) {
            return [];
        }
        $ids = '{'.implode(',', $checkIds).'}';
        /** @var list<object{check_id: int, bucket_start: string, maximum_ms: float|null, failed_count: int, errored_count: int, warning_count: int, failed_seconds: int, outage: bool}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT b.check_id, to_char(b.bucket_start AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS') || '+00:00' AS bucket_start,
                1000 * b.maximum_seconds AS maximum_ms,
                b.failed_count::int, b.errored_count::int, b.warning_count::int,
                (b.failed_count::bigint * c.interval_seconds)::bigint AS failed_seconds,
                EXISTS (SELECT 1 FROM incidents i WHERE i.site_id = c.site_id AND i.check_id = b.check_id AND i.cause = 'failed'
                    AND i.first_failure_at < least(b.bucket_start + interval '18 minutes', ?::timestamptz)
                    AND (i.resolved_at IS NULL OR i.resolved_at > b.bucket_start)) AS outage
            FROM list_response_buckets b JOIN checks c ON c.id = b.check_id
            WHERE b.check_id = ANY(?::bigint[])
                AND b.bucket_start >= ?::timestamptz
                AND b.bucket_start < ?::timestamptz
            ORDER BY b.check_id, b.bucket_start
            SQL, [$until->format('Y-m-d H:i:s.uP'), $ids, $from->format('Y-m-d H:i:s.uP'), $until->format('Y-m-d H:i:s.uP')]);
        $groups = array_fill_keys($checkIds, []);
        foreach ($rows as $row) {
            $groups[$row->check_id][] = [
                'bucket_start' => $row->bucket_start, 'maximum_ms' => $row->maximum_ms,
                'failed_count' => $row->failed_count, 'errored_count' => $row->errored_count, 'warning_count' => $row->warning_count,
                'failure_status' => $row->failed_count > 0 || $row->outage
                    ? BucketStatus::forUptime($row->failed_seconds, $row->outage, 0, 0, 0) : null,
            ];
        }

        return $groups;
    }
}
