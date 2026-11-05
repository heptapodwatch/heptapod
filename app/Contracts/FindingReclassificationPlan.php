<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** One check's reclassification: which counted findings stop counting and the run state recomputed from the relabeled snapshot. */
final readonly class FindingReclassificationPlan
{
    /**
     * @param  list<string>  $relabeledFindingIds  Previously counted identities that the policy no longer counts.
     * @param  list<string>  $remainingFindingIds  Identities still counted after relabeling.
     * @param  array<int, string>  $labels  Finding row id => new stored label (only rows whose label changes).
     * @param  bool  $nothingRemains  The relabeled snapshot has no counted finding and nothing unchecked: an earlier warning is explained only by relabeled findings.
     * @param  array<string, mixed>  $metrics  Replacement run metrics.
     * @param  array<string, mixed>  $evidence  Run evidence fields to replace.
     */
    public function __construct(
        public int $checkId,
        public int $snapshotId,
        public int $runId,
        public int $policyVersion,
        public array $relabeledFindingIds,
        public array $remainingFindingIds,
        public array $labels,
        public bool $nothingRemains,
        /** Null when raw retention already dropped the run: only the snapshot is relabeled. */
        public ?RunResult $runResultBefore,
        public ?RunResult $runResultAfter,
        public ?string $summaryAfter,
        public array $metrics,
        public array $evidence,
        /** The snapshot's crawl covered the whole site (no page/time/comparison limit). */
        public bool $baselineComplete,
    ) {}
}
