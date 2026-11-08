<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The site overview's "Last 30 days": uptime outages that started in the window and the Performance tab's latest hourly average. */
#[TypeScript]
final class SiteRecordData extends Data
{
    public function __construct(
        public readonly int $outage_count,
        public readonly int $downtime_seconds,
        public readonly ?string $last_outage_started_at,
        public readonly ?float $response_ms,
    ) {}
}
