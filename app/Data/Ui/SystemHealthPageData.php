<?php

declare(strict_types=1);

namespace Heptapod\Data\Ui;

use Heptapod\SystemHealth\SystemHealthData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class SystemHealthPageData extends Data
{
    public function __construct(
        public readonly SystemHealthData $health,
        public readonly string $observed_at,
        public readonly ?string $last_result_at,
        public readonly int $check_errors,
    ) {}
}
