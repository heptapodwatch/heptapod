<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\HealthStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ShellHealthData extends Data
{
    /** @param list<array{title_key: string, action_key: string, href: string}> $causes */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly ?string $dispatcher_at,
        public readonly ?string $last_result_at,
        public readonly int $check_errors,
        public readonly int $active_checks,
        public readonly int $site_count,
        public readonly int $open_incident_count,
        public readonly int $down_site_count,
        public readonly bool $notifications_covered,
        /** Worst severity of open system issues; null when there are none or the user cannot open System health. */
        public readonly ?HealthStatus $system_issue_status,
        public readonly int $system_issue_count,
        public readonly array $causes = [],
    ) {}
}
