<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckRegistry;
use Heptapod\Data\Ui\CheckFreshnessData;
use Heptapod\Models\Check;
use LogicException;

/** The freshness decision for one check, from its stored episode columns and the configured thresholds only. */
final readonly class CheckFreshness
{
    public function __construct(private CheckRegistry $registry) {}

    public function threshold(Check $check): int
    {
        $queue = in_array($check->type, $this->registry->keys(), true) ? $this->registry->get($check->type)->definition()->queue : 'checks-fast';
        $min = config()->integer('heptapod.freshness.queues.'.$queue.'.min_seconds');
        $max = config()->integer('heptapod.freshness.queues.'.$queue.'.max_seconds');

        return max($min, min($max, config()->integer('heptapod.freshness.interval_multiplier') * $check->interval_seconds));
    }

    public function expected(Check $check): bool
    {
        $site = $check->site ?? throw new LogicException('Freshness requires an existing site.');

        return $check->enabled && $site->paused_at === null && $site->marked_for_deletion_at === null
            && ! in_array($check->type, $this->registry->unavailableKeys(), true);
    }

    public function state(Check $check, CarbonImmutable $now): CheckFreshnessData
    {
        $threshold = $this->threshold($check);
        $since = $check->awaiting_result_since;
        $notFreshSince = $since?->addSeconds($threshold);
        $state = ! $this->expected($check) ? 'not_expected'
            : ($notFreshSince !== null && $notFreshSince->lessThan($now) ? 'not_fresh' : 'fresh');

        return new CheckFreshnessData($state, $since?->toIso8601String(), $notFreshSince?->toIso8601String(), $threshold, $check->lost_attempts);
    }

    /** @return array{cause: 'lease_lost'|'did_not_start'|'worker_out_of_memory'|'timed_out'}|null */
    public function lastLoss(Check $check): ?array
    {
        $cause = $check->lost_attempts > 0 ? ($check->last_loss_started_at === null ? 'did_not_start' : 'lease_lost') : match ($check->summary) {
            'causes.engine.worker_memory_exhausted' => 'worker_out_of_memory',
            'causes.engine.job_timed_out' => 'timed_out',
            default => null,
        };

        return $cause === null ? null : ['cause' => $cause];
    }
}
