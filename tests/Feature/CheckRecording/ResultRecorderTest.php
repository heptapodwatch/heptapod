<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Heptapod\Actions\DeleteMaintenancePeriod;
use Heptapod\Addons\Cron\CronCheck;
use Heptapod\Addons\Cron\CronDefinitions;
use Heptapod\Addons\Uptime\UptimeEvidence;
use Heptapod\Addons\Uptime\UptimeMetrics;
use Heptapod\Checks\Execution\ClaimService;
use Heptapod\Checks\Recording\RecordableRun;
use Heptapod\Checks\Recording\RecordedRun;
use Heptapod\Checks\Recording\TransactionalResultRecorder;
use Heptapod\Contracts\AddonEvent;
use Heptapod\Contracts\CertificateFetcher;
use Heptapod\Contracts\CertificateFetchResult;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionComponent;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\IncidentCause;
use Heptapod\Enums\SiteStatus;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\OutboxRecorded;
use Heptapod\Incidents\SiteState;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\CheckSnooze;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Testing\ClaimedRun;
use Heptapod\Testing\FakeCertificateFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as DomainEvents;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
});

function recorderOutcome(RunResult $result, int $status = 503): CheckOutcome
{
    if ($result === RunResult::Errored) {
        return new CheckOutcome($result, 'checks.engine.errored', [], null, error: new CheckError(RuntimeException::class, 'Probe deadline exceeded'));
    }
    $curl = array_fill_keys(['namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time', 'pretransfer_time', 'redirect_time', 'total_time', 'header_size', 'size_download', 'speed_download'], null);

    return new CheckOutcome($result, 'uptime.'.$result->value, [],
        new UptimeMetrics(null, null, null, null, null, 0.2, $curl, CarbonImmutable::now()->toIso8601String()),
        new UptimeEvidence($status, $result === RunResult::Failed ? ['status'] : []));
}

function recordObservation(Check $check, CheckOutcome|NoResultReason $outcome, CarbonImmutable $at): RecordedRun
{
    $context = ClaimedRun::start($check, $at->subSecond());

    return app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $at, $outcome));
}

dataset('recorder transitions', [
    'success' => [[RunResult::Succeeded], 0, 0, null, false, ['check.result_changed']],
    'warning' => [[RunResult::Warning], 0, 0, null, false, ['check.result_changed']],
    'below threshold' => [[RunResult::Failed], 1, 0, null, false, ['check.result_changed']],
    'qualified failure' => [[RunResult::Failed, RunResult::Failed], 2, 1, IncidentCause::Failed, false, ['check.result_changed', 'incident.opened']],
    'qualified error' => [[RunResult::Errored, RunResult::Errored], 2, 1, IncidentCause::Errored, false, ['check.result_changed', 'incident.opened']],
    'mixed streak' => [[RunResult::Failed, RunResult::Errored], 2, 1, IncidentCause::Errored, false, ['check.result_changed', 'incident.opened', 'check.result_changed']],
    'failure becomes error' => [[RunResult::Failed, RunResult::Failed, RunResult::Errored], 3, 1, IncidentCause::Errored, false, ['check.result_changed', 'incident.opened', 'incident.cause_changed', 'check.result_changed']],
    'error becomes failure' => [[RunResult::Errored, RunResult::Errored, RunResult::Failed], 3, 1, IncidentCause::Failed, false, ['check.result_changed', 'incident.opened', 'incident.cause_changed', 'check.result_changed']],
    'recover' => [[RunResult::Failed, RunResult::Failed, RunResult::Succeeded], 0, 1, IncidentCause::Failed, true, ['check.result_changed', 'incident.opened', 'incident.resolved', 'check.result_changed']],
    'warning resolves' => [[RunResult::Failed, RunResult::Failed, RunResult::Warning], 0, 1, IncidentCause::Failed, true, ['check.result_changed', 'incident.opened', 'incident.resolved', 'check.result_changed']],
    'failure warning failure' => [[RunResult::Failed, RunResult::Warning, RunResult::Failed], 1, 0, null, false, ['check.result_changed', 'check.result_changed', 'check.result_changed']],
    'warning recovery then reopen' => [[RunResult::Failed, RunResult::Failed, RunResult::Warning, RunResult::Failed, RunResult::Failed], 2, 2, IncidentCause::Failed, false, ['check.result_changed', 'incident.opened', 'incident.resolved', 'check.result_changed', 'check.result_changed', 'incident.opened']],
    'no observation preserves incident' => [[RunResult::Errored, RunResult::Errored, NoResultReason::PerformanceNoSamples], 2, 1, IncidentCause::Errored, false, ['check.result_changed', 'incident.opened'], CheckKey::Performance],
    'no observation remains pending' => [[NoResultReason::PerformanceNoSamples], 0, 0, null, false, [], CheckKey::Performance],
]);

