<?php

declare(strict_types=1);

namespace Heptapod\Addons\Servers\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ServersPageData extends Data
{
    /** @param list<ServerRowData> $servers every server: added by hand by Owners and Admins, so the list shows in full */
    public function __construct(
        public readonly array $servers,
        public readonly ServerThresholdsData $thresholds,
    ) {}
}
