<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /** Manual attempts are a small share of all attempts; partial indexes keep their reads off the scheduled backlog. */
    private const array INDEXES = [
        // Running now (the viewer's results of the last minute) and the Runs board's Finished column filtered to manual runs.
        'check_run_attempts_manual_finished' => '(finished_at DESC) INCLUDE (check_id, check_run_id, actor_user_id)',
        // The Runs history filtered to manual runs.
        'check_run_attempts_manual_history' => '(requested_at DESC, id DESC) INCLUDE (check_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $columns) {
            // A failed concurrent build leaves an invalid index. Rebuild it when the migration retries.
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON check_run_attempts {$columns} WHERE origin = 'manual'");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