it('records the transition table through the real recorder', function (array $results, int $streak, int $incidentCount, ?IncidentCause $cause, bool $resolved, array $events, CheckKey $type = CheckKey::Uptime): void {
    $check = Check::factory()->create(['type' => $type, 'failure_threshold' => 2]);
    $at = CarbonImmutable::now()->startOfSecond();
    foreach ($results as $offset => $result) {
        $recorded = recordObservation($check, $result instanceof NoResultReason ? $result : recorderOutcome($result), $at->addSeconds($offset));
        expect($recorded->accepted)->toBeTrue();
        expect($recorded->runId === null)->toBe($result instanceof NoResultReason);
        if ($result instanceof NoResultReason) {
            expect(CheckRunAttempt::query()->where('check_id', $check->id)->latest('id')->firstOrFail()->note)->toBe('no_result:performance_no_samples');
        }
    }
    $check->refresh();
    expect($check->consecutive_failures)->toBe($streak)
        ->and($check->claim_token)->toBeNull()
        ->and($check->active_run_id)->toBeNull()
        ->and($check->dispatched_at)->toBeNull()
        ->and($check->lease_expires_at)->toBeNull()
        ->and($check->run_started_at)->toBeNull();
    $observations = array_values(array_filter($results, static fn ($result): bool => $result instanceof RunResult));
    expect($check->runs()->count())->toBe(count($observations));
    if ($observations !== []) {
        $latest = array_last($observations);
        expect($check->latest_run_result)->toBe(CheckResult::from($latest->value));
        $run = $check->runs()->orderByDesc('ended_at')->orderByDesc('id')->first();
        expect($check->latest_run_id)->toBe($run->id)
            ->and($check->latest_run_started_at->equalTo($run->started_at))->toBeTrue()
            ->and($check->latest_run_ended_at->equalTo($run->ended_at))->toBeTrue();
    } else {
        expect($check->latest_run_result)->toBe(CheckResult::Pending)->and($check->latest_run_id)->toBeNull();
    }
    expect(Incident::query()->count())->toBe($incidentCount);
    if ($incidentCount > 0) {
        $incident = Incident::query()->latest('id')->first();
        expect($incident->cause)->toBe($cause)->and($incident->resolved_at !== null)->toBe($resolved);
        expect($incident->evidence)->toHaveKeys(['run_id', 'run_ended_at', 'metrics', 'evidence', 'error']);
    }
    expect(Event::query()->orderBy('id')->pluck('type')->all())->toBe($events);
    expect(Event::query()->where('suppressed', true)->count())->toBe(0);
    expect($check->failing_since === null)->toBe($streak === 0);
})->with('recorder transitions');

it('keeps incident state on an incomplete run until a complete observation resolves it', function (RunResult $partial): void {
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $at = CarbonImmutable::now()->startOfSecond();
    recordObservation($check, recorderOutcome(RunResult::Failed), $at);
    $incident = Incident::query()->sole();
    $originalStreak = $check->refresh()->consecutive_failures;
    $originalSince = $check->failing_since;
    $outcome = recorderOutcome($partial);
    recordObservation($check, new CheckOutcome($outcome->result, $outcome->summary, $outcome->summaryParams, $outcome->metrics, $outcome->evidence, incomplete: true), $at->addSecond());
    expect($incident->refresh()->resolved_at)->toBeNull()
        ->and($check->refresh()->consecutive_failures)->toBe($originalStreak)
        ->and($check->failing_since->equalTo($originalSince))->toBeTrue()
        ->and(Event::query()->where('type', 'incident.resolved')->exists())->toBeFalse()
        ->and(Incident::query()->count())->toBe(1);
    recordObservation($check, recorderOutcome(RunResult::Warning), $at->addSeconds(2));
    expect($incident->refresh()->resolved_at)->not->toBeNull()
        ->and(Event::query()->where('type', 'incident.resolved')->count())->toBe(1);
})->with([RunResult::Succeeded, RunResult::Warning, RunResult::Failed]);

