<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\CancelCheckRun;
use Heptapod\Checks\Execution\AttemptControl;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\History\CheckDataMaintenance;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchFailure;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\Http\FetchRequest;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunCancelled;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Events\LiveSignal;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Event;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\CheckRunsReader;
use Heptapod\Queries\RunsReader;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as FrameworkEvent;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\Support\QueryPlanWork;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-29T12:00:00Z'));
    Queue::fake();
});

it('signals state changes after every finished run so history reloads even when the result is unchanged', function (): void {
    $check = Check::factory()->create();
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(static fn (): FetchResult => new FetchResult(200, 'ok')));
    FrameworkEvent::fake([LiveSignal::class]);
    $definition = app(CheckRegistry::class)->get($check->type)->definition();
    for ($index = 0; $index < 2; $index++) {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        app()->call(new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue)->handle(...));
    }
    FrameworkEvent::assertDispatchedTimes(LiveSignal::class, 8);
    FrameworkEvent::assertDispatched(LiveSignal::class, static fn (LiveSignal $signal): bool => $signal->topic === 'run.finished'
        && $signal->broadcastWith()['run']['outcome'] === 'done');
});

it('stops a crawl through its addon checkpoint after a running cancel request', function (): void {
    $check = Check::factory()->for(Site::factory()->state(['url' => 'https://fixtures.test/crawl-addon/page']))
        ->create(['type' => CheckKey::BrokenLinks, 'config' => app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition()->configSchema->validateConfig([])]);
    $cancelled = false;
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(function () use ($check, &$cancelled): FetchResult {
        if (! $cancelled) {
            $cancelled = true;
            app(CancelCheckRun::class)($check);
        }

        return new FetchResult(200, '<html><body>OK</body></html>', ['Content-Type' => ['text/html']]);
    }));
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get(CheckKey::BrokenLinks)->definition();
    app()->call(new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue)->handle(...));
    expect($cancelled)->toBeTrue()
        ->and(CheckRunAttempt::query()->sole()->phase)->toBe(RunPhase::Cancelled)
        ->and($check->refresh()->claim_token)->toBeNull()
        ->and(CheckRun::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0);
});

it('backfills in-flight pre-upgrade claims with their addon queue so results still record', function (bool $expired, CheckKey $type, string $queue): void {
    $token = (string) Str::uuid();
    $started = CarbonImmutable::now('UTC');
    $check = Check::factory()->create(['claim_token' => $token, 'dispatched_at' => $started->subSecond(),
        'run_started_at' => $started, 'lease_expires_at' => $expired ? $started->subSecond() : $started->addMinutes(5), 'type' => $type]);
    $migration = require base_path('database/migrations/2026_10_04_100642_backfill_in_flight_check_run_attempts.php');
    $migration->up();
    $migration->up();
    $attempt = CheckRunAttempt::query()->sole();
    expect($attempt->phase)->toBe(RunPhase::Running)->and($attempt->queue)->toBe($queue);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $token, $started, $started->addSecond(),
        new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'failed'))));
    expect($attempt->refresh()->phase)->toBe(RunPhase::Failed)->and(CheckRun::query()->count())->toBe(1);
})->with([[false, CheckKey::Lighthouse, 'checks-browser'], [true, CheckKey::Sitemap, 'checks-crawl']]);

