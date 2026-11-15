<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\Enums\BucketStatus;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;
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

    /** A check page sends thousands of points; the generic transformer took about 9 µs per point. Same output. */
    public function transform(null|TransformationContextFactory|TransformationContext $transformationContext = null): array
    {
        return ['time' => $this->time, 'value' => $this->value, 'failure_count' => $this->failure_count, 'error_count' => $this->error_count,
            'warning_count' => $this->warning_count, 'failure_status' => $this->failure_status?->value];
    }
}
