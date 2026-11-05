<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fence inserts during replacement so a committed run is counted exactly once.
        DB::statement('LOCK TABLE check_runs IN SHARE ROW EXCLUSIVE MODE');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION maintain_list_response_bucket() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO list_response_buckets
                    (check_id, bucket_start, maximum_seconds, failed_count, errored_count, warning_count)
                VALUES (NEW.check_id, date_bin(interval '18 minutes', NEW.ended_at, timestamptz '1970-01-01 00:00:00+00'),
                    CASE WHEN jsonb_typeof(NEW.metrics->'total_time_in_seconds') = 'number'
                        THEN (NEW.metrics->>'total_time_in_seconds')::double precision END,
                    (NEW.result = 'failed')::int, (NEW.result = 'errored-or-timed-out')::int, (NEW.result = 'warning')::int)
                ON CONFLICT (check_id, bucket_start) DO UPDATE SET
                    maximum_seconds = greatest(list_response_buckets.maximum_seconds, EXCLUDED.maximum_seconds),
                    failed_count = list_response_buckets.failed_count + EXCLUDED.failed_count,
                    errored_count = list_response_buckets.errored_count + EXCLUDED.errored_count,
                    warning_count = list_response_buckets.warning_count + EXCLUDED.warning_count;
                RETURN NEW;
            END $$;
            TRUNCATE list_response_buckets;
            ALTER TABLE list_response_buckets ADD CONSTRAINT list_response_buckets_grid
                CHECK (bucket_start = date_bin(interval '18 minutes', bucket_start, timestamptz '1970-01-01 00:00:00+00'));
            SQL);
        DB::statement(<<<'SQL'
            INSERT INTO list_response_buckets
                (check_id, bucket_start, maximum_seconds, failed_count, errored_count, warning_count)
            SELECT check_id, date_bin(interval '18 minutes', ended_at, timestamptz '1970-01-01 00:00:00+00'),
                max((metrics->>'total_time_in_seconds')::double precision)
                    FILTER (WHERE jsonb_typeof(metrics->'total_time_in_seconds') = 'number'),
                count(*) FILTER (WHERE result = 'failed'), count(*) FILTER (WHERE result = 'errored-or-timed-out'),
                count(*) FILTER (WHERE result = 'warning')
            FROM check_runs WHERE check_type = 'uptime'
                AND ended_at >= date_bin(interval '18 minutes', CURRENT_TIMESTAMP - interval '2 days', timestamptz '1970-01-01 00:00:00+00')
            GROUP BY check_id, 2
            SQL);
        DB::statement('ANALYZE list_response_buckets');
    }

    public function down(): void
    {
        DB::statement('LOCK TABLE check_runs IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('ALTER TABLE list_response_buckets DROP CONSTRAINT list_response_buckets_grid');
        DB::statement('DROP TRIGGER check_runs_list_response_bucket ON check_runs');
        DB::statement('DROP TABLE list_response_buckets');
        // The original migration recreates the minute table, function and trigger from retained runs.
        $minuteBuckets = require __DIR__.'/2026_10_12_085103_create_list_response_buckets.php';
        if (! $minuteBuckets instanceof Migration || ! method_exists($minuteBuckets, 'up')) {
            throw new LogicException('The minute bucket migration must provide up().');
        }
        $minuteBuckets->up();
        DB::statement('ANALYZE list_response_buckets');
    }
};