it('serves scoped schedule, live attempts, actors and late tolerance without leaking other clients', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create();
    $check = Check::factory()->for($site)->create(['interval_seconds' => 3600, 'next_run_at' => now()->subSeconds(359)]);
    $hidden = Check::factory()->create();
    $this->actingAs($user)->post("/sites/{$site->id}/checks/{$check->id}/run")->assertRedirect();
    $attempt = CheckRunAttempt::query()->where('check_id', $check->id)->sole();
    expect($attempt->actor_user_id)->toBe($user->id)->and($attempt->phase)->toBe(RunPhase::Queued);
    $runs = app(RunsReader::class)->history($user)->toArray();
    expect($runs['runs'][0]['check_id'])->toBe($check->id)
        ->and($runs['runs'][0]['run']['actor'])->toBe($user->name)
        ->and($runs['runs'][0]['run']['phase'])->toBe('queued');
    $this->get("/sites/{$site->id}/checks/{$check->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('schedule.interval_seconds', 3600)->where('schedule.run.actor', $user->name)->where('schedule.run.phase', 'queued'));
    $restricted = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $restricted->assignRole('Member');
    $restricted->clients()->attach($site->client_id);
    $this->actingAs($restricted);
    $this->get('/runs')->assertInertia(fn (Assert $page): Assert => $page->component('runs/index')->has('runs', 1));
    $this->get("/sites/{$hidden->site_id}/checks/{$hidden->id}")->assertNotFound();
    $this->actingAs($user);

    $attempt->update(['due_at' => now()->subSeconds(359)]);
    expect(app(RunsReader::class)->lateCount($user))->toBe(0);
    $attempt->update(['due_at' => now()->subSeconds(361)]);
    $runs = app(RunsReader::class)->history($user)->toArray();
    expect(app(RunsReader::class)->lateCount($user))->toBe(0);
    expect($runs['runs'][0]['run']['phase'])->toBe('queued')
        ->and($runs['runs'][0]['run']['queue_position'])->toBe(1);
    $attempt->update(['phase' => RunPhase::Skipped]);
    $check->update(['claim_token' => null, 'next_run_at' => now()->subSeconds(361)]);
    expect(app(CheckRunsReader::class)->state($check->refresh()->load('site'))->phase)->toBe(RunPhase::Late);
});

it('retains history for paused and disabled checks without counting them as active', function (bool $paused): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse]);
    $this->actingAs($user)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    if ($paused) {
        $check->site->update(['paused_at' => now()]);
    } else {
        $check->update(['enabled' => false]);
    }
    $this->get('/runs?check='.$check->id)->assertInertia(fn (Assert $page): Assert => $page
        ->component('runs/index')->has('runs', 1)->where('runs.0.check_id', $check->id)->has('current', 0));
    expect(app(RunsReader::class)->lateCount($user))->toBe(0);
})->with([true, false]);

it('requires confirmation for run all, keeps cadence and cancels queued heavy work before execution', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $site = Site::factory()->create();
    $nextDue = now()->addHour();
    $fast = Check::factory()->for($site)->create(['next_run_at' => $nextDue]);
    $heavy = Check::factory()->for($site)->create(['type' => CheckKey::Lighthouse, 'next_run_at' => $nextDue]);
    $this->actingAs($user)->get("/sites/{$site->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('run_all_checks', fn (Collection $plans): bool => $plans->contains(
            fn (array $plan): bool => $plan['check_id'] === $heavy->id && $plan['check_type'] === 'lighthouse'
                && $plan['queue'] === 'checks-browser'
                && $plan['maximum_seconds'] === app(CheckRegistry::class)->get(CheckKey::Lighthouse)->definition()->timeout,
        )));
    $this->post("/sites/{$site->id}/run-all")->assertSessionHasErrors('confirmed');
    expect(CheckRunAttempt::query()->count())->toBe(0);
    $this->post("/sites/{$site->id}/run-all", ['confirmed' => true])->assertRedirect();
    expect(CheckRunAttempt::query()->count())->toBe(2)
        ->and($heavy->refresh()->next_run_at->equalTo($fast->refresh()->next_run_at))->toBeTrue();
    $this->post("/sites/{$site->id}/checks/{$heavy->id}/cancel")->assertRedirect();
    expect(CheckRunAttempt::query()->where('check_id', $heavy->id)->sole()->phase)->toBe(RunPhase::Cancelled)
        ->and($heavy->refresh()->claim_token)->toBeNull()
        ->and(CheckRun::query()->count())->toBe(0);
    $this->get('/runs')->assertInertia(fn (Assert $page): Assert => $page->component('runs/index')->where('runs.0.note', 'Cancelled'));
    $this->post("/sites/{$site->id}/checks/{$fast->id}/cancel")->assertSessionHasErrors('check');
    $third = Check::factory()->for($site)->create(['type' => CheckKey::CertificateHealth]);
    $this->post("/sites/{$site->id}/run-all", ['confirmed' => true])->assertSessionHas('run_all', fn (array $result): bool => $result['queued'] === 1
        && $result['results'][0] === ['check_id' => $fast->id, 'status' => 'skipped', 'reason' => 'busy']
        && $result['results'][2] === ['check_id' => $third->id, 'status' => 'queued', 'reason' => null]);
});

