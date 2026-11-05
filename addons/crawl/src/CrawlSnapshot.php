<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckDetailData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\CheckRunPresentationPartData;
use Heptapod\Contracts\LatestRunEvidence;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CrawlSnapshot extends CheckDetailData
{
    public function __construct(
        CheckKey $check_type,
        public readonly string $run_ended_at,
        public readonly bool $discovery_complete,
        public readonly bool $crawl_truncated,
        public readonly int $page_cap,
        /** HTML pages checked; responses without readable HTML do not count. */
        public readonly int $observed_pages,
        public readonly int $observed_urls,
        /** All discovered URLs, including resources and external destinations, not just pages. */
        public readonly int $discovered_urls,
        public readonly bool $page_cap_reached,
        public readonly bool $resource_cap_reached,
        public readonly bool $time_limit_reached,
        public readonly bool $comparison_limit_reached,
        /** @var list<CheckRunPresentationPartData> */
        public readonly array $notes,
    ) {
        parent::__construct($check_type);
    }

    public static function fromRun(CheckKey $checkType, ?LatestRunEvidence $latestRun): ?self
    {
        if (! $latestRun instanceof LatestRunEvidence || ! isset($latestRun->observationEvidence()['crawl_truncated'])) {
            return null;
        }
        $evidence = CrawlEvidence::hydrateObservation($latestRun->observationEvidence());

        return new self($checkType, $latestRun->run->endedAt->toIso8601String(), $evidence->discovery_complete, $evidence->crawl_truncated, $evidence->page_cap, $evidence->observed_pages, $evidence->observed_urls, $evidence->discovered_urls, $evidence->page_cap_reached, $evidence->resource_cap_reached, $evidence->time_limit_reached, $evidence->comparison_limit_reached, $evidence->notes);
    }
}
