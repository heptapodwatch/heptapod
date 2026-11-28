<?php

declare(strict_types=1);

namespace Heptapod\Addons\Crawl;

use Heptapod\Contracts\CheckHistoryData;
use Heptapod\Contracts\CheckKey;
use Heptapod\Contracts\PaginationData;
use Heptapod\Contracts\RunReference;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CrawlFindingsData extends CheckHistoryData
{
    /**
     * @param  list<array{url: string, found_on: string|null, link_text: string|null, status_code: int|null, kind: string, kind_key: string, internal: bool, reason: string|null, reason_key: string|null, broken: bool, first_seen_in_retained_history_at: string|null, current_reason_key: string|null, after_first_complete_crawl: bool, ignored_by: string|null}>  $findings
     * @param  list<array{url: string, reason: string, status: int|null, source: string|null}>  $not_verifiable
     * @param  array{current: int, previous: int, new: int, fixed: int, since: string}|null  $weekly_change
     */
    public function __construct(
        CheckKey $check_type,
        public readonly ?string $observed_at,
        public readonly bool $time_limit_reached,
        public readonly bool $page_cap_reached,
        public readonly bool $resource_cap_reached,
        public readonly int $page_cap,
        public readonly array $findings,
        public readonly ?array $weekly_change,
        public readonly ?RunReference $snapshot_run = null,
        public readonly ?PaginationData $findings_pagination = null,
        public readonly array $not_verifiable = [],
        public readonly ?PaginationData $not_verifiable_pagination = null,
        public readonly int $ignored_count = 0,
        public readonly bool $ignored_view = false,
    ) {
        parent::__construct($check_type);
    }
}
