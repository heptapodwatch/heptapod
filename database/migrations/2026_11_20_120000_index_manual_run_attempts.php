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
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS check_run_attempts_manual_actor_finished');
        // Running now: the viewer's own manual runs that finished in the last minute.
        DB::statement(<<<'SQL'
            CREATE INDEX CONCURRENTLY check_run_attempts_manual_actor_finished
                ON check_run_attempts (actor_user_id, finished_at DESC) WHERE origin = 'manual'
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS check_run_attempts_manual_actor_finished');
    }
};
