<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** The uptime check's long-term view: up to 24 months since its first result, and uptime over 90 days, a year and all. */
#[TypeScript]
final class MonthlyUptimeData extends Data
{
    /** @param  list<UptimeMonthData>  $months */
    public function __construct(
        public readonly string $since,
        public readonly array $months,
        public readonly ?float $days90,
        public readonly ?float $days365,
        public readonly ?float $all,
    ) {}
}
