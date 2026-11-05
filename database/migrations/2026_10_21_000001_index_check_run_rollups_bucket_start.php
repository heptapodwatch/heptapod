<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (['hourly', 'daily'] as $period) {
            // A failed concurrent build leaves an invalid index. Rebuild it on migration retry.
            $name = 'rollups_'.$period.'_bucket_start';
            if (DB::scalar('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]) === false) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON check_run_rollups_'.$period.' (bucket_start)');
        }
    }

    public function down(): void
    {
        foreach (['hourly', 'daily'] as $period) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS rollups_'.$period.'_bucket_start');
        }
    }
};
