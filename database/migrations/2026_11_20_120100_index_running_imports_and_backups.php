<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    /** Running now reads imports and backups on every Owner/Admin page: work in flight and the viewer's results of the last minute. */
    private const array INDEXES = [
        'import_runs_active' => "import_runs (created_at) WHERE status IN ('queued', 'running')",
        'import_runs_user_finished' => 'import_runs (user_id, finished_at) WHERE finished_at IS NOT NULL',
        'backup_runs_active' => "backup_runs (id) WHERE state IN ('queued', 'running')",
        'backup_runs_requester_completed' => 'backup_runs (requested_by, completed_at) WHERE completed_at IS NOT NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            // A failed concurrent build leaves an invalid index. Rebuild it when the migration retries.
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }
};
