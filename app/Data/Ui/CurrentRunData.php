<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CurrentRunData extends Data
{
    public function __construct(
        public readonly int $check_id,
        public readonly int $site_id,
        public readonly int $client_id,
        public readonly string $site_name,
        public readonly CheckKey $check_type,
        public readonly CheckRunStateData $run,
        public readonly ?string $icon_url = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
    ) {}
}
