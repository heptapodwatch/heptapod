<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class IncidentSummaryData extends Data
{
    public function __construct(
        public readonly int $count,
        public readonly int $total_duration_seconds,
        public readonly ?int $median_duration_seconds,
    ) {}
}
