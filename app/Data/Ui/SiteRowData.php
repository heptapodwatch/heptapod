<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\SiteStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SiteRowData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  list<ResponsePointData>  $response
     * @param  list<CheckStripData>  $checks
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $url,
        public readonly int $client_id,
        public readonly array $tags,
        public readonly SiteStatus $state,
        public readonly ?float $uptime,
        public readonly array $response,
        public readonly array $checks,
        public readonly ?string $checked_at,
        public readonly SiteIdentityData $identity,
    ) {}
}
