<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The purge before a site deletion removes a check's observations in batches by check_id (DnsPersistence::purgeCheck). */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // A failed concurrent build leaves an invalid index. Rebuild it when the migration retries.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS dns_observations_check_id_index');
        DB::statement('CREATE INDEX CONCURRENTLY dns_observations_check_id_index ON dns_observations (check_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS dns_observations_check_id_index');
    }
};
