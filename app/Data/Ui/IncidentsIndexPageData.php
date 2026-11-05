<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentsIndexPageData extends Data
{
    /**
     * @param  ClosureLazy|list<IncidentData>  $incidents
     * @param  ClosureLazy|list<ClientData>  $clients
     */
    public function __construct(
        public readonly ClosureLazy|array $incidents,
        public readonly ClosureLazy|PaginationData $pagination,
        public readonly ClosureLazy|IncidentFilterData $filters,
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|IncidentSummaryData $summary,
    ) {}
}
