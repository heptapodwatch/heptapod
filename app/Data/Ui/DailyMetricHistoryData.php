<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\DailyMetricRange;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DailyMetricHistoryData extends Data
{
    /** @param list<array{day: string, averages: array<string, float>|null}> $points */
    public function __construct(
        public readonly DailyMetricRange $range,
        public readonly array $points,
    ) {}
}
