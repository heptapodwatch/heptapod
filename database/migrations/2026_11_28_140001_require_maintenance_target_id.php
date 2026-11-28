<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Every writer sets target_id since 2026_10_04_224400 backfilled it from site_id, so readers no longer fall back to site_id. */
return new class extends Migration
{
    private const array Tables = ['maintenance_periods', 'recurring_maintenance_periods'];

    public function up(): void
    {
        foreach (self::Tables as $table) {
            $missing = DB::table($table)->whereNull('target_id')->count();
            if ($missing > 0) {
                throw new LogicException("{$table} has {$missing} rows without target_id; set target_id before migrating.");
            }
            DB::statement("ALTER TABLE {$table} ALTER COLUMN target_id SET NOT NULL");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_target_valid");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_target_valid CHECK (target_type IN ('site', 'client', 'tag'))");
        }
    }

    public function down(): void
    {
        foreach (self::Tables as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_target_valid");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_target_valid CHECK ((target_type = 'site' AND coalesce(target_id, site_id) IS NOT NULL) OR (target_type IN ('client', 'tag') AND target_id IS NOT NULL))");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN target_id DROP NOT NULL");
        }
    }
};
