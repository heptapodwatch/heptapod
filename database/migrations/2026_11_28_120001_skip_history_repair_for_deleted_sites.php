<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The purge before a site deletion (PurgeDeletedSite) deletes its runs while their checks still exist. Repairing the
 * attempt history around each deleted run is pointless for a site that is going away and made up nearly all of a batch's
 * time, so the repair now stops, as for a check that is already gone, when the check's site is marked for deletion; the
 * final DeleteSite cascade skips it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->define('PERFORM 1 FROM checks JOIN sites ON sites.id = checks.site_id
                WHERE checks.id = OLD.check_id AND sites.marked_for_deletion_at IS NULL FOR UPDATE OF checks;');
    }

    public function down(): void
    {
        $this->define('PERFORM 1 FROM checks WHERE id = OLD.check_id FOR UPDATE;');
    }

    /** @param literal-string $lockLiveCheck */
    private function define(string $lockLiveCheck): void
    {
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION repair_deleted_attempt_history_result() RETURNS trigger LANGUAGE plpgsql AS \$\$
            DECLARE
                successor record;
            BEGIN
                {$lockLiveCheck}
                IF NOT FOUND THEN
                    RETURN OLD;
                END IF;
                UPDATE check_run_attempts SET history_result_repeated = NULL
                WHERE check_id = OLD.check_id AND check_run_id = OLD.id AND finished_at = OLD.ended_at
                    AND history_result_repeated IS NOT NULL;
                SELECT id, ended_at INTO successor FROM check_runs
                WHERE check_id = OLD.check_id AND (ended_at, id) > (OLD.ended_at, OLD.id)
                ORDER BY ended_at, id LIMIT 1;
                IF FOUND THEN
                    UPDATE check_run_attempts SET history_result_repeated = attempt_history_result_repeated(
                        check_id, check_run_id, finished_at, phase)
                    WHERE check_id = OLD.check_id AND check_run_id = successor.id
                        AND finished_at = successor.ended_at AND phase IN ('done', 'failed');
                END IF;
                RETURN OLD;
            END \$\$;
            SQL);
    }
};
