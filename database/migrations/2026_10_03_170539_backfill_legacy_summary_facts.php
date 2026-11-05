<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE check_runs AS r SET summary = 'causes.response_time',
                summary_params = jsonb_build_object(
                    'seconds', round(((COALESCE(r.metrics->>'average_response_time_in_ms', r.summary_params->>'average'))::numeric / 1000), 2),
                    'threshold', round((COALESCE(c.config->>'threshold_in_ms', '2000')::numeric / 1000), 2))
            FROM checks AS c WHERE r.check_id = c.id AND r.summary = 'performance.slow'
                AND jsonb_typeof(COALESCE(r.metrics->'average_response_time_in_ms', r.summary_params->'average')) = 'number'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE check_runs AS r SET summary = 'causes.certificate_expiry',
                summary_params = jsonb_build_object('days', trunc(extract(epoch FROM ((r.evidence->'certificate_details'->>'valid_until')::timestamptz - r.ended_at)) / 86400)::integer,
                    'presentation', 'direct')
            WHERE r.summary = 'certificate_health.expires_soon'
                AND jsonb_typeof(r.evidence->'certificate_details'->'valid_until') = 'string'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE check_runs AS r SET summary = 'causes.timeout',
                summary_params = jsonb_build_object('seconds', COALESCE(r.evidence->'timeout_seconds', c.config->'timeout', '30'::jsonb))
            FROM checks AS c WHERE r.check_id = c.id AND r.summary IN ('uptime.failed', 'uptime.status')
                AND r.result = 'failed' AND r.evidence->>'failure_kind' = 'timeout'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE check_runs SET summary = 'causes.tls', summary_params = '{}'::jsonb
            WHERE summary IN ('uptime.failed', 'uptime.status') AND result = 'failed' AND evidence->>'failure_kind' = 'tls'
            SQL);
        DB::statement(<<<'SQL'
            UPDATE check_runs SET summary = CASE WHEN jsonb_typeof(metrics->'total_time_in_seconds') = 'number'
                    THEN 'causes.http_timing' ELSE 'causes.http' END,
                summary_params = CASE WHEN jsonb_typeof(metrics->'total_time_in_seconds') = 'number'
                    THEN jsonb_build_object('status', evidence->'status_code', 'seconds', round((metrics->>'total_time_in_seconds')::numeric, 2))
                    ELSE jsonb_build_object('status', evidence->'status_code') END
            WHERE summary IN ('uptime.failed', 'uptime.status') AND result = 'failed'
                AND jsonb_typeof(evidence->'status_code') = 'number' AND jsonb_exists(evidence->'issues', 'status')
            SQL);

        DB::statement(<<<'SQL'
            UPDATE check_runs SET summary = CASE evidence#>>'{issues,0}'
                    WHEN 'connection' THEN 'causes.connection'
                    WHEN 'header' THEN 'causes.header'
                    WHEN 'body_assertion' THEN 'causes.body_assertion'
                    WHEN 'redirect_url' THEN 'causes.redirect_unexpected' END,
                summary_params = '{}'::jsonb
            WHERE summary = 'uptime.failed' AND result = 'failed'
                AND evidence#>>'{issues,0}' IN ('connection', 'header', 'body_assertion', 'redirect_url')
            SQL);

        // Physical run identity includes ended_at; sequence IDs can recur across historical partitions.
        DB::statement(<<<'SQL'
            UPDATE checks AS c SET summary = r.summary, summary_params = r.summary_params
            FROM check_runs AS r WHERE c.latest_run_id = r.id AND c.latest_run_ended_at = r.ended_at
                AND c.summary IN ('performance.slow', 'certificate_health.expires_soon', 'uptime.failed', 'uptime.status')
                AND r.summary NOT IN ('performance.slow', 'certificate_health.expires_soon', 'uptime.failed', 'uptime.status')
            SQL);
        DB::statement(<<<'SQL'
            UPDATE events AS e SET payload = e.payload || jsonb_build_object('summary', r.summary, 'summary_params', r.summary_params)
            FROM check_runs AS r WHERE e.check_id = r.check_id AND e.payload->>'run_id' = r.id::text
                AND (e.payload->>'run_ended_at')::timestamptz = r.ended_at
                AND e.payload->>'summary' IN ('performance.slow', 'certificate_health.expires_soon', 'uptime.failed', 'uptime.status')
                AND r.summary NOT IN ('performance.slow', 'certificate_health.expires_soon', 'uptime.failed', 'uptime.status')
            SQL);
    }

    public function down(): void
    {
        // Old summary facts cannot be reconstructed after run retention; keep the canonical facts on rollback.
    }
};
