<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_run_attempts', function (Blueprint $table): void {
            $table->boolean('history_result_repeated')->nullable();
        });
        DB::unprepared(<<<'SQL'
            -- Only a terminal attempt with an exact physical run identity can repeat its physical predecessor.
            CREATE OR REPLACE FUNCTION attempt_history_result_repeated(bigint, bigint, timestamptz, text)
            RETURNS boolean LANGUAGE sql STABLE AS $$
                SELECT accepted.result = (
                    SELECT previous.result FROM check_runs previous
                    WHERE previous.check_id = accepted.check_id
                        AND (previous.ended_at, previous.id) < (accepted.ended_at, accepted.id)
                    ORDER BY previous.ended_at DESC, previous.id DESC LIMIT 1
                ) FROM check_runs accepted
                WHERE $4 IN ('done', 'failed') AND accepted.check_id = $1
                    AND accepted.id = $2 AND accepted.ended_at = $3
            $$;
            CREATE OR REPLACE FUNCTION project_attempt_history_result() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.phase IN ('done', 'failed') THEN
                    NEW.history_result_repeated := attempt_history_result_repeated(
                        NEW.check_id, NEW.check_run_id, NEW.finished_at, NEW.phase);
                ELSE
                    NEW.history_result_repeated := NULL;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER check_run_attempts_history_result
                BEFORE INSERT OR UPDATE OF check_id, check_run_id, finished_at, phase ON check_run_attempts
                FOR EACH ROW EXECUTE FUNCTION project_attempt_history_result();

            -- Recorders serialize on the check row and insert the immutable run before finishing its attempt.
            -- A late physical insertion can also change the next accepted run's predecessor.
            CREATE OR REPLACE FUNCTION repair_successor_attempt_history_result() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                successor record;
            BEGIN
                SELECT id, ended_at INTO successor FROM check_runs
                WHERE check_id = NEW.check_id AND (ended_at, id) > (NEW.ended_at, NEW.id)
                ORDER BY ended_at, id LIMIT 1;
                IF FOUND THEN
                    UPDATE check_run_attempts SET history_result_repeated = attempt_history_result_repeated(
                        check_id, check_run_id, finished_at, phase)
                    WHERE check_id = NEW.check_id AND check_run_id = successor.id
                        AND finished_at = successor.ended_at AND phase IN ('done', 'failed');
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER check_runs_history_successor AFTER INSERT ON check_runs
                FOR EACH ROW EXECUTE FUNCTION repair_successor_attempt_history_result();

            -- Row triggers also cover direct partition deletes; a deleted check has no surviving attempt history.
            CREATE OR REPLACE FUNCTION repair_deleted_attempt_history_result() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                successor record;
            BEGIN
                PERFORM 1 FROM checks WHERE id = OLD.check_id FOR UPDATE;
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
            END $$;
            CREATE TRIGGER check_runs_history_deleted AFTER DELETE ON check_runs
                FOR EACH ROW EXECUTE FUNCTION repair_deleted_attempt_history_result();
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER check_runs_history_deleted ON check_runs');
        DB::statement('DROP FUNCTION repair_deleted_attempt_history_result()');
        DB::statement('DROP TRIGGER check_runs_history_successor ON check_runs');
        DB::statement('DROP FUNCTION repair_successor_attempt_history_result()');
        DB::statement('DROP TRIGGER check_run_attempts_history_result ON check_run_attempts');
        DB::statement('DROP FUNCTION project_attempt_history_result()');
        DB::statement('DROP FUNCTION attempt_history_result_repeated(bigint, bigint, timestamptz, text)');
        Schema::table('check_run_attempts', function (Blueprint $table): void {
            $table->dropColumn('history_result_repeated');
        });
    }
};
