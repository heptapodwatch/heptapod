<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class UpcomingItemData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $date,
        public readonly string $type,
        public readonly ?string $site_name,
        public readonly ?string $client_name,
        public readonly string $detail,
        public readonly string $url,
        public readonly ?string $target_type = null,
        public readonly ?int $target_id = null,
        public readonly ?string $target_label = null,
        public readonly ?int $site_count = null,
        public readonly ?string $icon_url = null,
        public readonly SiteIconBacking $icon_backing = SiteIconBacking::None,
        public readonly ?int $client_id = null,
    ) {}
}
