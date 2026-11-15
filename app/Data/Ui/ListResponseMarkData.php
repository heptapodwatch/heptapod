<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\BucketStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A non-OK bucket of a ListResponseSeriesData, addressed by its slot. */
#[TypeScript]
final class ListResponseMarkData extends Data
{
    public function __construct(
        public readonly int $slot,
        public readonly int $failure_count,
        public readonly int $error_count,
        public readonly int $warning_count,
        public readonly ?BucketStatus $failure_status,
    ) {}
}
