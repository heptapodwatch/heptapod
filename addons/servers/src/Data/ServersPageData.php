<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Contracts\ListFilterData;
use Heptapod\Contracts\PaginationData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /servers. Servers and their pagination are closures over one read, so the list's polls ask for exactly them. */
#[TypeScript]
final class ServersPageData extends Data
{
    /**
     * @param  ClosureLazy|list<ServerRowData>  $servers  one page, filtered and sorted
     * @param  ServerThresholdsData  $defaults  the instance defaults; each row carries its server's own
     * @param  array<string, ListFilterData>  $filters
     */
    public function __construct(
        public readonly ClosureLazy|array $servers,
        public readonly ClosureLazy|PaginationData $servers_pagination,
        public readonly ServerThresholdsData $defaults,
        public readonly array $filters,
        /** One of ServerReader::SortKeys, `-` reversed. */
        public readonly string $sort,
    ) {}
}
