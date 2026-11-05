<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\FindingReclassificationPlan;
use Heptapod\Contracts\FindingReclassifier;
use Heptapod\Contracts\RunResult;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Relabels the latest legacy crawl snapshot of each check under LinkClassificationPolicy::VERSION. Older snapshots keep the read-time fallback. */
final class CrawlReclassifier implements FindingReclassifier
{
    private ?bool $versioned = null;

    public function policyVersion(): int
    {
        return LinkClassificationPolicy::VERSION;
    }

    public function pendingChecks(): array
    {
        // Snapshots of deleted checks stay until pruning; they have nothing to reclassify.
        $latest = DB::table('crawl_snapshots')->selectRaw('DISTINCT ON (check_id) check_id, '.($this->versioned() ? 'classification_policy_version' : 'NULL::smallint AS classification_policy_version'))
            ->whereNotNull('run_ended_at')->whereExists(static fn (Builder $check): Builder => $check->from('checks')->whereColumn('checks.id', 'crawl_snapshots.check_id'))
            ->orderBy('check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');

        /** @var list<int> */
        return DB::query()->fromSub($latest, 'latest')->whereNull('classification_policy_version')->orderBy('check_id')->pluck('check_id')->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new \LogicException('Check id must be numeric.'))->all();
    }

    public function plan(int $checkId): ?FindingReclassificationPlan
    {
        $snapshot = $this->snapshot(DB::table('crawl_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')->orderByDesc('run_ended_at')->orderByDesc('run_id'));
        if ($snapshot === null || $snapshot['policy_version'] !== null) {
            return null;
        }
        // Raw retention may have dropped the run of a still retained baseline (e.g. a paused check): relabel the snapshot only.
        $run = DB::table('check_runs')->where('id', $snapshot['run_id'])->first(['result', 'summary', 'metrics', 'evidence']);
        /** @var (\stdClass&object{result: string, summary: string, metrics: string|null, evidence: string|null})|null $run */
        /** @var array<string, mixed> $metrics */
        $metrics = $run?->metrics === null ? [] : json_decode($run->metrics, true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $evidence */
        $evidence = $run?->evidence === null ? [] : json_decode($run->evidence, true, flags: JSON_THROW_ON_ERROR);
        $evaluated = $snapshot['evaluated'];
        $before = $run === null ? null : RunResult::from($run->result);
        $labels = [];
        $newlyNotVerifiable = [];
        $findings = [];
        foreach (DB::table('crawl_findings')->where('snapshot_id', $snapshot['id'])->orderBy('id')->get(['id', 'crawled_url', 'status_code', 'error_message', 'internal', 'type']) as $row) {
            /** @var \stdClass&object{id: int, crawled_url: string, status_code: int|null, error_message: string|null, internal: bool, type: string} $row */
            $label = LinkClassificationPolicy::legacyLabel($row->status_code, $row->error_message, $row->internal);
            if ($label !== $row->error_message && $label !== null) {
                $labels[$row->id] = $label;
                if (LinkClassificationPolicy::notVerifiable($label, $row->internal)) {
                    $newlyNotVerifiable[hash('sha256', $row->crawled_url)] = true;
                }
            }
            $findings[] = new CrawlFinding($row->crawled_url, $row->crawled_url, $row->status_code, null, null, null, $row->type, $label, $row->internal);
        }
        if (DB::table('checks')->where('id', $checkId)->value('type') !== CheckKey::BrokenLinks->value) {
            return new FindingReclassificationPlan($checkId, $snapshot['id'], $snapshot['run_id'], LinkClassificationPolicy::VERSION, [], $evaluated, $labels, false, $before, $before, null, $metrics, [], false);
        }
        $counted = array_fill_keys($evaluated, true);
        $stillBroken = [];
        foreach ($findings as $finding) {
            $id = hash('sha256', $finding->crawled_url);
            if (isset($counted[$id]) && $finding->broken()) {
                $stillBroken[$id] = true;
            }
        }
        // Relabeled = counted identities the policy turned Not verifiable; other uncounted rows (e.g. unchanged unchecked ones) are not part of it.
        $relabeled = array_values(array_filter($evaluated, static fn (string $id): bool => ! isset($stillBroken[$id]) && isset($newlyNotVerifiable[$id])));
        $remaining = array_values(array_diff($evaluated, $relabeled));
        $remainingFindings = array_filter($findings, static fn (CrawlFinding $finding): bool => isset($stillBroken[hash('sha256', $finding->crawled_url)]));
        $brokenInternal = count(array_filter($remainingFindings, static fn (CrawlFinding $finding): bool => $finding->internal));
        $unconfirmed = array_any($findings, static fn (CrawlFinding $finding): bool => LinkClassificationPolicy::unchecked($finding->error_message) || $finding->error_message === 'rate_limited');
        $nothingRemains = $stillBroken === [] && ! $unconfirmed;
        $complete = ($evidence['crawl_truncated'] ?? true) === false && ($evidence['comparison_limit_reached'] ?? false) === false;
        // Only an observed Warning that nothing else explains turns into OK; Failed (internal) and incomplete runs keep their result.
        $after = $before === RunResult::Warning && $relabeled !== [] && $complete && $nothingRemains && $brokenInternal === 0 ? RunResult::Succeeded : $before;
        $externalNotVerifiable = array_values(array_filter($findings, static fn (CrawlFinding $finding): bool => ! $finding->internal && LinkClassificationPolicy::notVerifiable($finding->error_message, false)));
        $refused = array_values(array_filter($externalNotVerifiable, static fn (CrawlFinding $finding): bool => in_array($finding->error_message, LinkClassificationPolicy::REFUSALS, true)));
        $unverified = array_values(array_filter($externalNotVerifiable, static fn (CrawlFinding $finding): bool => ! in_array($finding->error_message, LinkClassificationPolicy::REFUSALS, true)));
        $notVerifiable = array_filter($findings, static fn (CrawlFinding $finding): bool => LinkClassificationPolicy::notVerifiable($finding->error_message, $finding->internal));
        $notVerifiableReasons = [];
        foreach ($notVerifiable as $finding) {
            $label = $finding->error_message ?? throw new \LogicException('Not-verifiable finding without a label.');
            $notVerifiableReasons[$label] = ($notVerifiableReasons[$label] ?? 0) + 1;
        }
        $evidencePatch = [
            'finding_ids' => array_slice(array_keys($stillBroken), 0, 10), 'truncated' => count($stillBroken) > 10,
            'not_verifiable_count' => count($notVerifiable),
            'not_verifiable_reasons' => $notVerifiableReasons,
            'external_refused_count' => count($refused), 'external_refused_reason' => $this->sharedReason($refused),
            'external_not_verifiable_count' => count($unverified), 'external_not_verifiable_reason' => $this->sharedReason($unverified),
        ];
        $metrics = [...$metrics, 'broken_internal' => $brokenInternal, 'broken_external' => count($remainingFindings) - $brokenInternal];

        return new FindingReclassificationPlan($checkId, $snapshot['id'], $snapshot['run_id'], LinkClassificationPolicy::VERSION, $relabeled, $remaining, $labels, $nothingRemains,
            $before, $after, $after !== $before && $run?->summary === 'broken_links.'.$before?->value ? 'broken_links.'.$after?->value : null, $metrics, $evidencePatch, $complete);
    }

    public function apply(FindingReclassificationPlan $plan): void
    {
        $updated = DB::table('crawl_snapshots')->where('id', $plan->snapshotId)->whereNull('classification_policy_version')
            ->update(['classification_policy_version' => $plan->policyVersion, 'evaluated_finding_ids' => json_encode($plan->remainingFindingIds, JSON_THROW_ON_ERROR),
                'reclassified_finding_ids' => $plan->relabeledFindingIds === [] ? null : json_encode($plan->relabeledFindingIds, JSON_THROW_ON_ERROR)]);
        if ($updated !== 1) {
            throw new \LogicException("Crawl snapshot {$plan->snapshotId} was already reclassified.");
        }
        foreach ($plan->labels as $id => $label) {
            DB::table('crawl_findings')->where('id', $id)->where('snapshot_id', $plan->snapshotId)->update(['error_message' => $label]);
        }
    }

    public function explainedByRelabeling(int $checkId, int $runId, ?array $findingIds, array $relabeledFindingIds): bool
    {
        $snapshot = $this->snapshot(DB::table('crawl_snapshots')->where('check_id', $checkId)->where('run_id', $runId)->whereNotNull('run_ended_at'));
        if ($snapshot === null) {
            return false;
        }
        $ids = array_fill_keys($findingIds ?? $snapshot['evaluated'], true);
        $relabeled = array_fill_keys($relabeledFindingIds, true);
        if ($ids === [] || array_diff_key($ids, $relabeled) !== []) {
            return false;
        }
        $seen = [];
        foreach (DB::table('crawl_findings')->where('snapshot_id', $snapshot['id'])->orderBy('id')->get(['crawled_url', 'status_code', 'error_message', 'internal']) as $row) {
            [$id, $label, $internal] = $this->labeled($row, $snapshot['policy_version'] === null);
            if (isset($ids[$id])) {
                if (! LinkClassificationPolicy::notVerifiable($label, $internal)) {
                    return false;
                }
                $seen[$id] = true;
            } elseif ($findingIds === null && (LinkClassificationPolicy::unchecked($label) || $label === 'rate_limited')) {
                // An unchecked link also kept that run in Warning.
                return false;
            }
        }

        return count($seen) === count($ids);
    }

    /** @return array{id: int, run_id: int, evaluated: list<string>, policy_version: int|null}|null */
    private function snapshot(Builder $query): ?array
    {
        // Before the upgrade migration the column is missing and every snapshot is legacy (read-only preflight on the old schema).
        $row = $query->first(['id', 'run_id', 'evaluated_finding_ids', ...($this->versioned() ? ['classification_policy_version'] : [])]);
        if ($row === null) {
            return null;
        }
        $version = $this->versioned() ? $row->classification_policy_version : null;
        $evaluated = is_string($row->evaluated_finding_ids) ? json_decode($row->evaluated_finding_ids, true, flags: JSON_THROW_ON_ERROR) : null;
        if (! is_int($row->id) || ! is_int($row->run_id) || ($version !== null && ! is_int($version)) || ! is_array($evaluated) || ! array_is_list($evaluated) || ! array_all($evaluated, static fn (mixed $id): bool => is_string($id))) {
            throw new \LogicException('Malformed crawl snapshot row.');
        }

        return ['id' => $row->id, 'run_id' => $row->run_id, 'evaluated' => array_values(array_filter($evaluated, is_string(...))), 'policy_version' => $version];
    }

    /** @return array{string, string|null, bool} Identity, label under the current policy, internal. */
    private function labeled(\stdClass $row, bool $legacy): array
    {
        $url = $row->crawled_url;
        $status = $row->status_code;
        $label = $row->error_message;
        $internal = $row->internal;
        if (! is_string($url) || ($status !== null && ! is_int($status)) || ($label !== null && ! is_string($label)) || ! is_bool($internal)) {
            throw new \LogicException('Malformed crawl finding row.');
        }

        return [hash('sha256', $url), $legacy ? LinkClassificationPolicy::legacyLabel($status, $label, $internal) : $label, $internal];
    }

    private function versioned(): bool
    {
        return $this->versioned ??= Schema::hasColumn('crawl_snapshots', 'classification_policy_version');
    }

    /** @param list<CrawlFinding> $findings */
    private function sharedReason(array $findings): ?string
    {
        $reasons = array_values(array_unique(array_map(static fn (CrawlFinding $finding): string => $finding->error_message ?? throw new \LogicException('Not-verifiable finding without a label.'), $findings)));

        return count($reasons) > 1 ? 'mixed' : ($reasons[0] ?? null);
    }
}
