<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a user SETs NULL on the deliveries it sent as actor (client messages); DeleteProfile locks those rows first.
 * Both find them through this index instead of scanning every delivery.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // A failed concurrent build leaves an invalid index. Drop it before retrying.
        if (DB::scalar('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', ['deliveries_actor']) === false) {
            DB::statement('DROP INDEX CONCURRENTLY deliveries_actor');
        }
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS deliveries_actor ON deliveries (actor_id) WHERE actor_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS deliveries_actor');
    }
};
