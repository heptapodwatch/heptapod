<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SystemHealthData extends Data
{
    /** @param array<string, HealthMetric> $queues
     * @param  array<string, HealthMetric>  $browserServices
     * @param  list<array{name: string, status: string, workers: int}>  $workers
     * @param  list<string>  $deliveryPrivateHosts
     * @param  list<SystemIssueData>  $systemIssues
     * @param  array{evaluated_at: string|null, failed_at: string|null, error: string|null}  $systemIssueEvaluation
     */
    public function __construct(
        public readonly array $queues,
        public readonly HealthMetric $horizon,
        public readonly array $workers,
        public readonly HealthMetric $workerMemory,
        public readonly HealthMetric $dispatcher,
        public readonly HealthMetric $scheduler,
        public readonly array $browserServices,
        public readonly HealthMetric $reverb,
        public readonly int $databaseBytes,
        /** Null until the first daily size sample exists. */
        public readonly ?DatabaseGrowthData $databaseGrowth,
        public readonly int $failedJobsTotal,
        public readonly HealthMetric $recentFailedJobs,
        public readonly ?string $lastExternalPing,
        public readonly ?string $lastLiveTerminationFailure,
        public readonly CheckDataMaintenanceHealth $checkDataMaintenance,
        public readonly array $systemIssues,
        public readonly array $systemIssueEvaluation,
        public readonly MailTransportHealth $mailTransport,
        public readonly array $deliveryPrivateHosts,
    ) {}
}