it('opens an incident only after confirmed incomplete failures meet the threshold', function (): void {
    $check = Check::factory()->create(['failure_threshold' => 2]);
    $at = CarbonImmutable::now()->startOfSecond();
    $failed = recorderOutcome(RunResult::Failed);
    $confirmed = new CheckOutcome($failed->result, $failed->summary, [], $failed->metrics, $failed->evidence, incomplete: true, confirmedIncompleteFailure: true);
    recordObservation($check, $confirmed, $at);
    expect(Incident::query()->count())->toBe(0)->and($check->refresh()->consecutive_failures)->toBe(1);
    recordObservation($check, $confirmed, $at->addSecond());
    $incident = Incident::query()->sole();
    expect($incident->cause)->toBe(IncidentCause::Failed)
        ->and($incident->resolved_at)->toBeNull()
        ->and(Event::query()->where('type', 'incident.opened')->count())->toBe(1);
    $warning = recorderOutcome(RunResult::Warning);
    recordObservation($check, new CheckOutcome($warning->result, $warning->summary, [], $warning->metrics, $warning->evidence, incomplete: true), $at->addSeconds(2));
    expect($incident->refresh()->resolved_at)->toBeNull()->and($check->refresh()->consecutive_failures)->toBe(2);
    recordObservation($check, $warning, $at->addSeconds(3));
    expect($incident->refresh()->resolved_at)->not->toBeNull();
});

it('does not open an incident from an incomplete failed observation', function (): void {
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $at = CarbonImmutable::now()->startOfSecond();
    $outcome = recorderOutcome(RunResult::Failed);
    recordObservation($check, new CheckOutcome($outcome->result, $outcome->summary, [], $outcome->metrics, $outcome->evidence, incomplete: true), $at);
    expect(Incident::query()->count())->toBe(0)
        ->and(Event::query()->where('type', 'incident.opened')->exists())->toBeFalse()
        ->and($check->refresh()->consecutive_failures)->toBe(0);
});

it('fences stale and duplicate results without clearing the replacement slot', function (): void {
    $check = Check::factory()->create();
    $at = CarbonImmutable::now();
    $replacement = ClaimedRun::start($check, $at->subSecond())->claimToken;
    $recorder = app(TransactionalResultRecorder::class);
    $stale = $recorder->record(new RecordableRun($check->id, (string) Str::uuid(), $at->subSecond(), $at, recorderOutcome(RunResult::Failed)));
    expect($stale->accepted)->toBeFalse()->and($check->fresh()->claim_token)->toBe($replacement);
    $accepted = $recorder->record(new RecordableRun($check->id, $replacement, $at->subSecond(), $at, recorderOutcome(RunResult::Succeeded)));
    $duplicate = $recorder->record(new RecordableRun($check->id, $replacement, $at->subSecond(), $at, recorderOutcome(RunResult::Failed)));
    expect($accepted->accepted)->toBeTrue()->and($duplicate->accepted)->toBeFalse();
    expect(CheckRun::query()->count())->toBe(1)->and(Event::query()->count())->toBe(1);
});

it('rejects an unclassified null outcome without accepting the attempt or changing history', function (): void {
    $check = Check::factory()->create();
    $context = ClaimedRun::start($check);
    $before = $check->refresh()->getRawOriginal();
    $attempt = CheckRunAttempt::query()->where('claim_token', $context->claimToken)->sole();
    $attemptBefore = $attempt->getRawOriginal();

    expect(fn () => app(TransactionalResultRecorder::class)->record(new RecordableRun(
        $check->id, $context->claimToken, $context->startedAt, CarbonImmutable::now(), null,
    )))->toThrow(LogicException::class, 'A no-result attempt must carry a classified reason.');

    expect($check->refresh()->getRawOriginal())->toBe($before)
        ->and($attempt->refresh()->getRawOriginal())->toBe($attemptBefore)
        ->and(CheckRun::query()->count())->toBe(0)
        ->and(Event::query()->count())->toBe(0)
        ->and(Incident::query()->count())->toBe(0);
});

it('records invalid metrics as an engine error with the exact validation field', function (): void {
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $valid = recorderOutcome(RunResult::Succeeded);
    $invalid = UptimeMetrics::from([...$valid->metrics->toArray(), 'total_time_in_seconds' => -1]);
    recordObservation($check, new CheckOutcome(RunResult::Succeeded, 'uptime.succeeded', [], $invalid), CarbonImmutable::now());
    $run = CheckRun::query()->sole();
    expect($run->result)->toBe(RunResult::Errored)->and($run->metrics)->toBeNull();
    $error = json_decode($run->error, true, flags: JSON_THROW_ON_ERROR);
    expect($error['class'])->toBe(ValidationException::class)
        ->and(json_decode($error['message'], true, flags: JSON_THROW_ON_ERROR))->toHaveKey('values.total_time_in_seconds');
    expect(Incident::query()->sole()->cause)->toBe(IncidentCause::Errored)
        ->and(app(SiteState::class)->derive($check->site))->toBe(SiteStatus::CheckError);
});

