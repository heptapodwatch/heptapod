<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\RunReference;
use Spatie\LaravelData\Attributes\Computed;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SitemapSnapshot extends CheckDetailData
{
    #[Computed]
    public readonly string $issue_summary;

    /** @var array<string, int|string> */
    #[Computed]
    public readonly array $issue_summary_params;

    /** @var array<string, int>|null */
    public readonly ?array $issue_counts;

    /**
     * @param  array<string, int>|null  $issue_counts
     * @param  list<array{url: string, reason: string, status: int|null}>  $not_verifiable
     * @param  list<string>  $notes
     * @param  list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>  $issues
     * @param  list<array{url: string, issues: list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>}>  $sitemapIndexes
     * @param  list<array{url: string, urlCount: int, checkedReachabilityOfAllUrls: bool, issuesCount: int, issues: list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>}>  $sitemaps
     */
    public function __construct(
        public readonly string $checkUrl,
        public readonly int $totalIssuesCount,
        public readonly int $totalUrlCount,
        public readonly bool $hasIssues,
        public readonly array $issues,
        public readonly array $sitemapIndexes,
        public readonly array $sitemaps,
        public readonly array $notes = [],
        ?array $issue_counts = null,
        public readonly ?RunReference $export_run = null,
        /** Only older recorded runs legitimately lack this classification. */
        public readonly array $not_verifiable = [],
    ) {
        parent::__construct(CheckKey::Sitemap);
        if ($totalIssuesCount < count($issues) || $totalUrlCount < 0 || $hasIssues !== ($totalIssuesCount > 0)) {
            throw new \LogicException('Sitemap snapshot totals are invalid.');
        }
        $retainedCounts = SitemapIssueCounts::fromIssues($issues);
        if ($issue_counts !== null) {
            SitemapIssueCounts::validate($issue_counts, $totalIssuesCount);
            foreach ($retainedCounts as $kind => $count) {
                if (($issue_counts[$kind] ?? 0) < $count) {
                    throw new \LogicException('Sitemap reason counts contradict the retained issues.');
                }
            }
        }
        $this->issue_counts = $issue_counts ?? ($totalIssuesCount === count($issues) ? $retainedCounts : null);
        [$this->issue_summary, $this->issue_summary_params] = SitemapIssueSummary::forSnapshot($this);
    }

    public function bounded(): self
    {
        $indexes = array_slice($this->sitemapIndexes, 0, 20);
        foreach ($indexes as &$index) {
            $index['url'] = mb_substr($index['url'], 0, 2048);
            $index['issues'] = $this->boundIssues(array_slice($index['issues'], 0, 5));
        }
        unset($index);
        $sitemaps = array_slice($this->sitemaps, 0, 20);
        foreach ($sitemaps as &$sitemap) {
            $sitemap['url'] = mb_substr($sitemap['url'], 0, 2048);
            $sitemap['issues'] = $this->boundIssues(array_slice($sitemap['issues'], 0, 5));
        }
        unset($sitemap);

        return new self(mb_substr($this->checkUrl, 0, 2048), $this->totalIssuesCount, $this->totalUrlCount, $this->hasIssues,
            $this->boundIssues($this->issues), $indexes, $sitemaps, $this->notes, $this->issue_counts, $this->export_run, array_map(static fn (array $finding): array => [...$finding, 'url' => mb_strcut($finding['url'], 0, 2048, 'UTF-8')], array_slice($this->not_verifiable, 0, 50)));
    }

    /**
     * @param  list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>  $issues
     * @return list<array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}>
     */
    private function boundIssues(array $issues): array
    {
        $issues = array_slice($issues, 0, 50);
        foreach ($issues as &$issue) {
            $issue['url'] = mb_strcut($issue['url'], 0, $issue['type'] === 'sitemap.issue.url_length' ? 2124 : 2048, 'UTF-8');
        }
        unset($issue);

        return $issues;
    }
}
