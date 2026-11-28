<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every writer sets target_id since 2026_10_04_224400 backfilled it from site_id, so readers no longer fall back to site_id.
 * Each statement commits on its own so no full-table scan runs under ACCESS EXCLUSIVE: a CHECK is added NOT VALID and
 * validated separately, and SET NOT NULL reuses the validated target_id_present check instead of scanning. Every step
 * drops before it adds, so a rerun after a failure is safe.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const array Tables = ['maintenance_periods', 'recurring_maintenance_periods'];

    public function up(): void
    {
        foreach (self::Tables as $table) {
            $missing = DB::table($table)->whereNull('target_id')->count();
            if ($missing > 0) {
                throw new LogicException("{$table} has {$missing} rows without target_id; set target_id before migrating.");
            }
            // A row written without target_id after the count fails this VALIDATE, as loud as the count.
            $this->validCheck($table, "{$table}_target_id_present", 'target_id IS NOT NULL');
            DB::statement("ALTER TABLE {$table} ALTER COLUMN target_id SET NOT NULL");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_target_id_present");
            $this->validCheck($table, "{$table}_target_valid", "target_type IN ('site', 'client', 'tag')");
        }
    }

    public function down(): void
    {
        foreach (self::Tables as $table) {
            $this->validCheck($table, "{$table}_target_valid",
                "(target_type = 'site' AND coalesce(target_id, site_id) IS NOT NULL) OR (target_type IN ('client', 'tag') AND target_id IS NOT NULL)");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN target_id DROP NOT NULL");
        }
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $name
     * @param  literal-string  $condition
     */
    private function validCheck(string $table, string $name, string $condition): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$condition}) NOT VALID");
        DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$name}");
    }
};
