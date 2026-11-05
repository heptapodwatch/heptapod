<?php

declare(strict_types=1);

namespace Heptapod\Incidents;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\ReclassifiedCheckError;
use Heptapod\Contracts\RunResult;
use Heptapod\Enums\IncidentCause;
use Heptapod\Events\EventOutbox;
use Heptapod\Events\Notifications\UptimeAlertEpisode;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRun;
use Heptapod\Models\Event;
use Heptapod\Models\Incident;
use Heptapod\Models\MaintenancePeriod;
use Heptapod\Settings\InstanceSettings;
use Illuminate\Support\Facades\Log;

/** Called only with the check row locked in the recording transaction. */
final readonly class IncidentTransitions
{
    public function __construct(private EventOutbox $outbox, private UptimeDependencies $dependencies) {}

    /** The same observation that an earlier release recorded as Failed now reported as a Check error. */
    private function reclassifiedOnUpgrade(CheckRun $run, IncidentCause $fromCause, IncidentCause $toCause): bool
    {
        if ($fromCause !== IncidentCause::Failed || $toCause !== IncidentCause::Errored || $run->error === null) {
            return false;
        }
        $error = json_decode($run->error, true, flags: JSON_THROW_ON_ERROR);

        return is_array($error) && ($error['class'] ?? null) === ReclassifiedCheckError::class;
    }

    public function apply(Check $check, CheckRun $run, CheckResult $from, bool $incomplete = false, bool $confirmedIncompleteFailure = false, bool $verifiedNothing = false): ?Incident
    {
        $query = $check->incidents()->getQuery();
        $query->getQuery()->whereNull('resolved_at');
        $incident = $query->first();
        if (! $verifiedNothing && $incident instanceof Incident && isset($incident->evidence['unverified_reason_key'])) {
            $incident->update(['evidence' => array_diff_key($incident->evidence, array_flip(['unverified_reason_key', 'unverified_reason_params', 'unverified_run_id']))]);
        }
        // A cause flip must not erase the need to re-check findings from this incident.
        $requiresCompleteRecovery = $incident instanceof Incident && ($incomplete && $incident->cause === IncidentCause::Errored
            ? $this->requiresCompleteRecovery($incident, $run)
            : $incident->requiresCompleteRecovery());
        if ($verifiedNothing && ($incident instanceof Incident || in_array($run->result, [RunResult::Succeeded, RunResult::Warning], true))) {
            if ($incident instanceof Incident && $incident->cause === IncidentCause::Errored) {
                // Legacy opening snapshots can lack a retained summary.
                $previousReason = $incident->evidence['unverified_reason_key'] ?? $incident->evidence['summary'] ?? null;
                if ($previousReason !== $run->summary) {
                    $incident->update(['evidence' => [...$incident->evidence,
                        'unverified_reason_key' => $run->summary, 'unverified_reason_params' => $run->summary_params,
                        'unverified_run_id' => $run->id]]);
                    $this->outbox->record($check, $run->ended_at, 'incident.cause_changed', CheckResult::Errored, CheckResult::Errored,
                        ['result' => RunResult::Errored->value, 'run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP'),
                            'summary' => $run->summary, 'summary_params' => $run->summary_params,
                            'from_cause' => IncidentCause::Errored->value, 'to_cause' => IncidentCause::Errored->value,
                            'from_reason_key' => $previousReason, 'to_reason_key' => $run->summary],
                        $incident, IncidentCause::Errored);
                }
            }

            return $incident;
        }
        $recoversError = ! $verifiedNothing && $incident?->cause === IncidentCause::Errored && ! $requiresCompleteRecovery
            && in_array($run->result, [RunResult::Succeeded, RunResult::Warning], true);
        if ($incomplete && ! $confirmedIncompleteFailure && ! $recoversError) {
            return $incident;
        }
        $to = CheckResult::from($run->result->value);
        $payload = ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP'), 'summary' => $run->summary, 'summary_params' => $run->summary_params];
        if (in_array($run->result, [RunResult::Succeeded, RunResult::Warning], true)) {
            $recheckAfter = UptimeDependencies::recheckAfter($incident);
            if (UptimeDependencies::isGrouped($incident) && $recheckAfter !== null && ($run->started_at ?? throw new \LogicException('A recovery observation needs its start time.'))->lessThanOrEqualTo(CarbonImmutable::parse($recheckAfter))) {
                // An in-flight outage-era observation cannot confirm recovery after uptime came back.
                return $incident;
            }
            if ($incident instanceof Incident) {
                // A confirmed but incomplete warning escalates the check; it does not prove that the incident recovered.
                if ($incomplete && $confirmedIncompleteFailure) {
                    return $incident;
                }
                if ($incomplete) {
                    $check->update(['failing_since' => null, 'consecutive_failures' => 0]);
                }
                $incident->update(['resolved_at' => $run->ended_at]);
                $this->outbox->record($check, $run->ended_at, 'incident.resolved', $from, $to, $payload, $incident, $incident->cause);
                $this->dependencies->synchronize($check, $run, $incident, $this->outbox);
            }

            return $incident;
        }
        $cause = $run->result === RunResult::Errored ? IncidentCause::Errored : IncidentCause::Failed;
        if ($incident instanceof Incident) {
            if ($incident->cause !== $cause) {
                $payload['from_cause'] = $incident->cause->value;
                $payload['to_cause'] = $cause->value;
                // Only the first cause change of a pre-activation incident can be the upgrade reclassification.
                $preActivation = ! isset($incident->evidence['upgrade_reclassification_consumed'])
                    && $incident->opened_at->lessThan(CarbonImmutable::parse(app(InstanceSettings::class)->check_error_reclassified_at));
                $reclassified = $preActivation && $this->reclassifiedOnUpgrade($run, $incident->cause, $cause);
                $incident->update(['cause' => $cause, 'evidence' => [...$incident->evidence,
                    'requires_complete_recovery' => $requiresCompleteRecovery || $incident->cause === IncidentCause::Failed || $cause === IncidentCause::Failed,
                    ...($preActivation ? ['upgrade_reclassification_consumed' => $run->id] : [])]]);
                $this->outbox->record($check, $run->ended_at, 'incident.cause_changed', $from, $to, $payload, $incident, $cause,
                    suppressionReason: $reclassified ? 'policy_reclassified' : null);
            }

            $this->dependencies->synchronize($check, $run, $incident, $this->outbox);

            return $incident;
        }
        if ($check->consecutive_failures < $check->failure_threshold) {
            return null;
        }
        $windowQuery = MaintenancePeriod::forSite($check->site_id, $run->ended_at);
        $windowQuery->getQuery()->where('mute_alerts', true)
            ->where('starts_at', '<=', $run->ended_at->format('Y-m-d H:i:s.uP'))
            ->where('ends_at', '>', $run->ended_at->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('catch_up_at_end')->orderBy('ends_at')->orderBy('id');
        $window = $windowQuery->first();
        $incident = $check->incidents()->create([
            'site_id' => $check->site_id, 'cause' => $cause, 'opened_at' => $run->ended_at,
            'opened_during_maintenance_window_id' => $window?->id,
            'first_failure_at' => $check->failing_since,
            ...UptimeAlertEpisode::opening($check, $check->failing_since ?? throw new \LogicException('An opening incident requires its first failure.')),
            'evidence' => ['run_id' => $run->id, 'run_ended_at' => $run->ended_at->format('Y-m-d\TH:i:s.uP'),
                'summary' => $run->summary, 'summary_params' => $run->summary_params,
                'metrics' => $run->metrics, 'evidence' => $run->evidence, 'error' => $run->error,
                'requires_complete_recovery' => $cause === IncidentCause::Failed],
        ]);
        $this->dependencies->synchronize($check, $run, $incident, $this->outbox);
        $this->outbox->record($check, $run->ended_at, 'incident.opened', $from, $to, $payload, $incident, $cause);

        return $incident;
    }

    private function requiresCompleteRecovery(Incident $incident, CheckRun $run): bool
    {
        $evidence = $incident->evidence;
        if (array_key_exists('requires_complete_recovery', $evidence)) {
            return $incident->requiresCompleteRecovery();
        }

        // Legacy incidents derive their sticky finding history once, before any new cause flip.
        $query = Event::query();
        $query->getQuery()->where('incident_id', $incident->id)
            ->whereIn('type', ['incident.opened', 'incident.cause_changed'])
            ->where('occurred_at', '>=', $incident->opened_at->format('Y-m-d H:i:s.uP'))
            ->where('occurred_at', '<=', $run->ended_at->format('Y-m-d H:i:s.uP'))
            ->orderByDesc('occurred_at')->limit(1001);
        $events = $query->get(['type', 'cause', 'payload']);
        $hadFindings = $events->contains(static fn (Event $event): bool => $event->cause === IncidentCause::Failed
            || ($event->payload['from_cause'] ?? null) === IncidentCause::Failed->value
            || ($event->payload['to_cause'] ?? null) === IncidentCause::Failed->value);
        $historyIncomplete = $events->count() > 1000 || ! $events->contains('type', 'incident.opened');
        $requiresCompleteRecovery = $hadFindings || $historyIncomplete;
        $incident->update(['evidence' => [...$evidence, 'requires_complete_recovery' => $requiresCompleteRecovery]]);
        if ($historyIncomplete) {
            Log::notice('Legacy incident recovery history unavailable; complete verification required.', ['incident_id' => $incident->id]);
        }

        return $requiresCompleteRecovery;
    }
}
