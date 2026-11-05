<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

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
use Illuminate\Support\Facades\DB;

final class SitemapFindingsQuery implements FindingsQueryProvider
{
    public function snapshot(CheckKey $type, int $checkId, RunReference $run): AvailableFindingsSnapshot|UnavailableFindingsSnapshot
    {
        if ($type !== CheckKey::Sitemap) {
            throw new \InvalidArgumentException('Sitemap findings require a sitemap check.');
        }
        $row = DB::table('sitemap_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->sharedLock()->first(['id', 'findings_coverage', 'comparison_key']);
        if ($row === null) {
            return new UnavailableFindingsSnapshot(FindingsUnavailableStatus::NoDataset, $checkId, $type, $run, FindingsUnavailableReason::NotRetained);
        }
        /** @var \stdClass&object{id: int, findings_coverage: string|null, comparison_key: string|null} $row */
        if ($row->findings_coverage === null) {
            return new UnavailableFindingsSnapshot(FindingsUnavailableStatus::NoDataset, $checkId, $type, $run, FindingsUnavailableReason::LegacyMetadataMissing);
        }
        $coverage = FindingsCoverage::from(json_decode($row->findings_coverage, true, flags: JSON_THROW_ON_ERROR));

        return new AvailableFindingsSnapshot($checkId, $type, 'sitemap:'.$row->id, $run, $run->endedAt->utc()->format('Y-m-d\TH:i:s.uP'),
            $coverage, $coverage->unchecked_rows_available ? FindingStatus::cases() : [FindingStatus::Broken, FindingStatus::NotVerifiable, FindingStatus::CoverageLimit],
            $coverage->comparable(), 1, $row->comparison_key);
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
        if ($limit < 1 || $limit > 200 || ($after instanceof FindingsPosition && $after->group !== FindingsSourceGroup::Rows) || ($filter->only_new_since && ! $baseline instanceof AvailableFindingsSnapshot)) {
            throw new \InvalidArgumentException('Invalid sitemap findings page.');
        }
        $id = (int) substr($source->snapshot_ref, 8);
        $position = $after instanceof FindingsPosition ? $after->position : 0;
        $query = DB::table('sitemap_findings as finding')->where('finding.snapshot_id', $id)->where('finding.id', '>', $position);
        if ($filter->status instanceof FindingStatus) {
            $query->where('finding.status', $filter->status->value);
        }
        $old = $baseline instanceof AvailableFindingsSnapshot ? DB::table('sitemap_findings as old')->selectRaw('1')
            ->where('old.snapshot_id', (int) substr($baseline->snapshot_ref, 8))->where('old.status', FindingStatus::Broken->value)
            ->whereColumn('old.identity_hash', 'finding.identity_hash')->whereColumn('old.identity', 'finding.identity') : null;
        // Absence of an issue on a previously unverified URL is unknown, not proof that this issue is new.
        $unknown = $baseline instanceof AvailableFindingsSnapshot ? DB::table('sitemap_findings as unknown')->selectRaw('1')
            ->where('unknown.snapshot_id', (int) substr($baseline->snapshot_ref, 8))->whereIn('unknown.status', [FindingStatus::NotVerifiable->value, FindingStatus::Unchecked->value])
            ->whereColumn('unknown.url', 'finding.url') : null;
        if ($filter->only_new_since) {
            $query->where('finding.status', FindingStatus::Broken->value)->whereNotExists($old ?? throw new \LogicException('Missing baseline query.'))
                ->whereNotExists($unknown ?? throw new \LogicException('Missing baseline verification query.'));
        }
        $query->select('finding.*');
        if ($old !== null) {
            $query->selectSub((clone $old)->select(DB::raw('true'))->limit(1), 'baseline_seen')
                ->selectSub((clone ($unknown ?? throw new \LogicException('Missing baseline verification query.')))->select(DB::raw('true'))->limit(1), 'baseline_unverified');
        } else {
            $query->selectRaw('NULL::boolean AS baseline_seen, NULL::boolean AS baseline_unverified');
        }
        $found = $query->orderBy('finding.id')->limit($limit + 1)->get();
        $hasMore = $found->count() > $limit;
        $found = $found->take($limit);
        $rows = [];
        $position = null;
        foreach ($found as $row) {
            /** @var \stdClass&object{id: int, identity_hash: string, status: string, issue_code: string|null, http_status: int|null, kind: string|null, url: string, reason: string|null, carried_forward: bool|null, baseline_seen: bool|null, baseline_unverified: bool|null} $row */
            $status = FindingStatus::from($row->status);
            $rows[] = new FindingsRow('sitemap:'.$id.':'.$row->id, $row->identity_hash, $status, $row->issue_code, $row->http_status, $row->kind, null,
                $row->url, null, null, $row->reason, null, null, null, $status === FindingStatus::Broken && $baseline instanceof AvailableFindingsSnapshot
                    ? ($row->baseline_seen !== null ? false : ($row->baseline_unverified !== null ? null : true)) : null,
                $row->carried_forward, $row->carried_forward === false ? $source->observed_at : null, null);
            $position = new FindingsPosition(FindingsSourceGroup::Rows, $row->id);
        }

        return new FindingsPage($source, $baseline, $baseline instanceof AvailableFindingsSnapshot ? 'available' : 'not_requested', $rows,
            $hasMore ? $position : null, $hasMore);
    }
}
