<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A server as the command palette lists it: what names it, and its state badge (state, open issues, snooze). */
#[TypeScript]
final class ServerSearchData extends Data
{
    /**
     * @param  list<string>  $ips  the addresses the agent reports: the palette shows the first and the search matches every one
     * @param  list<array{kind: string}>  $issues  the open issues, in the addon's display order: the first names the state
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $hostname,
        public readonly array $ips,
        /** A ServerState value of the servers addon; core cannot use addon enums. */
        #[LiteralTypeScriptType('Heptapod.Addons.Servers.ServerState')]
        public readonly string $state,
        #[LiteralTypeScriptType('{ kind: Heptapod.Addons.Servers.ServerIssueKind }[]')]
        public readonly array $issues,
        public readonly ?string $snoozed_until,
    ) {}
}