it('does not offer or accept cancellation for Sitemap despite its crawl queue', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Sitemap]);
    $this->actingAs($user)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    $this->get("/sites/{$check->site_id}/checks/{$check->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('schedule.run.can_cancel', false));
    $this->post("/sites/{$check->site_id}/checks/{$check->id}/cancel")->assertSessionHasErrors('check');
    expect(CheckRunAttempt::query()->sole()->phase)->toBe(RunPhase::Queued);
});

it('does not mark paused or unavailable checks late on the Schedule card', function (bool $paused): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['next_run_at' => now()->subHours(2)]);
    if ($paused) {
        $check->site->update(['paused_at' => now()]);
    } else {
        app()->instance(CheckRegistry::class, new CheckRegistry(app()));
    }
    $this->actingAs($user)->get("/sites/{$check->site_id}/checks/{$check->id}")->assertInertia(fn (Assert $page): Assert => $page
        ->where('schedule.run.phase', 'scheduled'));
})->with([true, false]);

it('authorizes cancel and run-all separately from visibility', function (): void {
    $visible = Site::factory()->create();
    $hidden = Site::factory()->create();
    $visibleCheck = Check::factory()->for($visible)->create(['type' => CheckKey::Lighthouse]);
    $hiddenCheck = Check::factory()->for($hidden)->create(['type' => CheckKey::Lighthouse]);
    expect(app(ClaimService::class)->runNow($visibleCheck->id, RunOrigin::System))->not->toBeNull()
        ->and(app(ClaimService::class)->runNow($hiddenCheck->id, RunOrigin::System))->not->toBeNull();
    $member = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $member->assignRole('Member');
    $member->clients()->attach($visible->client_id);
    $this->actingAs($member)->post("/sites/{$visible->id}/checks/{$visibleCheck->id}/cancel")->assertForbidden();
    $this->post("/sites/{$visible->id}/run-all", ['confirmed' => true])->assertForbidden();
    $this->post("/sites/{$hidden->id}/checks/{$hiddenCheck->id}/cancel")->assertNotFound();
    $this->post("/sites/{$hidden->id}/run-all", ['confirmed' => true])->assertNotFound();
    expect(CheckRunAttempt::query()->where('phase', RunPhase::Queued)->count())->toBe(2);
});

it('cancels Lighthouse at a checkpoint after releasing its proxy scope', function (bool $busy): void {
    config(['lighthouse.service_url' => 'http://lighthouse.test:3000', 'lighthouse.proxy_control_url' => 'http://proxy-control.test:3129']);
    $check = Check::factory()->for(Site::factory()->state(['url' => 'https://example.com/']))->create(['type' => CheckKey::Lighthouse]);
    $fixture = file_get_contents(base_path('addons/lighthouse/tests/Fixtures/mobile.json'));
    if (! is_string($fixture)) {
        throw new LogicException('Lighthouse fixture is missing.');
    }
    $calls = [];
    app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(function (FetchRequest $request) use ($check, $fixture, &$calls, $busy): FetchResult {
        $calls[] = [$request->method, $request->url];
        if ($request->method === 'POST' && str_ends_with($request->url, '/scopes')) {
            return new FetchResult(200, json_encode(['token' => str_repeat('a', 64)], JSON_THROW_ON_ERROR));
        }
        if ($request->method === 'POST' && str_ends_with($request->url, '/lighthouse')) {
            app(CancelCheckRun::class)($check);
            usleep(1_010_000);

            return $busy ? new FetchResult(429, failure: new FetchFailure(FetchFailureKind::HttpStatus, 429)) : new FetchResult(200, $fixture);
        }
        if ($request->method === 'DELETE' && str_contains($request->url, '/scopes/')) {
            return new FetchResult(204);
        }
        throw new LogicException('Unexpected Lighthouse request.');
    }));
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $definition = app(CheckRegistry::class)->get(CheckKey::Lighthouse)->definition();
    app()->call(new RunCheck($check->id, $check->refresh()->claim_token, $definition->timeout, $definition->queue)->handle(...));
    expect(array_column($calls, 0))->toBe(['POST', 'POST', 'DELETE'])
        ->and(CheckRunAttempt::query()->sole()->phase)->toBe(RunPhase::Cancelled)
        ->and(CheckRun::query()->count())->toBe(0);
})->with([false, true]);

