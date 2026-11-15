<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The Sites list's second deferred group: one site's 30-day uptime and 24 h response series. */
#[TypeScript]
final class SiteHistoryData extends Data
{
    public function __construct(
        public readonly int $site_id,
        public readonly ?float $uptime,
        public readonly ListResponseSeriesData $response,
    ) {}
}
