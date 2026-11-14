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
use Heptapod\Contracts\Findings\UnavailableFindingsSnapshot;
use Heptapod\Contracts\FindingsExportProvider;
use Heptapod\Contracts\FindingsQueryProvider;
use Heptapod\Contracts\Http\AutomatedCheckBlockReason;
use Heptapod\Contracts\Http\FetchFailureKind;
use Heptapod\Contracts\RunPersistence;
use Heptapod\Contracts\RunReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class SitemapPersistence implements FindingsExportProvider, FindingsQueryProvider, RunPersistence
{
    /** Issues that describe how far the check got, not a broken URL. */
    private const array CoverageLimitTypes = ['sitemap.issue.time_limit', 'sitemap.issue.url_limit', 'sitemap.issue.recursion_limit'];

    public function __construct(private SitemapFindingsQuery $findings) {}

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
            $existing = DB::table('sitemap_snapshots')->where('run_id', $context->runId)->where('claim_token', $context->claimToken)->lockForUpdate()->first();
            if ($existing !== null && $existing->run_ended_at !== null) {
                throw new \LogicException('Cannot stage an accepted sitemap snapshot.');
            }
            DB::table('sitemap_snapshots')->updateOrInsert(['run_id' => $context->runId, 'claim_token' => $context->claimToken], [
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
        $query = DB::table('sitemap_snapshots')->where('run_id', $run->id)->where('claim_token', $claimToken);
        $row = $query->lockForUpdate()->first();
        // Accepted transport errors can have no staged snapshot.
        if ($row === null) {
            return;
        }
        /** @var \stdClass&object{run_ended_at: string|null} $row */
        if ($row->run_ended_at !== null) {
            if (! CarbonImmutable::parse($row->run_ended_at)->eq($run->endedAt)) {
                throw new \LogicException('Sitemap attempt finalized for a different run timestamp.');
            }

            return;
        }
        $query->update(['run_ended_at' => $run->endedAt->format('Y-m-d H:i:s.uP')]);
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

    public function exportFindings(CheckKey $type, int $checkId, RunReference $run): ?iterable
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

        return (static function () use ($snapshot): \Generator {
            $offset = 0;
            do {
                // PostgreSQL expands the retained JSON array; PHP holds only one 250-row page.
                $rows = DB::select("SELECT finding.issue::text AS issue FROM sitemap_snapshots AS snapshot
                    CROSS JOIN LATERAL jsonb_array_elements((snapshot.snapshot->'issues') || COALESCE(snapshot.snapshot->'not_verifiable', '[]'::jsonb)) WITH ORDINALITY AS finding(issue, position)
                    WHERE snapshot.id = ? ORDER BY finding.position LIMIT 250 OFFSET ?", [$snapshot->id, $offset]);
                foreach ($rows as $row) {
                    /** @var \stdClass&object{issue: string} $row */
                    /** @var array{url: string, type?: string, kind?: string, status?: int|null, reason?: string} $issue */
                    $issue = json_decode($row->issue, true, flags: JSON_THROW_ON_ERROR);
                    if (isset($issue['reason'])) {
                        yield ['url' => $issue['url'], 'result' => __('sitemap.not_verifiable.'.$issue['reason']), 'status' => FindingStatus::NotVerifiable,
                            'found_on' => null, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];

                        continue;
                    }
                    if (! isset($issue['type'])) {
                        throw new \LogicException('Sitemap finding requires a type or a not-verifiable reason.');
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
                    yield ['url' => $issue['url'], 'result' => (string) $status === $problem ? (string) $status : $status.' · '.$problem,
                        'status' => in_array($issue['type'], self::CoverageLimitTypes, true) ? FindingStatus::CoverageLimit : FindingStatus::Broken,
                        'found_on' => null, 'link_text' => null, 'first_seen_at' => null, 'after_first_complete_crawl' => false];
                }
                $offset += count($rows);
            } while (count($rows) === 250);
        })();
    }

    public function prunedTables(): array
    {
        return ['sitemap_snapshots'];
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
        DB::table('sitemap_snapshots')->where('run_id', $runId)->where('claim_token', $claimToken)->whereNull('run_ended_at')->delete();
    }
}
