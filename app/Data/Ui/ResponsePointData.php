<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\BucketStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ResponsePointData extends Data
{
    public function __construct(
        public readonly string $time,
        public readonly ?float $value,
        public readonly int $failure_count = 0,
        public readonly int $error_count = 0,
        public readonly int $warning_count = 0,
        public readonly ?BucketStatus $failure_status = null,
    ) {}
}
