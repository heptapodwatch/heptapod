<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Retry failed concurrent builds, which can leave an invalid index behind.
        $this->down();
        DB::statement('CREATE INDEX CONCURRENTLY application_health_snapshots_accepted_check_run ON application_health_snapshots (check_id, run_ended_at DESC, run_id DESC) WHERE run_ended_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS application_health_snapshots_accepted_check_run');
    }
};
