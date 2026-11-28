<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\ServerSearchData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SearchResultsData extends Data
{
    /**
     * @param  list<SearchSiteData>  $sites
     * @param  list<ClientData>  $clients
     * @param  list<IncidentData>  $incidents
     * @param  list<ServerSearchData>  $servers  from the servers addon (ServerSearch), only for those who may see servers
     */
    public function __construct(
        public readonly array $sites,
        public readonly array $clients,
        public readonly array $incidents,
        public readonly array $servers,
    ) {}
}
