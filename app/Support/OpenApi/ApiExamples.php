<?php

declare(strict_types=1);

namespace Heptapod\Support\OpenApi;

/**
 * The examples of docs/openapi.json, keyed by operationId. scripts/check-openapi.php validates every example against
 * its schema, and the DocumentApi guard fails on a body without one, so an example cannot drift from the contract.
 *
 * One small world keeps every ID consistent across operations, with the values of tests/Fixtures/OhDear (monitor 1 at
 * example.com, uptime check 100, run 200): broken links check 101 (run 201), cron check 102 whose task 400 failed
 * (run 202, incident 300; manual run attempt 700 recorded run 203), certificate check 103, maintenance window 500, tag 600.
 */
final class ApiExamples
{
    private const string Created = '2026-10-01 07:25:00';

    private const string Opened = '2026-10-01T07:30:00.000000Z';

    /** @return array<string, mixed> request body examples */
    public static function requests(): array
    {
        $task = ['name' => 'Nightly backup', 'type' => 'cron', 'cron_expression' => '0 3 * * *', 'server_timezone' => 'Europe/Berlin', 'grace_time_in_minutes' => 30,
            'frequency_in_minutes' => null, 'description' => 'Database backup'];

        return [
            'createMonitor' => ['team_id' => 1, 'type' => 'http', 'url' => 'https://example.com', 'group_name' => 'Websites', 'tags' => ['production'],
                'checks' => ['uptime', 'broken_links', 'cron'], 'uptime_check_settings' => ['frequency_value' => 1, 'frequency_unit' => 'minute']],
            'updateMonitor' => ['friendly_name' => 'Example shop', 'tags' => ['production', 'shop']],
            'createTag' => ['team_id' => 1, 'name' => 'production', 'monitors' => [1]],
            'requestCheckRun' => ['httpClientHeaders' => [['name' => 'X-Monitor', 'value' => 'heptapod']]],
            'snoozeCheck' => ['snooze_until' => '2026-10-01T09:30:00Z'],
            'createMaintenancePeriod' => ['monitor_id' => 1, 'starts_at' => '2026-10-20 22:00', 'ends_at' => '2026-10-20 23:00', 'name' => 'Planned deploy'],
            'updateMaintenancePeriod' => ['starts_at' => '2026-10-20 22:30', 'ends_at' => '2026-10-20 23:30', 'name' => 'Planned deploy'],
            'startMaintenance' => ['stop_maintenance_after_seconds' => 1800, 'name' => 'Deploy'],
            'stopMaintenance' => ['delay_seconds' => 60],
            'createCronCheck' => $task,
            'updateCronCheck' => ['grace_time_in_minutes' => 45],
            'syncCronChecks' => ['cron_checks' => [$task, ['name' => 'Hourly import', 'type' => 'simple', 'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 10]]],
            'snoozeCronCheck' => ['minutes' => 120],
            'internal.saveIncidentNote' => ['note' => 'Backup host was rebooted; watching the next run.'],
            'internal.snoozeIncident' => ['minutes' => 90],
        ];
    }

    /** @return array<string, array<int, mixed>> response body examples per status */
    public static function responses(): array
    {
        $monitor = self::monitor(fresh: false);
        $created = self::monitor(fresh: true);
        $links = static fn (string $path): array => ['first' => 'https://monitoring.example.com/api/'.$path.'?page=1', 'last' => 'https://monitoring.example.com/api/'.$path.'?page=1',
            'prev' => null, 'next' => null];
        $meta = static fn (string $path): array => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'path' => 'https://monitoring.example.com/api/'.$path, 'per_page' => 200, 'to' => 1, 'total' => 1];
        $snoozedCheck = [...self::check(102, 'cron', 'Cron', 'failed', '1 of 2 failing · Nightly backup', '2026-10-01 07:30:00', 900),
            'active_snooze' => self::snooze(800, '2026-10-01 07:35:00', '2026-10-01 09:30:00', 'api')];
        $task = self::cronTask();
        $tag = ['id' => 600, 'team_id' => 1, 'team_name' => 'Heptapod', 'name' => 'production', 'slug' => 'production', 'created_at' => self::Created, 'updated_at' => self::Created, 'sites' => [1]];
        $window = ['id' => 500, 'monitor_id' => 1, 'name' => 'Planned deploy', 'starts_at' => '2026-10-20 22:00:00', 'ends_at' => '2026-10-20 23:00:00', 'scope' => 'site', 'shared' => false];
        $note = ['note' => 'Backup host was rebooted; watching the next run.', 'note_author' => 'Ada Admin', 'note_updated_at' => '2026-10-01T07:40:00+00:00'];

        return [
            'getOpenApiDocument' => [200 => ['openapi' => '3.1.0', 'info' => ['title' => 'Heptapod API', 'version' => '1'], 'paths' => ['/me' => ['get' => ['operationId' => 'getMe']]]]],
            'getMe' => [200 => ['id' => 1, 'name' => 'Ada Admin', 'email' => 'ada@example.com', 'photo_url' => 'https://monitoring.example.com/favicon.svg',
                'teams' => [['id' => 1, 'name' => 'Heptapod', 'timezone' => 'Europe/Berlin', 'created_at' => '2026-09-30T12:00:00+00:00']]]],
            'listMonitors' => [200 => ['data' => [$monitor], 'links' => $links('monitors'), 'meta' => $meta('monitors')]],
            'createMonitor' => [201 => $created],
            'getMonitor' => [200 => $monitor],
            'getMonitorByUrl' => [200 => $monitor],
            'updateMonitor' => [200 => [...$monitor, 'label' => 'Example shop', 'tags' => ['production', 'shop']]],
            'listTags' => [200 => ['data' => [$tag]]],
            'createTag' => [201 => $tag],
            'enableCheck' => [200 => ['id' => 101, 'type' => 'broken_links', 'enabled' => true]],
            'disableCheck' => [200 => ['id' => 101, 'type' => 'broken_links', 'enabled' => false]],
            'snoozeCheck' => [200 => $snoozedCheck],
            'unsnoozeCheck' => [200 => self::check(102, 'cron', 'Cron', 'failed', '1 of 2 failing · Nightly backup', '2026-10-01 07:30:00', 900)],
            'requestCheckRun' => [200 => self::check(100, 'uptime', 'Uptime', 'succeeded', 'Up', '2026-10-01 07:29:02', 900)],
            'getCheckSummary' => [200 => ['result' => 'succeeded', 'summary' => 'Up']],
            'createCheckRun' => [202 => ['attempt_ref' => 'attempt:700', 'check_ref' => 'check:102', 'phase' => 'queued', 'requested_at' => '2026-10-01T07:45:00.000000Z', 'poll_url' => '/api/runs/attempt:700']],
            'getRun' => [200 => ['attempt_ref' => 'attempt:700', 'check_ref' => 'check:102', 'phase' => 'done', 'terminal' => true, 'requested_at' => '2026-10-01T07:45:00.000000Z',
                'started_at' => '2026-10-01T07:45:01.000000Z', 'finished_at' => '2026-10-01T07:45:02.000000Z', 'progress' => ['done' => 2, 'total' => 2], 'result_status' => 'recorded',
                'result' => ['run_ref' => ['id' => 203, 'endedAt' => '2026-10-01T07:45:02.000000+00:00'], 'result' => 'failed', ...self::cronHeadline()]]],
            'listCheckRuns' => [200 => ['data' => [['id' => 200, 'check_id' => 100, 'check_type' => 'uptime', 'result' => 'succeeded', 'started_at' => '2026-10-01T07:29:01+00:00',
                'ended_at' => '2026-10-01T07:29:02+00:00', 'ohdear_url' => 'https://monitoring.example.com/checks/100/runs/200']],
                'links' => [...$links('monitors/1/checks/uptime/runs'), 'documentation_url' => 'https://monitoring.example.com/docs/api'], 'meta' => $meta('monitors/1/checks/uptime/runs')]],
            'findings' => [200 => self::findings()],
            'listIncidents' => [200 => ['data' => [self::incident()], 'effective_filters' => ['state' => 'open', 'site_id' => null, 'client_id' => null, 'check_id' => null, 'since' => null,
                'window_start' => '2026-09-01T07:50:00.000000Z', 'window' => 'open_or_first_failure_since'], 'order' => 'opened_at_desc', 'has_more' => false, 'next_cursor' => null]],
            'getIncident' => [200 => ['data' => [...self::incident(), 'opening_evidence' => ['run_ref' => ['id' => 202, 'endedAt' => '2026-10-01T07:30:00.000000+00:00'], ...self::cronHeadline(), 'summary' => self::cronSummary()],
                'current_evidence' => ['run_ref' => ['id' => 203, 'endedAt' => '2026-10-01T07:45:02.000000+00:00'], 'result' => 'failed', ...self::cronHeadline(), 'summary' => self::cronSummary()],
                'findings_selectors' => null, 'note' => ['included' => false, 'present' => true],
                'timeline' => [['kind' => 'event', 'at' => '2026-10-01T07:30:00.000000+00:00', 'event_ref' => 'event:900', 'event_type' => 'incident.opened'],
                    ['kind' => 'notification_group', 'at' => '2026-10-01T07:30:05.000000+00:00', 'last_at' => '2026-10-01T07:30:06.000000+00:00', 'event_type' => 'incident.opened', 'state' => 'accepted',
                        'count' => 2, 'recipient_count' => 2, 'accepted_count' => 2, 'logged_count' => 0, 'failed_count' => 0, 'suppressed_count' => 0, 'pending_count' => 0, 'unrecorded_count' => 0,
                        'unrecorded_reasons' => new \stdClass],
                    ['kind' => 'note', 'at' => '2026-10-01T07:40:00.000000+00:00', 'note' => null]],
                'timeline_total' => 3, 'timeline_as_of' => '2026-10-01T07:50:00.000000Z', 'timeline_has_more' => false, 'timeline_next_cursor' => null]]],
            'internal.getIncidentNote' => [200 => $note],
            'internal.saveIncidentNote' => [200 => $note],
            'internal.snoozeIncident' => [200 => ['snoozed_until' => '2026-10-01T09:20:00+00:00']],
            'internal.unsnoozeIncident' => [200 => ['snoozed_until' => null]],
            'listActivity' => [200 => ['data' => [['group_ref' => 'activity:900', 'latest_event_ref' => 'event:900', 'event_type' => 'incident.opened', 'check_ref' => 'check:102', 'site_ref' => 'site:1',
                'client_ref' => 'client:1', 'client_name' => 'Internal', 'incident_ref' => 'incident:300', 'event_count' => 1, 'started_at' => self::Opened, 'ended_at' => self::Opened,
                'first_state' => 'succeeded', 'second_state' => 'failed', 'current_state' => 'failed', ...self::cronHeadline(), 'summary' => self::cronSummary()]],
                'range' => ['since' => '2026-09-24T07:50:00.000000Z'], 'order' => 'ended_at_desc', 'consistency' => 'live_keyset', 'has_more' => false, 'next_cursor' => null]],
            'listUpcoming' => [200 => ['data' => [
                ['key' => 'maintenance:500', 'type' => 'maintenance', 'at' => '2026-10-20T22:00:00.000000Z', 'ends_at' => '2026-10-20T23:00:00.000000Z', 'check_ref' => null, 'site_ref' => 'site:1',
                    'client_ref' => null, 'client_name' => null, 'target' => ['type' => 'site', 'id' => 1], 'detail' => 'Planned deploy', 'detail_source' => 'maintenance_name'],
                ['key' => 'certificate:103', 'type' => 'certificate', 'at' => '2026-10-28T12:00:00.000000Z', 'ends_at' => null, 'check_ref' => 'check:103', 'site_ref' => 'site:1',
                    'client_ref' => 'client:1', 'client_name' => 'Internal', 'target' => null, 'detail' => 'Example Certificate Authority', 'detail_source' => 'certificate_issuer']],
                'range' => ['from' => '2026-10-01T07:50:00.000000Z', 'until' => '2026-10-31T07:50:00.000000Z'], 'consistency' => 'live_keyset', 'has_more' => false, 'next_cursor' => null]],
            'getUptime' => [200 => [['datetime' => '2026-10-01 06:00:00', 'uptime_percentage' => 100], ['datetime' => '2026-10-01 07:00:00', 'uptime_percentage' => 98.75]]],
            'httpUptimeMetrics' => [200 => ['data' => [['dns_time_in_seconds' => 0.012, 'tcp_time_in_seconds' => 0.021, 'ssl_handshake_time_in_seconds' => 0.045,
                'remote_server_processing_time_in_seconds' => 0.18, 'download_time_in_seconds' => 0.004, 'total_time_in_seconds' => 0.262,
                'curl' => ['header_size' => 412, 'size_download' => 18234, 'speed_download' => 69594], 'date' => '2026-10-01 07:00:00']]]],
            'getDowntime' => [200 => ['data' => [['id' => 301, 'started_at' => '2026-10-01 07:10:00', 'ended_at' => '2026-10-01 07:14:00', 'notes_markdown' => '', 'notes_html' => '']]]],
            'listMaintenancePeriods' => [200 => ['data' => [$window]]],
            'createMaintenancePeriod' => [201 => $window],
            'updateMaintenancePeriod' => [200 => [...$window, 'starts_at' => '2026-10-20 22:30:00', 'ends_at' => '2026-10-20 23:30:00']],
            'startMaintenance' => [200 => [...$window, 'id' => 501, 'name' => 'Deploy', 'starts_at' => '2026-10-01 07:50:00', 'ends_at' => '2026-10-01 08:20:00']],
            'listCronChecks' => [200 => ['data' => [$task]]],
            'createCronCheck' => [201 => [...$task, 'latest_result' => 'pending', 'active_snooze' => null]],
            'updateCronCheck' => [200 => [...$task, 'grace_time_in_minutes' => 45]],
            'syncCronChecks' => [200 => [$task, [...self::cronTask(), 'id' => 401, 'uuid' => '6f1c2e9a-0b7d-4c55-9a3e-2d4f8b1c7e60', 'name' => 'Hourly import', 'type' => 'simple',
                'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 10, 'cron_expression' => null, 'server_timezone' => null, 'description' => '',
                'ping_url' => 'https://monitoring.example.com/cron/6f1c2e9a-0b7d-4c55-9a3e-2d4f8b1c7e60', 'latest_result' => 'pending']]],
            'snoozeCronCheck' => [200 => [...$task, 'active_snooze' => self::snooze(801, '2026-10-01 07:35:00', '2026-10-01 09:35:00', 'api')]],
            'unsnoozeCronCheck' => [200 => $task],
        ];
    }

    /**
     * Shared error examples (components.examples), name => [summary, value]. DocumentApiOperation::error() names them per status.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function errors(): array
    {
        return [
            'Unauthenticated' => ['Missing or invalid token', ['error' => 'Unauthenticated.']],
            'Forbidden' => ['The token lacks the ability', ['message' => 'Invalid ability provided.']],
            'NotFound' => ['Missing or hidden', ['message' => 'No query results for model [Heptapod\\Models\\Site] 12']],
            'ValidationFailed' => ['Invalid input', ['message' => 'The starts at field must match the format Y-m-d H:i.',
                'errors' => ['starts_at' => ['The starts at field must match the format Y-m-d H:i.']]]],
            'TooManyRequests' => ['Rate limited', ['error' => 'Too Many Attempts.']],
        ];
    }

    /** @return array<string, mixed> monitor 1 with its uptime, broken links and cron checks; `fresh` is the state right after createMonitor */
    private static function monitor(bool $fresh): array
    {
        $result = static fn (string $result): string => $fresh ? 'pending' : $result;
        $checks = [
            self::check(100, 'uptime', 'Uptime', $result('succeeded'), $fresh ? null : 'Up', $fresh ? null : '2026-10-01 07:29:02', 900),
            self::check(101, 'broken_links', 'Broken links', $result('succeeded'), $fresh ? null : 'No broken links', $fresh ? null : '2026-10-01 07:28:40', 21600),
            self::check(102, 'cron', 'Cron', $result('failed'), $fresh ? null : '1 of 2 failing · Nightly backup', $fresh ? null : '2026-10-01 07:30:00', 900),
            self::check(103, 'certificate_health', 'Certificate health', $result('warning'), $fresh ? null : 'Certificate expires in 27 days', $fresh ? null : '2026-10-01 07:26:10', 10800),
        ];

        return ['id' => 1, 'team_id' => 1, 'type' => 'http', 'url' => 'https://example.com', 'uses_https' => true, 'sort_url' => 'example.com', 'label' => 'example.com',
            'group_name' => 'Websites', 'client_ref' => 'client:1', 'client_name' => 'Internal', 'tags' => ['production'], 'description' => null, 'notes' => null,
            'latest_run_date' => $fresh ? null : '2026-10-01 07:30:00', 'summarized_check_result' => $result('failed'),
            'alert_sensitivity' => ['preset' => 'relaxed', 'alert_after_minutes' => 10, 'recovery_checks' => 3, 'inherited' => true],
            'checks' => $checks, 'badge_id' => '00000000-0000-4000-8000-000000000123', 'marked_for_deletion_at' => null, 'crawler_headers' => null,
            'send_report_to_emails' => null, 'include_check_types_in_report' => null, 'created_at' => self::Created, 'updated_at' => self::Created,
            'uptime_check_settings' => self::settings('uptime'), 'broken_links_check_settings' => self::settings('broken_links'),
            'certificate_health_check_settings' => self::settings('certificate_health')];
    }

    /** @return array<string, mixed> */
    private static function check(int $id, string $type, string $label, string $result, ?string $summary, ?string $endedAt, int $thresholdSeconds): array
    {
        return ['freshness_suppressed_by' => null, 'freshness_suppressed_since' => null, 'freshness_latest_error_at' => null, 'display_result' => $result,
            'performance_unavailable' => null, 'freshness' => ['state' => 'fresh', 'awaiting_result_since' => null, 'not_fresh_since' => null, 'threshold_seconds' => $thresholdSeconds, 'lost_attempts' => 0],
            'last_attempt_loss' => null, 'unavailable_reason' => null, 'id' => $id, 'type' => $type, 'label' => $label, 'enabled' => true,
            'latest_run_ended_at' => $endedAt, 'latest_run_result' => $result, 'summary' => $summary, 'settings' => [$type.'_check_settings' => self::settings($type)], 'active_snooze' => null];
    }

    /** @return array<string, int|string>|\stdClass the `<type>_check_settings` of a check; cron has none */
    private static function settings(string $type): array|\stdClass
    {
        return match ($type) {
            'uptime' => ['failed_notification_threshold' => 10, 'frequency_value' => 1, 'frequency_unit' => 'minute'],
            'broken_links' => ['failed_notification_threshold' => 1, 'frequency_value' => 1, 'frequency_unit' => 'day'],
            'certificate_health' => ['failed_notification_threshold' => 1, 'frequency_value' => 60, 'frequency_unit' => 'minute'],
            default => new \stdClass,
        };
    }

    /** @return array<string, mixed> */
    private static function snooze(int $id, string $startsAt, string $endsAt, string $source): array
    {
        return ['id' => $id, 'starts_at' => $startsAt, 'ends_at' => $endsAt, 'snoozed_by' => 'Ada Admin', 'unsnoozed_by' => null, 'source' => $source, 'created_at' => $startsAt];
    }

    /** @return array<string, mixed> task 400 of cron check 102, as an Owner or Admin sees it */
    private static function cronTask(): array
    {
        return ['id' => 400, 'uuid' => '3b2f8c1e-7d4a-4e6b-9f20-5c8a1d3e4b70', 'name' => 'Nightly backup', 'type' => 'cron', 'frequency_in_minutes' => null, 'grace_time_in_minutes' => 30,
            'cron_expression' => '0 3 * * *', 'server_timezone' => 'Europe/Berlin', 'description' => 'Database backup',
            'ping_url' => 'https://monitoring.example.com/cron/3b2f8c1e-7d4a-4e6b-9f20-5c8a1d3e4b70', 'created_at' => self::Created, 'latest_result' => 'pingFailed',
            'latest_ping_at' => '2026-10-01 01:02:10', 'human_readable_latest_ping_at' => '6 hours ago', 'active_snooze' => null];
    }

    /** @return array<string, mixed> incident 300: cron check 102 failed because task 400 reported a failure */
    private static function incident(): array
    {
        return ['incident_ref' => 'incident:300', 'check_ref' => 'check:102', 'site_ref' => 'site:1', 'client_ref' => 'client:1', 'client_name' => 'Internal', 'check_type' => 'cron',
            'state' => 'open', 'cause' => 'failed', ...self::cronHeadline(), 'first_failure_at' => self::Opened, 'opened_at' => self::Opened, 'resolved_at' => null,
            'duration_seconds' => 1200, 'snoozed_until' => null, 'site_url' => 'https://example.com'];
    }

    /** @return array<string, mixed> the presented headline of the failing cron run; the task name is untrusted DATA in headline_data */
    private static function cronHeadline(): array
    {
        return ['headline' => '1 of 2 tasks is failing: see failing_tasks.', 'headline_status' => 'presented',
            'headline_parts' => ['reasons' => [['key' => 'cron.presentation.failed', 'params' => ['total' => 2, 'failed' => 1, 'pending' => 0, 'warning' => 0, 'remaining' => 0, 'succeeded' => 1, 'count' => 1],
                'labels' => new \stdClass, 'untrusted_fields' => ['names' => 'failing_tasks']]], 'more_reasons' => 0, 'names_check' => true, 'changes' => [], 'details' => []],
            'headline_data' => ['failing_tasks' => ['Nightly backup']], 'display_headline' => '1 of 2 tasks is failing: Nightly backup.', 'display_headline_untrusted' => true];
    }

    /** @return array<string, mixed> */
    private static function cronSummary(): array
    {
        return ['template' => 'cron.tasks_failed', 'params' => ['total' => 2, 'failed' => 1, 'pending' => 0, 'warning' => 0, 'remaining' => 0, 'succeeded' => 1],
            'untrusted_params' => ['names' => 'Nightly backup']];
    }

    /** @return array<string, mixed> one broken link from broken links run 201, selected by check and run */
    private static function findings(): array
    {
        $run = ['id' => 201, 'endedAt' => '2026-10-01T07:28:40.000000+00:00'];

        return ['selector' => ['check_id' => 101, 'run_ref' => $run],
            'source' => ['check_id' => 101, 'type' => 'broken_links', 'snapshot_ref' => 'crawl:41', 'run' => $run, 'observed_at' => '2026-10-01T07:28:40.000000+00:00',
                'coverage' => ['discovery_complete' => true, 'crawl_truncated' => false, 'page_cap_reached' => false, 'resource_cap_reached' => false, 'time_limit_reached' => false,
                    'comparison_limit_reached' => false, 'stored_rows_complete' => true, 'stored_not_verifiable_complete' => true, 'unchecked_rows_available' => true, 'legacy' => false,
                    'counters' => ['observed_pages' => 48, 'observed_urls' => 312, 'discovered_urls' => 312], 'limits' => ['max_pages' => 1000, 'comparison' => 25000]],
                'supported_filters' => ['broken', 'not_verifiable', 'unchecked'], 'supported_baseline_comparison' => true, 'dataset_version' => 1],
            'baseline' => ['status' => 'not_requested', 'definition' => null, 'source' => null],
            'rows' => [['row_ref' => 'crawl:41:rows:9001', 'finding_identity' => '5d41402abc4b2a76b9719d911017c5925d41402abc4b2a76b9719d911017c592', 'status' => 'broken',
                'issue_code' => null, 'http_status' => 404, 'kind' => 'broken', 'internal' => true, 'url' => 'https://example.com/old-pricing', 'found_on' => 'https://example.com/blog/launch',
                'link_text' => 'See our pricing', 'error_message' => null, 'cause' => null, 'first_seen_at' => '2026-09-28T07:31:12.000000+00:00', 'new_since_opening' => null,
                'new_since_baseline' => null, 'carried_forward' => false, 'source_observed_at' => '2026-10-01T07:28:40.000000+00:00', 'current_observation_cause' => null, 'field_truncation' => []]],
            'has_more' => false, 'next_cursor' => null];
    }
}
