<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/** Applies Ignored URLs to crawl observations. The settings action owns the save. */
interface BrokenLinkSettings
{
    /** @param list<string> $entries
     * @return list<string>
     */
    public function editIgnoredUrls(array $entries, string $url, bool $ignore): array;

    /**
     * Re-evaluate the retained observations without collecting again. Only the evaluated projection changes.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  array<array-key, mixed>  $secrets
     * @return array{internal: int, external: int, rate_limited: bool, ids: list<string>}|null
     */
    public function reEvaluateIgnoredUrls(int $checkId, RunReference $run, array $before, array $after, array $secrets, string $siteUrl): ?array;
}
