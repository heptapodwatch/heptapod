<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Http\Api;

use Carbon\CarbonImmutable;
use Heptapod\Addons\Servers\Data\ServerIssueData;
use Heptapod\Addons\Servers\Data\ServerRowData;
use Heptapod\Addons\Servers\Data\ServerValuesData;
use Heptapod\Contracts\DirectorySiteData;

/** The server and server issue bodies of the API (ServerItem, ServerDetail and ServerIssue in docs/openapi.json). */
final class ServerApi
{
    /** @return array<string, mixed> */
    public static function item(ServerRowData $server): array
    {
        $values = $server->values;
        $used = static fn (int $total, int $free): ?float => $total > 0 ? round(100 - 100 * $free / $total, 1) : null;

        return ['id' => $server->id, 'name' => $server->name, 'hostname' => $server->hostname, 'ips' => $server->ips, 'state' => $server->state->value,
            'open_issue_count' => count($server->issues), 'last_report_at' => $server->last_report_at, 'agent_version' => $server->agent_version,
            'snoozed_until' => $server->snoozed_until !== null && CarbonImmutable::parse($server->snoozed_until)->isFuture() ? $server->snoozed_until : null,
            'values' => $values instanceof ServerValuesData ? ['observed_at' => $values->observed_at, 'cpu_cores' => $values->cores, 'cpu_used_percent' => $values->cpu_used_percent,
                'load_5' => $values->load_5, 'memory_total_bytes' => $values->memory_total_bytes, 'memory_available_bytes' => $values->memory_available_bytes,
                'memory_used_percent' => $used($values->memory_total_bytes, $values->memory_available_bytes),
                'filesystems' => array_map(static fn (array $filesystem): array => ['mount' => $filesystem['mount'], 'total_bytes' => $filesystem['total_bytes'],
                    'free_bytes' => $filesystem['free_bytes'], 'used_percent' => $used($filesystem['total_bytes'], $filesystem['free_bytes'])], $values->filesystems)] : null];
    }

    /**
     * @param  list<DirectorySiteData>  $sites  the linked sites the token can see
     * @return array<string, mixed>
     */
    public static function detail(ServerRowData $server, array $sites): array
    {
        $values = $server->values;

        return [...self::item($server), 'thresholds' => $server->thresholds->toArray(), 'ignored_mounts' => $server->ignored_mounts,
            'facts' => ['os' => $server->agent_os, 'arch' => $server->agent_arch, 'distro' => $server->agent_distro, 'kernel' => $server->agent_kernel,
                'uptime_seconds' => $values?->uptime_seconds, 'reboot_required' => $values?->reboot_required,
                'updates_pending' => $values?->updates_pending, 'updates_security' => $values?->updates_security],
            'sites' => array_map(static fn (DirectorySiteData $site): array => ['id' => $site->id, 'name' => $site->name, 'url' => $site->url], $sites),
            'issues' => array_map(static fn (ServerIssueData $issue): array => ['id' => $issue->id, 'kind' => $issue->kind->value, 'scope' => $issue->scope,
                'opened_at' => $issue->opened_at, 'resolved_at' => null, 'opened_values' => (object) $issue->values, 'resolved_values' => null], $server->issues)];
    }

    /**
     * A row of ServerReader::issues().
     *
     * @return array<string, mixed>
     */
    public static function issue(object $issue): array
    {
        /** @var object{id: int, kind: string, scope: string, opened_at: string, resolved_at: string|null, opened_values: string, resolved_values: string|null} $issue */
        return ['id' => $issue->id, 'kind' => $issue->kind, 'scope' => $issue->scope, 'opened_at' => CarbonImmutable::parse($issue->opened_at)->toIso8601String(),
            'resolved_at' => $issue->resolved_at === null ? null : CarbonImmutable::parse($issue->resolved_at)->toIso8601String(),
            'opened_values' => json_decode($issue->opened_values, flags: JSON_THROW_ON_ERROR),
            'resolved_values' => $issue->resolved_values === null ? null : json_decode($issue->resolved_values, flags: JSON_THROW_ON_ERROR)];
    }
}
