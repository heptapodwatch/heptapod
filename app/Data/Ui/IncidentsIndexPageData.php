<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\PaginationData;
use Heptapod\Enums\IncidentCause;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Lazy\ClosureLazy;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentsIndexPageData extends Data
{
    /**
     * @param  ClosureLazy|list<IncidentData>  $incidents
     * @param  ClosureLazy|list<ClientData>  $clients
     * @param  array{cause: array{value: IncidentCause, clear_url: string}|null, started_after: array{timestamp: string, whole_day: bool, clear_url: string}|null, started_before: array{timestamp: string, whole_day: bool, clear_url: string}|null}  $filter_chips
     */
    public function __construct(
        public readonly ClosureLazy|array $incidents,
        public readonly ClosureLazy|PaginationData $pagination,
        public readonly ClosureLazy|IncidentFilterData $filters,
        public readonly ClosureLazy|array $clients,
        public readonly ClosureLazy|IncidentSummaryData $summary,
        public readonly array $filter_chips,
    ) {}
}
