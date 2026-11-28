<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ListFilterData;
use Heptapod\Contracts\PaginationData;
use Heptapod\Contracts\SecurityAdvisoryPackageData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\LaravelData\Support\Lazy\InertiaDeferred;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /sites. Every prop is a closure so partial reloads evaluate only the requested keys. */
#[TypeScript]
final class SitesIndexPageData extends Data
{
    /**
     * @param  ClosureLazy|array<string, ListFilterData>  $filters
     * @param  InertiaDeferred|list<SiteRowData>  $sites  Rows without uptime and response; those arrive in $sites_history.
     * @param  InertiaDeferred|list<SiteHistoryData>  $sites_history
     * @param  ClosureLazy|list<ClientData>  $clients
     * @param  ClosureLazy|list<TagData>  $tags
     * @param  ClosureLazy|list<SiteRowData>  $needs_attention
     * @param  ClosureLazy|list<SecurityAdvisoryPackageData>  $advisory_packages
     */
    public function __construct(
        public readonly InertiaDeferred|array $sites,
        public readonly InertiaDeferred|array $sites_history,
        public readonly ClosureLazy|PaginationData $sites_pagination,
        public readonly ClosureLazy|array $filters,
        public readonly string $sort,
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|array $tags,
        public readonly ClosureLazy|SiteListSummaryData $summary,
        public readonly ClosureLazy|bool $can_create,
        public readonly ClosureLazy|DashboardTilesData $tiles,
        public readonly ClosureLazy|array $needs_attention,
        public readonly ClosureLazy|int $needs_attention_total,
        public readonly ClosureLazy|array $advisory_packages,
        public readonly ClosureLazy|bool $more_advisory_packages,
    ) {}
}