it('discards inactive queued results and preserves existing history', function (string $flag): void {
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $at = CarbonImmutable::now();
    recordObservation($check, recorderOutcome(RunResult::Failed), $at);
    $pending = ClaimedRun::start($check, $at);
    if ($flag === 'disabled') {
        $check->update(['enabled' => false]);
    } else {
        $check->site->update([$flag => $at]);
    }
    $discarded = app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $pending->claimToken,
        $at, $at->addSecond(), recorderOutcome(RunResult::Succeeded)));
    expect($discarded->accepted)->toBeFalse()->and($check->fresh()->claim_token)->toBeNull();
    expect(CheckRun::query()->count())->toBe(1)->and(Event::query()->count())->toBe(2)
        ->and(Incident::query()->sole()->resolved_at)->toBeNull()
        ->and($check->fresh()->consecutive_failures)->toBe(1);
})->with(['disabled', 'paused_at', 'marked_for_deletion_at']);

it('discards a result when its site has been deleted', function (): void {
    $check = Check::factory()->create(['claim_token' => (string) Str::uuid()]);
    $at = CarbonImmutable::now();
    $check->site->delete();
    $recorded = app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $check->claim_token, $at, $at, recorderOutcome(RunResult::Failed)));
    expect($recorded->accepted)->toBeFalse()->and(CheckRun::query()->count())->toBe(0)->and(Event::query()->count())->toBe(0);
});

it('uses the current threshold without rewriting the outcome of a config edit mid-run', function (): void {
    $schema = app(CheckRegistry::class)->get(CheckKey::Uptime)->definition()->configSchema;
    $check = Check::factory()->create(['failure_threshold' => 2,
        'config' => $schema->validateConfig(['look_for_string' => 'old assertion'])]);
    $pending = ClaimedRun::start($check);
    $outcome = recorderOutcome(RunResult::Failed, 418);
    $token = $pending->claimToken;
    $check->update(['failure_threshold' => 1, 'config' => $schema->validateConfig(['look_for_string' => 'new assertion']), 'interval_seconds' => 300]);
    $at = CarbonImmutable::now();
    app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $token, $at->subSecond(), $at, $outcome));
    expect(Incident::query()->sole()->evidence['evidence']['status_code'])->toBe(418)
        ->and($check->fresh()->interval_seconds)->toBe(300)->and($check->fresh()->failure_threshold)->toBe(1)
        ->and($check->fresh()->config['look_for_string'])->toBe('new assertion');
});

it('stores suppressed transitions and incidents without changing the run or streak', function (): void {
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $at = CarbonImmutable::now()->startOfSecond()->addMicroseconds(123456);
    CheckSnooze::factory()->create(['check_id' => $check->id, 'starts_at' => $at, 'ends_at' => $at->addMinute()]);
    recordObservation($check, recorderOutcome(RunResult::Failed), $at);
    expect($check->fresh()->latest_run_result)->toBe(CheckResult::Failed)
        ->and(Incident::query()->whereNull('resolved_at')->count())->toBe(1);
    foreach (Event::query()->get() as $event) {
        expect($event->suppressed)->toBeTrue()->and($event->suppression_reason)->toBe('snooze')
            ->and($event->suppression_evaluated_at->equalTo($at))->toBeTrue();
    }
    recordObservation($check, recorderOutcome(RunResult::Succeeded), $at->addMinute());
    expect(Event::query()->where('suppressed', false)->count())->toBe(2)
        ->and(Incident::query()->sole()->resolved_at)->not->toBeNull();
});

it('retains a new warning finding without fabricating a severity transition', function (): void {
    $check = Check::factory()->create();
    $at = CarbonImmutable::now();
    recordObservation($check, recorderOutcome(RunResult::Warning, 200), $at);
    recordObservation($check, recorderOutcome(RunResult::Warning, 201), $at->addSecond());
    expect($check->fresh()->latest_run_result)->toBe(CheckResult::Warning)
        ->and(CheckRun::query()->orderByDesc('ended_at')->first()->evidence['status_code'])->toBe(201)
        ->and(CheckRun::query()->count())->toBe(2)
        ->and(Event::query()->where('type', 'check.result_changed')->count())->toBe(1);
});

it('rolls back the run snapshot incident and outbox together on an invalid addon event', function (): void {
    $check = Check::factory()->create(['failure_threshold' => 1]);
    $outcome = recorderOutcome(RunResult::Failed);
    $bad = new CheckOutcome($outcome->result, $outcome->summary, [], $outcome->metrics, $outcome->evidence,
        [new AddonEvent('undeclared', 'once', 1, [])]);
    expect(fn (): RecordedRun => recordObservation($check, $bad, CarbonImmutable::now()))->toThrow(InvalidArgumentException::class, 'undeclared');
    expect(CheckRun::query()->count())->toBe(0)->and(Incident::query()->count())->toBe(0)->and(Event::query()->count())->toBe(0);
    expect($check->fresh()->latest_run_result)->toBe(CheckResult::Pending)
        ->and($check->fresh()->claim_token)->not->toBeNull()->and($check->fresh()->consecutive_failures)->toBe(0);
});

