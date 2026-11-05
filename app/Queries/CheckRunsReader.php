<?php

declare(strict_types=1);

namespace Heptapod\Queries;

use Carbon\CarbonImmutable;
use Heptapod\Checks\CheckFreshness;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Contracts\RunPhase;
use Heptapod\Data\Ui\CheckRunStateData;
use Heptapod\Data\Ui\CheckScheduleData;
use Heptapod\Models\Check;
use Heptapod\Models\CheckRunAttempt;

final readonly class CheckRunsReader
{
    public function __construct(private CheckRegistry $registry, private CheckFreshness $freshness) {}

    /** @param array<int, int> $positions */
    public function state(Check $check, ?CheckRunAttempt $attempt = null, array $positions = []): CheckRunStateData
    {
        $attempt ??= $check->claim_token === null ? null : CheckRunAttempt::query()->with('actor')->where('claim_token', $check->claim_token)->first();
        $now = CarbonImmutable::now('UTC');
        $due = $attempt === null ? $check->next_run_at : $attempt->due_at;
        $site = $check->site ?? throw new \LogicException('Check schedule requires an existing site.');
        $late = $check->enabled && $site->paused_at === null && $site->marked_for_deletion_at === null
            && in_array($check->type, $this->registry->keys(), true) && $this->registry->availability($check->type)->reason === null
            && $attempt === null
            && $due !== null && $due->addSeconds(max(60, (int) ceil($check->interval_seconds * 0.1)))->lessThan($now);
        $phase = $late ? RunPhase::Late : ($attempt === null ? RunPhase::Scheduled : $attempt->phase);
        $position = $attempt?->phase === RunPhase::Queued ? ($positions[$attempt->id] ?? CheckRunAttempt::query()
            ->where('queue', $attempt->queue)->where('phase', RunPhase::Queued)->where('id', '<=', $attempt->id)->getQuery()->count()) : null;

        return new CheckRunStateData($check->id, $phase, $attempt?->id, $attempt?->queue, $position,
            $attempt?->progress_done, $attempt?->progress_total, $attempt?->phase_label,
            $attempt?->started_at?->toIso8601String(), $attempt?->actor?->name, $due?->toIso8601String(),
            $attempt !== null && in_array($attempt->phase, [RunPhase::Queued, RunPhase::Running], true)
                && $check->type->canCancelRun() && $attempt->cancel_requested_at === null,
            $attempt?->requested_at->format('Y-m-d\TH:i:s.uP'));
    }

    public function schedule(Check $check): CheckScheduleData
    {
        $state = $this->state($check);
        $duration = $check->latest_run_started_at === null || $check->latest_run_ended_at === null
            ? null : (int) $check->latest_run_started_at->diffInMilliseconds($check->latest_run_ended_at);

        return new CheckScheduleData($check->interval_seconds, $check->enabled ? $check->next_run_at->toIso8601String() : null,
            $check->latest_run_started_at?->toIso8601String(), $check->latest_run_ended_at?->toIso8601String(),
            $duration, $state, $this->freshness->state($check, CarbonImmutable::now('UTC')), $this->freshness->lastLoss($check));
    }
}
