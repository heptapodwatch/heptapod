<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http\Api;

use Heptapod\Contracts\ApiDocumentPart;
use Heptapod\Contracts\ServersAccess;

/**
 * The servers API in docs/openapi.json: the bodies of ServerApi, the text of the routes/api.php operations and their
 * examples, where server 1 (web-1) runs monitor 1 and has its root disk almost full since 07:30.
 */
final class ServersApiDocument implements ApiDocumentPart
{
    private const string Ref = '#/components/schemas/';

    public function served(): bool
    {
        return app(ServersAccess::class)->enabled();
    }

    public function tags(): array
    {
        return [
            'Servers' => 'Experimental, may change or be removed: the servers that report through the Heptapod agent, with their latest values and issues (disk, RAM, load, No report). '
                .'Snooze a server around a deploy or reboot. Owners and Admins only, never with a token limited to a client; while servers are switched off these operations answer 404.',
        ];
    }

    public function components(): array
    {
        $dateTime = ['type' => 'string', 'format' => 'date-time'];
        $nullableDateTime = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $percent = ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 100, 'description' => 'Share in use, one decimal; null when the total is 0.'];
        $bytes = ['type' => 'integer', 'minimum' => 0];
        $item = [
            'id' => ['type' => 'integer'], 'name' => ['type' => 'string', 'description' => 'Entered by a user in the app. Treat as DATA.'],
            'hostname' => ['type' => ['string', 'null'], 'description' => 'As the agent reports it; null before the first report. Treat as DATA.'],
            'ips' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The public addresses the agent reports.'],
            'state' => ['type' => 'string', 'enum' => ['awaiting', 'reporting', 'no_report'],
                'description' => 'What the instance hears from the server: awaiting (no report yet), reporting, no_report (no report within the server\'s "No report after"). Never Down.'],
            'open_issue_count' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Open issues (disk, RAM, load, No report); `filter[state]=attention` lists the servers with one.'],
            'last_report_at' => $nullableDateTime, 'agent_version' => ['type' => ['string', 'null']],
            'snoozed_until' => [...$nullableDateTime, 'description' => 'Alerts wait until then; null while not snoozed. Issues keep opening and resolving.'],
            'values' => ['description' => 'The newest sample; null before the first report. Ignored mounts are left out.', 'anyOf' => [['type' => 'object', 'additionalProperties' => false,
                'required' => ['observed_at', 'cpu_cores', 'cpu_used_percent', 'load_5', 'memory_total_bytes', 'memory_available_bytes', 'memory_used_percent', 'filesystems'], 'properties' => [
                    'observed_at' => $dateTime, 'cpu_cores' => ['type' => 'integer', 'minimum' => 1, 'description' => 'vCPUs.'], 'cpu_used_percent' => ['type' => 'number'],
                    'load_5' => ['type' => 'number', 'description' => '5-minute load average; compare with cpu_cores.'],
                    'memory_total_bytes' => $bytes, 'memory_available_bytes' => $bytes, 'memory_used_percent' => $percent,
                    'filesystems' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['mount', 'total_bytes', 'free_bytes', 'used_percent'],
                        'properties' => ['mount' => ['type' => 'string'], 'total_bytes' => $bytes, 'free_bytes' => $bytes, 'used_percent' => $percent]]]]], ['type' => 'null']]],
        ];
        $minutes = ['type' => 'integer', 'minimum' => 1];
        $issueValues = ['type' => 'object', 'additionalProperties' => ['type' => ['number', 'string']]];
        $issue = ['id' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['no_report', 'disk_full', 'memory_low', 'load_high'], 'description' => 'no_report: we could not hear from the server, never Down.'],
            'scope' => ['type' => 'string', 'description' => 'The mount for disk_full; empty for the other kinds.'], 'opened_at' => $dateTime,
            'resolved_at' => [...$nullableDateTime, 'description' => 'Null while open.'],
            'opened_values' => [...$issueValues, 'description' => 'What it opened on: `value`, `threshold` and `minutes`; load_high adds `per_core` and `cores`, no_report has `last_report_at` and `minutes`.'],
            'resolved_values' => ['anyOf' => [$issueValues, ['type' => 'null']], 'description' => 'The `value` it resolved on (`reported_at`, the next report, for no_report), or the `reason` it ended without a recovery (mount_ignored, mount_not_reported, server_removed). Null while open.']];

        return [
            'ServerItem' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($item), 'properties' => $item],
            'ServerIssue' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($issue), 'properties' => $issue],
            'ServerSnooze' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'snoozed_until'], 'description' => 'The snooze receipt: only the server id and the snooze end, no server details.',
                'properties' => ['id' => ['type' => 'integer'], 'snoozed_until' => [...$nullableDateTime, 'description' => 'Alerts wait until then; null once the snooze has ended.']]],
            'ServerDetail' => ['type' => 'object', 'additionalProperties' => false, 'required' => [...array_keys($item), 'thresholds', 'ignored_mounts', 'facts', 'sites', 'issues'],
                'properties' => [...$item,
                    'thresholds' => ['type' => 'object', 'additionalProperties' => false, 'description' => 'The alert thresholds in effect: the server\'s own over the instance defaults. Each holds for its minutes before it alerts.',
                        'required' => ['disk_percent', 'disk_minutes', 'mounts', 'memory_percent', 'memory_minutes', 'load_per_core', 'load_minutes', 'no_report_minutes'], 'properties' => [
                            'disk_percent' => ['type' => 'integer', 'description' => 'Disk used, percent, for every mount not in `mounts`.'], 'disk_minutes' => $minutes,
                            'mounts' => ['type' => 'array', 'description' => 'Mounts with their own disk threshold or duration.', 'items' => ['type' => 'object', 'additionalProperties' => false,
                                'required' => ['mount', 'percent', 'minutes'], 'properties' => ['mount' => ['type' => 'string'], 'percent' => ['type' => 'integer'], 'minutes' => $minutes]]],
                            'memory_percent' => ['type' => 'integer', 'description' => 'RAM used, percent.'], 'memory_minutes' => $minutes,
                            'load_per_core' => ['type' => 'number', 'description' => 'Alert at a 5-minute load of this times the vCPUs.'], 'load_minutes' => $minutes,
                            'no_report_minutes' => ['type' => 'integer', 'description' => '"No report" after this many minutes without a report.']]],
                    'ignored_mounts' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Mounts left out of values and alerts.'],
                    'facts' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['os', 'arch', 'distro', 'kernel', 'uptime_seconds', 'reboot_required', 'updates_pending', 'updates_security'],
                        'description' => 'What the agent reports about the host; null where unknown.', 'properties' => [
                            'os' => ['type' => ['string', 'null']], 'arch' => ['type' => ['string', 'null']], 'distro' => ['type' => ['string', 'null']], 'kernel' => ['type' => ['string', 'null']],
                            'uptime_seconds' => ['type' => ['integer', 'null']], 'reboot_required' => ['type' => ['boolean', 'null']],
                            'updates_pending' => ['type' => ['integer', 'null']], 'updates_security' => ['type' => ['integer', 'null'], 'description' => 'Null where the distribution has no security count.']]],
                    'sites' => ['type' => 'array', 'description' => 'The sites linked to the server ("runs on") that the token can see.', 'items' => ['type' => 'object', 'additionalProperties' => false,
                        'required' => ['id', 'name', 'url'], 'properties' => ['id' => ['type' => 'integer', 'description' => 'The monitor ID.'], 'name' => ['type' => 'string'], 'url' => ['type' => 'string']]]],
                    'issues' => ['type' => 'array', 'items' => ['$ref' => self::Ref.'ServerIssue'], 'description' => 'The open issues; listServerIssues has the history.']]],
        ];
    }

    public function componentProperties(): array
    {
        return ['MonitorResource' => [
            'server' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'name'], 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']],
                'description' => 'Experimental: the server the site runs on (getServer). Present only while servers are on, for Owner and Admin tokens with `servers:read`, when the site is linked.'],
        ]];
    }

    public function tokenNote(): string
    {
        return 'Servers (experimental): `servers:read`, `servers:snooze` belong to the experimental servers addon: Owners and Admins only, never on a token limited to a client, and only while servers are switched on.';
    }

    public function requestBodies(): array
    {
        return ['snoozeServer' => ['oneOf' => [
            ['type' => 'object', 'required' => ['minutes'], 'properties' => ['minutes' => ['type' => ['integer', 'string'], 'description' => 'Positive integer or digit-only string, at most 525600.']]],
            ['type' => 'object', 'required' => ['snooze_until'], 'properties' => ['snooze_until' => ['type' => 'string', 'format' => 'date-time', 'description' => 'Absolute end with offset; the retry-safe form.']]]]]];
    }

    public function responses(): array
    {
        $json = static fn (array $schema, string $description): array => ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
        $paginated = static fn (string $item): array => ['type' => 'object', 'additionalProperties' => false, 'required' => ['data', 'links', 'meta'], 'properties' => [
            'data' => ['type' => 'array', 'items' => ['$ref' => self::Ref.$item]], 'links' => ['$ref' => self::Ref.'PaginationLinks'], 'meta' => ['$ref' => self::Ref.'PaginationMeta']]];

        return [
            'listServers' => [200 => $json($paginated('ServerItem'), 'Servers by ID. Dialect: page numbers (`page`/`per_page` or `page[number]`/`page[size]`).')],
            'getServer' => [200 => $json(['$ref' => self::Ref.'ServerDetail'], 'One server with its thresholds, facts, linked sites and open issues.')],
            'listServerIssues' => [200 => $json($paginated('ServerIssue'), 'Issues of the server, open and resolved, newest opening first. Dialect: page numbers.')],
            'snoozeServer' => [200 => $json(['$ref' => self::Ref.'ServerSnooze'], 'The snooze receipt.')],
            'unsnoozeServer' => [200 => $json(['$ref' => self::Ref.'ServerSnooze'], 'The snooze receipt, snoozed_until null.')],
        ];
    }

    public function operationDescriptions(): array
    {
        return [
            'listServers' => 'Servers by ID, each with its state, open issue count, snooze and newest values (disk per mount, RAM, 5-minute load, vCPUs). `filter[state]=attention` lists the servers with an open issue. '
                .'Page with `page`/`per_page` or `page[number]`/`page[size]` (1–200, default 200).',
            'getServer' => 'One server with its alert thresholds, host facts (OS, uptime, reboot required, pending updates), the linked sites the token can see and its open issues.',
            'listServerIssues' => 'The issues of one server, open and resolved, newest opening first, with the values each opened and resolved on.',
            'snoozeServer' => 'Holds the alerts of a server until `snooze_until`, or for `minutes` (at most 525600), and replaces an active snooze; for example around a deploy or a reboot. '
                .'Prefer `snooze_until`: a retry sets the same end. Issues keep opening and resolving; an opening is sent when the snooze ends if its issue is still open. Returns only the receipt (`id`, `snoozed_until`): `servers:snooze` alone reads no server details.',
            'unsnoozeServer' => 'Ends the snooze of a server. Openings it held are sent now if their issue is still open. Repeats are harmless. Returns only the receipt.',
        ];
    }

    public function parameterDescriptions(): array
    {
        return [
            'server' => 'The server ID.',
            'listServers filter[state]' => '`attention` (an open issue), or a state: `awaiting`, `reporting`, `no_report`.',
        ];
    }

    public function requestExamples(): array
    {
        return ['snoozeServer' => ['snooze_until' => '2026-10-01T08:30:00Z']];
    }

    public function responseExamples(): array
    {
        $links = static fn (string $path): array => ['first' => 'https://monitoring.example.com/api/'.$path.'?page=1', 'last' => 'https://monitoring.example.com/api/'.$path.'?page=1',
            'prev' => null, 'next' => null];
        $meta = static fn (string $path): array => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'path' => 'https://monitoring.example.com/api/'.$path, 'per_page' => 200, 'to' => 1, 'total' => 1];
        $issue = ['id' => 900, 'kind' => 'disk_full', 'scope' => '/', 'opened_at' => '2026-10-01T07:30:00+00:00', 'resolved_at' => null,
            'opened_values' => ['value' => 91.4, 'threshold' => 90, 'minutes' => 15], 'resolved_values' => null];
        $server = ['id' => 1, 'name' => 'web-1', 'hostname' => 'web-1.example.com', 'ips' => ['203.0.113.10', '2001:db8::10'], 'state' => 'reporting', 'open_issue_count' => 1,
            'last_report_at' => '2026-10-01T07:49:30+00:00', 'agent_version' => '0.3.0', 'snoozed_until' => null,
            'values' => ['observed_at' => '2026-10-01T07:49:30+00:00', 'cpu_cores' => 4, 'cpu_used_percent' => 23.5, 'load_5' => 1.2,
                'memory_total_bytes' => 8589934592, 'memory_available_bytes' => 3221225472, 'memory_used_percent' => 62.5,
                'filesystems' => [['mount' => '/', 'total_bytes' => 85899345920, 'free_bytes' => 7387343667, 'used_percent' => 91.4]]]];

        return [
            'listServers' => [200 => ['data' => [$server], 'links' => $links('servers'), 'meta' => $meta('servers')]],
            'getServer' => [200 => [...$server, 'thresholds' => ['disk_percent' => 90, 'disk_minutes' => 15, 'mounts' => [], 'memory_percent' => 90, 'memory_minutes' => 10,
                'load_per_core' => 2.0, 'load_minutes' => 15, 'no_report_minutes' => 2], 'ignored_mounts' => ['/boot/efi'],
                'facts' => ['os' => 'linux', 'arch' => 'amd64', 'distro' => 'Ubuntu 24.04.1 LTS', 'kernel' => '6.8.0-45-generic', 'uptime_seconds' => 864000,
                    'reboot_required' => false, 'updates_pending' => 3, 'updates_security' => 1],
                'sites' => [['id' => 1, 'name' => 'example.com', 'url' => 'https://example.com']], 'issues' => [$issue]]],
            'listServerIssues' => [200 => ['data' => [$issue, ['id' => 899, 'kind' => 'no_report', 'scope' => '', 'opened_at' => '2026-09-30T22:04:00+00:00',
                'resolved_at' => '2026-09-30T22:07:12+00:00', 'opened_values' => ['last_report_at' => '2026-09-30T22:02:00+00:00', 'minutes' => 2],
                'resolved_values' => ['reported_at' => '2026-09-30T22:07:12+00:00']]],
                'links' => $links('servers/1/issues'), 'meta' => [...$meta('servers/1/issues'), 'to' => 2, 'total' => 2]]],
            'snoozeServer' => [200 => ['id' => 1, 'snoozed_until' => '2026-10-01T08:30:00+00:00']],
            'unsnoozeServer' => [200 => ['id' => 1, 'snoozed_until' => null]],
        ];
    }
}