it('keeps the exact first failure time after raw retention removes the beginning of the streak', function (): void {
    $check = Check::factory()->create();
    $at = CarbonImmutable::now()->startOfSecond()->addMicroseconds(123456);
    recordObservation($check, recorderOutcome(RunResult::Failed), $at);
    CheckRun::query()->delete();
    recordObservation($check, recorderOutcome(RunResult::Failed), $at->addMinute());
    $incident = Incident::query()->sole();
    expect($incident->first_failure_at->equalTo($at->subSecond()))->toBeTrue()
        ->and($incident->opened_at->equalTo($at->addMinute()))->toBeTrue();
    CheckRun::query()->delete();
    expect($incident->fresh()->evidence['evidence']['status_code'])->toBe(503);
});

it('uses afterCommit only as a trigger for already durable events', function (): void {
    $seen = [];
    DomainEvents::listen(OutboxRecorded::class, function (OutboxRecorded $trigger) use (&$seen): void {
        $seen[] = Event::query()->findOrFail($trigger->eventId)->type;
    });
    $check = Check::factory()->create();
    // Laravel ignores the test harness transaction; this is the caller's real transaction boundary.
    DB::beginTransaction();
    recordObservation($check, recorderOutcome(RunResult::Succeeded), CarbonImmutable::now());
    expect($seen)->toBe([])->and(Event::query()->count())->toBe(1);
    DB::commit();
    expect($seen)->toBe(['check.result_changed']);
});

it('derives site display state separately from run results', function (): void {
    $check = Check::factory()->create();
    $service = app(SiteState::class);
    $at = CarbonImmutable::now();
    expect($service->derive($check->site))->toBe(SiteStatus::Pending);
    recordObservation($check, recorderOutcome(RunResult::Failed), $at);
    expect($service->derive($check->site))->toBe(SiteStatus::Failed);
    recordObservation($check, recorderOutcome(RunResult::Failed), $at->addSecond());
    expect($service->derive($check->site))->toBe(SiteStatus::Down);
    recordObservation($check, recorderOutcome(RunResult::Errored), $at->addSeconds(2));
    expect($service->derive($check->site))->toBe(SiteStatus::CheckError);
    $other = Check::factory()->create(['site_id' => $check->site_id, 'type' => CheckKey::Performance, 'latest_run_result' => CheckResult::Failed]);
    expect($service->derive($check->site))->toBe(SiteStatus::Failed);
    $other->update(['enabled' => false]);
    recordObservation($check, recorderOutcome(RunResult::Warning), $at->addSeconds(3));
    expect($service->derive($check->site))->toBe(SiteStatus::Warning);
    recordObservation($check, recorderOutcome(RunResult::Succeeded), $at->addSeconds(4));
    expect($service->derive($check->site))->toBe(SiteStatus::Ok);
    $check->update(['enabled' => false]);
    expect($service->derive($check->site))->toBe(SiteStatus::Disabled);
    $check->site->update(['paused_at' => $at]);
    expect($service->derive($check->site))->toBe(SiteStatus::Paused);
});

function addonRecorderContext(Check $check, ?int $runId = null, ?string $token = null): CheckContext
{
    return ClaimedRun::start($check, runId: $runId);
}

function acceptAddonObservation(Check $check): RecordedRun
{
    $context = addonRecorderContext($check);
    $outcome = app(CheckRegistry::class)->get($check->type)->execute($context);

    return app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome));
}

function recorderCronTask(Check $check, string $name): CronCheck
{
    return app(CronDefinitions::class)->create($check->id, [
        'name' => $name, 'type' => 'simple', 'frequency_in_minutes' => 60, 'grace_time_in_minutes' => 5,
    ]);
}

