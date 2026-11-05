<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $indexes = [
            'deliveries_digest_event' => 'deliveries (digest_event_id) WHERE digest_event_id IS NOT NULL',
            'events_incident' => 'events (incident_id) WHERE incident_id IS NOT NULL',
            'incidents_resolved' => 'incidents (resolved_at, id) WHERE resolved_at IS NOT NULL',
            'activity_log_retention' => 'activity_log (created_at, id)',
            'incidents_opened_maintenance' => 'incidents (opened_during_maintenance_window_id) WHERE opened_during_maintenance_window_id IS NOT NULL',
        ];
        foreach ($indexes as $name => $definition) {
            // A failed concurrent build leaves an invalid index. Keep valid builds when retrying.
            if (DB::scalar('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]) === false) {
                DB::statement('DROP INDEX CONCURRENTLY '.$name);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
        }
    }

    public function down(): void
    {
        foreach (['deliveries_digest_event', 'events_incident', 'incidents_resolved', 'activity_log_retention', 'incidents_opened_maintenance'] as $name) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }
    }
};