it('serializes cancellation against recording so a cancelled run cannot publish a false result', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse]);
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    $attempt = CheckRunAttempt::query()->where('check_id', $check->id)->sole();
    $started = CarbonImmutable::now('UTC');
    $check->forceFill(['run_started_at' => $started, 'active_run_id' => 123456])->save();
    $attempt->update(['phase' => RunPhase::Running, 'started_at' => $started]);
    FrameworkEvent::fake([LiveSignal::class]);
    app(CancelCheckRun::class)($check);
    $result = app(ResultRecorder::class)->record(new RecordableRun($check->id, $attempt->claim_token, $started, $started->addSecond(),
        new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'failed'))));
    expect($result->accepted)->toBeTrue()->and($attempt->refresh()->phase)->toBe(RunPhase::Cancelled)
        ->and($check->refresh()->claim_token)->toBeNull()->and(CheckRun::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0);
    FrameworkEvent::assertDispatched(LiveSignal::class, static fn (LiveSignal $signal): bool => $signal->topic === 'run.finished'
        && $signal->broadcastWith()['run']['outcome'] === 'cancelled');
    $this->post("/sites/{$check->site_id}/checks/{$check->id}/cancel")->assertSessionHasErrors('check');
});

it('emits a skipped finish outcome when an attempt has no result', function (): void {
    // ded7eb579 requires an explicit no-observation classification.
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $check->refresh();
    $at = CarbonImmutable::now('UTC');
    FrameworkEvent::fake([LiveSignal::class]);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $check->claim_token, $at, $at, NoResultReason::CronTasksPending));
    expect(CheckRunAttempt::query()->sole()->phase)->toBe(RunPhase::Skipped);
    FrameworkEvent::assertDispatched(LiveSignal::class, static fn (LiveSignal $signal): bool => $signal->topic === 'run.finished'
        && $signal->broadcastWith()['run']['outcome'] === 'skipped');
});

it('serializes a real concurrent cancellation ahead of result recording', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse]);
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $check->refresh();
    $started = CarbonImmutable::now('UTC');
    /** @var int $runId */
    $runId = DB::scalar("SELECT nextval('check_runs_id_seq')");
    $check->forceFill(['run_started_at' => $started, 'active_run_id' => $runId])->save();
    CheckRunAttempt::query()->where('claim_token', $check->claim_token)->update(['phase' => RunPhase::Running, 'started_at' => $started]);
    $key = random_int(100000, 2000000000);
    $name = 'run_cancel_race_'.$key;
    DB::commit();
    DB::unprepared("CREATE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN
        IF OLD.cancel_requested_at IS NULL AND NEW.cancel_requested_at IS NOT NULL THEN PERFORM pg_advisory_xact_lock({$key}); END IF;
        RETURN NEW; END \$\$;
        CREATE TRIGGER {$name} BEFORE UPDATE ON check_run_attempts FOR EACH ROW EXECUTE FUNCTION {$name}()");
    DB::select('SELECT pg_advisory_lock(?)', [$key]);
    $locked = true;
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\DB::statement("SET application_name = '".getenv('RUN_RACE_NAME')."'");
        $check = Heptapod\Models\Check::findOrFail((int) getenv('RUN_RACE_CHECK'));
        if (getenv('RUN_RACE_ACTION') === 'cancel') {
            app(Heptapod\Actions\CancelCheckRun::class)($check);
        } else {
            $at = Carbon\CarbonImmutable::parse(getenv('RUN_RACE_STARTED'));
            app(Heptapod\Checks\Recording\ResultRecorder::class)->record(new Heptapod\Checks\Recording\RecordableRun(
                $check->id, getenv('RUN_RACE_TOKEN'), $at, $at->addSecond(),
                new Heptapod\Contracts\CheckOutcome(Heptapod\Contracts\RunResult::Errored, 'checks.engine.errored', [], null,
                    error: new Heptapod\Contracts\CheckError(RuntimeException::class, 'failed'))));
        }
        echo 'done';
        PHP;
    $processes = [];
    try {
        foreach (['cancel', 'record'] as $action) {
            $process = new Process([PHP_BINARY, '-r', $script], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.pgsql.database'), 'DB_URL' => '',
                'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'RUN_RACE_NAME' => $name.'_'.$action, 'RUN_RACE_ACTION' => $action,
                'RUN_RACE_CHECK' => (string) $check->id, 'RUN_RACE_TOKEN' => $check->claim_token,
                'RUN_RACE_STARTED' => $started->format('Y-m-d H:i:s.uP'),
            ], timeout: 30);
            $process->start();
            $processes[] = $process;
            $deadline = hrtime(true) + 15_000_000_000;
            do {
                $waiting = DB::scalar('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = ?', [$name.'_'.$action, 'Lock']);
                if ($waiting !== null) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline && $process->isRunning());
            expect($waiting)->toBe('Lock', $process->getOutput().$process->getErrorOutput());
        }
        DB::select('SELECT pg_advisory_unlock(?)', [$key]);
        $locked = false;
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
        }
        expect(CheckRunAttempt::query()->sole()->phase)->toBe(RunPhase::Cancelled)
            ->and(CheckRun::query()->count())->toBe(0)
            ->and(Event::query()->count())->toBe(0)
            ->and($check->refresh()->claim_token)->toBeNull();
    } finally {
        if ($locked) {
            DB::select('SELECT pg_advisory_unlock(?)', [$key]);
        }
        foreach ($processes as $process) {
            $process->stop(1);
        }
        DB::unprepared("DROP TRIGGER {$name} ON check_run_attempts; DROP FUNCTION {$name}()");
        $site = $check->site;
        $client = $site->client;
        $check->delete();
        $site->delete();
        $client->delete();
        DB::beginTransaction();
    }
});

