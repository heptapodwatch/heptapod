<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Props of GET /clients/{id}. Every prop is a closure so partial reloads evaluate only the requested keys. */
#[TypeScript]
final class ClientDetailPageData extends Data
{
    /**
     * @param  ClosureLazy|list<SiteRowData>  $sites
     * @param  ClosureLazy|list<IncidentData>  $incidents
     */
    public function __construct(
        public readonly ClosureLazy|ClientData $client,
        public readonly ClosureLazy|DashboardTilesData $summary,
        public readonly ClosureLazy|array $sites,
        public readonly ClosureLazy|PaginationData $pagination,
        public readonly ClosureLazy|array $incidents,
        public readonly ClosureLazy|bool $can_manage,
    ) {}
}
