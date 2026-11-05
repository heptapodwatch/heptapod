<?php

declare(strict_types=1);

use Heptapod\Contracts\CheckResult;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

/** Commit only this test's fixtures so independent PostgreSQL sessions can see them. */
function committedExecutionChecks(int $count, Closure $test): void
{
    $checks = Check::factory()->count($count)->create(['next_run_at' => now()->subMinute()]);
    $sites = $checks->map(fn (Check $check): int => $check->site_id)->all();
    $clients = DB::table('sites')->whereIn('id', $sites)->pluck('client_id')->all();
    $heartbeats = DB::table('dispatcher_heartbeats')->pluck('id')->all();
    DB::commit();
    try {
        $test($checks);
    } finally {
        Queue::connection('redis')->clear('checks-fast');
        DB::table('sites')->whereIn('id', $sites)->delete();
        DB::table('clients')->whereIn('id', $clients)->where('is_internal', false)->delete();
        DB::table('dispatcher_heartbeats')->whereNotIn('id', $heartbeats)->delete();
        DB::beginTransaction();
    }
}

function executionProcess(array $arguments): Process
{
    return new Process([PHP_BINARY, __DIR__.'/Fixtures/execution-process.php', ...$arguments], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => config('database.connections.pgsql.database'),
        'DB_URL' => '',
        'QUEUE_CONNECTION' => 'redis',
        'CACHE_STORE' => 'redis',
        'REDIS_PREFIX' => config('database.redis.options.prefix'),
    ], timeout: 15);
}

function waitForExecutionSignal(string $path, Process $process): void
{
    $deadline = hrtime(true) + 10_000_000_000;
    while (! file_exists($path) && $process->isRunning() && hrtime(true) < $deadline) {
        usleep(10000);
    }
    expect(file_exists($path))->toBeTrue($process->getErrorOutput().$process->getOutput());
}

beforeEach(function (): void {
    config(['database.redis.options.prefix' => 'heptapod_execution_test_'.Str::uuid().':']);
    Redis::purge('default');
});

it('runs two independent dispatchers with SKIP LOCKED and enqueues one job per claim only after commit', function (): void {
    committedExecutionChecks(2, function ($checks): void {
        $locked = tempnam(sys_get_temp_dir(), 'execution-lock-');
        $release = $locked.'-release';
        unlink($locked);
        $first = executionProcess(['dispatch-hold', $locked, $release]);
        try {
            $first->start();
            waitForExecutionSignal($locked, $first);
            expect(Queue::connection('redis')->size('checks-fast'))->toBe(0);
            $second = executionProcess(['dispatch']);
            $second->mustRun();
            expect(Queue::connection('redis')->size('checks-fast'))->toBe(1);
            file_put_contents($release, 'commit');
            expect($first->wait())->toBe(0);
            expect(Queue::connection('redis')->size('checks-fast'))->toBe(2);
            $tokens = $checks->map(fn (Check $check): string => $check->refresh()->claim_token)->all();
            expect(count(array_unique($tokens)))->toBe(2);
        } finally {
            $first->stop(0, SIGKILL);
            if (file_exists($locked)) {
                unlink($locked);
            }
            if (file_exists($release)) {
                unlink($release);
            }
        }
    });
});

it('recovers process death after commit before enqueue even when the next cadence is tomorrow', function (): void {
    committedExecutionChecks(1, function ($checks): void {
        $check = $checks->first();
        $process = executionProcess(['dispatch-kill-before-enqueue']);
        expect(fn (): int => $process->run())->toThrow(ProcessSignaledException::class);
        expect($process->getTermSignal())->toBe(SIGKILL)
            ->and(Queue::connection('redis')->size('checks-fast'))->toBe(0);
        $oldToken = $check->refresh()->claim_token;
        expect($oldToken)->not->toBeNull();
        DB::update("UPDATE checks SET next_run_at = CURRENT_TIMESTAMP + INTERVAL '1 day', lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?", [$check->id]);
        $due = $check->refresh()->next_run_at;
        executionProcess(['dispatch'])->mustRun();
        $newToken = $check->refresh()->claim_token;
        expect($newToken)->not->toBe($oldToken)
            ->and($check->next_run_at->equalTo($due))->toBeTrue()
            ->and(Queue::connection('redis')->size('checks-fast'))->toBe(1);
        executionProcess(['execute', (string) $check->id, $newToken])->mustRun();
        executionProcess(['execute', (string) $check->id, $oldToken])->mustRun();
        expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1)
            ->and(Event::query()->where('check_id', $check->id)->count())->toBe(1)
            ->and($check->refresh()->latest_run_result)->toBe(CheckResult::Succeeded);
    });
});

it('recovers a SIGKILL during execution and fences both the abandoned token and redelivered accepted job', function (): void {
    committedExecutionChecks(1, function ($checks): void {
        $check = $checks->first();
        executionProcess(['dispatch'])->mustRun();
        $oldToken = $check->refresh()->claim_token;
        $started = tempnam(sys_get_temp_dir(), 'execution-start-');
        unlink($started);
        $worker = executionProcess(['execute-hold', (string) $check->id, $oldToken, $started]);
        try {
            $worker->start();
            waitForExecutionSignal($started, $worker);
            expect($check->refresh()->active_run_id)->not->toBeNull()
                ->and($check->run_started_at)->not->toBeNull();
            $worker->signal(SIGKILL);
            $worker->wait();
            expect($worker->getTermSignal())->toBe(SIGKILL);
            DB::update("UPDATE checks SET lease_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE id = ?", [$check->id]);
            executionProcess(['dispatch'])->mustRun();
            $newToken = $check->refresh()->claim_token;
            expect($newToken)->not->toBe($oldToken);
            executionProcess(['execute', (string) $check->id, $newToken])->mustRun();
            executionProcess(['execute', (string) $check->id, $oldToken])->mustRun();
            executionProcess(['execute', (string) $check->id, $newToken])->mustRun();
            expect(CheckRun::query()->where('check_id', $check->id)->count())->toBe(1)
                ->and(Event::query()->where('check_id', $check->id)->count())->toBe(1)
                ->and($check->refresh()->claim_token)->toBeNull();
        } finally {
            $worker->stop(0, SIGKILL);
            if (file_exists($started)) {
                unlink($started);
            }
        }
    });
});

it('stops the dispatcher loop on SIGTERM after publishing its heartbeat', function (): void {
    committedExecutionChecks(0, function (): void {
        $process = new Process([PHP_BINARY, 'artisan', 'heptapod:dispatch'], base_path(), [
            'APP_ENV' => 'local', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
        ], timeout: 15);
        try {
            $process->start();
            $id = gethostname().':'.$process->getPid();
            $deadline = hrtime(true) + 10_000_000_000;
            while (! DB::table('dispatcher_heartbeats')->where('id', $id)->exists() && $process->isRunning() && hrtime(true) < $deadline) {
                usleep(10000);
            }
            expect(DB::table('dispatcher_heartbeats')->where('id', $id)->exists())->toBeTrue($process->getErrorOutput());
            $process->signal(SIGTERM);
            expect($process->wait())->toBe(0);
        } finally {
            $process->stop(0, SIGKILL);
        }
    });
});
