<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Contracts\DirectorySiteData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What the "Link site…" sheet offers (GET /servers/{id}/sites): the DNS suggestions, then the sites matching its search. */
#[TypeScript]
final class ServerLinkData extends Data
{
    /**
     * @param  list<array{site: DirectorySiteData, address: string}>  $suggestions  at most 20 sites on no server whose own host name points at this server, strongest first; only without a search
     * @param  list<DirectorySiteData>  $sites  at most 20 visible sites matching the search and not on this server, problem sites first
     * @param  list<array{site_id: int, server_name: string}>  $linked_elsewhere  the sites among them that linking moves from another server
     */
    public function __construct(
        public readonly array $suggestions,
        public readonly array $sites,
        public readonly array $linked_elsewhere,
    ) {}
}
