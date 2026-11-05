<?php

declare(strict_types=1);

namespace Heptapod\Checks\Recording;

use Carbon\CarbonImmutable;
use Heptapod\Actions\WakeUptimeRecoveries;
use Heptapod\Checks\CauseSummary;
use Heptapod\Checks\CheckPending;
use Heptapod\Checks\CheckSchedule;
use Heptapod\Checks\DatabaseClock;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Contracts\AvailabilityFailure;
use Heptapod\Contracts\CheckError;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckOutcome;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\CheckType;
use Heptapod\Contracts\GuardsAcceptance;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\RunPhase;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SecretRedactor;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\LiveSignal;
use Heptapod\Events\Notifications\NotificationLocks;
use Heptapod\Events\RecordedRunSignal;
use Heptapod\Incidents\IncidentTransitions;
use Heptapod\Incidents\UptimeDependencies;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Site;
use Heptapod\Support\RedactionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

final readonly class TransactionalResultRecorder implements ResultRecorder
{
    public function __construct(
        private CheckRegistry $registry,
        private IncidentTransitions $incidents,
        private EventOutbox $outbox,
        private CauseSummary $summaries,
        private DatabaseClock $clock,
    ) {}

    /**
     * A deadlock (40P01) aborts the whole recording; it runs once more so the observation is kept rather than replaced by
     * a Check error. The claim token fences the retry: a committed recording clears it, so a retry cannot record twice.
     * A second deadlock fails the job, and RunCheck::failed records an errored run with that message. Laravel retries only
     * an outermost transaction: a caller that records inside its own (CheckExecutor's serialized evaluation) owns the retry.
     */
    public function record(RecordableRun $run): RecordedRun
    {
        return DB::transaction(function () use ($run): RecordedRun {
            // NotificationLocks: this check (FOR UPDATE) and, for uptime, the dependent checks it may release an opening on,
            // in ascending id; then the site. The unlocked read finds out which set to lock (one primary-key lookup).
            $subject = DB::table('checks')->where('id', $run->checkId)->first(['site_id', 'type']);
            /** @var (\stdClass&object{site_id: int, type: string})|null $subject */
            $check = $subject === null ? null : NotificationLocks::checkForUpdate($run->checkId,
                $subject->type === CheckKey::Uptime->value ? UptimeDependencies::dependentCheckIds($subject->site_id) : []);
            if (! $check instanceof Check || $check->claim_token !== $run->claimToken) {
                Log::notice('Discarded stale check result.', ['check_id' => $run->checkId, 'claim_token' => $run->claimToken]);

                return new RecordedRun(false, null);
            }
            NotificationLocks::sites([$check->site_id]);
            $attempt = CheckRunAttempt::query()->with('actor')->where('claim_token', $run->claimToken)->firstOrFail();
            $type = in_array($check->type, $this->registry->keys(), true) ? $this->registry->get($check->type) : null;
            if ($attempt->cancel_requested_at !== null) {
                if ($check->active_run_id !== null) {
                    $type?->persistence()?->discard($check->active_run_id, $run->claimToken);
                }
                $attempt->update(['phase' => RunPhase::Cancelled, 'finished_at' => $run->endedAt, 'note' => 'cancelled']);
                $cleared = $this->clearSlot($check);
                LiveSignal::forRun('run.finished', $check->site()->getQuery()->firstOrFail(), $check->id, $run->startedAt, $attempt->actor?->name, outcome: RunPhase::Cancelled, attemptId: $attempt->id);
                $this->announceCleared($cleared, $check);

                return new RecordedRun(true, null);
            }
            $persistence = $type?->persistence();
            if ($type instanceof GuardsAcceptance && $run->acceptanceRevision !== null && $type->revision($check->id) !== $run->acceptanceRevision) {
                if ($check->active_run_id !== null) {
                    $persistence?->discard($check->active_run_id, $run->claimToken);
                }
                $type->serializeNext($check->id, true);
                $cleared = $this->clearSlot($check);
                $attempt->update(['phase' => RunPhase::Skipped, 'finished_at' => $run->endedAt, 'note' => 'definition_changed']);
                LiveSignal::forRun('run.finished', $check->site()->getQuery()->firstOrFail(), $check->id, $run->startedAt, $attempt->actor?->name, outcome: RunPhase::Skipped, attemptId: $attempt->id);
                $this->announceCleared($cleared, $check);
                // Definition churn waits at least one interval, never retrying in an immediate hot loop.
                $check->update(['next_run_at' => CheckSchedule::nextSlot($check->id, $check->interval_seconds,
                    max($this->clock->now(), $run->endedAt)->addSeconds($check->interval_seconds))]);
                Log::notice('Discarded stale check evaluation.', ['check_id' => $check->id]);

                return new RecordedRun(false, null);
            }
            $site = $check->site()->getQuery()->firstOrFail();
            if (! $check->enabled || $site->paused_at !== null || $site->marked_for_deletion_at !== null) {
                if ($check->active_run_id !== null) {
                    $persistence?->discard($check->active_run_id, $run->claimToken);
                }
                $cleared = $this->clearSlot($check);
                $attempt->update(['phase' => RunPhase::Skipped, 'finished_at' => $run->endedAt, 'note' => 'check_unavailable']);
                LiveSignal::forRun('run.finished', $site, $check->id, $run->startedAt, $attempt->actor?->name, outcome: RunPhase::Skipped, attemptId: $attempt->id);
                $this->announceCleared($cleared, $check, $site);
                Log::notice('Discarded inactive check result.', ['check_id' => $run->checkId]);

                return new RecordedRun(false, null);
            }
            $outcome = $type instanceof CheckType ? $run->outcome
                : new CheckOutcome(RunResult::Errored, 'checks.engine.addon_missing', [], null,
                    error: new CheckError(\LogicException::class, 'Check addon is not installed.'));
            if ($type instanceof GuardsAcceptance) {
                $type->serializeNext($check->id, false);
            }
            if (! $outcome instanceof CheckOutcome) {
                if ($check->active_run_id !== null) {
                    $persistence?->discard($check->active_run_id, $run->claimToken);
                }
                if (! $outcome instanceof NoResultReason) {
                    throw new \LogicException('A no-result attempt must carry a classified reason.');
                }
                $classification = app(NoResultFreshness::class)->classify($outcome, $check, $run->endedAt);
                $cleared = $this->clearSlot($check, $run->endedAt, $classification['action'] === 'resets');
                $attempt->update(['phase' => RunPhase::Skipped, 'finished_at' => $run->endedAt, 'note' => 'no_result:'.$outcome->value]);
                LiveSignal::forRun('run.finished', $site, $check->id, $run->startedAt, $attempt->actor?->name, outcome: RunPhase::Skipped, attemptId: $attempt->id);
                $this->announceCleared($cleared, $check, $site);

                return new RecordedRun(true, null);
            }
            if ($outcome->metrics instanceof Data && $type instanceof CheckType) {
                try {
                    /** @var array<string, mixed> $metrics */
                    $metrics = $outcome->metrics->toArray();
                    $type->definition()->metricSchema->validateValues($metrics);
                } catch (ValidationException $exception) {
                    $outcome = new CheckOutcome(RunResult::Errored, 'checks.engine.errored', [], null,
                        error: new CheckError($exception::class, json_encode($exception->errors(), JSON_THROW_ON_ERROR)));
                }
            }
            $presentationEvents = [];
            foreach ($outcome->events as $event) {
                if ($event->presentationParams !== null) {
                    if (array_key_exists($event->type, $presentationEvents)) {
                        throw new \LogicException('An aggregate presentation event must be unique per run.');
                    }
                    $presentationEvents[$event->type] = $event->presentationParams;
                }
            }
            $attributes = [
                'check_id' => $check->id, 'check_type' => $check->type, 'result' => $outcome->result,
                'started_at' => $run->startedAt, 'ended_at' => $run->endedAt, 'summary' => $outcome->summary,
                'summary_params' => $outcome->summaryParams, 'metrics' => $outcome->metrics?->toArray(),
                'evidence' => [...($outcome->evidence?->toArray() ?? []), ...($presentationEvents === [] ? [] : ['presentation_events' => $presentationEvents]), ...($outcome->availabilityFailure instanceof AvailabilityFailure ? ['availability_failure' => $outcome->availabilityFailure->value] : [])],
                'error' => $outcome->error?->toJson(),
            ];
            if ($check->active_run_id !== null) {
                $attributes['id'] = $check->active_run_id;
            }
            $check->setRelation('site', $site);
            $stored = new CheckRun($attributes);
            // Presentation masks can invalidate typed facts such as certificate dates.
            $summary = $this->summaries->project($check, $stored);
            $stored->summary = $summary['key'] ?? throw new \LogicException('A run must have a summary.');
            $stored->summary_params = $summary['params'];
            $redaction = RedactionContext::forSite($site, $check, $attributes['evidence']);
            /** @var array<string, mixed> $redactedEvidence Redaction preserves the input array shape. */
            $redactedEvidence = SecretRedactor::redactValues($attributes['evidence'], $redaction->secrets, $redaction->urls);
            // The availability class is a protocol fact, not target-supplied display text.
            if ($outcome->availabilityFailure instanceof AvailabilityFailure) {
                $redactedEvidence['availability_failure'] = $outcome->availabilityFailure->value;
            }
            $redactedEvidence['verified_nothing'] = $outcome->verifiedNothing;
            $stored->evidence = $redactedEvidence;
            $stored->save();
            if ($outcome->result === RunResult::Errored) {
                $persistence?->discard($stored->id, $run->claimToken);
            } else {
                $persistence?->finalize($stored->reference(), $run->claimToken);
            }
            $from = $check->latest_run_result;
            $to = CheckResult::from($outcome->result->value);
            $failing = in_array($outcome->result, [RunResult::Failed, RunResult::Errored], true);
            $countFailure = ! $outcome->incomplete || $outcome->confirmedIncompleteFailure;
            $check->update([
                'first_result_at' => $outcome->result === RunResult::Errored ? $check->first_result_at : ($check->first_result_at ?? $run->endedAt),
                'failing_since' => $countFailure ? ($failing ? ($check->failing_since ?? $run->startedAt) : null) : $check->failing_since,
                'latest_run_result' => $countFailure ? $to : $from, 'latest_run_id' => $stored->id,
                'latest_run_started_at' => $run->startedAt, 'latest_run_ended_at' => $run->endedAt,
                'summary' => $stored->summary, 'summary_params' => $stored->summary_params,
                'consecutive_failures' => $countFailure ? ($failing ? $check->consecutive_failures + 1 : 0) : $check->consecutive_failures,
            ]);
            $incident = $this->incidents->apply($check, $stored, $from, $outcome->incomplete, $outcome->confirmedIncompleteFailure, $outcome->verifiedNothing);
            // Partial verification can recover a pure Check error; verifying nothing never can.
            $recoversError = ! $outcome->verifiedNothing && $incident?->cause === IncidentCause::Errored && $incident->resolved_at !== null;
            if (! $countFailure && $recoversError) {
                $check->update(['latest_run_result' => $to]);
            }
            // heptapod:reclassify-links marks a Warning that still rested on relabeled findings. The next complete evaluation
            // consumes the mark; if it ends that Warning, the change is a policy reclassification, not a verified recovery.
            $policyMark = $check->policy_reclassification_pending;
            $policyOnly = false;
            if (is_array($policyMark) && ! $outcome->incomplete) {
                $policyOnly = $from === CheckResult::Warning && $check->latest_run_result === CheckResult::Succeeded;
                $check->update(['policy_reclassification_pending' => null]);
            }
            if ($from !== $check->latest_run_result) {
                $this->outbox->record($check, $run->endedAt, 'check.result_changed', $from, $check->latest_run_result,
                    ['run_id' => $stored->id, 'run_ended_at' => $run->endedAt->format('Y-m-d\TH:i:s.uP'), 'summary' => $stored->summary, 'summary_params' => $stored->summary_params], $incident, $incident?->cause,
                    suppressionReason: $policyOnly ? 'policy_reclassified' : null);
            }
            if ($policyOnly) {
                $relabeled = $policyMark['relabeled_ids'] ?? null;
                $this->outbox->record($check, $run->endedAt, 'check.findings_reclassified', $from, $check->latest_run_result,
                    ['run_id' => $stored->id, 'run_ended_at' => $run->endedAt->format('Y-m-d\TH:i:s.uP'), 'policy_version' => $policyMark['policy'] ?? throw new \LogicException('A policy mark needs its version.'),
                        'count' => is_array($relabeled) ? count($relabeled) : throw new \LogicException('A policy mark needs its relabeled findings.')],
                    $incident, $incident?->cause, suppressionReason: 'policy_reclassified');
            }
            foreach ($outcome->events as $event) {
                if (! $type instanceof CheckType || ! in_array($event->type, $type->definition()->emittedEvents, true)) {
                    throw new \InvalidArgumentException('An addon emitted an undeclared event type.');
                }
                $this->outbox->record($check, $run->endedAt, $event->type, $from, $to,
                    ['summary' => $stored->summary, 'summary_params' => $stored->summary_params, ...$event->payload,
                        'run_id' => $stored->id, 'run_ended_at' => $run->endedAt->format('Y-m-d\TH:i:s.uP')],
                    $incident, $incident?->cause, $event->dedupeKey, $event->version, $event->subject);
            }
            // The stored result sends state.changed below, so a cleared mark needs no signal of its own.
            $this->clearSlot($check, $run->endedAt);
            $runOutcome = in_array($outcome->result, [RunResult::Failed, RunResult::Errored], true) ? RunPhase::Failed : RunPhase::Done;
            $attempt->update(['phase' => $runOutcome, 'finished_at' => $run->endedAt, 'check_run_id' => $stored->id]);
            LiveSignal::forRun('run.finished', $site, $check->id, $run->startedAt, $attempt->actor?->name, outcome: $runOutcome,
                attemptId: $attempt->id, recorded: new RecordedRunSignal($stored->id, $run->endedAt, CheckResult::from($stored->result->value)));
            LiveSignal::forSite($site, ['check' => $check->id]);
            if ($check->type === CheckKey::Uptime) {
                DB::afterCommit(static fn () => app(WakeUptimeRecoveries::class)($check->id));
            }

            return new RecordedRun(true, $stored->id);
        }, attempts: 2);
    }

    /** A skip stores no result and sends no state.changed, yet a cleared mark moves the site off pending. */
    private function announceCleared(bool $cleared, Check $check, ?Site $site = null): void
    {
        if ($cleared) {
            LiveSignal::forSite($site ?? $check->site()->getQuery()->firstOrFail(), ['check' => $check->id]);
        }
    }

    /** @return bool whether a pending mark was cleared */
    private function clearSlot(Check $check, ?CarbonImmutable $completedAt = null, bool $resetFreshness = true): bool
    {
        if ($completedAt instanceof CarbonImmutable) {
            $at = max($this->clock->now(), $completedAt);
            // Manual runs retain a future slot; an overdue slot advances without changing its phase.
            if (! $check->manual_run || $check->next_run_at <= $at) {
                $check->next_run_at = CheckSchedule::nextSlot($check->id, $check->interval_seconds, $at);
            }
        }
        // Every finished attempt ends a pending recheck, whatever its outcome; a lease replacement or revoke never reaches here.
        $cleared = CheckPending::clear($check);
        $check->update(['run_headers' => null, 'manual_run' => false, 'awaiting_result_since' => $resetFreshness ? null : $check->awaiting_result_since, 'lost_attempts' => $resetFreshness ? 0 : $check->lost_attempts, 'last_loss_started_at' => $resetFreshness ? null : $check->last_loss_started_at, 'claim_token' => null, 'dispatched_at' => null, 'lease_expires_at' => null,
            'run_started_at' => null, 'active_run_id' => null]);

        return $cleared;
    }
}