it('records one certificate fingerprint change from the retained accepted baseline at unchanged severity', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::CertificateHealth]);
    $check->site->update(['url' => 'https://fixtures.test']);
    $fake = new FakeCertificateFetcher;
    app()->instance(CertificateFetcher::class, $fake);
    $oldPem = file_get_contents(base_path('docker/fixtures/tls/valid.crt'));
    $newPem = file_get_contents(base_path('addons/certificate_health/tests/Fixtures/replacement.crt'));
    $fake->respondWith('fixtures.test', new CertificateFetchResult([$oldPem], 'fixtures.test', 443, true));
    expect(acceptAddonObservation($check)->accepted)->toBeTrue()
        ->and(Event::query()->where('type', 'certificate_health.certificate_changed')->count())->toBe(0);
    $this->travel(61)->days();
    app(CheckRegistry::class)->get(CheckKey::CertificateHealth)->persistence()->prune(CarbonImmutable::now()->subDays(31));
    $this->travelTo(CarbonImmutable::parse('2026-10-05T12:00:00Z'));
    $fake->respondWith('fixtures.test', new CertificateFetchResult([$newPem], 'fixtures.test', 443, true));
    $context = addonRecorderContext($check);
    $type = app(CheckRegistry::class)->get(CheckKey::CertificateHealth);
    $outcome = $type->execute($context);
    $repeated = $type->execute($context);
    $recorder = app(TransactionalResultRecorder::class);
    $run = new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $outcome);
    expect($recorder->record($run)->accepted)->toBeTrue()
        ->and($recorder->record($run)->accepted)->toBeFalse();
    expect(Event::query()->where('type', 'certificate_health.certificate_changed')->count())->toBe(1)
        ->and($outcome->events)->toHaveCount(1)
        ->and($repeated->events[0]->dedupeKey)->toBe($outcome->events[0]->dedupeKey);
    $event = Event::query()->where('type', 'certificate_health.certificate_changed')->sole();
    expect($event->from_result)->toBe(CheckResult::Succeeded)
        ->and($event->to_result)->toBe(CheckResult::Succeeded)
        ->and($event->payload_version)->toBe(1)
        ->and($event->payload)->toEqual([
            'old' => ['fingerprint' => '6c2692ea51cbda888bfa8d2e15aa6ff0323ef3fbace9da3bdf777a1c9379bf1a', 'issuer' => 'Heptapod offline fixture CA', 'valid_to' => '2046-01-01T00:00:00+00:00'],
            'new' => ['fingerprint' => '7ff386ccf9821730a803f4ec0507c74b825eea4f8ac1b5e332ec483e7e847311', 'issuer' => 'Heptapod offline fixture CA', 'valid_to' => '2046-09-26T22:48:34+00:00'],
            'summary' => 'certificate_health.valid', 'summary_params' => [],
            'run_id' => $context->runId, 'run_ended_at' => '2026-10-05T12:00:01.000000+00:00',
        ]);
    expect(acceptAddonObservation($check)->accepted)->toBeTrue()
        ->and(Event::query()->where('type', 'certificate_health.certificate_changed')->count())->toBe(1)
        ->and(Event::query()->where('type', 'check.result_changed')->count())->toBe(1);
});

it('records meaningful addon findings when severity remains warning', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $first = recorderCronTask($check, 'First backup');
    $second = recorderCronTask($check, 'Second backup');
    $first->update(['next_due_at' => CarbonImmutable::now()->subMinute()]);
    $this->postJson(route('cron.ping', ['uuid' => $first->uuid]))->assertNoContent();
    expect(acceptAddonObservation($check)->accepted)->toBeTrue();
    $second->update(['next_due_at' => CarbonImmutable::now()->subMinute()]);
    $this->postJson(route('cron.ping', ['uuid' => $second->uuid]))->assertNoContent();
    expect(acceptAddonObservation($check)->accepted)->toBeTrue();
    expect($check->fresh()->latest_run_result)->toBe(CheckResult::Warning)
        ->and(CheckRun::query()->count())->toBe(2)
        ->and(Event::query()->where('type', 'check.result_changed')->count())->toBe(1);
    $events = Event::query()->where('type', 'cron.task_result_changed')->orderBy('id')->get();
    expect($events->pluck('component_id')->all())->toBe([$first->id, $second->id])
        ->and($events->last()->from_result)->toBe(CheckResult::Warning)
        ->and($events->last()->to_result)->toBe(CheckResult::Warning)
        ->and($events->last()->payload['summary'])->toBe('cron.task_late')
        ->and($events->last()->payload['summary_params'])->toBe(['presentation' => 'direct']);
    expect(acceptAddonObservation($check)->accepted)->toBeTrue();
    expect(Event::query()->where('type', 'cron.task_result_changed')->count())->toBe(2);
});

