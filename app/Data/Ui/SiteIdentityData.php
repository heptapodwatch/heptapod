<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\SiteIconBacking;
use Heptapod\Enums\SiteStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SiteIdentityData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $url,
        public readonly int $client_id,
        public readonly SiteStatus $state,
        public readonly ?string $icon_url = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
    ) {}
}
