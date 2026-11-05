<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            WITH accepted_openings AS (
                SELECT incident.id
                FROM incidents incident
                JOIN events opening_event ON opening_event.incident_id = incident.id
                    AND opening_event.type = 'incident.opened'
                    AND opening_event.occurred_at >= incident.opened_at
                    AND opening_event.occurred_at <= CURRENT_TIMESTAMP
                JOIN deliveries accepted_delivery ON accepted_delivery.event_id = opening_event.id
                    AND accepted_delivery.state = 'sent' AND NOT accepted_delivery.suppressed
                WHERE incident.resolved_at IS NULL
                UNION
                SELECT incident.id
                FROM incidents incident
                JOIN maintenance_catch_up_incidents catch_up ON catch_up.incident_id = incident.id
                JOIN events catch_up_event ON catch_up_event.id = catch_up.event_id
                    AND catch_up_event.type = 'maintenance.catch_up'
                    AND catch_up_event.occurred_at >= incident.opened_at
                    AND catch_up_event.occurred_at <= CURRENT_TIMESTAMP
                JOIN deliveries accepted_delivery ON accepted_delivery.event_id = catch_up.event_id
                    AND accepted_delivery.state = 'sent' AND NOT accepted_delivery.suppressed
                WHERE incident.resolved_at IS NULL
            )
            UPDATE incidents incident
            SET evidence = jsonb_set(incident.evidence, '{notification_opening_accepted}', 'true'::jsonb, true)
            WHERE incident.resolved_at IS NULL AND incident.id IN (SELECT id FROM accepted_openings)
                AND NOT (incident.evidence @> '{"notification_opening_accepted":true}'::jsonb)
            SQL);
    }

    public function down(): void
    {
        // Acceptance is retained delivery history, not a schema feature to erase on rollback.
    }
};
