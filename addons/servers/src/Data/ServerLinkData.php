<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Heptapod\Contracts\DirectorySiteData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** What the "Link site…" sheet offers: the DNS suggestions, then every other site to pick from. */
#[TypeScript]
final class ServerLinkData extends Data
{
    /**
     * @param  list<array{site: DirectorySiteData, address: string}>  $suggestions  sites linked to no server whose DNS points at one of this server's addresses
     * @param  list<DirectorySiteData>  $sites  every visible site not linked to this server, problem sites first
     * @param  list<array{site_id: int, server_name: string}>  $linked_elsewhere  the sites among them that linking moves from another server
     */
    public function __construct(
        public readonly array $suggestions,
        public readonly array $sites,
        public readonly array $linked_elsewhere,
    ) {}
}
