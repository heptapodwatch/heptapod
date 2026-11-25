<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One calendar month in the viewer's time zone; both values come from the same incident intervals. */
#[TypeScript]
final class UptimeMonthData extends Data
{
    public function __construct(
        public readonly string $month,
        public readonly ?float $uptime,
        public readonly int $interrupted_seconds,
    ) {}
}
