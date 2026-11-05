<?php

declare(strict_types=1);

namespace Heptapod\Checks;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\SuppressionDecision;
use Heptapod\Models\CheckRunAttempt;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/** No-result inputs for a fixed set of checks at one instant, read once by NoResultFreshness::snapshot(). */
final readonly class FreshnessSnapshot
{
    /**
     * @param  array<int, true>  $checkIds
     * @param  Collection<int, CheckRunAttempt>  $latestAttempts  Latest finished attempt keyed by check ID.
     * @param  array<int, SuppressionDecision>  $plannedDecisions  Planned-suppression decisions at $at keyed by check ID.
     */
    public function __construct(public CarbonImmutable $at, public UptimeSnapshot $uptime, private array $checkIds, private Collection $latestAttempts, private array $plannedDecisions) {}

    public function latestAttempt(int $checkId): ?CheckRunAttempt
    {
        if (! isset($this->checkIds[$checkId])) {
            throw new LogicException('Check '.$checkId.' is outside this freshness snapshot.');
        }

        return $this->latestAttempts->get($checkId);
    }

    public function suppression(int $checkId): SuppressionDecision
    {
        return $this->plannedDecisions[$checkId] ?? throw new LogicException('Check '.$checkId.' has no planned-suppression decision in this freshness snapshot.');
    }
}
