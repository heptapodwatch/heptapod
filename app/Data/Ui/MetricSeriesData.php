<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class MetricSeriesData extends Data
{
    /**
     * @param  list<ResponsePointData>  $points
     */
    public function __construct(
        public readonly string $key,
        public readonly string $unit,
        public readonly array $points,
    ) {}
}
