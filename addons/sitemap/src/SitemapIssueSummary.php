<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Heptapod\Contracts\Http\FetchFailureKind;

final class SitemapIssueSummary
{
    /** @param list<string> $documents
     * @return array{string, array<string, int|string>}
     */
    public static function forSnapshot(SitemapSnapshot $snapshot, array $documents = []): array
    {
        $documents = [...$documents, $snapshot->checkUrl, ...array_column($snapshot->sitemapIndexes, 'url'), ...array_column($snapshot->sitemaps, 'url')];
        $listed404 = [];
        foreach ($snapshot->issues as $issue) {
            if (in_array($issue['url'], $documents, true)) {
                if (($issue['kind'] ?? null) === FetchFailureKind::Tls->value) {
                    return ['sitemap.summary.unreachable_tls', []];
                }
                $path = basename(explode('?', $issue['url'], 2)[0]);
                if (($issue['status'] ?? null) === 404) {
                    return ['sitemap.summary.not_found', ['path' => $path === '' ? '/' : $path]];
                }
                if ($issue['type'] === 'sitemap.issue.invalid') {
                    return ['sitemap.summary.invalid_xml', ['path' => $path === '' ? '/' : $path, ...array_intersect_key($issue, array_flip(['xml_reason', 'xml_line', 'xml_element']))]];
                }
            } elseif (($issue['status'] ?? null) === 404) {
                $listed404[$issue['url']] = true;
            }
        }
        $count = count($listed404);
        if ($count > 0) {
            return [$count === 1 ? 'sitemap.summary.url_404' : 'sitemap.summary.urls_404', ['count' => $count]];
        }
        if ($snapshot->issues === []) {
            return [in_array('sitemap.not_published', $snapshot->notes, true) ? 'sitemap.not_published' : 'sitemap.succeeded', []];
        }

        return [$snapshot->totalIssuesCount === 1 ? 'sitemap.summary.issue' : 'sitemap.summary.issues', ['count' => $snapshot->totalIssuesCount]];
    }
}
