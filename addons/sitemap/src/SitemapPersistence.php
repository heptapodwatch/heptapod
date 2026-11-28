<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Carbon\CarbonImmutable;
use Heptapod\Contracts\CheckContext;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\Findings\AvailableFindingsSnapshot;
use Heptapod\Contracts\Findings\FindingsFilter;
use Heptapod\Contracts\Findings\FindingsPage;
use Heptapod\Contracts\Findings\FindingsPosition;
use Heptapod\Contracts\Findings\FindingStatus;
use Heptapod\Contracts\Findings\FindingsView;
use Heptapod\Contracts\Findings\UnavailableFindingsSnapshot;
use Heptapod\Contracts\FindingsExportProvider;
use Heptapod\Contracts\FindingsQueryProvider;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\ListPaging;
use Heptapod\Contracts\PaginationData;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Heptapod\Contracts\StagedSnapshots;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class SitemapPersistence implements FindingsExportProvider, FindingsQueryProvider, RunPersistence
{
    /** Issues that describe how far the check got, not a broken URL. */
    private const array CoverageLimitTypes = ['sitemap.issue.time_limit', 'sitemap.issue.url_limit', 'sitemap.issue.recursion_limit'];

    public function __construct(private SitemapFindingsQuery $findings, private ListPaging $paging) {}

    /**
     * One page of the run's retained issues (list `findings`) and not-verifiable rows (list `not_verifiable`), in their
     * recorded order; null for a run without a retained snapshot (older runs keep their bounded evidence).
     *
     * @return array{issues: list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>, issues_pagination: PaginationData, not_verifiable: list<array{url: string, reason: string, status: int|null}>, not_verifiable_pagination: PaginationData}|null
     */
    public function detailPages(int $checkId, RunReference $run): ?array
    {
        $snapshot = DB::table('sitemap_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->value('id');
        if (! is_int($snapshot)) {
            return null;
        }
        $issues = $this->paging->paginate(DB::table('sitemap_snapshots as snapshot')
            ->crossJoin(DB::raw("LATERAL jsonb_array_elements(snapshot.snapshot->'issues') WITH ORDINALITY AS finding(issue, position)"))
            ->where('snapshot.id', $snapshot)->orderBy('finding.position')->select('finding.issue'), 'findings');
        $notVerifiable = $this->paging->paginate(DB::table('sitemap_findings')->where('snapshot_id', $snapshot)
            ->where('status', 'not_verifiable')->orderBy('id')->select('identity'), 'not_verifiable');
        $issueRows = [];
        foreach ($issues->items() as $row) {
            /** @var \stdClass&object{issue: string} $row */
            /** @var array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string} $issue */
            $issue = json_decode($row->issue, true, flags: JSON_THROW_ON_ERROR);
            $issueRows[] = $issue;
        }
        $notVerifiableRows = [];
        foreach ($notVerifiable->items() as $row) {
            /** @var \stdClass&object{identity: string} $row */
            /** @var array{url: string, reason: string, status?: int|null} $finding */
            $finding = json_decode($row->identity, true, flags: JSON_THROW_ON_ERROR);
            $notVerifiableRows[] = ['url' => $finding['url'], 'reason' => $finding['reason'], 'status' => $finding['status'] ?? null];
        }

        return ['issues' => $issueRows, 'issues_pagination' => PaginationData::fromPaginator($issues),
            'not_verifiable' => $notVerifiableRows, 'not_verifiable_pagination' => PaginationData::fromPaginator($notVerifiable)];
    }

    public function snapshot(CheckKey $type, int $checkId, RunReference $run): AvailableFindingsSnapshot|UnavailableFindingsSnapshot
    {
        return $this->findings->snapshot($type, $checkId, $run);
    }

    public function page(AvailableFindingsSnapshot $snapshot, FindingsFilter $filter, ?AvailableFindingsSnapshot $baseline, ?FindingsPosition $after, int $limit): FindingsPage|UnavailableFindingsSnapshot
    {
        return $this->findings->page($snapshot, $filter, $baseline, $after, $limit);
    }

    public function stage(CheckContext $context, string $dataset, array $rows): void
    {
        if ($dataset !== 'snapshot' || count($rows) !== 1) {
            throw new \InvalidArgumentException('Sitemap persistence requires one snapshot row.');
        }
        $issueRules = ['array:url,type,kind,status,xml_reason,xml_line,xml_element', 'required_array_keys:url,type'];
        $rules = [
            'checkUrl' => ['required', 'string'],
            'totalIssuesCount' => ['required', 'integer:strict', 'min:0'],
            'totalUrlCount' => ['required', 'integer:strict', 'min:0'],
            'hasIssues' => ['required', 'boolean:strict'],
            'issue_counts' => ['sometimes', 'nullable', 'array'],
            'issue_counts.*' => ['integer:strict', 'min:1'],
            'not_verifiable' => ['sometimes', 'array', 'list'],
            'unchecked' => ['sometimes', 'array', 'list'],
            'unchecked.*' => ['required', 'string'],
            'observed_urls' => ['sometimes', 'array', 'list'],
            'observed_urls.*' => ['required', 'string'],
            'findings_coverage' => ['sometimes', 'array'],
            'comparison_key' => ['sometimes', 'string', 'size:64'],
            'rotation_next' => ['sometimes', 'integer:strict', 'min:0'],
            'not_verifiable.*' => ['array:url,reason,status', 'required_array_keys:url,reason,status'],
            'not_verifiable.*.url' => ['required', 'string'],
            'not_verifiable.*.reason' => ['required', Rule::enum(AutomatedCheckBlockReason::class)],
            'not_verifiable.*.status' => ['present', 'nullable', 'integer:strict', 'min:100', 'max:999'],
            'issues' => ['present', 'array', 'list'],
            'issues.*' => $issueRules,
            'issues.*.url' => ['required', 'string'],
            'issues.*.type' => ['required', 'string'],
            'comparisonIssues' => ['present', 'nullable', 'array', 'list'],
            'notes' => ['present', 'array', 'list'],
            'notes.*' => [Rule::in(['sitemap.note.comparison_limit', 'sitemap.not_published'])],
            'comparisonIssues.*' => $issueRules,
            'comparisonIssues.*.url' => ['required', 'string'],
            'comparisonIssues.*.type' => ['required', 'string'],
            'sitemapIndexes' => ['present', 'array', 'list'],
            'sitemapIndexes.*' => ['array:url,issues'],
            'sitemapIndexes.*.url' => ['required', 'string'],
            'sitemapIndexes.*.issues' => ['present', 'array', 'list'],
            'sitemapIndexes.*.issues.*' => $issueRules,
            'sitemapIndexes.*.issues.*.url' => ['required', 'string'],
            'sitemapIndexes.*.issues.*.type' => ['required', 'string'],
            'sitemaps' => ['present', 'array', 'list'],
            'sitemaps.*' => ['array:url,urlCount,checkedReachabilityOfAllUrls,issuesCount,issues'],
            'sitemaps.*.url' => ['required', 'string'],
            'sitemaps.*.urlCount' => ['required', 'integer:strict', 'min:0'],
            'sitemaps.*.checkedReachabilityOfAllUrls' => ['required', 'boolean:strict'],
            'sitemaps.*.issuesCount' => ['required', 'integer:strict', 'min:0'],
            'sitemaps.*.issues' => ['present', 'array', 'list'],
            'sitemaps.*.issues.*' => $issueRules,
            'sitemaps.*.issues.*.url' => ['required', 'string'],
            'sitemaps.*.issues.*.type' => ['required', 'string'],
        ];
        foreach (['issues.*', 'comparisonIssues.*', 'sitemapIndexes.*.issues.*', 'sitemaps.*.issues.*'] as $prefix) {
            $rules[$prefix.'.xml_reason'] = ['required_with:'.$prefix.'.xml_line,'.$prefix.'.xml_element', 'prohibited_unless:'.$prefix.'.type,sitemap.issue.invalid', Rule::enum(SitemapXmlReason::class)];
            $rules[$prefix.'.xml_line'] = ['required_with:'.$prefix.'.xml_reason', 'integer:strict', 'min:1'];
            $rules[$prefix.'.xml_element'] = ['required_if:'.$prefix.'.xml_reason,root,child', 'prohibited_unless:'.$prefix.'.xml_reason,root,child', 'string', 'max:40', 'regex:/^[a-zA-Z0-9:_-]+$/D'];
            $rules[$prefix.'.kind'] = ['sometimes', Rule::enum(FetchFailureKind::class)];
            $rules[$prefix.'.status'] = ['sometimes', 'nullable', 'integer:strict', 'min:100', 'max:999'];
        }
        /** @var array{checkUrl: string, rotation_next?: int, totalIssuesCount: int, totalUrlCount: int, hasIssues: bool, issues: list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>, comparisonIssues: list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>|null, not_verifiable?: list<array{url: string, reason: string, status: int|null}>, unchecked?: list<string>, observed_urls?: list<string>, findings_coverage?: array<string, mixed>, comparison_key?: string, issue_counts?: array<string, int>|null} $snapshot */
        $snapshot = Validator::make($rows[0], $rules)->validate();
        $counts = $snapshot['issue_counts'] ?? null;
        $totalIssuesCount = $snapshot['totalIssuesCount'];
        if ($counts !== null) {
            SitemapIssueCounts::validate($counts, $totalIssuesCount);
        }
        $comparisonIssues = $snapshot['comparisonIssues'];
        unset($snapshot['comparisonIssues']);
        $coverage = $snapshot['findings_coverage'] ?? null;
        $comparisonKey = $snapshot['comparison_key'] ?? null;
        $observed = array_fill_keys($snapshot['observed_urls'] ?? [], true);
        $findingRows = [];
        $seen = [];
        foreach ([...$snapshot['issues'], ...($comparisonIssues ?? [])] as $index => $issue) {
            $issue = SitemapIssueCounts::identity($issue);
            ksort($issue);
            $identity = json_encode($issue, JSON_THROW_ON_ERROR);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $status = in_array($issue['type'], self::CoverageLimitTypes, true) ? 'coverage_limit' : 'broken';
            $findingRows[] = ['identity' => $issue, 'status' => $status, 'carried_forward' => $index >= count($snapshot['issues']) || ($coverage !== null && ! isset($observed[$issue['url']]))];
        }
        foreach ($snapshot['not_verifiable'] ?? [] as $finding) {
            $findingRows[] = ['identity' => $finding, 'status' => 'not_verifiable', 'carried_forward' => false];
        }
        foreach ($snapshot['unchecked'] ?? [] as $url) {
            $findingRows[] = ['identity' => ['url' => $url, 'reason' => 'not_checked'], 'status' => 'unchecked', 'carried_forward' => false];
        }
        unset($snapshot['unchecked'], $snapshot['observed_urls'], $snapshot['findings_coverage'], $snapshot['comparison_key']);
        // The compatibility projection remains capped; the complete rows were assembled before this slice.
        $snapshot['not_verifiable'] = array_slice($snapshot['not_verifiable'] ?? [], 0, 50);
        DB::transaction(function () use ($context, $snapshot, $comparisonIssues, $coverage, $comparisonKey, $findingRows): void {
            StagedSnapshots::stage('sitemap_snapshots', $context, [
                'site_id' => $context->siteId, 'check_id' => $context->checkId,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'comparison_issues' => $comparisonIssues === null ? null : json_encode($comparisonIssues, JSON_THROW_ON_ERROR),
                'created_at' => now()->format('Y-m-d H:i:s.uP'),
                'findings_coverage' => $coverage === null ? null : json_encode($coverage, JSON_THROW_ON_ERROR),
                'comparison_key' => $comparisonKey,
            ]);
            $id = DB::table('sitemap_snapshots')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->value('id');
            DB::table('sitemap_findings')->where('snapshot_id', $id)->delete();
            foreach (array_chunk($findingRows, 1000) as $batchIndex => $batch) {
                DB::statement("INSERT INTO sitemap_findings (snapshot_id, position, identity, identity_hash, status, issue_code, http_status, kind, url, reason, carried_forward)
                    SELECT ?, ? + finding.position, finding.row->'identity', encode(sha256(convert_to((finding.row->'identity')::text, 'UTF8')), 'hex'),
                        finding.row->>'status', finding.row->'identity'->>'type', (finding.row->'identity'->>'status')::smallint,
                        finding.row->'identity'->>'kind', finding.row->'identity'->>'url', finding.row->'identity'->>'reason', (finding.row->>'carried_forward')::boolean
                    FROM jsonb_array_elements(?::jsonb) WITH ORDINALITY finding(row, position)", [$id, $batchIndex * 1000, json_encode($batch, JSON_THROW_ON_ERROR)]);
            }
        });
    }

    public function finalize(RunReference $run, string $claimToken): void
    {
        // Accepted transport errors can have no staged snapshot.
        StagedSnapshots::finalize('sitemap_snapshots', $run, $claimToken);
    }

    /** @return array{run_id: int, issues: list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>}|null */
    public function baseline(int $checkId): ?array
    {
        $row = DB::table('sitemap_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->whereNotNull('comparison_issues')
            ->whereJsonDoesntContain('snapshot->issues', [['type' => 'sitemap.issue.time_limit']])
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->first(['run_id', 'comparison_issues']);
        if ($row === null) {
            return null;
        }
        /** @var \stdClass&object{run_id: int, comparison_issues: string} $row */
        /** @var list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}> $issues */
        $issues = json_decode($row->comparison_issues, true, flags: JSON_THROW_ON_ERROR);

        return ['run_id' => $row->run_id, 'issues' => $issues];
    }

    /** The rotation window of the latest accepted run ends where the next run starts; errored attempts are never accepted here. */
    public function rotationStart(int $checkId): int
    {
        $row = DB::table('sitemap_snapshots')->where('check_id', $checkId)->whereNotNull('run_ended_at')
            ->orderByDesc('run_ended_at')->orderByDesc('run_id')->selectRaw("(snapshot->>'rotation_next')::integer AS rotation_next")->first();
        /** @var (\stdClass&object{rotation_next: int|null})|null $row */

        return $row->rotation_next ?? 0;
    }

    public function exportFindings(CheckKey $type, int $checkId, RunReference $run, FindingsView $view = FindingsView::Raw): ?iterable
    {
        if ($type !== CheckKey::Sitemap) {
            throw new \InvalidArgumentException('Sitemap export requires a sitemap check.');
        }
        $snapshot = DB::table('sitemap_snapshots')->where('check_id', $checkId)->where('run_id', $run->id)
            ->where('run_ended_at', $run->endedAt->format('Y-m-d H:i:s.uP'))->sharedLock()->first(['id']);
        if ($snapshot === null) {
            return null;
        }
        /** @var \stdClass&object{id: int} $snapshot */

        return (function () use ($snapshot): \Generator {
            $xmlDiagnostics = null;
            $lastId = 0;
            $streamed = false;
            do {
                $rows = DB::table('sitemap_findings')
                    ->where('snapshot_id', $snapshot->id)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit(250)
                    ->get(['id', 'status', 'issue_code', 'identity']);
                foreach ($rows as $row) {
                    /** @var \stdClass&object{id: int, status: string, issue_code: string|null, identity: string} $row */
                    $lastId = $row->id;
                    $streamed = true;
                    $findingStatus = FindingStatus::from($row->status);
                    if ($findingStatus === FindingStatus::NotVerifiable) {
                        /** @var array{url: string, reason: string} $finding */
                        $finding = json_decode($row->identity, true, flags: JSON_THROW_ON_ERROR);
                        yield ['url' => $finding['url'], 'result' => (string) __('sitemap.not_verifiable.'.$finding['reason']), 'status' => FindingStatus::NotVerifiable,
                            'found_on' => null, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];

                        continue;
                    }
                    if ($findingStatus === FindingStatus::Unchecked) {
                        /** @var array{url: string, reason?: string} $finding */
                        $finding = json_decode($row->identity, true, flags: JSON_THROW_ON_ERROR);
                        yield ['url' => $finding['url'], 'result' => (string) __('Not checked'), 'status' => FindingStatus::Unchecked,
                            'found_on' => null, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];

                        continue;
                    }
                    /** @var array{url: string, type?: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string} $issue */
                    $issue = json_decode($row->identity, true, flags: JSON_THROW_ON_ERROR);
                    if ($row->issue_code === 'sitemap.issue.invalid') {
                        $xmlDiagnostics ??= $this->xmlDiagnostics($snapshot->id);
                        if (isset($xmlDiagnostics[$issue['url']])) {
                            $issue = [...$issue, ...$xmlDiagnostics[$issue['url']]];
                        }
                    }
                    yield $this->exportIssueRow($issue, $findingStatus);
                }
            } while ($rows->count() === 250);

            if ($streamed) {
                return;
            }

            // Legacy snapshots predating sitemap_findings keep their issues and up to 50 not-verifiable rows in snapshot JSON.
            $offset = 0;
            do {
                $rows = DB::select("SELECT finding.issue::text AS issue FROM sitemap_snapshots AS snapshot
                    CROSS JOIN LATERAL jsonb_array_elements(COALESCE(snapshot.snapshot->'issues', '[]'::jsonb)) WITH ORDINALITY AS finding(issue, position)
                    WHERE snapshot.id = ? ORDER BY finding.position LIMIT 250 OFFSET ?", [$snapshot->id, $offset]);
                foreach ($rows as $row) {
                    /** @var \stdClass&object{issue: string} $row */
                    /** @var array{url: string, type?: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string} $issue */
                    $issue = json_decode($row->issue, true, flags: JSON_THROW_ON_ERROR);
                    if (! isset($issue['type'])) {
                        throw new \LogicException('Sitemap finding requires a type.');
                    }
                    yield $this->exportIssueRow($issue, in_array($issue['type'], self::CoverageLimitTypes, true) ? FindingStatus::CoverageLimit : FindingStatus::Broken);
                }
                $offset += count($rows);
            } while (count($rows) === 250);

            $legacyRows = DB::select("SELECT finding.row::text AS row FROM sitemap_snapshots AS snapshot
                CROSS JOIN LATERAL jsonb_array_elements(COALESCE(snapshot.snapshot->'not_verifiable', '[]'::jsonb)) WITH ORDINALITY AS finding(row, position)
                WHERE snapshot.id = ? ORDER BY finding.position", [$snapshot->id]);
            foreach ($legacyRows as $row) {
                /** @var \stdClass&object{row: string} $row */
                /** @var array{url: string, reason: string} $finding */
                $finding = json_decode($row->row, true, flags: JSON_THROW_ON_ERROR);
                yield ['url' => $finding['url'], 'result' => (string) __('sitemap.not_verifiable.'.$finding['reason']), 'status' => FindingStatus::NotVerifiable,
                    'found_on' => null, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];
            }
        })();
    }

    /**
     * @param  array{url: string, type?: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}  $issue
     * @return array{url: string, result: string, status: FindingStatus, found_on: null, link_text: null, first_seen_at: null, after_first_complete_crawl: false}
     */
    private function exportIssueRow(array $issue, FindingStatus $findingStatus): array
    {
        if (! isset($issue['type'])) {
            throw new \LogicException('Sitemap finding requires a type.');
        }
        $status = isset($issue['kind']) && $issue['kind'] !== 'http_status'
            ? __('sitemap.status.'.$issue['kind']) : ($issue['status'] ?? __($issue['type']));
        $xmlProblem = SitemapXmlProblem::presentation($issue);
        $problem = $xmlProblem instanceof CheckRunPresentationPartData ? __($xmlProblem->key, $xmlProblem->localizedParams(Lang::locale())) : (($issue['kind'] ?? null) === 'tls' && ! str_starts_with($issue['type'], 'causes.tls.')
            ? __('TLS certificate verification failed') : __($issue['type']));
        if ($xmlProblem instanceof CheckRunPresentationPartData) {
            $status = $problem;
        }
        if ((! is_string($status) && ! is_int($status)) || ! is_string($problem)) {
            throw new \LogicException('Sitemap finding translations must be text.');
        }

        return ['url' => $issue['url'], 'result' => (string) $status === $problem ? (string) $status : $status.' · '.$problem,
            'status' => $findingStatus, 'found_on' => null, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];
    }

    /** @return array<string, array{xml_reason?: string, xml_line?: int, xml_element?: string}> */
    private function xmlDiagnostics(int $snapshotId): array
    {
        $rows = DB::select("SELECT finding.issue::text AS issue FROM sitemap_snapshots AS snapshot
            CROSS JOIN LATERAL jsonb_array_elements(COALESCE(snapshot.snapshot->'issues', '[]'::jsonb)) AS finding(issue)
            WHERE snapshot.id = ? AND finding.issue->>'type' = 'sitemap.issue.invalid'", [$snapshotId]);
        $diagnostics = [];
        foreach ($rows as $row) {
            /** @var \stdClass&object{issue: string} $row */
            /** @var array{url?: string, xml_reason?: string, xml_line?: int, xml_element?: string} $issue */
            $issue = json_decode($row->issue, true, flags: JSON_THROW_ON_ERROR);
            if (isset($issue['url'], $issue['xml_reason'])) {
                $diagnostics[$issue['url']] = array_intersect_key($issue, array_flip(['xml_reason', 'xml_line', 'xml_element']));
            }
        }

        return $diagnostics;
    }

    public function purgeCheck(int $checkId, int $limit): int
    {
        // Findings before their snapshots, finished ones only.
        $findings = DB::delete('DELETE FROM sitemap_findings WHERE ctid = ANY(ARRAY(SELECT ctid FROM sitemap_findings
            WHERE snapshot_id IN (SELECT id FROM sitemap_snapshots WHERE check_id = ? AND run_ended_at IS NOT NULL) LIMIT ?))', [$checkId, $limit]);

        return $findings > 0 ? $findings : StagedSnapshots::purge('sitemap_snapshots', $checkId, $limit);
    }

    public function prunedTables(): array
    {
        return ['sitemap_snapshots', 'sitemap_findings'];
    }

    public function prune(CarbonImmutable $before): void
    {
        $latest = DB::table('sitemap_snapshots')->selectRaw('DISTINCT ON (check_id) id')
            ->whereNotNull('run_ended_at')->orderBy('check_id')->orderByDesc('run_ended_at')->orderByDesc('run_id');
        $baselines = (clone $latest)->whereNotNull('comparison_issues')->whereJsonDoesntContain('snapshot->issues', [['type' => 'sitemap.issue.time_limit']]);
        DB::table('sitemap_snapshots')->where('run_ended_at', '<', $before->format('Y-m-d H:i:s.uP'))
            ->whereNotIn('id', $latest)->whereNotIn('id', $baselines)->delete();
    }

    public function discard(int $runId, string $claimToken): void
    {
        StagedSnapshots::discard('sitemap_snapshots', $runId, $claimToken);
    }
}
