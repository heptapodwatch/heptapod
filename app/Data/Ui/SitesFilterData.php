<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Contracts\CheckKey;
use Heptapod\Enums\SiteStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SitesFilterData extends Data
{
    public function __construct(
        public readonly ?string $search,
        public readonly ?int $client_id,
        public readonly ?SiteStatus $state,
        public readonly ?string $tag,
        public readonly string $sort,
        public readonly ?CheckKey $check = null,
        public readonly bool $attention = false,
    ) {}
}
