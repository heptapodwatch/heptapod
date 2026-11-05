<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->timestampTz('first_result_at', 6)->nullable();
        });

        $last = 0;
        do {
            $ids = DB::table('checks')->where('id', '>', $last)->orderBy('id')->limit(500)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            // Daily history survives raw partition pruning; raw runs refine the first surviving day.
            // PostgreSQL least() ignores NULL, preserving an earlier marker on repeated backfills.
            DB::update(<<<'SQL'
                UPDATE checks c SET first_result_at = least(c.first_result_at, s.marker)
                FROM (
                    SELECT k.id,
                        CASE WHEN r.ended_at IS NOT NULL AND (d.bucket_start IS NULL OR r.ended_at < d.bucket_start + interval '1 day')
                             THEN r.ended_at ELSE d.bucket_start END AS marker
                    FROM checks k
                    LEFT JOIN LATERAL (SELECT bucket_start FROM check_run_rollups_daily
                        WHERE check_id = k.id AND succeeded_count + warning_count + failed_count > 0
                        ORDER BY bucket_start LIMIT 1) d ON true
                    LEFT JOIN LATERAL (SELECT ended_at FROM check_runs
                        WHERE check_id = k.id AND result <> 'errored-or-timed-out'
                        ORDER BY ended_at LIMIT 1) r ON true
                    WHERE k.id > ? ORDER BY k.id LIMIT 500
                ) s WHERE c.id = s.id AND s.marker IS NOT NULL
                SQL, [$last]);
            $last = $ids->last();
        } while ($ids->count() === 500);
    }

    public function down(): void
    {
        Schema::table('checks', function (Blueprint $table): void {
            $table->dropColumn('first_result_at');
        });
    }
};
