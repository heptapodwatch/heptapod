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
        $days = config()->integer('monitoring.raw_retention_days');
        if ($days < 1) {
            throw new LogicException('Activity retention must be positive.');
        }
        // Serialize the retained backfill before acquiring event-table locks, like result recording.
        DB::statement('SELECT id FROM checks ORDER BY id FOR UPDATE');
        // Column-only EXPLAIN scanned 5,678 members for a deep page. Persist only heads, not unbounded counts.
        Schema::create('event_flap_groups', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->foreignId('latest_event_id')->unique()->constrained('events')->cascadeOnDelete();
            $table->timestampTz('last_at', 6);
            $table->index(['last_at', 'latest_event_id']);
        });
        foreach (DB::table('checks')->orderBy('id')->lazyById(100) as $check) {
            if (! is_int($check->id)) {
                throw new LogicException('Check identity must be an integer.');
            }
            $this->backfillCheck($check->id, $days);
        }
        $this->backfillCheck(null, $days);
    }

    private function backfillCheck(?int $checkId, int $days): void
    {
        DB::statement(<<<'SQL'
            WITH previous AS (
                SELECT id, check_id, type, component_type, from_result, to_result, occurred_at,
                    lag(type) OVER history AS previous_type,
                    lag(component_type) OVER history AS previous_component,
                    lag(from_result) OVER history AS previous_from,
                    lag(to_result) OVER history AS previous_to,
                    lag(occurred_at) OVER history AS previous_at
                FROM events WHERE occurred_at > CURRENT_TIMESTAMP - (? * interval '1 day')
                    AND ((?::bigint IS NULL AND check_id IS NULL) OR check_id = ?::bigint)
                WINDOW history AS (PARTITION BY check_id ORDER BY occurred_at, id)
            ), boundaries AS (
                SELECT *, CASE WHEN check_id IS NOT NULL
                    AND type = 'check.result_changed' AND previous_type = type
                    AND component_type IS NULL AND previous_component IS NULL
                    AND from_result <> 'pending' AND to_result <> 'pending'
                    AND from_result <> to_result AND from_result = previous_to AND to_result = previous_from
                    AND occurred_at - previous_at < interval '2 hours'
                    THEN 0 ELSE 1 END AS boundary FROM previous
            ), numbered AS (
                SELECT *, sum(boundary) OVER (PARTITION BY check_id ORDER BY occurred_at, id ROWS UNBOUNDED PRECEDING) AS sequence
                FROM boundaries
            ), assigned AS (
                SELECT id, first_value(id) OVER (PARTITION BY check_id, sequence ORDER BY occurred_at, id) AS group_id FROM numbered
            )
            UPDATE events SET flap_group_id = CASE WHEN events.id = assigned.group_id THEN NULL ELSE assigned.group_id END
            FROM assigned WHERE events.id = assigned.id
            SQL, [$days, $checkId, $checkId]);
        // Historical snapshot of feed visibility. No current application classes are called by this migration.
        DB::statement(<<<'SQL'
            INSERT INTO event_flap_groups (id, latest_event_id, last_at)
            SELECT DISTINCT ON (coalesce(flap_group_id, id)) coalesce(flap_group_id, id), id, occurred_at
            FROM events
            WHERE occurred_at > CURRENT_TIMESTAMP - (? * interval '1 day')
                    AND ((?::bigint IS NULL AND check_id IS NULL) OR check_id = ?::bigint)
                AND type <> 'incident.still_down'
                AND NOT ((type = 'cron.task_result_changed' AND (payload->>'from_result' IS NULL OR payload->>'from_result' = 'pending') AND payload->>'result' = 'succeeded') IS TRUE)
                AND (type <> 'check.result_changed' OR (from_result IS NOT NULL AND from_result <> 'pending' AND from_result <> to_result
                    AND NOT EXISTS (SELECT 1 FROM events transition WHERE transition.check_id IS NOT DISTINCT FROM events.check_id
                        AND transition.site_id IS NOT DISTINCT FROM events.site_id AND transition.occurred_at = events.occurred_at
                        AND transition.type IN ('incident.opened', 'incident.resolved', 'incident.cause_changed')
                        AND transition.payload->>'run_id' = events.payload->>'run_id' AND transition.payload->>'run_ended_at' = events.payload->>'run_ended_at')))
                AND NOT (type = 'check.result_changed' AND to_result IN ('warning', 'failed', 'errored-or-timed-out')
                    AND EXISTS (SELECT 1 FROM events dns_change WHERE dns_change.type = 'dns.records_changed'
                        AND dns_change.check_id IS NOT DISTINCT FROM events.check_id AND dns_change.site_id IS NOT DISTINCT FROM events.site_id
                        AND dns_change.occurred_at = events.occurred_at AND dns_change.from_result IS NOT DISTINCT FROM events.from_result
                        AND dns_change.to_result IS NOT DISTINCT FROM events.to_result AND jsonb_typeof(events.payload->'run_id') = 'number'
                        AND CASE WHEN dns_change.type <> 'dns.records_changed' THEN NULL
                            WHEN dns_change.dedupe_key ~ '^dns:[1-9][0-9]*:[1-9][0-9]*$'
                                AND (dns_change.check_id IS NULL OR split_part(dns_change.dedupe_key, ':', 2) = dns_change.check_id::text)
                                THEN split_part(dns_change.dedupe_key, ':', 3)
                            ELSE ('Invalid DNS event dedupe key: ' || coalesce(dns_change.dedupe_key, 'NULL'))::bigint::text END = events.payload->>'run_id'))
                AND NOT (type = 'check.result_changed' AND to_result IN ('failed', 'errored-or-timed-out')
                    AND EXISTS (SELECT 1 FROM incidents failure_window WHERE failure_window.check_id = events.check_id
                        AND failure_window.first_failure_at <= events.occurred_at AND (failure_window.resolved_at IS NULL OR events.occurred_at <= failure_window.resolved_at)))
            ORDER BY coalesce(flap_group_id, id), occurred_at DESC, id DESC
            SQL, [$days, $checkId, $checkId]);
    }

    public function down(): void
    {
        Schema::dropIfExists('event_flap_groups');
    }
};
