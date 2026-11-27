<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The Site settings "Server" field: the server the site runs on and every server to pick from. */
#[TypeScript]
final class SiteServerChoicesData extends Data
{
    /** @param list<array{id: int, name: string, ips: list<string>}> $servers by name */
    public function __construct(
        public readonly ?int $server_id,
        public readonly array $servers,
    ) {}
}
