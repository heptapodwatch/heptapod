<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\LaravelData\Support\Lazy\InertiaDeferred;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /sites. Every prop is a closure so partial reloads evaluate only the requested keys. */
#[TypeScript]
final class SitesIndexPageData extends Data
{
    /**
     * @param  InertiaDeferred|list<SiteRowData>  $sites
     * @param  ClosureLazy|list<ClientData>  $clients
     * @param  ClosureLazy|list<TagData>  $tags
     * @param  ClosureLazy|list<SiteRowData>  $needs_attention
     */
    public function __construct(
        public readonly InertiaDeferred|array $sites,
        public readonly ClosureLazy|PaginationData $pagination,
        public readonly ClosureLazy|SitesFilterData $filters,
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|array $tags,
        public readonly ClosureLazy|SiteListSummaryData $summary,
        public readonly ClosureLazy|bool $can_create,
        public readonly ClosureLazy|DashboardTilesData $tiles,
        public readonly ClosureLazy|array $needs_attention,
        public readonly ClosureLazy|int $needs_attention_total,
    ) {}
}
