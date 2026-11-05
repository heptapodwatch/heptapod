<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The parent index also indexes future monthly partitions. Existing check_id-first indexes cannot bound a global latest run.
        DB::statement('CREATE INDEX check_runs_ended_at ON check_runs (ended_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX check_runs_ended_at');
    }
};
