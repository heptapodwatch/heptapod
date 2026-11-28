<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The purge before a site deletion removes a check's run tasks in batches by check_id (CronPersistence::purgeCheck), and
 * the cascades of deleting a check or a task find their run tasks by check_id and cron_check_id.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const array Columns = ['check_id', 'cron_check_id'];

    public function up(): void
    {
        foreach (self::Columns as $column) {
            // A failed concurrent build leaves an invalid index. Rebuild it when the migration retries.
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS cron_run_tasks_'.$column.'_index');
            DB::statement('CREATE INDEX CONCURRENTLY cron_run_tasks_'.$column.'_index ON cron_run_tasks ('.$column.')');
        }
    }

    public function down(): void
    {
        foreach (self::Columns as $column) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS cron_run_tasks_'.$column.'_index');
        }
    }
};
