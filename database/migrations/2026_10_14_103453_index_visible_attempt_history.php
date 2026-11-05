<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // A failed concurrent build leaves an invalid index. Rebuild it when the migration retries.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS check_run_attempts_visible_history');
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY check_run_attempts_visible_history
                ON check_run_attempts (requested_at DESC, id DESC) INCLUDE (check_id)
                WHERE history_result_repeated IS NOT TRUE
            SQL);
        DB::statement('ANALYZE check_run_attempts');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS check_run_attempts_visible_history');
    }
};
