<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX events_incident_notification_cadence ON events (incident_id, occurred_at DESC)
            WHERE type IN ('incident.opened', 'incident.cause_changed', 'incident.still_down')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX events_incident_notification_cadence');
    }
};
