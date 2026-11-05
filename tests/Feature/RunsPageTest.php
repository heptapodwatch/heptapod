<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Execution\RunOrigin;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\ResultRecorder;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\Http\FetchResult;
use Heptapod\Contracts\Http\OutboundHttp;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\ClientScope;
use Heptapod\Jobs\RunCheck;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Site;
use Heptapod\Models\User;
use Heptapod\Queries\CheckRunsReader;
use Heptapod\Testing\FakeOutboundHttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
});

it('requires authentication and monitoring visibility for Runs', function (): void {
    $this->get('/runs')->assertRedirect('/login');
    $user = User::factory()->create();
    $this->actingAs($user)->get('/runs')->assertForbidden();
});

it('keeps unclaimed late checks out of the active board and shares the authorized late badge', function (): void {
    $this->freezeTime();
    $user = User::factory()->create(['client_scope' => ClientScope::Restricted]);
    $user->assignRole('Member');
    $site = Site::factory()->create();
    $user->clients()->attach($site->client_id);
    $late = Check::factory()->for($site)->create(['next_run_at' => now()->subSeconds(61), 'interval_seconds' => 60]);
    Check::factory()->for($site)->create(['type' => 'dns', 'next_run_at' => now()->addHour()]);
    $hidden = Check::factory()->create(['next_run_at' => now()->subHours(2)]);
    Check::factory()->for($site)->create(['type' => 'domain', 'enabled' => false, 'next_run_at' => now()->subHours(2)]);
    Check::factory()->for(Site::factory()->state(['client_id' => $site->client_id, 'paused_at' => now()]))->create(['next_run_at' => now()->subHours(2)]);

    expect(app(CheckRunsReader::class)->state($late->load('site'))->phase)->toBe(RunPhase::Late);
    $this->actingAs($user)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->component('runs/index')->missing('late')->missing('lateRunsCount')
        ->has('current', 0)
        ->has('runs', 0)->has('clients', 1)
        ->loadDeferredProps(['sidebar'], fn (Assert $sidebar): Assert => $sidebar->where('lateRunsCount', 1)));
    expect(CheckRunAttempt::query()->count())->toBe(0);
    $this->get('/runs?state=late')->assertInertia(fn (Assert $page): Assert => $page->has('current', 0));
    $this->get('/runs?check='.$hidden->id.'&show_same_result=1')->assertInertia(fn (Assert $page): Assert => $page->has('current', 0)->has('runs', 0)
        ->loadDeferredProps(['sidebar'], fn (Assert $sidebar): Assert => $sidebar->where('lateRunsCount', 1)));
    $late->update(['enabled' => false]);
    $this->get('/runs')->assertInertia(fn (Assert $page): Assert => $page->missing('late')->has('current', 0)
        ->loadDeferredProps(['sidebar'], fn (Assert $sidebar): Assert => $sidebar->where('lateRunsCount', 0)));
});

it('filters current and attempt history by authorized client and check type while preserving check-id links', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $visible = Check::factory()->create(['next_run_at' => now()->addHour()]);
    $other = Check::factory()->create(['type' => 'lighthouse', 'next_run_at' => now()->addHour()]);
    expect(app(ClaimService::class)->runNow($visible->id, RunOrigin::System))->not->toBeNull();
    expect(app(ClaimService::class)->runNow($other->id, RunOrigin::System))->not->toBeNull();

    $this->actingAs($user)->get('/runs?client='.$visible->site->client_id.'&type=uptime&state=queued')->assertInertia(fn (Assert $page): Assert => $page
        ->has('runs', 1)->where('runs.0.check_id', $visible->id)->has('current', 1)->where('current.0.check_id', $visible->id));
    $this->get('/runs?check='.$other->id)->assertInertia(fn (Assert $page): Assert => $page->has('runs', 1)->where('runs.0.check_id', $other->id));
    $this->get('/runs?type=dns')->assertInertia(fn (Assert $page): Assert => $page->has('runs', 0)->has('current', 0));
    $this->get('/runs?state=not-a-state')->assertSessionHasErrors('state');
});

it('hides only repeated retained accepted results and keeps first observations and recoveries', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $record = function (RunResult $result) use ($check): int {
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        $check->refresh();
        app()->instance(OutboundHttp::class, (new FakeOutboundHttp)->respondUsing(
            static fn (): FetchResult => new FetchResult($result === RunResult::Failed ? 503 : 200, 'ok'),
        ));
        $definition = app(CheckRegistry::class)->get($check->type)->definition();
        app()->call(new RunCheck($check->id, $check->claim_token, $definition->timeout, $definition->queue)->handle(...));
        $this->travel(1)->seconds();

        return CheckRunAttempt::query()->where('check_id', $check->id)->orderByDesc('id')->firstOrFail()->id;
    };
    $first = $record(RunResult::Failed);
    $repeat = $record(RunResult::Failed);
    $recovery = $record(RunResult::Succeeded);

    $this->actingAs($user)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.show_same_result', false)->has('runs', 2)->where('runs.0.id', $recovery)->where('runs.1.id', $first));
    $this->get('/runs?show_same_result=1')->assertInertia(fn (Assert $page): Assert => $page
        ->has('runs', 3)->where('runs.1.id', $repeat));
    $firstAttempt = CheckRunAttempt::query()->findOrFail($first);
    CheckRun::query()->forRun(new RunReference($firstAttempt->check_run_id, $firstAttempt->finished_at))->delete();
    $this->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->has('runs', 3)->where('runs.1.id', $repeat)->where('runs.2.summary', null));
});

