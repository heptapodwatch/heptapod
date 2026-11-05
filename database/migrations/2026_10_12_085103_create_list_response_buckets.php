<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_response_buckets', function (Blueprint $table): void {
            $table->foreignId('check_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('bucket_start', 6);
            $table->double('maximum_seconds')->nullable();
            $table->unsignedBigInteger('failed_count');
            $table->unsignedBigInteger('errored_count');
            $table->unsignedBigInteger('warning_count');
            $table->primary(['check_id', 'bucket_start']);
            $table->index('bucket_start');
        });
        // Runs are immutable. The insert trigger also covers imports and commits each bucket with its run.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION maintain_list_response_bucket() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO list_response_buckets
                    (check_id, bucket_start, maximum_seconds, failed_count, errored_count, warning_count)
                VALUES (NEW.check_id, date_trunc('minute', NEW.ended_at, 'UTC'),
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
            CREATE TRIGGER check_runs_list_response_bucket AFTER INSERT ON check_runs
                FOR EACH ROW WHEN (NEW.check_type = 'uptime') EXECUTE FUNCTION maintain_list_response_bucket();
            SQL);
        // Two days cover the rolling 24h list window and its split boundary minutes.
        DB::statement(<<<'SQL'
            INSERT INTO list_response_buckets
                (check_id, bucket_start, maximum_seconds, failed_count, errored_count, warning_count)
            SELECT check_id, date_trunc('minute', ended_at, 'UTC'),
                max((metrics->>'total_time_in_seconds')::double precision)
                    FILTER (WHERE jsonb_typeof(metrics->'total_time_in_seconds') = 'number'),
                count(*) FILTER (WHERE result = 'failed'), count(*) FILTER (WHERE result = 'errored-or-timed-out'),
                count(*) FILTER (WHERE result = 'warning')
            FROM check_runs WHERE check_type = 'uptime' AND ended_at >= CURRENT_TIMESTAMP - interval '2 days'
            GROUP BY check_id, 2
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER check_runs_list_response_bucket ON check_runs');
        DB::statement('DROP FUNCTION maintain_list_response_bucket()');
        Schema::drop('list_response_buckets');
    }
};
