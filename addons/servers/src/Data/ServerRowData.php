<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Addons\Servers\ServerState;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A server with its state, its newest values and what its agent reports about it, as the list and the server page show it. */
#[TypeScript]
final class ServerRowData extends Data
{
    /**
     * @param  list<string>  $ips
     * @param  list<string>  $ignored_mounts  hidden from the values and the chart; their samples are kept
     * @param  list<ServerIssueData>  $issues  the open issues, in ServerIssueKind order: the first names the server's state
     * @param  string|null  $snoozed_until  alerts wait until then while it is in the future
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ServerState $state,
        public readonly ?string $last_report_at,
        public readonly ?string $agent_version,
        public readonly ?ServerValuesData $values,
        public readonly ServerThresholdsData $thresholds,
        public readonly array $ignored_mounts,
        public readonly int $site_count,
        public readonly ?string $hostname,
        public readonly ?string $agent_os,
        public readonly ?string $agent_arch,
        public readonly ?string $agent_distro,
        public readonly ?string $agent_kernel,
        public readonly array $ips,
        public readonly array $issues,
        public readonly ?string $snoozed_until,
    ) {}
}