it('stores distinct subjects and component snooze versus whole check snooze decisions for two components of one check', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $first = recorderCronTask($check, 'First backup');
    $second = recorderCronTask($check, 'Second backup');
    $at = CarbonImmutable::now();
    CheckSnooze::factory()->create(['check_id' => $check->id, 'component_type' => 'cron', 'component_id' => $first->id, 'starts_at' => $at->subMinute(), 'ends_at' => $at->addMinutes(10)]);
    foreach ([$first, $second] as $task) {
        $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'failed']))->assertNoContent();
    }
    acceptAddonObservation($check);
    $events = Event::query()->where('type', 'cron.task_result_changed')->orderBy('component_id')->get();
    expect($events->pluck('component_type')->all())->toBe([SuppressionComponent::Cron, SuppressionComponent::Cron])
        ->and($events->pluck('component_id')->all())->toBe([$first->id, $second->id])
        ->and($events->pluck('suppressed')->all())->toBe([true, false])
        ->and($events->first()->suppression_reason)->toBe('snooze')
        ->and($events->first()->payload['summary'])->toBe('cron.task_failed');
    CheckSnooze::factory()->create(['check_id' => $check->id, 'starts_at' => $at->subMinute(), 'ends_at' => $at->addMinutes(10)]);
    foreach ([$first, $second] as $task) {
        $this->postJson(route('cron.ping', ['uuid' => $task->uuid, 'phase' => 'failed']))->assertNoContent();
    }
    acceptAddonObservation($check);
    $later = Event::query()->where('type', 'cron.task_result_changed')->where('id', '>', $events->max('id'))->get();
    expect($later)->toHaveCount(2)->and($later->pluck('suppressed')->all())->toBe([true, true]);
});

it('rejects an addon event subject belonging to another check', function (): void {
    $check = Check::factory()->create(['type' => CheckKey::Cron]);
    $task = recorderCronTask($check, 'Backup');
    $this->postJson(route('cron.ping', ['uuid' => $task->uuid]))->assertNoContent();
    $context = addonRecorderContext($check);
    $outcome = app(CheckRegistry::class)->get(CheckKey::Cron)->execute($context);
    $event = $outcome->events[0];
    $foreign = Check::factory()->create(['type' => CheckKey::Cron]);
    $bad = new CheckOutcome($outcome->result, $outcome->summary, $outcome->summaryParams, $outcome->metrics, $outcome->evidence, [
        new AddonEvent($event->type, $event->dedupeKey, $event->version, $event->payload,
            new SuppressionSubject($foreign->id, SuppressionComponent::Cron, $task->id)),
    ]);
    expect(fn () => app(TransactionalResultRecorder::class)->record(new RecordableRun($check->id, $context->claimToken, $context->startedAt, $context->startedAt->addSecond(), $bad)))
        ->toThrow(InvalidArgumentException::class, 'another check');
    expect(CheckRun::query()->count())->toBe(0)->and(Event::query()->count())->toBe(0)
        ->and(DB::table('cron_run_tasks')->whereNotNull('run_ended_at')->count())->toBe(0)
        ->and($task->refresh()->evaluated_result)->toBeNull()
        ->and($check->fresh()->latest_run_result)->toBe(CheckResult::Pending);
});

it('finalizes only accepted attempt artifacts in the recorder transaction', function (CheckKey $key, string $table): void {
    config(['certificate_health.ca_file' => base_path('docker/fixtures/tls/ca.crt')]);
    $check = Check::factory()->create(['type' => $key]);
    $check->site->update(['url' => 'https://fixtures.test']);
    if ($key === CheckKey::Cron) {
        $task = recorderCronTask($check, 'Backup');
        $this->postJson(route('cron.ping', ['uuid' => $task->uuid]))->assertNoContent();
    }
    $type = app(CheckRegistry::class)->get($key);
    $stale = addonRecorderContext($check);
    $staleOutcome = $type->execute($stale);
    app(ClaimService::class)->revoke($check);
    $accepted = addonRecorderContext($check, $stale->runId);
    $outcome = $type->execute($accepted);
    $recorder = app(TransactionalResultRecorder::class);
    expect($recorder->record(new RecordableRun($check->id, $stale->claimToken, $stale->startedAt, $stale->startedAt->addSecond(), $staleOutcome))->accepted)->toBeFalse();
    expect(DB::table($table)->whereNotNull('run_ended_at')->count())->toBe(0);
    DB::beginTransaction();
    $recorder->record(new RecordableRun($check->id, $accepted->claimToken, $accepted->startedAt, $accepted->startedAt->addSecond(), $outcome));
    expect(DB::table($table)->whereNotNull('run_ended_at')->count())->toBe(1);
    DB::rollBack();
    expect(CheckRun::query()->count())->toBe(0)->and(Event::query()->count())->toBe(0)
        ->and(DB::table($table)->whereNotNull('run_ended_at')->count())->toBe(0);
    if ($key === CheckKey::CertificateHealth) {
        expect(DB::table('detected_certificates')->count())->toBe(0);
    }
    $recorded = $recorder->record(new RecordableRun($check->id, $accepted->claimToken, $accepted->startedAt, $accepted->startedAt->addSecond(), $outcome));
    expect($recorded->accepted)->toBeTrue();
    $reference = new RunReference($accepted->runId, $accepted->startedAt->addSecond());
    $type->persistence()->finalize($reference, $accepted->claimToken);
    $type->persistence()->finalize($reference, $accepted->claimToken);
    $type->persistence()->discard($stale->runId, $stale->claimToken);
    $type->persistence()->discard($stale->runId, $stale->claimToken);
    $type->persistence()->discard($accepted->runId, $accepted->claimToken);
    $type->persistence()->discard($accepted->runId, $accepted->claimToken);
    $absent = new RunReference($accepted->runId + 100000, $reference->endedAt);
    $type->persistence()->finalize($absent, $accepted->claimToken);
    $type->persistence()->finalize($absent, $accepted->claimToken);
    $type->persistence()->discard($absent->id, $accepted->claimToken);
    $type->persistence()->discard($absent->id, $accepted->claimToken);
    expect(DB::table($table)->count())->toBe(1)
        ->and(DB::table($table)->value('claim_token'))->toBe($accepted->claimToken)
        ->and(DB::table($table)->whereNotNull('run_ended_at')->count())->toBe(1)
        ->and(CheckRun::query()->count())->toBe(1);
    if ($key === CheckKey::CertificateHealth) {
        expect(DB::table('detected_certificates')->count())->toBe(1);
    } else {
        expect($task->refresh()->evaluated_result)->toBe(CheckResult::Succeeded);
    }
})->with([
    'certificate' => [CheckKey::CertificateHealth, 'certificate_health_snapshots'],
    'cron' => [CheckKey::Cron, 'cron_run_tasks'],
]);

