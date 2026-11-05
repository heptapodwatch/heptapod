<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\ClientAvatarSource;
use Heptapod\Enums\SiteIconBacking;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ClientAvatarData extends Data
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly SiteIconBacking $backing = SiteIconBacking::None,
        public readonly ClientAvatarSource $source = ClientAvatarSource::Initials,
        public readonly ?int $site_id = null,
        public readonly ?string $site_host = null,
    ) {}
}
