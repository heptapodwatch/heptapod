<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A site as other parts of the app list it beside their own records: its name, state and client. */
#[TypeScript]
final class DirectorySiteData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $url,
        /** A Heptapod\Enums\SiteStatus value; addons cannot use core enums. */
        #[LiteralTypeScriptType('Heptapod.Enums.SiteStatus')]
        public readonly string $state,
        /** Null for a site without a client. */
        public readonly ?string $client_name,
    ) {}
}
