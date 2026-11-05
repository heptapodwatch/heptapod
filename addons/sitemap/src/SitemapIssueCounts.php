<?php

declare(strict_types=1);

namespace Heptapod\Addons\Sitemap;

use Heptapod\Contracts\Http\FetchFailureKind;

/** Complete reason counts survive the retained issue-list cap. */
final class SitemapIssueCounts
{
    public const array Types = ['sitemap.issue.recursion_limit', 'sitemap.issue.url_limit', 'sitemap.issue.time_limit', 'sitemap.issue.size_limit', 'sitemap.issue.invalid', 'sitemap.issue.invalid_url', 'sitemap.issue.unreachable', 'sitemap.issue.blocked', 'sitemap.issue.credentials', 'sitemap.issue.url_length'];

    /** XML diagnostics describe the observation, never the finding's identity.
     * @param  array{url: string, type: string, kind?: string, status?: int|null, xml_reason?: string, xml_line?: int, xml_element?: string}  $issue
     * @return array{url: string, type: string, kind?: string, status?: int|null}
     */
    public static function identity(array $issue): array
    {
        unset($issue['xml_reason'], $issue['xml_line'], $issue['xml_element']);

        return $issue;
    }

    /** @param array<array-key, mixed> $issues
     * @return array<string, int>
     */
    public static function fromIssues(array $issues): array
    {
        $counts = [];
        foreach ($issues as $issue) {
            if (! is_array($issue) || ! is_string($issue['url'] ?? null) || ! is_string($issue['type'] ?? null)
                || ! in_array($issue['type'], self::Types, true)
                || (array_key_exists('kind', $issue) && ! is_string($issue['kind']))) {
                throw new \LogicException('Sitemap issue facts are invalid.');
            }
            $kind = isset($issue['kind']) ? FetchFailureKind::from($issue['kind']) : null;
            $status = $issue['status'] ?? null;
            if ($status !== null && (! is_int($status) || $status < 100 || $status > 999)) {
                throw new \LogicException('Sitemap issue HTTP status is invalid.');
            }
            $key = ($kind === FetchFailureKind::HttpStatus || $kind === null) && $status !== null
                ? 'http_'.$status : ($kind === null ? substr($issue['type'], strlen('sitemap.issue.')) : $kind->value);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /** @param array<array-key, mixed> $counts */
    public static function validate(array $counts, int $total): void
    {
        $known = [...array_column(FetchFailureKind::cases(), 'value'), ...array_map(static fn (string $type): string => substr($type, strlen('sitemap.issue.')), self::Types)];
        foreach ($counts as $key => $count) {
            if (! is_string($key) || (! in_array($key, $known, true) && preg_match('/^http_[1-9][0-9]{2}$/D', $key) !== 1)
                || ! is_int($count) || $count < 1) {
                throw new \LogicException('Sitemap reason counts are invalid.');
            }
        }
        if (array_sum($counts) !== $total) {
            throw new \LogicException('Sitemap reason counts must cover every issue.');
        }
    }
}
