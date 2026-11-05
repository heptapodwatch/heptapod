<?php

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\RunResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Horizon;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $prefix = 'journey_'.Str::uuid().':';
    config(['database.redis.options.prefix' => $prefix, 'cache.default' => 'redis',
        'cache.prefix' => $prefix, 'horizon.prefix' => $prefix.'horizon:']);
    Horizon::use(config('horizon.use'));
    Redis::purge('default');
    Redis::purge('cache');
    Redis::purge('horizon');
});

function journeyWaitBudget(): int
{
    // Allow a full lease or Redis reservation to expire, plus scheduler grace under load.
    return max(ExecutionBudget::lease(ExecutionBudget::MaximumTimeout), config('queue.connections.redis.retry_after')) + ExecutionBudget::Grace;
}

function journeyProcess(array $arguments): Process
{
    return new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), [
        'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis', 'CACHE_PREFIX' => config('cache.prefix'),
        'REDIS_PREFIX' => config('database.redis.options.prefix'), 'HORIZON_PREFIX' => config('horizon.prefix'),
    ], timeout: journeyWaitBudget());
}

function waitForJourney(Closure $condition, Process $process, Check $check): void
{
    $budget = journeyWaitBudget();
    $deadline = hrtime(true) + $budget * 1_000_000_000;
    while (! $condition()) {
        if (! $process->isRunning() || hrtime(true) >= $deadline) {
            // A one-shot worker can commit between the condition query and the exit check.
            if ($condition()) {
                return;
            }
            $diagnostic = json_encode([
                'budget_seconds' => $budget,
                'process' => ['pid' => $process->getPid(), 'running' => $process->isRunning(), 'exit_code' => $process->getExitCode()],
                'check' => $check->fresh()->only(['id', 'latest_run_result', 'dispatched_at', 'lease_expires_at', 'run_started_at', 'active_run_id']),
                'last_run' => CheckRun::query()->where('check_id', $check->id)->latest('id')->first()?->only(['id', 'result', 'started_at', 'ended_at']),
                'queue_size' => Queue::connection('redis')->size('checks-fast'),
                'horizon' => app(MasterSupervisorRepository::class)->all(),
                // Never dump job payloads: check configurations can contain credentials.
                'jobs' => app(JobRepository::class)->getRecent()->map(fn (object $job): array => ['id' => $job->id, 'status' => $job->status, 'queue' => $job->queue])->all(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            test()->fail("Journey condition not reached.\n".$diagnostic."\n".$process->getErrorOutput().$process->getOutput());
        }
        usleep(100_000);
    }
}

/** Independent workers require committed fixtures, not the enclosing RefreshDatabase transaction. */
function withJourneySite(string $path, Closure $test): void
{
    $site = Site::factory()->create(['url' => 'http://fixtures.test'.$path]);
    $heartbeatIds = DB::table('dispatcher_heartbeats')->pluck('id')->all();
    DB::commit();
    try {
        $test($site);
    } finally {
        Queue::connection('redis')->clear('checks-fast');
        DB::table('sites')->where('id', $site->id)->delete();
        DB::table('clients')->where('id', $site->client_id)->delete();
        DB::table('dispatcher_heartbeats')->whereNotIn('id', $heartbeatIds)->delete();
        DB::beginTransaction();
    }
}

it('dispatches through Redis and Horizon to record a fixture failure, incident and transactional events', function (): void {
    withJourneySite('/status/503', function (Site $site): void {
        $check = Check::factory()->for($site)->create(['failure_threshold' => 1, 'next_run_at' => now()->subMinute()]);
        $horizon = journeyProcess(['horizon']);
        try {
            $horizon->start();
            $this->artisan('heptapod:dispatch', ['--once' => true])->assertSuccessful();
            waitForJourney(fn (): bool => $check->fresh()->latest_run_result === CheckResult::Failed, $horizon, $check);
            $run = CheckRun::query()->where('check_id', $check->id)->sole();
            $incident = Incident::query()->where('check_id', $check->id)->sole();
            expect($run->result)->toBe(RunResult::Failed)
                ->and($run->metrics['total_time_in_seconds'])->toBeGreaterThan(0)
                ->and($incident->evidence['evidence']['status_code'])->toBe(503)
                ->and(Event::query()->where('check_id', $check->id)->orderBy('id')->pluck('type')->all())->toBe(['incident.opened', 'check.result_changed'])
                ->and($check->refresh()->claim_token)->toBeNull();
        } finally {
            $horizon->stop(5, SIGTERM);
        }
    });
});

it('observes real redirect, slow and timeout responses without inventing cURL samples', function (string $path, array $settings, RunResult $result): void {
    withJourneySite($path, function (Site $site) use ($path, $settings, $result): void {
        $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
        $check = Check::factory()->for($site)->create(['config' => $schema->validateConfig($settings)]);
        $horizon = journeyProcess(['horizon']);
        try {
            $horizon->start();
            expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
            waitForJourney(fn (): bool => CheckRun::query()->where('check_id', $check->id)->exists(), $horizon, $check);
            $run = CheckRun::query()->where('check_id', $check->id)->sole();
            expect($run->result)->toBe($result);
            if ($path === '/timeout') {
                expect($run->metrics['total_time_in_seconds'])->toBeNull()
                    ->and($run->evidence['issues'])->toContain('connection');
            } else {
                expect($run->metrics['total_time_in_seconds'])->toBeGreaterThan($path === '/slow' ? 2.5 : 0)
                    ->and($run->metrics['curl']['size_download'])->toBeGreaterThan(0);
            }
            if ($path === '/slow') {
                $performance = Check::factory()->for($site)->create(['type' => CheckKey::Performance]);
                expect(app(ClaimService::class)->runNow($performance->id, RunOrigin::System))->not->toBeNull();
                waitForJourney(fn (): bool => CheckRun::query()->where('check_id', $performance->id)->exists(), $horizon, $performance);
                $derived = CheckRun::query()->where('check_id', $performance->id)->sole();
                expect($derived->result)->toBe(RunResult::Warning)
                    ->and($derived->metrics['sample_count'])->toBe(1)
                    ->and($derived->metrics['average_response_time_in_ms'])->toBeGreaterThan(2500);
            }
        } finally {
            $horizon->stop(5, SIGTERM);
        }
    });
})->with([
    'redirect' => ['/redirect/start', ['expected_final_redirect_url' => 'http://fixtures.test/redirect/final'], RunResult::Succeeded],
    'slow uptime stays online, derived performance warns' => ['/slow', [], RunResult::Succeeded],
    'timeout is target connection failure' => ['/timeout', ['timeout' => 1], RunResult::Failed],
]);

it('reclaims a Redis worker killed during a real probe and rejects its late result', function (): void {
    withJourneySite('/slow', function (Site $site): void {
        $check = Check::factory()->for($site)->create();
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        $oldToken = $check->refresh()->claim_token;
        $worker = journeyProcess(['queue:work', 'redis', '--queue=checks-fast', '--once', '--timeout=60']);
        $horizon = journeyProcess(['horizon']);
        try {
            $worker->start();
            waitForJourney(fn (): bool => $check->fresh()->run_started_at instanceof CarbonImmutable, $worker, $check);
            $oldStartedAt = $check->refresh()->run_started_at;
            $worker->signal(SIGKILL);
            $worker->wait();
            expect($worker->getTermSignal())->toBe(SIGKILL)
                ->and(CheckRun::query()->where('check_id', $check->id)->count())->toBe(0);
            DB::update("UPDATE checks SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?", [$check->id]);
            expect(app(ClaimService::class)->dispatchDue())->toBe(1)
                ->and($check->refresh()->claim_token)->not->toBe($oldToken);
            $horizon->start();
            waitForJourney(fn (): bool => CheckRun::query()->where('check_id', $check->id)->exists(), $horizon, $check);
            $late = app(ResultRecorder::class)->record(new RecordableRun($check->id, $oldToken, $oldStartedAt, CarbonImmutable::now(),
                new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'Late dead attempt'))));
            expect($late->accepted)->toBeFalse()
                ->and(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1)
                ->and(Event::query()->where('check_id', $check->id)->count())->toBe(1)
                ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded)
                ->and($check->claim_token)->toBeNull();
        } finally {
            $worker->stop(0, SIGKILL);
            $horizon->stop(5, SIGTERM);
        }
    });
});