it('keeps normal incident notification events when a maintenance window is informational', function (): void {
    $at = CarbonImmutable::now()->startOfSecond();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => $at->subMinute(),
        'ends_at' => $at->addMinute(), 'mute_alerts' => false]);
    expect(recordObservation($check, recorderOutcome(RunResult::Failed), $at)->accepted)->toBeTrue();
    $opened = Event::query()->where('type', 'incident.opened')->sole();
    expect($opened->suppressed)->toBeFalse()
        ->and(Incident::query()->sole()->opened_during_maintenance_window_id)->toBeNull();
});

it('resolves a visible incident during muted maintenance without a recovery alert', function (): void {
    $at = CarbonImmutable::now()->startOfSecond();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => $at->subMinute(),
        'ends_at' => $at->addMinute()]);
    recordObservation($check, recorderOutcome(RunResult::Failed), $at);
    recordObservation($check, recorderOutcome(RunResult::Succeeded, 200), $at->addSecond());
    expect(CheckRun::query()->count())->toBe(2)
        ->and(Incident::query()->sole()->resolved_at)->not->toBeNull()
        ->and(Event::query()->where('type', 'incident.resolved')->sole()->suppressed)->toBeTrue();
});

it('records maintenance suppression for site-wide and component events while runs and incidents continue', function (): void {
    $at = CarbonImmutable::now()->startOfSecond();
    $check = Check::factory()->create(['failure_threshold' => 1]);
    MaintenancePeriod::factory()->create(['site_id' => $check->site_id, 'starts_at' => $at->subMinute(), 'ends_at' => $at->addMinute()]);
    expect(recordObservation($check, recorderOutcome(RunResult::Failed), $at)->accepted)->toBeTrue();
    expect(CheckRun::query()->count())->toBe(1)->and(Incident::query()->whereNull('resolved_at')->count())->toBe(1)
        ->and(Incident::query()->sole()->opened_during_maintenance_window_id)->toBe(MaintenancePeriod::query()->sole()->id);
    $component = new SuppressionSubject($check->id, SuppressionComponent::Cron, 1);
    $componentEvent = app(EventOutbox::class)->record($check, $at, 'cron.missed', CheckResult::Failed, CheckResult::Failed, [], subject: $component);
    expect($componentEvent->suppression_reason)->toBe('maintenance')->and($componentEvent->component_id)->toBe(1);
    $events = Event::query()->where('check_id', $check->id)->get();
    expect($events)->toHaveCount(3);
    foreach ($events as $event) {
        expect($event->suppressed)->toBeTrue()->and($event->suppression_reason)->toBe('maintenance');
    }
    app(DeleteMaintenancePeriod::class)(MaintenancePeriod::query()->sole());
    expect($events->first()->fresh()->suppression_reason)->toBe('maintenance');
    expect(app(NotificationSuppression::class)->isSuppressed($component, $at)->suppressed)->toBeFalse();
});
