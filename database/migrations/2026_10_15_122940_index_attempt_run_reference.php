<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // The Runs board finds the attempt that recorded a run by its physical run reference.
        // A failed concurrent build leaves an invalid index. Rebuild it when the migration retries.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS check_run_attempts_run_reference');
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY check_run_attempts_run_reference
                ON check_run_attempts (check_id, check_run_id, finished_at)
                WHERE check_run_id IS NOT NULL
            SQL);
        DB::statement('ANALYZE check_run_attempts');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS check_run_attempts_run_reference');
    }
};
