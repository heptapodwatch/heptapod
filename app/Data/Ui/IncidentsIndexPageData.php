<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ListFilterData;
use Heptapod\Contracts\PaginationData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentsIndexPageData extends Data
{
    /**
     * @param  ClosureLazy|list<IncidentData>  $incidents
     * @param  ClosureLazy|list<ClientData>  $clients
     * @param  ClosureLazy|array<string, ListFilterData>  $filters
     */
    public function __construct(
        public readonly ClosureLazy|array $incidents,
        public readonly ClosureLazy|PaginationData $incidents_pagination,
        public readonly ClosureLazy|array $filters,
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|IncidentSummaryData $summary,
    ) {}
}
