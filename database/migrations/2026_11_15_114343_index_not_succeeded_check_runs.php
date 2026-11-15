<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // Result-filtered reads of one check's runs (check page failures, API result filter) otherwise scan every succeeded run.
        // A partitioned index cannot be built concurrently: create it on the parent only, build each partition's index
        // concurrently and attach it. The parent turns valid once every partition is attached; later partitions get it on creation.
        DB::statement("CREATE INDEX IF NOT EXISTS check_runs_check_ended_not_succeeded ON ONLY check_runs (check_id, ended_at DESC, id DESC) WHERE result <> 'succeeded'");
        /** @var list<string> $partitions */
        $partitions = array_column(DB::select("SELECT inhrelid::regclass::text AS name FROM pg_inherits WHERE inhparent = 'check_runs'::regclass ORDER BY 1"), 'name');
        foreach ($partitions as $partition) {
            $index = $partition.'_check_ended_not_succeeded';
            // A failed concurrent build leaves an invalid index. Keep valid builds when retrying.
            if (DB::scalar('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$index]) === false) {
                DB::statement('DROP INDEX CONCURRENTLY '.$index);
            }
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON {$partition} (check_id, ended_at DESC, id DESC) WHERE result <> 'succeeded'");
            DB::statement("ALTER INDEX check_runs_check_ended_not_succeeded ATTACH PARTITION {$index}");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS check_runs_check_ended_not_succeeded');
    }
};