it('records a finished result if completion acquired the check lock before cancellation', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse]);
    $this->actingAs($user)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    $attempt = CheckRunAttempt::query()->sole();
    $started = CarbonImmutable::now('UTC');
    $check->forceFill(['run_started_at' => $started, 'active_run_id' => 123457])->save();
    $attempt->update(['phase' => RunPhase::Running, 'started_at' => $started]);
    app(ResultRecorder::class)->record(new RecordableRun($check->id, $attempt->claim_token, $started, $started->addSecond(),
        new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'failed'))));
    $this->post("/sites/{$check->site_id}/checks/{$check->id}/cancel")->assertSessionHasErrors('check');
    expect($attempt->refresh()->phase)->toBe(RunPhase::Failed)
        ->and(CheckRun::query()->count())->toBe(1)
        ->and($attempt->check_run_id)->toBe(CheckRun::query()->sole()->id);
    $run = app(RunsReader::class)->history($user)->toArray()['runs'][0];
    expect($run['summary'])->toBe('causes.error')
        ->and($run['summary_params']['message'])->toBe('failed');
});

it('bounds Runs list query work and SQL count as attempt history grows', function (int $count): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $requestRuns = static function (int $checkCount): void {
        foreach (Check::factory()->count($checkCount)->create(['next_run_at' => now()->addHour()]) as $check) {
            expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        }
    };
    $requestRuns(2);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($user)->get('/runs?show_same_result=1')->assertOk();
    $fewQueries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $requestRuns($count - 2);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $response = $this->get('/runs?show_same_result=1');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $list = collect($queries)->first(static fn (array $query): bool => str_contains($query['query'], '"check_run_attempts"') && str_contains($query['query'], 'order by "requested_at" desc, "id" desc'));
    expect($list)->not->toBeNull()
        ->and(count($queries))->toBeLessThanOrEqual($fewQueries, 'the Runs list grew from '.$fewQueries.' to '.count($queries).' queries as attempts grew from 2 to '.$count)
        // Upper budget: page shell and shell health, the bounded current/queued/finished attempt pages and their batched presentations.
        ->and($fewQueries)->toBeLessThanOrEqual(27, 'the Runs list used '.$fewQueries.' queries, over its budget of 27')
        ->and(QueryPlanWork::forQuery($list['query'], $list['bindings']))->toBeLessThan($count * 20);
    expect(collect($queries)->filter(static fn (array $query): bool => str_contains($query['query'], 'select count(*)') && str_contains($query['query'], '"queue" = ?')))->toBeEmpty();
    $response->assertInertia(fn (Assert $page): Assert => $page->component('runs/index')->where('runs.0.run.queue_position', $count));
})->with([20, 200]);

