<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Running now reads the imports in flight on every page for Owners and Admins; finished runs never match.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS import_runs_active');
        DB::statement("CREATE INDEX CONCURRENTLY import_runs_active ON import_runs (created_at) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS import_runs_active');
    }
};
