<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckResult;
use Heptapod\Contracts\NoResultReason;
use Heptapod\Contracts\NotificationSuppression;
use Heptapod\Contracts\PerformanceResponseTimeWindow;
use Heptapod\Contracts\RunResult;
use Heptapod\Contracts\SuppressionSubject;
use Heptapod\Enums\FreshnessSuppression;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;
use Heptapod\Models\Incident;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnhandledMatchError;

/**
 * @phpstan-type NoResultGapClassification array{action: 'suppressed', reason: 'uptime_incident'|'uptime_check_error'|'maintenance'|'snooze'}|array{action: 'counts', reason: 'freshness.no_result.mixed_content_start_page'|'freshness.no_result.uptime_disabled'|'freshness.no_result.uptime_interval_too_long'|'freshness.no_result.uptime_errors'|'freshness.no_result.uptime_no_measurements'}
 * @phpstan-type FreshnessClassification NoResultGapClassification|array{action: 'resets', reason: null}
 */
final readonly class NoResultFreshness
{
    private const array PerformanceNotes = ['no_result:performance_no_samples', 'no_result:performance_uptime_interval_too_long'];

    public function __construct(private CheckFreshness $freshness, private UptimeDependency $uptime, private NotificationSuppression $suppression) {}

    /**
     * Page-local inputs for list readers. The classifier stays the same for lists, the evaluator and individual checks.
     *
     * @param  Collection<int, Check>  $checks  Checks from the authorized page only.
     */
    public function snapshot(Collection $checks, CarbonImmutable $at): FreshnessSnapshot
    {
        $checkIds = array_values($checks->map(static fn (Check $check): int => $check->id)->all());
        if ($checkIds === []) {
            return new FreshnessSnapshot($at, $this->uptime->snapshot([], null), [], new Collection, []);
        }
        $latest = DB::table('check_run_attempts')->select('id')->whereColumn('check_id', 'checks.id')
            ->whereNotNull('finished_at')->orderByDesc('id')->limit(1);
        $ids = Check::query();
        $ids->getQuery()->selectSub($latest, 'attempt_id')->whereIntegerInRaw('checks.id', $checkIds);
        $attempts = CheckRunAttempt::query();
        $attempts->getQuery()->whereIn('id', $ids->getQuery());
        $latestAttempts = $attempts->get()->keyBy('check_id');
        // Only a current no-result attempt asks for incidents resolved after it (noResultAttempt()); other checks need open ones.
        $oldestAttemptAt = null;
        foreach ($checks as $check) {
            $attemptAt = $this->noResultAttemptAt($check, $latestAttempts->get($check->id));
            if ($attemptAt instanceof CarbonImmutable && (! $oldestAttemptAt instanceof CarbonImmutable || $attemptAt->lessThan($oldestAttemptAt))) {
                $oldestAttemptAt = $attemptAt;
            }
        }
        $uptime = $this->uptime->snapshot(array_values($checks->map(static fn (Check $check): int => $check->site_id)->unique()->all()), $oldestAttemptAt);
        $subjects = $checks->filter(static fn (Check $check): bool => $check->type === CheckKey::Performance
            && in_array($latestAttempts->get($check->id)?->note, self::PerformanceNotes, true));
        foreach ($subjects->all() as $check) {
            $uptimeCheck = $uptime->check($check->site_id);
            if ($uptimeCheck instanceof Check) {
                $subjects->push($uptimeCheck);
            }
        }
        $subjects = $subjects->unique('id')->values();
        $decide = function () use ($subjects, $at): array {
            $decisions = [];
            foreach ($subjects as $subject) {
                $decisions[$subject->id] = $this->suppression->isSuppressed(new SuppressionSubject($subject->id), $at);
            }

            return $decisions;
        };
        $decisions = $subjects->isNotEmpty() && $this->suppression instanceof SnoozeNotificationSuppression
            ? $this->suppression->withPreloadedChecks($subjects, $at, $decide) : $decide();

        return new FreshnessSnapshot($at, $uptime, array_fill_keys($checkIds, true), $latestAttempts, $decisions);
    }

    /** @return FreshnessClassification */
    public function classify(NoResultReason $reason, Check $check, CarbonImmutable $at, ?CarbonImmutable $attemptAt = null, ?FreshnessSnapshot $snapshot = null): array
    {
        try {
            $suppression = $this->noResultSuppression($reason, $check, $at, $attemptAt, $snapshot);
            if ($suppression instanceof FreshnessSuppressionState) {
                return ['action' => 'suppressed', 'reason' => match ($suppression->reason) {
                    FreshnessSuppression::Down => 'uptime_incident',
                    FreshnessSuppression::UptimeError => 'uptime_check_error',
                    FreshnessSuppression::Maintenance => 'maintenance',
                    FreshnessSuppression::Snooze => 'snooze',
                }];
            }

            return match ($reason) {
                NoResultReason::PerformanceNoSamples, NoResultReason::PerformanceUptimeIntervalTooLong => $this->performance($check, $at, $snapshot),
                NoResultReason::MixedContentStartPageNotScanned => ['action' => 'counts', 'reason' => 'freshness.no_result.mixed_content_start_page'],
                NoResultReason::CronTasksPending => ['action' => 'resets', 'reason' => null],
            };
        } catch (UnhandledMatchError $error) {
            throw new LogicException('Unclassified no-result reason: '.$reason->value, $error->getCode(), previous: $error);
        }
    }

    /** @return FreshnessClassification|null */
    public function forCheck(Check $check, CarbonImmutable $at, ?FreshnessSnapshot $snapshot = null): ?array
    {
        $this->assertSnapshotTime($at, $snapshot);
        $attempt = $this->noResultAttempt($check, $snapshot);

        return $attempt === null ? null : $this->classify($attempt['reason'], $check, $at, $attempt['finishedAt'], $snapshot);
    }

    public function suppression(Check $check, CarbonImmutable $at, ?FreshnessSnapshot $snapshot = null): ?FreshnessSuppressionState
    {
        $this->assertSnapshotTime($at, $snapshot);
        if ($this->freshness->state($check, $at)->state !== 'not_fresh') {
            return null;
        }
        // D9 suppresses every check's gap while the site is Down, even without a no-result attempt.
        $incident = $this->openIncident($check->site_id, IncidentCause::Failed, null, $snapshot);
        if ($incident instanceof Incident) {
            return FreshnessSuppressionState::since(FreshnessSuppression::Down, $incident->first_failure_at);
        }
        $attempt = $this->noResultAttempt($check, $snapshot);

        return $attempt === null ? null : $this->noResultSuppression($attempt['reason'], $check, $at, $attempt['finishedAt'], $snapshot);
    }

    private function assertSnapshotTime(CarbonImmutable $at, ?FreshnessSnapshot $snapshot): void
    {
        if ($snapshot instanceof FreshnessSnapshot && ! $snapshot->at->equalTo($at)) {
            throw new LogicException('A freshness snapshot answers only for the instant it was read at.');
        }
    }

    private function uptimeCheck(int $siteId, ?FreshnessSnapshot $snapshot): ?Check
    {
        return $snapshot instanceof FreshnessSnapshot ? $snapshot->uptime->check($siteId) : $this->uptime->check($siteId);
    }

    private function openIncident(int $siteId, ?IncidentCause $cause, ?CarbonImmutable $openAt, ?FreshnessSnapshot $snapshot): ?Incident
    {
        return $snapshot instanceof FreshnessSnapshot ? $snapshot->uptime->openIncident($siteId, $cause, $openAt)
            : $this->uptime->openIncident($siteId, $cause, $openAt);
    }

    /** @return array{reason: NoResultReason, finishedAt: CarbonImmutable}|null */
    private function noResultAttempt(Check $check, ?FreshnessSnapshot $snapshot): ?array
    {
        if ($check->awaiting_result_since === null) {
            return null;
        }
        // A finished attempt is stable while the next attempt is queued or running. Never scan the episode.
        if ($snapshot instanceof FreshnessSnapshot) {
            $attempt = $snapshot->latestAttempt($check->id);
        } else {
            $attempts = CheckRunAttempt::query()->where('check_id', $check->id);
            $attempts->getQuery()->whereNotNull('finished_at')->orderByDesc('id');
            $attempt = $attempts->first();
        }
        $finishedAt = $this->noResultAttemptAt($check, $attempt);
        if (! $finishedAt instanceof CarbonImmutable || ! is_string($attempt?->note)) {
            return null;
        }
        $reason = NoResultReason::tryFrom(substr($attempt->note, strlen('no_result:')))
            ?? throw new LogicException('Unknown no-result reason: '.$attempt->note);

        return ['reason' => $reason, 'finishedAt' => $finishedAt];
    }

    /** When the check's latest finished attempt recorded no result in the current wait, or null. */
    private function noResultAttemptAt(Check $check, ?CheckRunAttempt $attempt): ?CarbonImmutable
    {
        if ($check->awaiting_result_since === null || $attempt?->finished_at === null || $attempt->finished_at->lessThan($check->awaiting_result_since)) {
            return null;
        }

        return is_string($attempt->note) && str_starts_with($attempt->note, 'no_result:') ? $attempt->finished_at : null;
    }

    private function noResultSuppression(NoResultReason $reason, Check $check, CarbonImmutable $at, ?CarbonImmutable $attemptAt, ?FreshnessSnapshot $snapshot): ?FreshnessSuppressionState
    {
        return match ($reason) {
            NoResultReason::PerformanceNoSamples, NoResultReason::PerformanceUptimeIntervalTooLong => $this->performanceSuppression($check, $at, $attemptAt, $snapshot),
            NoResultReason::MixedContentStartPageNotScanned => $this->incidentSuppression($check, $attemptAt, $snapshot),
            NoResultReason::CronTasksPending => null,
        };
    }

    private function incidentSuppression(Check $check, ?CarbonImmutable $attemptAt, ?FreshnessSnapshot $snapshot): ?FreshnessSuppressionState
    {
        $incident = $this->openIncident($check->site_id, null, $attemptAt, $snapshot);

        return $incident instanceof Incident ? FreshnessSuppressionState::since(match ($incident->cause) {
            IncidentCause::Failed => FreshnessSuppression::Down,
            IncidentCause::Errored => FreshnessSuppression::UptimeError,
        }, $incident->first_failure_at) : null;
    }

    private function performanceSuppression(Check $check, CarbonImmutable $at, ?CarbonImmutable $attemptAt, ?FreshnessSnapshot $snapshot): ?FreshnessSuppressionState
    {
        $incident = $this->incidentSuppression($check, $attemptAt, $snapshot);
        if ($incident instanceof FreshnessSuppressionState) {
            return $incident;
        }
        $uptime = $this->uptimeCheck($check->site_id, $snapshot);
        // A disabled uptime check does not expose its stale Check error.
        if ($uptime?->enabled === true && $uptime->latest_run_result === CheckResult::Errored) {
            return FreshnessSuppressionState::latestUptimeError(
                $uptime->latest_run_ended_at ?? throw new LogicException('An Errored uptime result must retain its end time.'));
        }
        foreach ([$check, $uptime] as $subject) {
            if ($subject instanceof Check) {
                $decision = $snapshot instanceof FreshnessSnapshot ? $snapshot->suppression($subject->id)
                    : $this->suppression->isSuppressed(new SuppressionSubject($subject->id), $at);
                if ($decision->suppressed) {
                    return FreshnessSuppressionState::since(match ($decision->reason) {
                        'maintenance' => FreshnessSuppression::Maintenance,
                        'snooze' => FreshnessSuppression::Snooze,
                        default => throw new LogicException('Unknown notification suppression reason: '.$decision->reason),
                    }, $decision->since ?? throw new LogicException('A planned suppression must retain its source start.'));
                }
            }
        }

        return null;
    }

    /** @return array{action: 'counts', reason: 'freshness.no_result.uptime_disabled'|'freshness.no_result.uptime_interval_too_long'|'freshness.no_result.uptime_errors'|'freshness.no_result.uptime_no_measurements'} */
    private function performance(Check $check, CarbonImmutable $at, ?FreshnessSnapshot $snapshot): array
    {
        $uptime = $this->uptimeCheck($check->site_id, $snapshot);
        if (! $uptime instanceof Check || ! $uptime->enabled) {
            return ['action' => 'counts', 'reason' => 'freshness.no_result.uptime_disabled'];
        }
        if ($uptime->interval_seconds > PerformanceResponseTimeWindow::MaximumUptimeIntervalSeconds) {
            return ['action' => 'counts', 'reason' => 'freshness.no_result.uptime_interval_too_long'];
        }
        $hasErrors = in_array($uptime->latest_run_result, [CheckResult::Succeeded, CheckResult::Warning], true)
            && DB::table('check_runs')->where('check_id', $uptime->id)->where('check_type', 'uptime')
                ->where('ended_at', '>=', $at->subSeconds(PerformanceResponseTimeWindow::seconds($uptime->interval_seconds))->format('Y-m-d H:i:s.uP'))
                ->where('ended_at', '<', $at->format('Y-m-d H:i:s.uP'))->where('result', RunResult::Errored->value)->exists();

        return ['action' => 'counts', 'reason' => $hasErrors ? 'freshness.no_result.uptime_errors' : 'freshness.no_result.uptime_no_measurements'];
    }
}