it('bounds filtered history work as older attempts accumulate', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $at = now()->subHours(40)->startOfSecond();
    $rows = [];
    for ($index = 0; $index < 1800; $index++) {
        $requested = $at->addMinutes($index);
        $rows[] = [
            'check_id' => $check->id, 'claim_token' => (string) Str::uuid(), 'phase' => 'skipped', 'queue' => 'checks-fast',
            'requested_at' => $requested, 'due_at' => $requested,
            'started_at' => $requested->addMilliseconds($index < 1300 ? 1000 : 100)->format('Y-m-d H:i:s.uP'),
            'finished_at' => $requested->addSeconds(2), 'note' => 'no_result',
        ];
    }
    foreach (array_chunk($rows, 300) as $chunk) {
        DB::table('check_run_attempts')->insert($chunk);
    }
    DB::statement('ANALYZE check_run_attempts');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $runs = app(RunsReader::class)->history($user, $check->id);
    expect($runs->runs)->toHaveCount(50);
    $list = collect(DB::getQueryLog())->first(static fn (array $query): bool => str_contains($query['query'], '"check_run_attempts"')
        && str_contains($query['query'], '"check_id" = ?') && str_contains($query['query'], 'order by "requested_at" desc, "id" desc'));
    DB::disableQueryLog();
    expect($list)->not->toBeNull()
        ->and(QueryPlanWork::scannedRows($list['query'], $list['bindings']))->toBeLessThan(250);
});

it('prunes old attempts in bounded batches without deleting current claims', function (): void {
    $check = Check::factory()->create();
    $old = now()->subDays(8);
    $rows = [];
    for ($index = 0; $index < 1200; $index++) {
        $rows[] = ['check_id' => $check->id, 'claim_token' => (string) Str::uuid(), 'phase' => 'skipped',
            'queue' => 'checks-fast', 'requested_at' => $old, 'due_at' => $old, 'finished_at' => $old, 'note' => 'no_result'];
    }
    foreach (array_chunk($rows, 300) as $chunk) {
        DB::table('check_run_attempts')->insert($chunk);
    }
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(CheckDataMaintenance::class)->maintain(CarbonImmutable::now('UTC'));
    $deletes = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_starts_with($query['query'], 'delete from "check_run_attempts"'));
    DB::disableQueryLog();
    expect($deletes)->toHaveCount(2)
        ->and(CheckRunAttempt::query()->sole()->claim_token)->toBe($check->refresh()->claim_token);
});

it('throttles progress and rejects cancellation at addon checkpoints', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['type' => CheckKey::Lighthouse]);
    $this->actingAs($user)->post("/sites/{$check->site_id}/checks/{$check->id}/run")->assertRedirect();
    $attempt = CheckRunAttempt::query()->sole();
    $started = CarbonImmutable::now('UTC');
    $check->forceFill(['run_started_at' => $started, 'active_run_id' => 123458])->save();
    $attempt->update(['phase' => RunPhase::Running, 'started_at' => $started]);
    $control = new AttemptControl($check->id, $attempt->claim_token);
    $control->report(1, 3, 'lighthouse');
    $control->report(2, 3, 'lighthouse');
    expect($attempt->refresh()->progress_done)->toBe(1)->and($attempt->progress_total)->toBe(3);
    $probe = new AttemptControl($check->id, $attempt->claim_token);
    DB::enableQueryLog();
    DB::flushQueryLog();
    for ($index = 0; $index < 100; $index++) {
        $probe->assertActive();
    }
    $reads = collect(DB::getQueryLog())->filter(static fn (array $query): bool => str_contains($query['query'], '"check_run_attempts"'));
    DB::disableQueryLog();
    expect($reads)->toHaveCount(1);
    app(CancelCheckRun::class)($check);
    usleep(1_010_000);
    expect(fn (): mixed => $control->report(3, 3, 'lighthouse'))->toThrow(RunCancelled::class);
});
