<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * Relabels stored findings under a newer classification policy once, at upgrade time (heptapod:reclassify-links),
 * so ordinary evaluations only ever compare findings labeled by the same policy.
 */
interface FindingReclassifier
{
    public function policyVersion(): int;

    /** @return list<int> Checks whose latest accepted snapshot was labeled before policyVersion(). */
    public function pendingChecks(): array;

    /** Read-only; null when the check's latest snapshot already carries policyVersion(). */
    public function plan(int $checkId): ?FindingReclassificationPlan;

    /**
     * True only when every finding behind an alert of $runId (the given ids, or all counted findings of that run when null)
     * was relabeled Not verifiable by the policy and is among $relabeledFindingIds.
     *
     * @param  list<string>|null  $findingIds
     * @param  list<string>  $relabeledFindingIds
     */
    public function explainedByRelabeling(int $checkId, int $runId, ?array $findingIds, array $relabeledFindingIds): bool;

    /** Writes the plan's labels and stamps the snapshot; the caller holds the check lock and the transaction. */
    public function apply(FindingReclassificationPlan $plan): void;
}
