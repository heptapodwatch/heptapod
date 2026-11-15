<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A compact 24 h peak-response series: slot i starts at start + i * step_seconds; null means no measured value. */
#[TypeScript]
final class ListResponseSeriesData extends Data
{
    /**
     * @param  list<int|null>  $values  Peak response time per slot in whole milliseconds.
     * @param  list<ListResponseMarkData>  $marks
     */
    public function __construct(
        public readonly string $start,
        public readonly int $step_seconds,
        public readonly array $values,
        public readonly array $marks,
    ) {}
}
