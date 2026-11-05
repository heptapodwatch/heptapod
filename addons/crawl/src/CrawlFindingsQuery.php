<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\Findings\AvailableFindingsSnapshot;
use Heptapod\Contracts\Findings\FindingsCoverage;
use Heptapod\Contracts\Findings\FindingsFilter;
use Heptapod\Contracts\Findings\FindingsPage;
use Heptapod\Contracts\Findings\FindingsPosition;
use Heptapod\Contracts\Findings\FindingsRow;
use Heptapod\Contracts\Findings\FindingsSourceGroup;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\Findings\FindingsUnavailableReason;
use Heptapod\Contracts\Findings\FindingsUnavailableStatus;
use Heptapod\Contracts\Findings\UnavailableFindingsSnapshot;
use Heptapod\Contracts\FindingsQueryProvider;
use Heptapod\Contracts\RunReference;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CrawlFindingsQuery implements FindingsQueryProvider
{
    private const string UrlHash = "encode(sha256(replace(finding.crawled_url, chr(92), chr(92) || chr(92))::bytea), 'hex')";

    private const string MixedHash = "encode(sha256(replace(finding.element_name || '|' || finding.mixed_content_url || '|' || finding.found_on_url, chr(92), chr(92) || chr(92))::bytea), 'hex')";

    private const string OldUrlHash = "encode(sha256(replace(old.crawled_url, chr(92), chr(92) || chr(92))::bytea), 'hex')";

    private const string OldMixedHash = "encode(sha256(replace(old.element_name || '|' || old.mixed_content_url || '|' || old.found_on_url, chr(92), chr(92) || chr(92))::bytea), 'hex')";

    private const string RetainedUrlHash = "encode(sha256(replace(retained_finding.crawled_url, chr(92), chr(92) || chr(92))::bytea), 'hex')";

    public function snapshot(CheckKey $type, int $checkId, RunReference $run): AvailableFindingsSnapshot|UnavailableFindingsSnapshot
    {
        if (! in_array($type, [CheckKey::BrokenLinks, CheckKey::MixedContent], true)) {
            throw new \InvalidArgumentException('Crawl findings require a crawl check.');
        }
        $row = DB::table('crawl_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->sharedLock()
            ->select(['id', 'collected_at', 'discovery_complete', 'truncated', 'page_cap_reached', 'resource_cap_reached', 'time_limit_reached', 'target_fingerprint', 'config_fingerprint', 'comparison_key', 'settings'])
            ->selectRaw('jsonb_array_length(observed_pages) AS observed_pages, jsonb_array_length(observed_urls) AS observed_urls, jsonb_array_length(discovered_urls) AS discovered_urls')->first();
        if ($row === null) {
            return new UnavailableFindingsSnapshot(FindingsUnavailableStatus::NoDataset, $checkId, $type, $run, FindingsUnavailableReason::NotRetained);
        }
        /** @var \stdClass&object{id: int, collected_at: string, discovery_complete: bool, truncated: bool, page_cap_reached: bool, resource_cap_reached: bool, time_limit_reached: bool, target_fingerprint: string, config_fingerprint: string|null, comparison_key: string|null, settings: string, observed_pages: int, observed_urls: int, discovered_urls: int} $row */
        /** @var array{max_pages?: int} $settings */
        $settings = json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR);
        $coverage = new FindingsCoverage($row->discovery_complete, $row->truncated, $row->page_cap_reached, $row->resource_cap_reached, $row->time_limit_reached,
            false, true, true, true, $row->comparison_key === null,
            ['observed_pages' => $row->observed_pages, 'observed_urls' => $row->observed_urls, 'discovered_urls' => $row->discovered_urls],
            ['max_pages' => $settings['max_pages'] ?? null, 'comparison' => CrawlPersistence::COMPARISON_LIMIT]);

        return new AvailableFindingsSnapshot($checkId, $type, 'crawl:'.$row->id, $run, CarbonImmutable::parse($row->collected_at)->utc()->format('Y-m-d\TH:i:s.uP'),
            $coverage, [FindingStatus::Broken, FindingStatus::NotVerifiable, FindingStatus::Unchecked], $coverage->comparable(), 1,
            $row->comparison_key);
    }

    public function page(AvailableFindingsSnapshot $snapshot, FindingsFilter $filter, ?AvailableFindingsSnapshot $baseline, ?FindingsPosition $after, int $limit): FindingsPage|UnavailableFindingsSnapshot
    {
        $source = $this->snapshot($snapshot->type, $snapshot->check_id, $snapshot->run);
        if ($source instanceof UnavailableFindingsSnapshot) {
            return $source;
        }
        if ($baseline instanceof AvailableFindingsSnapshot) {
            $locked = $this->snapshot($baseline->type, $baseline->check_id, $baseline->run);
            if ($locked instanceof UnavailableFindingsSnapshot) {
                return $locked;
            }
            $baseline = $locked;
            if ($source->snapshot_ref !== $baseline->snapshot_ref && ! $source->comparableWith($baseline)) {
                throw new \InvalidArgumentException('Findings baseline is not comparable.');
            }
        }
        $mixed = $source->type === CheckKey::MixedContent;
        $groups = $mixed ? ($filter->only_new_since ? [FindingsSourceGroup::Mixed] : [FindingsSourceGroup::Mixed, FindingsSourceGroup::Verification]) : [FindingsSourceGroup::Rows];
        if ($limit < 1 || $limit > 200 || ($after instanceof FindingsPosition && ! in_array($after->group, $groups, true)) || ($filter->only_new_since && ! $baseline instanceof AvailableFindingsSnapshot)) {
            throw new \InvalidArgumentException('Invalid crawl findings page.');
        }
        $id = (int) substr($source->snapshot_ref, 6);
        $found = [];
        foreach ($groups as $group) {
            if ($after instanceof FindingsPosition && array_search($group, $groups, true) < array_search($after->group, $groups, true)) {
                continue;
            }
            $query = $this->query($id, $group, $filter, $baseline);
            $query->where('finding.id', '>', $after?->group === $group ? $after->position : 0);
            foreach ($query->orderBy('finding.id')->limit($limit + 1 - count($found))->get() as $row) {
                $found[] = [$group, $row];
            }
            if (count($found) > $limit) {
                break;
            }
        }
        $hasMore = count($found) > $limit;
        $found = array_slice($found, 0, $limit);
        $rows = [];
        $position = null;
        $urls = [];
        foreach ($found as [$group, $row]) {
            /** @var \stdClass&object{crawled_url: string, finding_status: string} $row */
            if ($group === FindingsSourceGroup::Rows && $row->finding_status === 'broken') {
                $urls[] = $row->crawled_url;
            }
        }
        $firstSeen = $urls === [] ? [] : $this->firstSeen($source, $urls);
        foreach ($found as [$group, $row]) {
            /** @var \stdClass&object{id: int, finding_identity: string, finding_status: string, baseline_seen: bool|null, baseline_unverified: bool|null, carried_forward: bool|null, crawled_url: string, mixed_content_url: string, found_on_url: string|null, link_text: string|null, status_code: int|null, error_message: string|null, current_error_message: string|null, internal: bool, type: string, element_name: string} $row */
            $status = FindingStatus::from($row->finding_status);
            $isMixedRow = $group === FindingsSourceGroup::Mixed;
            $rows[] = new FindingsRow('crawl:'.$id.':'.$group->value.':'.$row->id, $row->finding_identity, $status,
                $isMixedRow ? 'mixed_content' : null, $isMixedRow ? null : $row->status_code, $isMixedRow ? $row->element_name : $row->type,
                $isMixedRow ? null : $row->internal, $isMixedRow ? $row->mixed_content_url : $row->crawled_url, $row->found_on_url,
                $isMixedRow ? null : $row->link_text, $isMixedRow ? null : $row->error_message,
                $isMixedRow || $row->error_message === null ? null : CrawlFindingValues::cause($row->error_message),
                $group === FindingsSourceGroup::Rows && $status === FindingStatus::Broken ? ($firstSeen[$row->crawled_url] ?? null) : null,
                null, $status === FindingStatus::Broken && $baseline instanceof AvailableFindingsSnapshot
                    ? ($row->baseline_seen !== null ? false : ($row->baseline_unverified !== null ? null : true)) : null, $row->carried_forward, $row->carried_forward === false ? $source->observed_at : null,
                ! $isMixedRow && $row->carried_forward === true && $row->current_error_message !== null ? CrawlFindingValues::cause($row->current_error_message) : null);
            $position = new FindingsPosition($group, $row->id);
        }

        return new FindingsPage($source, $baseline, $baseline instanceof AvailableFindingsSnapshot ? 'available' : 'not_requested', $rows, $hasMore ? $position : null, $hasMore);
    }

    private function query(int $snapshotId, FindingsSourceGroup $group, FindingsFilter $filter, ?AvailableFindingsSnapshot $baseline): Builder
    {
        $mixed = $group === FindingsSourceGroup::Mixed;
        $table = $mixed ? 'mixed_content_findings' : 'crawl_findings';
        $hash = $mixed ? self::MixedHash : self::UrlHash;
        $oldHash = $mixed ? self::OldMixedHash : self::OldUrlHash;
        $legacy = $this->legacy($snapshotId);
        $status = $mixed ? "'broken'" : LinkClassificationPolicy::statusSql('finding', $legacy);
        $query = DB::table($table.' as finding')->where('finding.snapshot_id', $snapshotId)
            ->select($mixed ? ['finding.*'] : ['finding.id', 'finding.crawled_url', 'finding.status_code', 'finding.found_on_url', 'finding.link_text', 'finding.type', 'finding.internal', 'finding.carried_forward', 'finding.current_error_message', LinkClassificationPolicy::labelSql($legacy, 'finding')])
            ->selectRaw($hash.' AS finding_identity, '.$status.' AS finding_status');
        // Every mixed-content row is evaluated; only broken links have whitelist/external-link exclusions.
        if (! $mixed) {
            $query->where(function (Builder $eligible) use ($status, $hash, $snapshotId): void {
                $eligible->whereRaw("($status) <> 'broken'")->orWhereIn(DB::raw($hash), $this->evaluatedIdentities($snapshotId));
            });
            $query->whereRaw("($status) IS NOT NULL");
            if ($group === FindingsSourceGroup::Verification) {
                $query->where('finding.internal', true)->where('finding.type', 'link')->whereRaw("($status) <> 'broken'");
            }
        }
        if ($filter->status instanceof FindingStatus) {
            $query->whereRaw("($status) = ?", [$filter->status->value]);
        }
        $old = $baseline instanceof AvailableFindingsSnapshot && $group !== FindingsSourceGroup::Verification ? DB::table($table.' as old')->selectRaw('1')
            ->where('old.snapshot_id', (int) substr($baseline->snapshot_ref, 6))
            ->whereRaw($oldHash.' = '.$hash) : null;
        // A blocked/unchecked baseline URL (or mixed-content source page) cannot establish novelty by absence.
        $unknown = null;
        if ($old !== null) {
            $baselineId = (int) substr($baseline->snapshot_ref, 6);
            $oldStatus = LinkClassificationPolicy::statusSql('old', $this->legacy($baselineId));
            $comparedUrl = $mixed ? 'finding.source_url' : 'finding.crawled_url';
            $comparedHash = $mixed ? "encode(sha256(replace(finding.source_url, chr(92), chr(92) || chr(92))::bytea), 'hex')" : self::UrlHash;
            $unknown = DB::table('crawl_findings as old')->selectRaw('1')->where('old.snapshot_id', (int) substr($baseline->snapshot_ref, 6))
                ->whereRaw("($oldStatus) IN ('not_verifiable', 'unchecked')")
                ->whereRaw(self::OldUrlHash.' = '.$comparedHash)->whereColumn('old.crawled_url', $comparedUrl);
            if (! $mixed) {
                $old->whereIn(DB::raw($oldHash), $this->evaluatedIdentities((int) substr($baseline->snapshot_ref, 6)))
                    ->whereRaw("($oldStatus) IS DISTINCT FROM 'not_verifiable' AND ($oldStatus) IS DISTINCT FROM 'unchecked'");
            }
            foreach ($mixed ? ['element_name', 'mixed_content_url', 'found_on_url'] : ['crawled_url'] as $column) {
                $old->whereColumn('old.'.$column, 'finding.'.$column);
            }
            $query->selectSub((clone $old)->select(DB::raw('true'))->limit(1), 'baseline_seen')
                ->selectSub((clone $unknown)->select(DB::raw('true'))->limit(1), 'baseline_unverified');
        } else {
            $query->selectRaw('NULL::boolean AS baseline_seen, NULL::boolean AS baseline_unverified');
        }
        if ($filter->only_new_since) {
            $query->whereRaw("($status) = 'broken'")->whereNotExists($old ?? throw new \LogicException('Missing baseline query.'))
                ->whereNotExists($unknown);
        }

        return $query;
    }

    /** @return literal-string */
    private function legacy(int $snapshotId): string
    {
        /** @var int|null $version */
        $version = DB::table('crawl_snapshots')->where('id', $snapshotId)->value('classification_policy_version');

        return LinkClassificationPolicy::legacySql($version);
    }

    private function evaluatedIdentities(int $snapshotId): Builder
    {
        // Expand once per page query. Rechecking a large toasted JSON array for every candidate made filtered pages seconds long.
        return DB::table('crawl_snapshots')->where('id', $snapshotId)->selectRaw('jsonb_array_elements_text(evaluated_finding_ids)');
    }

    /** @param list<string> $urls
     * @return array<string, string>
     */
    private function firstSeen(AvailableFindingsSnapshot $source, array $urls): array
    {
        $findings = DB::table('crawl_findings as retained_finding')->select('retained_finding.crawled_url')
            ->whereColumn('retained_finding.snapshot_id', 'snapshot.id')
            ->whereIn(DB::raw(self::RetainedUrlHash), array_map(static fn (string $url): string => hash('sha256', $url), $urls))
            ->whereIn('retained_finding.crawled_url', $urls)
            ->whereRaw(LinkClassificationPolicy::brokenSql('retained_finding', 'snapshot.classification_policy_version IS NULL'))
            ->groupBy('retained_finding.crawled_url')->limit(count($urls));
        $query = DB::table('crawl_snapshots as snapshot')->joinLateral($findings, 'finding')->where('snapshot.check_id', $source->check_id)
            ->whereNotNull('snapshot.run_ended_at')
            ->where(static function (Builder $q) use ($source): void {
                $at = $source->run->endedAt->format('Y-m-d H:i:s.uP');
                $q->where('snapshot.run_ended_at', '<', $at)->orWhere(static fn (Builder $same) => $same->where('snapshot.run_ended_at', $at)->where('snapshot.run_id', '<=', $source->run->id));
            })
            ->selectRaw('finding.crawled_url, min(snapshot.run_ended_at) AS first_seen')->groupBy('finding.crawled_url');

        return $query->get()->mapWithKeys(static function (\stdClass $row): array {
            /** @var \stdClass&object{crawled_url: string, first_seen: string} $row */
            return [$row->crawled_url => CarbonImmutable::parse($row->first_seen)->utc()->format('Y-m-d\TH:i:s.uP')];
        })->all();
    }
}
