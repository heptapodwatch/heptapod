<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Retained events hold three ON DELETE SET NULL references (site_id, check_id, incident_id) across a
        // cascading diamond (sites -> checks -> incidents). PostgreSQL fires AFTER DELETE RI triggers in ASCII order
        // of RI_ConstraintTrigger_a_<oid>; when an OID digit boundary places events' SET NULL trigger before the
        // checks/incidents CASCADE triggers, an event written in the same transaction is re-validated after its check
        // or incident has been deleted and before its own SET NULL runs (SQLSTATE 23503). Detach retained events in
        // one BEFORE DELETE pass so the subsequent cascades never race each other.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION detach_site_events_before_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE events
                SET site_id = NULL, check_id = NULL, incident_id = NULL
                WHERE site_id = OLD.id;
                RETURN OLD;
            END $$;
            CREATE TRIGGER sites_detach_events BEFORE DELETE ON sites
                FOR EACH ROW EXECUTE FUNCTION detach_site_events_before_delete();

            CREATE OR REPLACE FUNCTION detach_check_events_before_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE events
                SET check_id = NULL, incident_id = NULL
                WHERE check_id = OLD.id;
                RETURN OLD;
            END $$;
            CREATE TRIGGER checks_detach_events BEFORE DELETE ON checks
                FOR EACH ROW EXECUTE FUNCTION detach_check_events_before_delete();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS checks_detach_events ON checks');
        DB::statement('DROP FUNCTION IF EXISTS detach_check_events_before_delete()');
        DB::statement('DROP TRIGGER IF EXISTS sites_detach_events ON sites');
        DB::statement('DROP FUNCTION IF EXISTS detach_site_events_before_delete()');
    }
};
