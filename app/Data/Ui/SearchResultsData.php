<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SearchResultsData extends Data
{
    /**
     * @param  list<SearchSiteData>  $sites
     * @param  list<ClientData>  $clients
     * @param  list<IncidentData>  $incidents
     */
    public function __construct(
        public readonly array $sites,
        public readonly array $clients,
        public readonly array $incidents,
    ) {}
}
