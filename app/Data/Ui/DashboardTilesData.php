<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DashboardTilesData extends Data
{
    /**
     * certificates_expiring counts enabled certificate checks whose latest result is Warning.
     * cron_jobs_failing sums failed tasks from the latest accepted cron run's metrics, not failed checks.
     */
    public function __construct(
        public readonly int $sites,
        public readonly int $down,
        public readonly int $check_errors,
        public readonly int $warning,
        public readonly int $pending,
        public readonly int $paused,
        public readonly int $open_incidents,
        public readonly ?float $uptime,
        public readonly int $certificates_expiring,
        public readonly int $cron_jobs_failing,
        public readonly int $ok,
        public readonly int $failed,
        public readonly int $maintenance,
        public readonly int $disabled,
        public readonly int $unavailable,
        public readonly ?int $certificates_expiring_30_days = null,
        public readonly ?int $domains_expiring_30_days = null,
        public readonly ?int $scheduled_tasks = null,
        public readonly ?int $scheduled_tasks_overdue_pings = null,
    ) {}
}