it('keeps unknown physical references visible and never reads an id-colliding accepted run', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $attempt = CheckRunAttempt::query()->where('check_id', $check->id)->sole();
    $run = CheckRun::factory()->for($check)->create(['ended_at' => now()->subSecond()]);
    $attempt->update(['phase' => RunPhase::Done, 'check_run_id' => $run->id, 'finished_at' => now(), 'due_at' => null]);
    $check->update(['claim_token' => null]);

    $this->actingAs($user)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->has('runs', 1)->where('runs.0.id', $attempt->id)->where('runs.0.summary', null)
        ->where('runs.0.note', 'Details not available for this older run')->where('runs.0.run.due_at', null));
});

it('applies result filtering before pagination and preserves filters across cursors', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $check = Check::factory()->create();
    $at = CarbonImmutable::now('UTC')->subMinutes(5);
    $ids = [];
    for ($index = 0; $index < 55; $index++) {
        $result = $index % 2 === 0 ? RunResult::Succeeded : RunResult::Warning;
        $run = CheckRun::factory()->for($check)->create(['result' => $result, 'started_at' => $at->addSeconds($index), 'ended_at' => $at->addSeconds($index)]);
        $ids[] = CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => (string) Str::uuid(),
            'phase' => RunPhase::Done, 'queue' => 'checks-fast', 'requested_at' => $at->addSeconds($index),
            'finished_at' => $run->ended_at, 'check_run_id' => $run->id])->id;
    }
    $repeat = CheckRun::factory()->for($check)->create(['result' => RunResult::Succeeded, 'started_at' => $at->addSeconds(56), 'ended_at' => $at->addSeconds(56)]);
    CheckRunAttempt::query()->create(['check_id' => $check->id, 'claim_token' => (string) Str::uuid(),
        'phase' => RunPhase::Done, 'queue' => 'checks-fast', 'requested_at' => $at->addSeconds(56), 'finished_at' => $repeat->ended_at, 'check_run_id' => $repeat->id]);

    $this->actingAs($user)->get('/runs?check='.$check->id.'&state=done')->assertInertia(fn (Assert $page): Assert => $page
        ->has('runs', 50)->where('runs.0.id', $ids[54])->where('next_cursor', (string) $ids[5]));
    $this->get('/runs?check='.$check->id.'&state=done&before='.$ids[5])->assertInertia(fn (Assert $page): Assert => $page
        ->has('runs', 5)->where('runs.0.id', $ids[4])->where('next_cursor', null));
});

it('roundtrips the accepted attempt identity with microseconds through the real recorder', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07T02:00:00.123456+00:00'));
    $check = Check::factory()->create();
    expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
    $check->refresh();
    $at = CarbonImmutable::now('UTC');
    app(ResultRecorder::class)->record(new RecordableRun(
        $check->id, $check->claim_token, $at, $at->addSecond(),
        new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null,
            error: new CheckError(RuntimeException::class, 'failed')),
    ));

    $attempt = CheckRunAttempt::query()->where('check_id', $check->id)->sole();
    $run = CheckRun::query()->sole();
    expect($attempt->finished_at->format('Y-m-d H:i:s.uP'))->toBe('2026-10-07 02:00:01.123456+00:00')
        ->and($attempt->finished_at->equalTo($run->ended_at))->toBeTrue();
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $this->actingAs($user)->get('/runs')->assertInertia(fn (Assert $page): Assert => $page
        ->where('runs.0.summary', 'causes.error')->where('runs.0.finished_at', '2026-10-07T02:00:01+00:00'));
    $token = $user->createToken('Runs timestamp check', ['checks:read'])->plainTextToken;
    $this->withToken($token)->getJson('/api/monitors/'.$check->site_id.'/checks/uptime/runs')
        ->assertJsonPath('data.0.ended_at', '2026-10-07T02:00:01+00:00');
});

it('keeps full Runs board and history query counts invariant with more checks', function (): void {
    $user = User::factory()->create();
    $user->assignRole('Owner');
    $checkIds = [];
    foreach (['broken_links', 'sitemap', 'lighthouse'] as $type) {
        $check = Check::factory()->create(['type' => $type, 'next_run_at' => now()->addHour()]);
        $checkIds[] = $check->id;
        expect(app(ClaimService::class)->runNow($check->id, RunOrigin::System))->not->toBeNull();
        CheckRunAttempt::query()->where('check_id', $check->id)->update(['phase' => RunPhase::Running, 'started_at' => now()]);
    }
    $this->actingAs($user);
    $this->get('/runs')->assertOk();
    $queryCount = function () use ($checkIds): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->get('/runs');
            $count = count(DB::getQueryLog());
            $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
                ->has('current', 3)->has('runs', 3)->has('finished', 0)->where('queued_more', 0)
                ->where('current', fn ($rows): bool => collect($rows)->pluck('check_id')->sort()->values()->all() === $checkIds)
                ->missing('queues'));

            return $count;
        } finally {
            DB::disableQueryLog();
        }
    };
    $baseline = $queryCount();
    Check::factory()->count(10)->create(['next_run_at' => now()->addHour()]);

    expect($queryCount())->toBe($baseline);
});
