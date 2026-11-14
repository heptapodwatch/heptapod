<?php

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Enums\HealthStatus;
use Heptapod\Models\DispatcherHeartbeat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

final readonly class ReadSystemHealth
{
    public function __construct(private MasterSupervisorRepository $masters, private SupervisorRepository $supervisors, private CheckDataMaintenanceRecord $checkDataMaintenance, private MailTransportRecord $mailTransport, private DatabaseGrowth $databaseGrowth) {}

    public function __invoke(): SystemHealthData
    {
        $queues = [];
        /** @var array<string, array<string, SupervisorOptions>> $plans */
        $plans = ProvisioningPlan::get('system-health')->parsed;
        // Horizon's CLI-only --environment override is unsupported by this health reading.
        /** @var string $horizonEnvironment */
        $horizonEnvironment = config('horizon.env') ?? app()->environment();
        foreach ($plans as $environment => $options) {
            if (! Str::is($environment, $horizonEnvironment)) {
                continue;
            }
            foreach ($options as $supervisor) {
                foreach (explode(',', $supervisor->queue) as $queue) {
                    $key = $supervisor->connection.':'.$queue;
                    if (isset($queues[$key])) {
                        continue;
                    }
                    $depth = Queue::connection($supervisor->connection)->size($queue);
                    $queues[$key] = new HealthMetric($depth >= config()->integer('heptapod.health.queue_down_depth') ? HealthStatus::Down : ($depth >= config()->integer('heptapod.health.queue_warn_depth') ? HealthStatus::Warn : HealthStatus::Ok), $depth);
                }
            }
            break;
        }
        /** @var array<object{name: string, status: string, processes: array<string, int>}> $supervisors */
        $supervisors = $this->supervisors->all();
        $workers = [];
        $runningWorkers = 0;
        foreach ($supervisors as $supervisor) {
            /** @var array<string, int> $processes */
            $processes = $supervisor->processes;
            $count = array_sum($processes);
            $workers[] = ['name' => $supervisor->name, 'status' => $supervisor->status, 'workers' => $count];
            if ($supervisor->status === 'running') {
                $runningWorkers += $count;
            }
        }
        /** @var array<object{name: string, status: string, processes: array<string, int>}> $masters */
        $masters = $this->masters->all();
        $masterRunning = collect($masters)->contains(fn (object $master): bool => $master->status === 'running');
        $browserServices = [];
        /** @var array<string, string|null> $urls */
        $urls = config()->array('heptapod.health.browser_urls');
        foreach ($urls as $name => $url) {
            $browserServices[$name] = $this->browserHealth($url);
        }
        $query = DispatcherHeartbeat::query();
        $query->getQuery()->orderByDesc('last_seen_at');
        $dispatcherAt = $query->first()?->getAttribute('last_seen_at');
        if ($dispatcherAt !== null && ! $dispatcherAt instanceof CarbonImmutable) {
            throw new \UnexpectedValueException('Dispatcher heartbeat must be a timestamp.');
        }
        $schedulerAt = $this->cachedTimestamp(config()->string('heptapod.health.scheduler_cache_key'));
        $recentFailed = DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count();
        /** @var int $databaseBytes */
        $databaseBytes = DB::scalar('SELECT pg_database_size(current_database())');

        /** @var list<string> $deliveryPrivateHosts */
        $deliveryPrivateHosts = config()->array('heptapod.outbound.delivery_private_hosts');

        return new SystemHealthData(
            $queues,
            new HealthMetric($masterRunning && $runningWorkers > 0 ? HealthStatus::Ok : HealthStatus::Down, $runningWorkers),
            $workers,
            $this->workerMemory(),
            $this->age($dispatcherAt, 'dispatcher'),
            $this->age($schedulerAt === null ? null : CarbonImmutable::parse($schedulerAt), 'scheduler'),
            $browserServices,
            $this->reverbHealth(),
            $databaseBytes,
            $this->databaseGrowth->read(),
            DB::table('failed_jobs')->count(),
            new HealthMetric($recentFailed > 0 ? HealthStatus::Warn : HealthStatus::Ok, $recentFailed),
            $this->cachedTimestamp(config()->string('heptapod.health.external_ping_cache_key')),
            $this->cachedTimestamp('heptapod:live:termination-failed-at'),
            $this->checkDataMaintenance->read(),
            SystemIssueData::open(),
            $this->systemIssueEvaluation(),
            $this->mailTransport->read(),
            $deliveryPrivateHosts,
        );
    }

    /** @return array{evaluated_at: string|null, failed_at: string|null, error: string|null} */
    private function systemIssueEvaluation(): array
    {
        $stored = Cache::store('redis')->get(EvaluateSystemIssues::EvaluationKey) ?? ['evaluated_at' => null, 'failed_at' => null, 'error' => null];
        if (! is_array($stored) || array_any(['evaluated_at', 'failed_at', 'error'], static fn (string $field): bool => ! array_key_exists($field, $stored) || ($stored[$field] !== null && ! is_string($stored[$field])))) {
            throw new \UnexpectedValueException('System issue evaluation record in the health cache is invalid.');
        }

        /** @var array{evaluated_at: string|null, failed_at: string|null, error: string|null} $stored */
        return $stored;
    }

    private function workerMemory(): HealthMetric
    {
        $sample = Cache::store('redis')->get(PublishWorkerMemory::Key);
        if ($sample === null) {
            return new HealthMetric(HealthStatus::NotConfigured, null, 'Worker memory unknown: no recent sample.', 'no_sample');
        }
        if (is_array($sample) && ($sample['available'] ?? null) === false) {
            $reason = match ($sample) {
                ['available' => false] => null,
                ['available' => false, 'reason' => 'no_sample'] => 'no_sample',
                ['available' => false, 'reason' => 'unlimited'] => 'unlimited',
                default => throw new \UnexpectedValueException('Worker memory unavailable reason is invalid.'),
            };

            return new HealthMetric(HealthStatus::NotConfigured, null, 'Worker cgroup memory limit unavailable.', $reason);
        }
        if (! is_array($sample) || ! isset($sample['current'], $sample['max']) || ! is_int($sample['current']) || ! is_int($sample['max']) || $sample['max'] < 1) {
            throw new \UnexpectedValueException('Worker memory sample is invalid.');
        }
        $percentage = (int) floor($sample['current'] * 100 / $sample['max']);

        return new HealthMetric($percentage > 80 ? HealthStatus::Warn : HealthStatus::Ok, $percentage);
    }

    private function cachedTimestamp(string $key): ?string
    {
        $value = Cache::store('redis')->get($key);
        if ($value !== null && ! is_string($value)) {
            throw new \UnexpectedValueException('Health cache timestamps must be ISO-8601 strings.');
        }

        return $value;
    }

    private function age(?CarbonImmutable $at, string $component): HealthMetric
    {
        $age = $at instanceof CarbonImmutable ? max(0, (int) $at->diffInSeconds(CarbonImmutable::now())) : null;

        return new HealthMetric($age === null || $age > config()->integer('heptapod.health.'.$component.'_warn_seconds') ? HealthStatus::Down : ($age > config()->integer('heptapod.health.'.$component.'_ok_seconds') ? HealthStatus::Warn : HealthStatus::Ok), $age);
    }

    private function reverbHealth(): HealthMetric
    {
        if (config('broadcasting.default') !== 'reverb') {
            return new HealthMetric(HealthStatus::NotConfigured, null);
        }

        foreach (['key', 'secret', 'app_id'] as $field) {
            $credential = config('broadcasting.connections.reverb.'.$field);
            if (! is_string($credential) || $credential === '') {
                return new HealthMetric(HealthStatus::NotConfigured, null, 'Reverb app credentials are missing; configure REVERB_APP_ID, REVERB_APP_KEY and REVERB_APP_SECRET.');
            }
        }

        $host = config('broadcasting.connections.reverb.options.host');
        if (! is_string($host) || $host === '') {
            throw new \UnexpectedValueException('Reverb publishing host is missing; set REVERB_HOST to the private Reverb service.');
        }
        $port = filter_var(config('broadcasting.connections.reverb.options.port'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new \UnexpectedValueException('Reverb port must be an integer between 1 and 65535.');
        }
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 1);
        if ($socket === false) {
            return new HealthMetric(HealthStatus::Down, null, "$errorMessage ($errorCode)");
        }
        fclose($socket);

        return new HealthMetric(HealthStatus::Ok, null);
    }

    private function browserHealth(?string $url): HealthMetric
    {
        if ($url === null) {
            return new HealthMetric(HealthStatus::NotConfigured, null);
        }
        try {
            $response = Http::timeout(3)->get($url);

            return new HealthMetric($response->successful() ? HealthStatus::Ok : HealthStatus::Down, $response->status());
        } catch (ConnectionException $exception) {
            // Health probes are the boundary: unreachable is an explicit operational failure, not a fallback.
            return new HealthMetric(HealthStatus::Down, null, $exception->getMessage());
        }
    }
}
