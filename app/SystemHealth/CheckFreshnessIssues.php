<?php

declare(strict_types=1);

namespace Heptapod\SystemHealth;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckFreshness;
use Heptapod\Checks\Execution\ExecutionBudget;
use Heptapod\Checks\FreshnessSnapshot;
use Heptapod\Checks\FreshnessSuppressionState;
use Heptapod\Checks\NoResultFreshness;
use Heptapod\Checks\UptimeDependency;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Enums\IncidentCause;
use Heptapod\Models\Check;
use Heptapod\Models\Incident;
use Heptapod\Models\SystemIssue;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final readonly class CheckFreshnessIssues
{
    public function __construct(private CheckRegistry $registry, private CheckFreshness $freshness, private NoResultFreshness $noResult, private UptimeDependency $uptime) {}

    /** Invalid thresholds could turn a single slow attempt into an instance issue. Validate once at boot. */
    public function validateConfiguration(): void
    {
        $multiplier = config('heptapod.freshness.interval_multiplier');
        if (! is_int($multiplier) || $multiplier < 1) {
            throw new LogicException('heptapod.freshness.interval_multiplier must be a positive integer.');
        }
        $windows = ['checks-fast' => 765, 'checks-crawl' => 2730, 'checks-browser' => 2730];
        foreach ($this->registry->keys() as $key) {
            $definition = $this->registry->get($key)->definition();
            $windows[$definition->queue] = max($windows[$definition->queue] ?? 0,
                ExecutionBudget::queuedLease($definition->timeout, $definition->queue) + ExecutionBudget::runningLease($definition->timeout));
        }
        foreach ($windows as $queue => $window) {
            $min = config('heptapod.freshness.queues.'.$queue.'.min_seconds');
            $max = config('heptapod.freshness.queues.'.$queue.'.max_seconds');
            if (! is_int($min) || ! is_int($max) || $min < $window || $min > $max) {
                throw new LogicException('Invalid freshness bounds for '.$queue.': min_seconds must cover '.$window.' seconds and must not exceed max_seconds.');
            }
        }
    }

    /** @return Builder<Check> Configuration-sized candidates; never joins run or attempt history. */
    public function candidates(CarbonImmutable $now): Builder
    {
        /** @var non-empty-array<string, array{min_seconds: int, max_seconds: int}> $queues Validated at boot. */
        $queues = config()->array('heptapod.freshness.queues');
        $minimum = min(array_column($queues, 'min_seconds'));
        $query = Check::query()->with('site');
        $query->getQuery()->select('checks.*')->join('sites', 'sites.id', '=', 'checks.site_id')
            ->whereNotNull('checks.awaiting_result_since')->where('checks.awaiting_result_since', '<', $now->subSeconds($minimum))
            ->where('checks.enabled', true)->whereNull('sites.paused_at')->whereNull('sites.marked_for_deletion_at')
            ->whereNotIn('checks.type', $this->registry->unavailableKeys());

        return $query;
    }

    /**
     * Not-fresh checks with their issue details and whether a no-result gap explains them, from one page-local snapshot.
     *
     * @return array<int, array{details: array<string, int|string|null>, suppressed: bool}>
     */
    public function notFresh(CarbonImmutable $now): array
    {
        $notFresh = $this->candidates($now)->get()->filter(fn (Check $check): bool => $this->freshness->state($check, $now)->state === 'not_fresh');
        $snapshot = $this->noResult->snapshot($notFresh, $now);
        $checks = [];
        foreach ($notFresh as $check) {
            $checks[$check->id] = ['details' => $this->details($check, $snapshot), 'suppressed' => $this->noResult->suppression($check, $now, $snapshot) instanceof FreshnessSuppressionState];
        }

        return $checks;
    }

    /** @return array<string, int|string|null> */
    private function details(Check $check, FreshnessSnapshot $snapshot): array
    {
        // Recovery compares this anchor with the stored result time; truncating microseconds can invent a result.
        return ['check_id' => $check->id, 'site_id' => $check->site_id, 'site_name' => ($check->site ?? throw new LogicException('Freshness requires an existing site.'))->displayName(),
            'check_type' => $check->type->value, 'awaiting_result_since' => $check->awaiting_result_since?->format('Y-m-d\TH:i:s.uP'),
            'last_result_at' => $check->latest_run_ended_at?->toIso8601String(), 'threshold_seconds' => $this->freshness->threshold($check),
            'lost_attempts' => $check->lost_attempts, 'loss_cause' => $this->freshness->lastLoss($check)['cause'] ?? null,
            'no_result_cause' => $this->noResult->forCheck($check, $snapshot->at, $snapshot)['reason'] ?? null];
    }

    public function siteDown(int $siteId): bool
    {
        return $this->uptime->openIncident($siteId, IncidentCause::Failed) instanceof Incident;
    }

    public function resolution(SystemIssue $issue): string
    {
        $check = Check::query()->with('site')->find((int) $issue->scope);
        if ($check === null) {
            return 'check_deleted';
        }
        if (! $this->freshness->expected($check)) {
            return 'monitoring_stopped';
        }
        $awaitingSince = $issue->details['awaiting_result_since'] ?? null;
        if (! is_string($awaitingSince)) {
            throw new LogicException('A freshness issue must retain its episode start.');
        }

        return $check->latest_run_ended_at !== null && $check->latest_run_ended_at->greaterThanOrEqualTo(CarbonImmutable::parse($awaitingSince))
            ? 'result_accepted' : 'check_reset';
    }
}
