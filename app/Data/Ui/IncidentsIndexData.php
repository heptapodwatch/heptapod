<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentsIndexData extends Data
{
    /**
     * @param  list<IncidentData>  $incidents
     * @param  list<ClientData>  $clients
     */
    public function __construct(
        public readonly array $incidents,
        public readonly PaginationData $pagination,
        public readonly IncidentFilterData $filters,
        public readonly array $clients,
    ) {}
}
